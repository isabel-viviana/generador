<?php
// Copia segura de archivos fuente con sustituciones literales explícitamente autorizadas.

function resultado_copia_fuente($estado, $origen, $destino, $motivo, array $pendientes = [], $adaptaciones_aplicadas = 0)
{
    return [
        'estado' => $estado,
        'origen' => $origen,
        'destino' => $destino,
        'motivo' => $motivo,
        'adaptaciones_aplicadas' => $adaptaciones_aplicadas,
        'pendientes' => $pendientes,
    ];
}

function ruta_relativa_fuente_segura($ruta)
{
    if (!is_string($ruta) || $ruta === '' || str_contains($ruta, "\0")) {
        return null;
    }

    $ruta = str_replace('\\', '/', $ruta);
    if ($ruta[0] === '/' || preg_match('/^[A-Za-z]:/', $ruta)) {
        return null;
    }

    $partes = [];
    foreach (explode('/', $ruta) as $parte) {
        if ($parte === '' || $parte === '.') {
            continue;
        }
        if ($parte === '..') {
            return null;
        }
        $partes[] = $parte;
    }

    return $partes === [] ? null : implode(DIRECTORY_SEPARATOR, $partes);
}

function ruta_fuente_dentro_de_raiz($ruta, $raiz)
{
    $prefijo = rtrim($raiz, '/\\') . DIRECTORY_SEPARATOR;
    if (DIRECTORY_SEPARATOR === '\\') {
        return strncasecmp($ruta, $prefijo, strlen($prefijo)) === 0;
    }

    return str_starts_with($ruta, $prefijo);
}

function escribir_temporal_fuente($directorio, $contenido, $permisos_origen)
{
    $temporal = null;
    for ($intento = 0; $intento < 5; $intento++) {
        $candidato = $directorio . DIRECTORY_SEPARATOR . '.crud-source-' . bin2hex(random_bytes(12));
        $archivo = @fopen($candidato, 'x+b');
        if ($archivo !== false) {
            $temporal = $candidato;
            break;
        }
    }
    if ($temporal === null) {
        return false;
    }

    $longitud = strlen($contenido);
    $escritos = 0;
    while ($escritos < $longitud) {
        $cantidad = @fwrite($archivo, substr($contenido, $escritos));
        if ($cantidad === false || $cantidad === 0) {
            fclose($archivo);
            @unlink($temporal);
            return false;
        }
        $escritos += $cantidad;
    }

    $vaciado = @fflush($archivo);
    $cerrado = fclose($archivo);
    if (!$vaciado || !$cerrado) {
        @unlink($temporal);
        return false;
    }

    if ($permisos_origen !== false && !@chmod($temporal, $permisos_origen & 0777)) {
        @unlink($temporal);
        return false;
    }

    return $temporal;
}

function escribir_destino_exclusivo_fuente($ruta, $contenido, $permisos_origen)
{
    $archivo = @fopen($ruta, 'xb');
    if ($archivo === false) {
        return false;
    }

    $longitud = strlen($contenido);
    $escritos = 0;
    while ($escritos < $longitud) {
        $cantidad = @fwrite($archivo, substr($contenido, $escritos));
        if ($cantidad === false || $cantidad === 0) {
            fclose($archivo);
            @unlink($ruta);
            return false;
        }
        $escritos += $cantidad;
    }

    $vaciado = @fflush($archivo);
    $cerrado = fclose($archivo);
    if (!$vaciado || !$cerrado) {
        @unlink($ruta);
        return false;
    }

    if ($permisos_origen !== false && !@chmod($ruta, $permisos_origen & 0777)) {
        @unlink($ruta);
        return false;
    }

    return true;
}

