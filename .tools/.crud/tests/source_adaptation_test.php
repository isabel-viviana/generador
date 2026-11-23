<?php

require_once __DIR__ . '/../src/source_adaptation.php';

function fuente_assert($condicion, $mensaje)
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function fuente_fixture()
{
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-source-' . bin2hex(random_bytes(6));
    if (!mkdir($ruta, 0777, true)) {
        throw new RuntimeException('No se pudo crear el fixture de archivos fuente.');
    }
    return $ruta;
}

function fuente_limpiarf_fixture($ruta)
{
    if (!is_dir($ruta)) {
        return;
    }
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterador as $elemento) {
        if ($elemento->isLink() || !$elemento->isDir()) {
            unlink($elemento->getPathname());
        } else {
            rmdir($elemento->getPathname());
        }
    }
    rmdir($ruta);
}

$fixture = fuente_fixture();
$fuentes = $fixture . DIRECTORY_SEPARATOR . 'source';
$destinos = $fixture . DIRECTORY_SEPARATOR . 'destination';
mkdir($fuentes);
mkdir($destinos);

try {
    $contenido_exacto = "bytes originales\r\ncon salto de línea y UTF-8: ñ\n";
    $origen_simple = $fuentes . DIRECTORY_SEPARATOR . 'simple.txt';
    file_put_contents($origen_simple, 'exact bytes');
    $resultado = copiar_archivo_fuente($origen_simple, $destinos, 'simple.txt');
    fuente_assert($resultado['estado'] === 'copiado', 'No se copió el archivo simple.');
    fuente_assert(file_get_contents($destinos . DIRECTORY_SEPARATOR . 'simple.txt') === 'exact bytes', 'La copia del archivo simple cambió sus bytes.');
    echo "OK: copia exacta de archivo simple\n";

    $origen_exacto = $fuentes . DIRECTORY_SEPARATOR . 'exact.txt';
    file_put_contents($origen_exacto, $contenido_exacto);
    $resultado = copiar_archivo_fuente($origen_exacto, $destinos, 'exact.txt');
    fuente_assert($resultado['estado'] === 'copiado', 'No se copió el archivo intacto.');
    fuente_assert(file_get_contents($destinos . DIRECTORY_SEPARATOR . 'exact.txt') === $contenido_exacto, 'La copia intacta cambió sus bytes.');
    echo "OK: copia exacta de archivo multilínea\n";

    $origen_anidado = $fuentes . DIRECTORY_SEPARATOR . 'nested.txt';
    file_put_contents($origen_anidado, "first\nsecond\nthird\n");
    $resultado = copiar_archivo_fuente($origen_anidado, $destinos, 'a/b/nested.txt');
    fuente_assert($resultado['estado'] === 'copiado', 'No se copió el archivo anidado.');
    fuente_assert(file_get_contents($destinos . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'nested.txt') === "first\nsecond\nthird\n", 'El archivo anidado no conserva su contenido.');
    echo "OK: creación y copia en directorios anidados\n";

    $origen_adaptable = $fuentes . DIRECTORY_SEPARATOR . 'adaptable.txt';
    $contenido_adaptable = "class {{MODEL_CLASS}} {}\n// Student literal permanece sin cambios\n";
    file_put_contents($origen_adaptable, $contenido_adaptable);
    $resultado = copiar_archivo_fuente($origen_adaptable, $destinos, 'adaptable.txt', [
        '{{MODEL_CLASS}}' => 'Learner',
    ]);
    fuente_assert($resultado['estado'] === 'adaptado', 'No se aplicó el token autorizado.');
    fuente_assert(file_get_contents($destinos . DIRECTORY_SEPARATOR . 'adaptable.txt') === "class Learner {}\n// Student literal permanece sin cambios\n", 'La adaptación modificó contenido no autorizado.');
    echo "OK: sustitución explícita y conservación del contenido no afectado\n";

    $origen_cadena = $fuentes . DIRECTORY_SEPARATOR . 'chain.txt';
    file_put_contents($origen_cadena, '{{FIRST}}');
    $resultado = copiar_archivo_fuente($origen_cadena, $destinos, 'chain.txt', [
        '{{FIRST}}' => '{{SECOND}}',
        '{{SECOND}}' => 'should-not-run',
    ]);
    fuente_assert($resultado['estado'] === 'adaptado', 'No se aplicó la primera adaptación independiente.');
    fuente_assert(file_get_contents($destinos . DIRECTORY_SEPARATOR . 'chain.txt') === '{{SECOND}}', 'Una sustitución encadenada modificó texto recién insertado.');
    echo "OK: las sustituciones no se encadenan sobre texto insertado\n";

    $destino_manual = $destinos . DIRECTORY_SEPARATOR . 'manual.txt';
    file_put_contents($destino_manual, 'contenido manual');
    $resultado = copiar_archivo_fuente($origen_exacto, $destinos, 'manual.txt');
    fuente_assert($resultado['estado'] === 'conflicto', 'No se detectó el conflicto con archivo manual.');
    fuente_assert(file_get_contents($destino_manual) === 'contenido manual', 'El archivo manual fue sobrescrito.');
    $destino_modificado = $destinos . DIRECTORY_SEPARATOR . 'modified.txt';
    $resultado = copiar_archivo_fuente($origen_exacto, $destinos, 'modified.txt');
    fuente_assert($resultado['estado'] === 'copiado', 'No se creó el archivo para probar una modificación posterior.');
    file_put_contents($destino_modificado, 'edición manual posterior');
    $resultado = copiar_archivo_fuente($origen_exacto, $destinos, 'modified.txt');
    fuente_assert($resultado['estado'] === 'conflicto', 'No se detectó el conflicto de una copia modificada manualmente.');
    fuente_assert(file_get_contents($destino_modificado) === 'edición manual posterior', 'La modificación manual posterior fue sobrescrita.');
    echo "OK: protección de archivos existentes y detección de conflictos\n";

    $resultado = copiar_archivo_fuente($origen_exacto, $destinos, 'exact.txt');
    fuente_assert($resultado['estado'] === 'sin_cambios', 'La segunda ejecución no fue idempotente.');
    fuente_assert(file_get_contents($destinos . DIRECTORY_SEPARATOR . 'exact.txt') === $contenido_exacto, 'La segunda ejecución duplicó o cambió el contenido.');
    echo "OK: segunda ejecución sin cambios ni duplicaciones\n";

    $resultado = copiar_archivo_fuente($origen_adaptable, $destinos, 'pendiente.txt', [
        '{{MISSING_TOKEN}}' => 'Ignored',
    ]);
    fuente_assert($resultado['estado'] === 'copiado' && count($resultado['pendientes']) === 1, 'No se registró la adaptación pendiente.');
    fuente_assert(file_get_contents($destinos . DIRECTORY_SEPARATOR . 'pendiente.txt') === $contenido_adaptable, 'Una adaptación no encontrada alteró el original.');
    echo "OK: adaptación no disponible queda pendiente sin modificar la fuente\n";

    $resultado = copiar_archivo_fuente($fuentes . DIRECTORY_SEPARATOR . 'missing.txt', $destinos, 'missing.txt');
    fuente_assert($resultado['estado'] === 'error', 'No se informó el error de lectura de la fuente.');
    $archivo_como_directorio = $destinos . DIRECTORY_SEPARATOR . 'not-a-directory';
    file_put_contents($archivo_como_directorio, 'block');
    $resultado = copiar_archivo_fuente($origen_exacto, $destinos, 'not-a-directory/child.txt');
    fuente_assert($resultado['estado'] === 'error', 'No se informó el error de creación del directorio.');
    echo "OK: manejo de fuentes ilegibles y errores de escritura/directorio\n";

    $fuera = $fixture . DIRECTORY_SEPARATOR . 'outside.txt';
    file_put_contents($fuera, 'outside sentinel');
    $resultado = copiar_archivo_fuente($origen_exacto, $destinos, '../outside.txt');
    fuente_assert($resultado['estado'] === 'error', 'Se aceptó una ruta que escapa del destino.');
    fuente_assert(file_get_contents($fuera) === 'outside sentinel', 'Se modificó un archivo fuera del destino.');

    if (function_exists('symlink')) {
        $enlace = $destinos . DIRECTORY_SEPARATOR . 'escape';
        if (@symlink($fixture, $enlace)) {
            $resultado = copiar_archivo_fuente($origen_exacto, $destinos, 'escape/linked.txt');
            fuente_assert($resultado['estado'] === 'error', 'Se permitió escribir mediante un enlace simbólico fuera del destino.');
            fuente_assert(!file_exists($fixture . DIRECTORY_SEPARATOR . 'linked.txt'), 'Se creó un archivo fuera del destino mediante enlace simbólico.');
        }
    }
    echo "OK: protección contra escrituras fuera del directorio destino\n";
} finally {
    fuente_limpiarf_fixture($fixture);
}
