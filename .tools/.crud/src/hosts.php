<?php
// Gestión del archivo hosts de Windows y dominios locales (.test).

// Devuelve la ruta por defecto del archivo hosts de Windows.
function ruta_hosts_windows()
{
    $winDir = getenv('SystemRoot') ?: 'C:\\Windows';
    return $winDir . '\\System32\\drivers\\etc\\hosts';
}

// Analiza el contenido de un archivo hosts y devuelve un array asociativo con dominios ya mapeados.
function analizar_hosts($contenido)
{
    $lineas = explode("\n", str_replace("\r\n", "\n", $contenido));
    $dominios = [];

    foreach ($lineas as $num => $linea) {
        $limpia = trim(preg_replace('/#.*/', '', $linea));
        if ($limpia === '') {
            continue;
        }

        $partes = preg_split('/\s+/', $limpia);
        if (count($partes) >= 2) {
            $ip = $partes[0];
            for ($i = 1; $i < count($partes); $i++) {
                $dominio = strtolower($partes[$i]);
                $dominios[$dominio] = [
                    'ip' => $ip,
                    'linea' => $num + 1,
                ];
            }
        }
    }

    return $dominios;
}

// Agrega entradas al contenido del hosts preservando todo el contenido existente.
// Devuelve el nuevo contenido con las entradas faltantes añadidas bajo un bloque gestionado.
function agregar_entradas_hosts_contenido($contenido_actual, array $entradas, $marcador = 'crud-generator')
{
    $dominios_actuales = analizar_hosts($contenido_actual);
    $nuevas_lineas = [];

    foreach ($entradas as $dominio => $ip) {
        $dom_lower = strtolower($dominio);
        if (!isset($dominios_actuales[$dom_lower])) {
            $nuevas_lineas[] = "$ip $dominio";
        }
    }

    if (empty($nuevas_lineas)) {
        return [
            'modificado' => false,
            'contenido' => $contenido_actual,
            'agregadas' => [],
        ];
    }

    $crlf = (strpos($contenido_actual, "\r\n") !== false) ? "\r\n" : "\n";
    $bloque = $crlf . "# <$marcador>" . $crlf;
    foreach ($nuevas_lineas as $nl) {
        $bloque .= $nl . $crlf;
    }
    $bloque .= "# </$marcador>" . $crlf;

    return [
        'modificado' => true,
        'contenido' => rtrim($contenido_actual) . $bloque,
        'agregadas' => $nuevas_lineas,
    ];
}

// Gestiona la adición de dominios locales al archivo hosts de Windows.
// Si se le pasa $ruta_hosts personalizada (para pruebas), escribe ahí.
// Devuelve un array:
// [
//   'exito' => bool,
//   'estado' => 'sin_cambios' | 'actualizado' | 'requiere_admin' | 'error',
//   'dominios' => array,
//   'lineas_manuales' => array,
//   'mensaje' => string
// ]
function configurar_hosts_local($nombre_proyecto, $ip = '127.0.0.1', $ruta_hosts = null)
{
    $ruta = $ruta_hosts ?: ruta_hosts_windows();
    $dominio_back = slug($nombre_proyecto) . '-backend.test';

    $resultado = [
        'exito' => false,
        'estado' => 'sin_cambios',
        'dominio' => $dominio_back,
        'ip' => $ip,
        'lineas_manuales' => ["$ip $dominio_back"],
        'mensaje' => '',
    ];

    if (!file_exists($ruta)) {
        $resultado['estado'] = 'error';
        $resultado['mensaje'] = "Archivo hosts no encontrado en: $ruta";
        return $resultado;
    }

    $contenido_actual = @file_get_contents($ruta);
    if ($contenido_actual === false) {
        $resultado['estado'] = 'requiere_admin';
        $resultado['mensaje'] = "No se pudo leer el archivo hosts ($ruta). Permisos insuficientes.";
        return $resultado;
    }

    $analisis = analizar_hosts($contenido_actual);
    if (isset($analisis[strtolower($dominio_back)])) {
        $resultado['exito'] = true;
        $resultado['estado'] = 'sin_cambios';
        $resultado['mensaje'] = "El dominio local '$dominio_back' ya está configurado en el archivo hosts.";
        return $resultado;
    }

    $prep = agregar_entradas_hosts_contenido($contenido_actual, [$dominio_back => $ip]);
    if (!$prep['modificado']) {
        $resultado['exito'] = true;
        $resultado['estado'] = 'sin_cambios';
        return $resultado;
    }

    // Intentar escritura directa
    $escribio = @file_put_contents($ruta, $prep['contenido']);
    if ($escribio !== false) {
        $resultado['exito'] = true;
        $resultado['estado'] = 'actualizado';
        $resultado['mensaje'] = "Dominio local '$dominio_back' agregado exitosamente al archivo hosts.";
        return $resultado;
    }

    // Si falló por permisos en el archivo hosts real de Windows (sin $ruta_hosts personalizada)
    if ($ruta_hosts === null) {
        // Intentar elevar con PowerShell de forma segura
        $temp_script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud_hosts_' . uniqid() . '.ps1';
        $linea_host = "$ip $dominio_back";
        $ps_code = "Add-Content -Path '$ruta' -Value \"`n# <crud-generator>`n$linea_host`n# </crud-generator>\" -Encoding ascii";
        file_put_contents($temp_script, $ps_code);

        try {
            $cmd = "powershell -Command \"Start-Process powershell -ArgumentList '-NoProfile -ExecutionPolicy Bypass -File \\\"$temp_script\\\"' -Verb RunAs -Wait\" 2>NUL";
            @exec($cmd);
        } finally {
            @unlink($temp_script);
        }

        // Volver a leer para confirmar si tuvo éxito
        $contenido_reintento = @file_get_contents($ruta);
        if ($contenido_reintento !== false) {
            $analisis_reintento = analizar_hosts($contenido_reintento);
            if (isset($analisis_reintento[strtolower($dominio_back)])) {
                $resultado['exito'] = true;
                $resultado['estado'] = 'actualizado';
                $resultado['mensaje'] = "Dominio local '$dominio_back' agregado al archivo hosts mediante elevación UAC.";
                return $resultado;
            }
        }
    }

    $resultado['estado'] = 'requiere_admin';
    $resultado['mensaje'] = "No se pudo modificar el archivo hosts automáticamente. Se requieren permisos de administrador.";
    return $resultado;
}