function adaptar_contenido_fuente($contenido, array $adaptaciones)
{
    $pendientes = [];
    foreach ($adaptaciones as $token => $reemplazo) {
        if (!is_string($token) || $token === '' || !is_string($reemplazo)) {
            return [
                'contenido' => null,
                'adaptaciones_aplicadas' => 0,
                'pendientes' => [],
                'error' => 'Cada adaptación debe declarar un token literal no vacío y un reemplazo de texto.',
            ];
        }
        if (!str_contains($contenido, $token)) {
            $pendientes[] = [
                'token' => $token,
                'motivo' => 'El token autorizado no aparece en el archivo fuente.',
            ];
        }
    }

    if ($adaptaciones === []) {
        return [
            'contenido' => $contenido,
            'adaptaciones_aplicadas' => 0,
            'pendientes' => [],
            'error' => null,
        ];
    }

    $tokens = array_keys($adaptaciones);
    usort($tokens, static fn($a, $b) => strlen($b) <=> strlen($a));
    $patron = '~(?:' . implode('|', array_map(static fn($token) => preg_quote($token, '~'), $tokens)) . ')~';
    $tokens_aplicados = [];
    $contenido_adaptado = preg_replace_callback(
        $patron,
        static function ($coincidencia) use ($adaptaciones, &$tokens_aplicados) {
            $token = $coincidencia[0];
            $tokens_aplicados[$token] = true;
            return $adaptaciones[$token];
        },
        $contenido
    );
    if ($contenido_adaptado === null) {
        return [
            'contenido' => null,
            'adaptaciones_aplicadas' => 0,
            'pendientes' => $pendientes,
            'error' => 'No se pudieron procesar las adaptaciones literales.',
        ];
    }

    return [
        'contenido' => $contenido_adaptado,
        'adaptaciones_aplicadas' => count($tokens_aplicados),
        'pendientes' => $pendientes,
        'error' => null,
    ];
}

/**
 * Copia un archivo sin alterar sus bytes, excepto por tokens literales declarados.
 *
 * $adaptaciones usa pares exactos token => reemplazo, por ejemplo:
 * ['{{ENTITY_CLASS}}' => 'Student']. No se infieren ni transforman nombres.
 * Los estados posibles son copiado, adaptado, sin_cambios, conflicto y error.
 */
function copiar_archivo_fuente($origen, $directorio_destino, $ruta_relativa, array $adaptaciones = [])
{
    $ruta = ruta_relativa_fuente_segura($ruta_relativa);
    if ($ruta === null) {
        return resultado_copia_fuente('error', $origen, $ruta_relativa, 'La ruta de destino debe ser relativa y no puede salir del directorio destino.');
    }

    $raiz = realpath($directorio_destino);
    if ($raiz === false || !is_dir($raiz)) {
        return resultado_copia_fuente('error', $origen, $ruta_relativa, 'El directorio destino no existe o no se puede resolver.');
    }

    if (!is_string($origen) || is_link($origen) || !is_file($origen) || !is_readable($origen)) {
        return resultado_copia_fuente('error', $origen, $ruta_relativa, 'El archivo fuente no existe, no es regular o no se puede leer.');
    }

    $origen_real = realpath($origen);
    $contenido = $origen_real === false ? false : @file_get_contents($origen_real);
    if ($contenido === false) {
        return resultado_copia_fuente('error', $origen, $ruta_relativa, 'No se pudo leer el archivo fuente.');
    }

    $adaptacion = adaptar_contenido_fuente($contenido, $adaptaciones);
    if ($adaptacion['error'] !== null) {
        return resultado_copia_fuente('error', $origen, $ruta_relativa, $adaptacion['error']);
    }
    $contenido_adaptado = $adaptacion['contenido'];
    $pendientes = $adaptacion['pendientes'];
    $adaptaciones_aplicadas = $adaptacion['adaptaciones_aplicadas'];

    $destino = $raiz . DIRECTORY_SEPARATOR . $ruta;
    $directorio = $raiz;
    $partes = explode(DIRECTORY_SEPARATOR, $ruta);
    $nombre_archivo = array_pop($partes);

    foreach ($partes as $parte) {
        $directorio .= DIRECTORY_SEPARATOR . $parte;
        if (is_link($directorio)) {
            return resultado_copia_fuente('error', $origen, $destino, 'Un directorio de destino es un enlace simbólico.');
        }
        if (!is_dir($directorio) && !@mkdir($directorio, 0777)) {
            if (!is_dir($directorio)) {
                return resultado_copia_fuente('error', $origen, $destino, 'No se pudo crear un directorio de destino.');
            }
        }
        $directorio_real = realpath($directorio);
        if ($directorio_real === false || !ruta_fuente_dentro_de_raiz($directorio_real, $raiz)) {
            return resultado_copia_fuente('error', $origen, $destino, 'Un directorio de destino resuelve fuera de la raíz permitida.');
        }
    }

    $destino = $directorio . DIRECTORY_SEPARATOR . $nombre_archivo;
    if (is_link($destino)) {
        return resultado_copia_fuente('conflicto', $origen, $destino, 'El destino es un enlace simbólico y se conserva sin cambios.', $pendientes, $adaptaciones_aplicadas);
    }

    if (file_exists($destino)) {
        if (!is_file($destino) || !is_readable($destino)) {
            return resultado_copia_fuente('conflicto', $origen, $destino, 'El destino existente no es un archivo regular legible y se conserva sin cambios.', $pendientes, $adaptaciones_aplicadas);
        }
        $contenido_existente = @file_get_contents($destino);
        if ($contenido_existente === false) {
            return resultado_copia_fuente('error', $origen, $destino, 'No se pudo leer el destino existente.');
        }
        if ($contenido_existente === $contenido_adaptado) {
            return resultado_copia_fuente('sin_cambios', $origen, $destino, 'El destino ya contiene exactamente el contenido esperado.', $pendientes, $adaptaciones_aplicadas);
        }
        return resultado_copia_fuente('conflicto', $origen, $destino, 'El destino ya existe con contenido distinto; se conserva sin sobrescribir.', $pendientes, $adaptaciones_aplicadas);
    }

    $permisos_origen = @fileperms($origen_real);
    $temporal = escribir_temporal_fuente($directorio, $contenido_adaptado, $permisos_origen);
    if ($temporal === false) {
        return resultado_copia_fuente('error', $origen, $destino, 'No se pudo escribir completamente el archivo temporal de destino.', $pendientes, $adaptaciones_aplicadas);
    }

    // link() crea el destino de forma exclusiva y evita reemplazar un archivo concurrente.
    $publicado = @link($temporal, $destino);
    if (!$publicado) {
        @unlink($temporal);
        if (file_exists($destino)) {
            $contenido_existente = @file_get_contents($destino);
            if ($contenido_existente !== false && $contenido_existente === $contenido_adaptado) {
                return resultado_copia_fuente('sin_cambios', $origen, $destino, 'Otro proceso publicó el contenido esperado.', $pendientes, $adaptaciones_aplicadas);
            }
            return resultado_copia_fuente('conflicto', $origen, $destino, 'El destino apareció durante la copia; se conserva sin sobrescribir.', $pendientes, $adaptaciones_aplicadas);
        }
        if (!escribir_destino_exclusivo_fuente($destino, $contenido_adaptado, $permisos_origen)) {
            if (file_exists($destino)) {
                return resultado_copia_fuente('conflicto', $origen, $destino, 'El destino apareció durante la copia; se conserva sin sobrescribir.', $pendientes, $adaptaciones_aplicadas);
            }
            return resultado_copia_fuente('error', $origen, $destino, 'No se pudo publicar el archivo en el destino.');
        }
    }

    if ($publicado && !@unlink($temporal)) {
        return resultado_copia_fuente('error', $origen, $destino, 'El archivo se publicó, pero no se pudo retirar el temporal.');
    }

    $estado = $adaptaciones_aplicadas > 0 ? 'adaptado' : 'copiado';
    $motivo = $adaptaciones_aplicadas > 0
        ? 'Se copió el original aplicando únicamente tokens literales autorizados.'
        : 'Se copiaron los bytes originales sin modificaciones.';

    return resultado_copia_fuente($estado, $origen, $destino, $motivo, $pendientes, $adaptaciones_aplicadas);
}
