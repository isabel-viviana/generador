<?php

require_once CRUD_DIR . '/src/source_adaptation.php';

function frontend_archivo_fuente($fuente, $destino, $ruta_relativa, array $adaptaciones, array &$resumen)
{
    $contenido = @file_get_contents($fuente);
    if ($contenido === false) {
        $resultado = [
            'origen' => $fuente,
            'destino' => $ruta_relativa,
            'estado' => 'error',
            'adaptaciones_aplicadas' => 0,
            'pendientes' => [],
            'motivo' => 'No se pudo leer el archivo fuente.',
        ];
    } else {
        $adaptaciones_presentes = [];
        foreach ($adaptaciones as $token => $reemplazo) {
            if ($token !== $reemplazo && str_contains($contenido, $token)) {
                $adaptaciones_presentes[$token] = $reemplazo;
            }
        }
        $resultado = copiar_archivo_fuente($fuente, $destino, $ruta_relativa, $adaptaciones_presentes);
    }
    $resumen['archivos_fuente'][] = [
        'origen' => $resultado['origen'],
        'destino' => $resultado['destino'],
        'estado' => $resultado['estado'],
        'adaptaciones_aplicadas' => $resultado['adaptaciones_aplicadas'],
        'pendientes' => $resultado['pendientes'],
        'motivo' => $resultado['motivo'],
    ];

    foreach ($resultado['pendientes'] as $pendiente) {
        $resumen['adaptaciones_pendientes'][] = [
            'tabla' => null,
            'tipo' => 'archivo fuente',
            'motivo' => $resultado['destino'] . ': ' . $pendiente['motivo'],
        ];
    }

    if (in_array($resultado['estado'], ['error', 'conflicto'], true)) {
        $resumen['errores'][] = $resultado['destino'] . ': ' . $resultado['motivo'];
    }

    return $resultado;
}

function frontend_aplicar_adaptaciones($contenido, array $pares)
{
    $pares_aplicables = [];
    foreach ($pares as $token => $reemplazo) {
        if ($token !== $reemplazo && str_contains($contenido, $token)) {
            $pares_aplicables[$token] = $reemplazo;
        }
    }

    return adaptar_contenido_fuente($contenido, $pares_aplicables);
}

function frontend_ruta_fuente($raiz, $ruta_relativa)
{
    $ruta = $raiz . DIRECTORY_SEPARATOR . str_replace(
        ['/', '\\'],
        DIRECTORY_SEPARATOR,
        $ruta_relativa
    );

    $raiz_real = realpath($raiz);
    $ruta_real = realpath($ruta);
    if (is_link($ruta)
        || !is_file($ruta)
        || !is_readable($ruta)
        || $raiz_real === false
        || $ruta_real === false
        || !ruta_fuente_dentro_de_raiz($ruta_real, $raiz_real)) {
        return null;
    }

    return $ruta_real;
}

function frontend_rutas_iguales($ruta_a, $ruta_b)
{
    $normal_a = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta_a), DIRECTORY_SEPARATOR);
    $normal_b = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta_b), DIRECTORY_SEPARATOR);

    return DIRECTORY_SEPARATOR === '\\'
        ? strcasecmp($normal_a, $normal_b) === 0
        : $normal_a === $normal_b;
}

function frontend_campos_editables($tabla_info)
{
    $campos = [];
    foreach ($tabla_info['columnas'] ?? [] as $nombre => $columna) {
        if (!empty($columna['es_pk']) || in_array($nombre, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        $campos[$nombre] = $columna;
    }

    return $campos;
}

function frontend_agregar_pendiente(array &$resumen, $tabla, $motivo)
{
    $resumen['adaptaciones_pendientes'][] = [
        'tabla' => $tabla,
        'tipo' => 'manifiesto frontend',
        'motivo' => $motivo,
    ];
}

function frontend_perfiles_ordenados(array $perfiles, $contenido_fuente, $tipo)
{
    $orden = [];
    if ($tipo === 'rutas' && preg_match_all(
        '/Route::resource\([\'"]([^\'"]+)[\'"],\s*([A-Za-z_][A-Za-z0-9_]*)::class\)/',
        $contenido_fuente,
        $coincidencias,
        PREG_SET_ORDER
    )) {
        foreach ($coincidencias as $coincidencia) {
            $orden[$coincidencia[1]] = count($orden);
        }
    } elseif ($tipo === 'nav' && preg_match_all(
        '/route\([\'"]([^\'"]+)\.index[\'"]\)/',
        $contenido_fuente,
        $coincidencias
    )) {
        foreach ($coincidencias[1] as $recurso) {
            $orden[$recurso] = count($orden);
        }
    } elseif ($tipo === 'home' && preg_match_all(
        '/route\([\'"]([^\'"]+)\.index[\'"]\)/',
        $contenido_fuente,
        $coincidencias
    )) {
        foreach ($coincidencias[1] as $recurso) {
            $orden[$recurso] = count($orden);
        }
    }

    usort($perfiles, static function ($a, $b) use ($orden) {
        return ($orden[$a['fuente']] ?? PHP_INT_MAX) <=> ($orden[$b['fuente']] ?? PHP_INT_MAX);
    });

    return $perfiles;
}

function frontend_validar_perfil($tabla, $tabla_info, array $perfil, array $perfiles_config, $raiz_fuente)
{
    $obligatorios = [
        'fuente',
        'clase_fuente',
        'singular_tecnico',
        'plural_tecnico',
        'clase_controlador',
        'ruta',
        'api_url',
        'variable_lista',
        'variable_item',
        'singular_interfaz',
        'plural_interfaz',
        'nav_etiqueta',
        'home',
        'campos',
        'relaciones',
        'textos',
    ];
    foreach ($obligatorios as $clave) {
        if (!array_key_exists($clave, $perfil)) {
            return "Falta el dato explícito '$clave'.";
        }
    }

    foreach (['fuente', 'singular_tecnico', 'plural_tecnico', 'clase_controlador', 'ruta', 'api_url', 'variable_lista', 'variable_item', 'singular_interfaz', 'plural_interfaz', 'nav_etiqueta'] as $clave) {
        if (!is_string($perfil[$clave]) || $perfil[$clave] === '') {
            return "El dato '$clave' debe ser texto no vacío.";
        }
    }
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $perfil['clase_controlador'])
        || !preg_match('/^[a-zA-Z0-9_-]+$/', $perfil['ruta'])
        || !preg_match('/^[a-zA-Z0-9_-]+$/', $perfil['api_url'])) {
        return 'El nombre de controlador o una ruta declarada no tiene un formato permitido.';
    }

    $perfil_fuente = $perfiles_config[$perfil['fuente']] ?? null;
    if (!is_array($perfil_fuente)
        || ($perfil_fuente['fuente'] ?? null) !== $perfil['fuente']
        || ($perfil_fuente['clase_fuente'] ?? null) !== $perfil['clase_fuente']) {
        return "No existe un manifiesto fuente explícito y compatible para '{$perfil['fuente']}'.";
    }
    if (!is_array($perfil['campos']) || !is_array($perfil['relaciones']) || !is_array($perfil['textos'])) {
        return 'Campos, relaciones y textos deben declararse como mapas explícitos.';
    }
    if (!is_array($perfil['home'] ?? null)) {
        return 'La tarjeta de Home necesita un mapeo explícito.';
    }
    if ($perfil['ruta'] !== $perfil['fuente']
        || $perfil['singular_interfaz'] !== $perfil_fuente['singular_interfaz']
        || $perfil['plural_interfaz'] !== $perfil_fuente['plural_interfaz']) {
        foreach ($perfil_fuente['textos_requeridos'] ?? [] as $texto_fuente) {
            if (!array_key_exists($texto_fuente, $perfil['textos'])) {
                return "Falta declarar el reemplazo literal del texto fuente '$texto_fuente'.";
            }
        }
    }

    $campos_destino = frontend_campos_editables($tabla_info);
    if (array_keys($campos_destino) !== array_keys($perfil['campos'])) {
        return 'El manifiesto debe declarar, en orden, todos y solo los campos editables de la migración.';
    }

    $campos_fuente_mapeados = [];
    $relaciones_requeridas = [];
    foreach ($perfil['campos'] as $nombre => $mapeo) {
        if (!is_array($mapeo)
            || !isset($mapeo['fuente'], $mapeo['etiqueta'], $mapeo['tipo'], $mapeo['control'])
            || !is_string($mapeo['fuente'])
            || !is_string($mapeo['etiqueta'])
            || !is_string($mapeo['tipo'])
            || !is_string($mapeo['control'])) {
            return "El campo '$nombre' necesita fuente, etiqueta, tipo y control explícitos.";
        }
        if (!isset($perfil_fuente['campos'][$mapeo['fuente']])) {
            return "El campo '$nombre' referencia el campo fuente desconocido '{$mapeo['fuente']}'.";
        }
        if (isset($campos_fuente_mapeados[$mapeo['fuente']])) {
            return "El campo fuente '{$mapeo['fuente']}' se asignó más de una vez.";
        }
        $campos_fuente_mapeados[$mapeo['fuente']] = true;
        if (($campos_destino[$nombre]['tipo'] ?? null) !== $mapeo['tipo']) {
            return "El tipo declarado para '$nombre' no coincide con la migración.";
        }
        $campo_fuente = $perfil_fuente['campos'][$mapeo['fuente']];
        if (($campo_fuente['control'] ?? null) !== $mapeo['control']) {
            return "El control de '$nombre' difiere del control original del campo '{$mapeo['fuente']}'.";
        }
        if (($campo_fuente['imagen'] ?? false) !== ($mapeo['imagen'] ?? false)) {
            return "La configuración de imagen de '$nombre' no coincide con la fuente declarada.";
        }

        $fk = $campos_destino[$nombre]['fk']['referencia_tabla']
            ?? $campos_destino[$nombre]['fk']['tabla_referenciada']
            ?? null;
        $relacion = $mapeo['relacion'] ?? null;
        if (($fk === null) !== ($relacion === null)) {
            return "La relación de '$nombre' debe declararse explícitamente y coincidir con la migración.";
        }
        if ($fk !== null) {
            if (!isset($perfil['relaciones'][$relacion])
                || !is_array($perfil['relaciones'][$relacion])
                || ($perfil['relaciones'][$relacion]['tabla'] ?? null) !== $fk) {
                return "La relación de '$nombre' no coincide con la tabla referenciada '$fk'.";
            }
            $relaciones_requeridas[$relacion] = true;
        }
    }

    if (array_keys($campos_fuente_mapeados) !== array_keys($perfil_fuente['campos'])) {
        return 'La vista original requiere un mapeo ordenado, uno a uno y completo de sus campos.';
    }
    if (count($relaciones_requeridas) !== count($perfil['relaciones'])
        || array_diff_key($relaciones_requeridas, $perfil['relaciones']) !== []) {
        return 'El manifiesto debe declarar únicamente las relaciones asociadas a campos FK.';
    }

    foreach ($perfil['relaciones'] as $campo => $relacion) {
        foreach (['fuente', 'tabla', 'api_url', 'variable_lista', 'variable_item', 'campo_etiqueta', 'etiqueta'] as $clave) {
            if (!isset($relacion[$clave]) || !is_string($relacion[$clave]) || $relacion[$clave] === '') {
                return "La relación '$campo' necesita el dato explícito '$clave'.";
            }
        }
        $relacion_fuente = $perfil_fuente['relaciones'][$perfil['campos'][$campo]['fuente'] ?? ''] ?? null;
        if (!is_array($relacion_fuente) || ($relacion_fuente['fuente'] ?? null) !== $relacion['fuente']) {
            return "No existe una relación fuente explícita compatible para '$campo'.";
        }
    }

    if (!is_array($perfil['home'])
        || !isset($perfil['home']['titulo'], $perfil['home']['descripcion'], $perfil['home']['enlace'])
        || !is_string($perfil['home']['titulo'])
        || !is_string($perfil['home']['descripcion'])
        || !is_string($perfil['home']['enlace'])) {
        return 'La tarjeta de Home necesita título, descripción y enlace explícitos.';
    }
    if (!is_array($perfil['textos'])) {
        return 'Los textos personalizados deben declararse como reemplazos literales.';
    }
    foreach ($perfil['textos'] as $origen => $reemplazo) {
        if (!is_string($origen) || $origen === '' || !is_string($reemplazo)) {
            return 'Cada texto personalizado debe indicar un texto fuente literal y su reemplazo.';
        }
    }

    $ruta_controlador = "app/Http/Controllers/{$perfil['clase_fuente']}Controller.php";
    $archivo_controlador = frontend_ruta_fuente($raiz_fuente, $ruta_controlador);
    if ($archivo_controlador === null) {
        return "No existe el controlador fuente '$ruta_controlador'.";
    }
    $contenido_fuentes = file_get_contents($archivo_controlador);
    if ($contenido_fuentes === false) {
        return "No se pudo leer el controlador fuente '$ruta_controlador'.";
    }
    if (!str_contains($contenido_fuentes, '$response->throw();')) {
        return "El controlador fuente '$ruta_controlador' no expone el manejo de respuesta API compatible para propagar 404.";
    }
    $contenidos_vistas = [];
    foreach (['index', 'create', 'edit', 'show'] as $vista) {
        $ruta_vista = frontend_ruta_fuente($raiz_fuente, "resources/views/{$perfil['fuente']}/$vista.blade.php");
        if ($ruta_vista === null) {
            return "No existe la vista fuente '{$perfil['fuente']}/$vista.blade.php'.";
        }
        $contenido_vista = file_get_contents($ruta_vista);
        if ($contenido_vista === false) {
            return "No se pudo leer la vista fuente '{$perfil['fuente']}/$vista.blade.php'.";
        }
        $contenidos_vistas[$vista] = $contenido_vista;
        $contenido_fuentes .= $contenidos_vistas[$vista];
    }
    foreach (['resources/views/layouts/app.blade.php', 'resources/views/home.blade.php'] as $ruta_global) {
        $archivo_global = frontend_ruta_fuente($raiz_fuente, $ruta_global);
        $contenido_global = $archivo_global === null ? false : file_get_contents($archivo_global);
        if ($contenido_global === false) {
            return "No se pudo leer el archivo fuente '$ruta_global'.";
        }
        $contenido_fuentes .= $contenido_global;
    }
    foreach ($perfil['textos'] as $texto_fuente => $_) {
        if (!str_contains($contenido_fuentes, $texto_fuente)) {
            return "El texto literal configurado '$texto_fuente' no aparece en los archivos fuente.";
        }
    }
    foreach ($perfil['relaciones'] as $campo => $relacion) {
        $relacion_fuente = $perfil_fuente['relaciones'][$perfil['campos'][$campo]['fuente']] ?? null;
        if (!is_array($relacion_fuente)
            || !str_contains($contenido_fuentes, $relacion_fuente['etiqueta'])
            || !str_contains($contenido_fuentes, "['{$relacion_fuente['campo_etiqueta']}']")
            || !str_contains($contenido_fuentes, "'/{$relacion_fuente['api_url']}'")) {
            return "La etiqueta, campo visible o endpoint fuente de la relación '$campo' no aparece en el original.";
        }
    }
    foreach ($perfil['campos'] as $nombre => $mapeo) {
        $campo_fuente = $mapeo['fuente'];
        $etiqueta_fuente = $perfil_fuente['campos'][$campo_fuente]['etiqueta'] ?? null;
        if (!is_string($etiqueta_fuente) || $etiqueta_fuente === '') {
            return "El manifiesto fuente no declara etiqueta literal para '$campo_fuente'.";
        }
        foreach ($contenidos_vistas as $vista => $contenido_vista) {
            $referencias = [
                "['$campo_fuente']",
                "name=\"$campo_fuente\"",
                "id=\"$campo_fuente\"",
                "old('$campo_fuente'",
                "@error('$campo_fuente')",
            ];
            $encontrado = false;
            foreach ($referencias as $referencia) {
                if (str_contains($contenido_vista, $referencia)) {
                    $encontrado = true;
                    break;
                }
            }
            if (!$encontrado) {
                return "El campo '$campo_fuente' no aparece en la vista fuente '$vista'.";
            }
        }
        if (!str_contains($contenido_fuentes, $etiqueta_fuente)) {
            return "La etiqueta fuente para '$nombre' no aparece en los archivos originales.";
        }
    }

    return null;
}

function frontend_pares_controlador(array $perfil)
{
    $pares = [
        'class ' . $perfil['clase_fuente'] . 'Controller' => 'class ' . $perfil['clase_controlador'],
        '$' . $perfil['variables_fuente']['lista'] => '$' . $perfil['variable_lista'],
        '$' . $perfil['variables_fuente']['item'] => '$' . $perfil['variable_item'],
        "view('{$perfil['fuente']}." => "view('{$perfil['ruta']}.",
        "route('{$perfil['fuente']}." => "route('{$perfil['ruta']}.",
        "'/{$perfil['fuente_api']}" => "'/{$perfil['api_url']}",
        "\"/{$perfil['fuente_api']}" => "\"/{$perfil['api_url']}",
        '"/' . $perfil['fuente_api'] . '"' => '"/' . $perfil['api_url'] . '"',
        "\$response->throw();" => "if (\$response->notFound()) {\n            abort(404);\n        }\n        \$response->throw();",
    ];

    foreach ($perfil['campos'] as $nombre => $campo) {
        if ($campo['fuente'] !== $nombre) {
            $pares["'{$campo['fuente']}'"] = "'$nombre'";
            $pares['"' . $campo['fuente'] . '"'] = '"' . $nombre . '"';
        }
    }
    foreach ($perfil['relaciones'] as $relacion) {
        $pares['$' . $relacion['variables_fuente']['lista']] = '$' . $relacion['variable_lista'];
        $pares['$' . $relacion['variables_fuente']['item']] = '$' . $relacion['variable_item'];
        $pares["'{$relacion['variables_fuente']['lista']}'"] = "'{$relacion['variable_lista']}'";
        $pares["'{$relacion['fuente_api']}'"] = "'{$relacion['api_url']}'";
        $pares["/{$relacion['fuente_api']}"] = "/{$relacion['api_url']}";
    }
    foreach ($perfil['textos'] as $origen => $reemplazo) {
        $pares[$origen] = $reemplazo;
    }

    return $pares;
}

function frontend_pares_vista(array $perfil)
{
    $pares = [
        '$' . $perfil['variables_fuente']['lista'] => '$' . $perfil['variable_lista'],
        '$' . $perfil['variables_fuente']['item'] => '$' . $perfil['variable_item'],
        "route('{$perfil['fuente']}." => "route('{$perfil['ruta']}.",
    ];

    foreach ($perfil['campos'] as $nombre => $campo) {
        $fuente = $campo['fuente'];
        if ($fuente !== $nombre) {
            $pares["\${$perfil['variables_fuente']['item']}['$fuente']"] = "\${$perfil['variable_item']}['$nombre']";
            $pares["old('$fuente'"] = "old('$nombre'";
            $pares["@error('$fuente')"] = "@error('$nombre')";
            $pares['name="' . $fuente . '"'] = 'name="' . $nombre . '"';
            $pares['id="' . $fuente . '"'] = 'id="' . $nombre . '"';
            $pares['for="' . $fuente . '"'] = 'for="' . $nombre . '"';
        }
        if ($campo['etiqueta_fuente'] !== $campo['etiqueta']) {
            $pares[$campo['etiqueta_fuente']] = $campo['etiqueta'];
        }
    }

    foreach ($perfil['relaciones'] as $relacion) {
        $pares['$' . $relacion['variables_fuente']['lista']] = '$' . $relacion['variable_lista'];
        $pares['$' . $relacion['variables_fuente']['item']] = '$' . $relacion['variable_item'];
        $pares["\${$relacion['variables_fuente']['item']}['{$relacion['campo_etiqueta_fuente']}']"]
            = "\${$relacion['variable_item']}['{$relacion['campo_etiqueta']}']";
        if ($relacion['etiqueta_fuente'] !== $relacion['etiqueta']) {
            $pares[$relacion['etiqueta_fuente']] = $relacion['etiqueta'];
        }
    }
    foreach ($perfil['textos'] as $origen => $reemplazo) {
        $pares[$origen] = $reemplazo;
    }

    return $pares;
}

function frontend_adaptar_perfil(array $perfil, array $perfil_fuente)
{
    $perfil['variables_fuente'] = [
        'lista' => $perfil_fuente['variable_lista'],
        'item' => $perfil_fuente['variable_item'],
    ];
    $perfil['fuente_api'] = $perfil_fuente['api_url'];
    foreach ($perfil['campos'] as $nombre => &$campo) {
        $campo['etiqueta_fuente'] = $perfil_fuente['campos'][$campo['fuente']]['etiqueta'];
    }
    unset($campo);
    foreach ($perfil['relaciones'] as &$relacion) {
        $relacion_fuente = null;
        foreach ($perfil_fuente['relaciones'] as $fuente) {
            if (($fuente['fuente'] ?? null) === $relacion['fuente']) {
                $relacion_fuente = $fuente;
                break;
            }
        }
        $relacion['variables_fuente'] = [
            'lista' => $relacion_fuente['variable_lista'],
            'item' => $relacion_fuente['variable_item'],
        ];
        $relacion['fuente_api'] = $relacion_fuente['api_url'];
        $relacion['campo_etiqueta_fuente'] = $relacion_fuente['campo_etiqueta'];
        $relacion['etiqueta_fuente'] = $relacion_fuente['etiqueta'];
    }
    unset($relacion);

    return $perfil;
}

function frontend_adaptar_layout($contenido, array $perfiles)
{
    preg_match_all('/^[ \t]*<a href="\{\{ route\(\'([^\']+)\.index\'\) \}\}".*?<\/a>$/m', $contenido, $anclas);
    if (empty($anclas[0])) {
        return ['contenido' => null, 'error' => 'No se encontraron anclas de navegación de recursos en el layout fuente.'];
    }

    $anclas_fuente = [];
    foreach ($anclas[1] as $indice => $recurso) {
        $anclas_fuente[$recurso] = $anclas[0][$indice];
    }
    $perfiles = frontend_perfiles_ordenados($perfiles, $contenido, 'nav');
    $nuevas_anclas = [];
    foreach ($perfiles as $perfil) {
        if (!isset($anclas_fuente[$perfil['fuente']])) {
            return ['contenido' => null, 'error' => "No existe ancla fuente para '{$perfil['fuente']}'."];
        }
        $ancla = $anclas_fuente[$perfil['fuente']];
        if ($perfil['nav_etiqueta_fuente'] !== $perfil['nav_etiqueta']
            && !str_contains($anclas_fuente[$perfil['fuente']], '>' . $perfil['nav_etiqueta_fuente'] . '</a>')) {
            return ['contenido' => null, 'error' => "La etiqueta de navegación fuente de '{$perfil['fuente']}' no coincide con su manifiesto."];
        }
        $ancla = str_replace(
            [
                "route('{$perfil['fuente']}.index')",
                "routeIs('{$perfil['fuente']}.*')",
                '>' . $perfil['nav_etiqueta_fuente'] . '</a>',
            ],
            [
                "route('{$perfil['ruta']}.index')",
                "routeIs('{$perfil['ruta']}.*')",
                '>' . $perfil['nav_etiqueta'] . '</a>',
            ],
            $ancla
        );
        $nuevas_anclas[] = $ancla;
    }

    $inicio = strpos($contenido, $anclas[0][0]);
    $fin = strrpos($contenido, end($anclas[0]));
    if ($inicio === false || $fin === false) {
        return ['contenido' => null, 'error' => 'No se pudo delimitar la navegación fuente.'];
    }
    $fin += strlen(end($anclas[0]));
    $bloque_fuente = substr($contenido, $inicio, $fin - $inicio);
    $bloque_nuevo = implode("\n", $nuevas_anclas);
    $adaptacion = frontend_aplicar_adaptaciones($contenido, [$bloque_fuente => $bloque_nuevo]);
    return ['contenido' => $adaptacion['contenido'], 'error' => $adaptacion['error']];
}

function frontend_adaptar_home($contenido, array $perfiles, array $config)
{
    preg_match_all('/<article class="home-card">.*?<\/article>/s', $contenido, $articulos, PREG_OFFSET_CAPTURE);
    if (empty($articulos[0])) {
        return ['contenido' => null, 'error' => 'No se encontraron tarjetas home-card en la Home fuente.'];
    }

    $tarjetas_fuente = [];
    foreach ($articulos[0] as [$articulo]) {
        if (preg_match('/route\(\'([^\']+)\.index\'\)/', $articulo, $recurso)
            && preg_match('/<h2>(.*?)<\/h2>/s', $articulo, $titulo)
            && preg_match('/<p>(.*?)<\/p>/s', $articulo, $descripcion)
            && preg_match('/<a\b[^>]*>(.*?)<\/a>/s', $articulo, $enlace)) {
            $tarjetas_fuente[$recurso[1]] = [
                'contenido' => $articulo,
                'titulo' => $titulo[1],
                'descripcion' => $descripcion[1],
                'enlace' => $enlace[1],
            ];
        }
    }

    $perfiles = frontend_perfiles_ordenados($perfiles, $contenido, 'home');
    $tarjetas_nuevas = [];
    foreach ($perfiles as $perfil) {
        if (!isset($tarjetas_fuente[$perfil['fuente']])) {
            return ['contenido' => null, 'error' => "No existe tarjeta Home fuente para '{$perfil['fuente']}'."];
        }
        $tarjeta = $tarjetas_fuente[$perfil['fuente']]['contenido'];
        $tarjeta = str_replace(
            [
                "route('{$perfil['fuente']}.index')",
                '>' . $tarjetas_fuente[$perfil['fuente']]['titulo'] . '</h2>',
                '>' . $tarjetas_fuente[$perfil['fuente']]['descripcion'] . '</p>',
                '>' . $tarjetas_fuente[$perfil['fuente']]['enlace'] . '</a>',
            ],
            [
                "route('{$perfil['ruta']}.index')",
                '>' . $perfil['home']['titulo'] . '</h2>',
                '>' . $perfil['home']['descripcion'] . '</p>',
                '>' . $perfil['home']['enlace'] . '</a>',
            ],
            $tarjeta
        );
        $tarjetas_nuevas[] = $tarjeta;
    }

    $primera = $articulos[0][0][1];
    [$ultima_texto, $ultima_posicion] = end($articulos[0]);
    $bloque_fin = $ultima_posicion + strlen($ultima_texto);
    $bloque_fuente = substr($contenido, $primera, $bloque_fin - $primera);
    $separador = "\n";
    if (count($articulos[0]) > 1) {
        [$primera_tarjeta, $primera_posicion] = $articulos[0][0];
        [$segunda_tarjeta, $segunda_posicion] = $articulos[0][1];
        $separador = substr(
            $contenido,
            $primera_posicion + strlen($primera_tarjeta),
            $segunda_posicion - ($primera_posicion + strlen($primera_tarjeta))
        );
    }
    $bloque_nuevo = implode($separador, $tarjetas_nuevas);
    $adaptacion = frontend_aplicar_adaptaciones($contenido, [$bloque_fuente => $bloque_nuevo]);
    if ($adaptacion['error'] !== null) {
        return ['contenido' => null, 'error' => $adaptacion['error']];
    }

    $parrafo_fuente = $config['frontend_home_parrafo_fuente'] ?? null;
    $parrafo_destino = $config['frontend_home_parrafo'] ?? null;
    if (!is_string($parrafo_fuente) || !is_string($parrafo_destino) || $parrafo_fuente === '') {
        return ['contenido' => null, 'error' => 'El párrafo de Home necesita un mapeo fuente y destino explícito.'];
    }
    if (!str_contains($adaptacion['contenido'], '>' . $parrafo_fuente . '</p>')) {
        return ['contenido' => null, 'error' => 'El párrafo configurado no coincide exactamente con el texto de Home fuente.'];
    }
    $adaptacion_parrafo = frontend_aplicar_adaptaciones($adaptacion['contenido'], [
        '>' . $parrafo_fuente . '</p>' => '>' . $parrafo_destino . '</p>',
    ]);

    return ['contenido' => $adaptacion_parrafo['contenido'], 'error' => $adaptacion_parrafo['error']];
}

function frontend_adaptar_rutas($contenido, array $perfiles)
{
    $perfiles = frontend_perfiles_ordenados($perfiles, $contenido, 'rutas');
    $pares = [];
    preg_match_all('/^[ \t]*use App\\\\Http\\\\Controllers\\\\([A-Za-z_][A-Za-z0-9_]*)Controller;[ \t]*$/m', $contenido, $imports, PREG_SET_ORDER);
    foreach ($imports as $import) {
        $clase_fuente = $import[1];
        $linea_fuente = $import[0];
        $lineas = [];
        foreach ($perfiles as $perfil) {
            if ($perfil['clase_fuente'] === $clase_fuente) {
                $lineas[] = 'use App\\Http\\Controllers\\' . $perfil['clase_controlador'] . ';';
            }
        }
        $pares[$linea_fuente] = implode("\n", array_unique($lineas));
    }

    preg_match_all('/^[ \t]*Route::resource\(\'([^\']+)\', ([A-Za-z_][A-Za-z0-9_]*)Controller::class\);[ \t]*$/m', $contenido, $rutas, PREG_SET_ORDER);
    $conteo_rutas = [];
    foreach ($rutas as $ruta_fuente) {
        $linea_fuente = $ruta_fuente[0];
        $recurso_fuente = $ruta_fuente[1];
        $clase_fuente = $ruta_fuente[2];
        $lineas = [];
        foreach ($perfiles as $perfil) {
            if ($perfil['fuente'] !== $recurso_fuente || $perfil['clase_fuente'] !== $clase_fuente) {
                continue;
            }
            $lineas[] = str_replace(
                ["'$recurso_fuente'", $clase_fuente . 'Controller::class'],
                ["'{$perfil['ruta']}'", $perfil['clase_controlador'] . '::class'],
                $linea_fuente
            );
            $conteo_rutas[$perfil['ruta']] = ($conteo_rutas[$perfil['ruta']] ?? 0) + 1;
        }
        $pares[$linea_fuente] = implode("\n", $lineas);
    }

    foreach ($perfiles as $perfil) {
        if (($conteo_rutas[$perfil['ruta']] ?? 0) !== 1) {
            return ['contenido' => null, 'error' => "La ruta '{$perfil['ruta']}' no tiene una declaración fuente única."];
        }
    }

    $adaptacion = frontend_aplicar_adaptaciones($contenido, $pares);
    return ['contenido' => $adaptacion['contenido'], 'error' => $adaptacion['error']];
}

function frontend_adaptar_nombre_app($contenido, array $config)
{
    $fuente = $config['frontend_nombre_app_fuente'] ?? null;
    $destino = $config['frontend_nombre_app'] ?? null;
    if (!is_string($fuente) || $fuente === '' || !is_string($destino)) {
        return ['contenido' => null, 'error' => 'El nombre de la aplicación necesita un mapeo fuente y destino explícito.'];
    }
    if ($fuente !== $destino && !str_contains($contenido, $fuente)) {
        return ['contenido' => null, 'error' => 'El nombre configurado no coincide exactamente con la fuente.'];
    }
    $adaptacion = frontend_aplicar_adaptaciones($contenido, [$fuente => $destino]);
    return ['contenido' => $adaptacion['contenido'], 'error' => $adaptacion['error']];
}

function frontend_integrar_rutas_destino($fuente, $destino, $contenido_fuente_adaptado, array &$resumen)
{
    $inicio = '// <crud-generator-frontend-routes>';
    $fin = '// </crud-generator-frontend-routes>';
    $archivo = $destino . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'web.php';

    if (!file_exists($archivo)) {
        $adaptaciones = frontend_pares_bloque(file_get_contents($fuente), $contenido_fuente_adaptado);
        return frontend_archivo_fuente($fuente, $destino, 'routes/web.php', $adaptaciones, $resumen)['estado'];
    }

    $raiz_real = realpath($destino);
    $directorio_rutas = realpath(dirname($archivo));
    if (is_link($destino . DIRECTORY_SEPARATOR . 'routes')
        || $raiz_real === false
        || $directorio_rutas === false
        || !ruta_fuente_dentro_de_raiz($directorio_rutas, $raiz_real)) {
        $motivo = 'El directorio routes resuelve fuera de la raíz permitida; se conserva sin cambios.';
        $resumen['errores'][] = $archivo . ': ' . $motivo;
        return 'error';
    }

    if (is_link($archivo) || !is_file($archivo) || !is_readable($archivo)) {
        $motivo = 'El destino routes/web.php no es un archivo regular legible; se conserva sin cambios.';
        $resumen['errores'][] = $archivo . ': ' . $motivo;
        $resumen['archivos_fuente'][] = [
            'origen' => $fuente,
            'destino' => $archivo,
            'estado' => 'conflicto',
            'adaptaciones_aplicadas' => 0,
            'pendientes' => [],
            'motivo' => $motivo,
        ];
        return 'conflicto';
    }

    $actual = file_get_contents($archivo);
    if ($actual === false) {
        $resumen['errores'][] = $archivo . ': no se pudo leer el destino.';
        return 'error';
    }
    if ($actual === $contenido_fuente_adaptado) {
        $resumen['archivos_fuente'][] = [
            'origen' => $fuente,
            'destino' => $archivo,
            'estado' => 'sin_cambios',
            'adaptaciones_aplicadas' => 0,
            'pendientes' => [],
            'motivo' => 'El destino ya contiene exactamente las rutas adaptadas desde la fuente.',
        ];
        return 'sin_cambios';
    }

    $bloque_patron = '/' . preg_quote($inicio, '/') . '.*?' . preg_quote($fin, '/') . '/s';
    $tiene_inicio = str_contains($actual, $inicio);
    $tiene_fin = str_contains($actual, $fin);
    if ($tiene_inicio !== $tiene_fin) {
        $motivo = 'El archivo contiene un marcador de rutas frontend incompleto; requiere revisión manual.';
        $resumen['errores'][] = $archivo . ': ' . $motivo;
        return 'conflicto';
    }
    $manual = $tiene_inicio
        ? preg_replace($bloque_patron, '', $actual, 1)
        : $actual;
    if ($manual === null) {
        $resumen['errores'][] = $archivo . ': no se pudo analizar el bloque de rutas administrado.';
        return 'error';
    }

    $ruta_bienvenida_laravel = '/^[ \t]*Route::get\([\'"]\/[\'"],[ \t]*function[ \t]*\([ \t]*\)[ \t]*\{[ \t]*\r?\n[ \t]+return[ \t]+view\([\'"]welcome[\'"]\);[ \t]*\r?\n[ \t]*\}\);[ \t]*$/m';
    $coincidencias_bienvenida = preg_match_all($ruta_bienvenida_laravel, $manual);
    if ($coincidencias_bienvenida === 1) {
        $manual_sin_bienvenida = preg_replace($ruta_bienvenida_laravel, '', $manual, 1, $reemplazos_manual);
        $actual_sin_bienvenida = preg_replace($ruta_bienvenida_laravel, '', $actual, 1, $reemplazos_actual);
        if (!is_string($manual_sin_bienvenida) || !is_string($actual_sin_bienvenida)
            || $reemplazos_manual !== 1 || $reemplazos_actual !== 1) {
            $resumen['errores'][] = $archivo . ': no se pudo retirar de forma segura la ruta welcome predeterminada de Laravel.';
            return 'error';
        }
        $manual = $manual_sin_bienvenida;
        $actual = $actual_sin_bienvenida;
    }

    preg_match_all('/^[ \t]*Route::resource\(\'([^\']+)\', ([A-Za-z_][A-Za-z0-9_]*)::class\);[ \t]*$/m', $contenido_fuente_adaptado, $rutas, PREG_SET_ORDER);
    $agregar = [];
    foreach ($rutas as $ruta) {
        $recurso = $ruta[1];
        $clase = $ruta[2];
        preg_match_all(
            '/(?:Route|\\\\Illuminate\\\\Support\\\\Facades\\\\Route)::resource\([\'"]' . preg_quote($recurso, '/') . '[\'"],\s*([^)]*?)::class\)/',
            $manual,
            $existentes
        );
        if (count($existentes[1]) > 1) {
            $resumen['errores'][] = "$archivo: la ruta '$recurso' ya aparece más de una vez; no se añade otra.";
            continue;
        }
        if (count($existentes[1]) === 1) {
            $clase_existente = trim($existentes[1][0], " \t\\");
            if ($clase_existente !== $clase) {
                $resumen['errores'][] = "$archivo: la ruta '$recurso' ya apunta a '{$existentes[1][0]}', no a '$clase'.";
            }
            continue;
        }
        $agregar[] = '\\Illuminate\\Support\\Facades\\Route::resource('
            . var_export($recurso, true)
            . ', \\App\\Http\\Controllers\\' . $clase . '::class);';
    }

    if (preg_match('/Route::view\([\'"]\/[\'"],\s*[\'"]home[\'"]\)->name\([\'"]home[\'"]\)/', $contenido_fuente_adaptado, $home_fuente)) {
        if (preg_match_all('/(?:Route|\\\\Illuminate\\\\Support\\\\Facades\\\\Route)::(?:get|view|match|any)\([\'"]\/[\'"]/', $manual, $home_existente)) {
            if (count($home_existente[0]) > 1 || !str_contains($manual, $home_fuente[0])) {
                $resumen['errores'][] = "$archivo: ya existe una ruta raíz manual distinta de la Home fuente; no se reemplaza.";
            }
        } else {
            $agregar[] = '\\Illuminate\\Support\\Facades\\Route::view(\'/\', \'home\')->name(\'home\');';
        }
    }

    if ($resumen['errores'] !== []) {
        $resumen['archivos_fuente'][] = [
            'origen' => $fuente,
            'destino' => $archivo,
            'estado' => 'conflicto',
            'adaptaciones_aplicadas' => 0,
            'pendientes' => [],
            'motivo' => 'Se preservó routes/web.php; existen rutas manuales que requieren resolución explícita.',
        ];
        return 'conflicto';
    }

    $bloque_nuevo = $agregar === []
        ? ''
        : $inicio . "\n" . implode("\n", $agregar) . "\n" . $fin;
    if ($tiene_inicio) {
        $nuevo = preg_replace($bloque_patron, $bloque_nuevo, $actual, 1);
    } elseif ($bloque_nuevo === '') {
        $nuevo = $actual;
    } else {
        $nuevo = rtrim($actual) . "\n\n" . $bloque_nuevo . "\n";
    }
    if (!is_string($nuevo)) {
        $resumen['errores'][] = $archivo . ': no se pudo preparar la actualización segura de rutas.';
        return 'error';
    }
    if ($nuevo === $actual) {
        $estado = 'sin_cambios';
    } elseif (!guardar_archivo_atomico($archivo, $nuevo)) {
        $resumen['errores'][] = $archivo . ': no se pudieron integrar las rutas sin sobrescribir el contenido original.';
        $estado = 'error';
    } else {
        $estado = 'integrado';
    }

    $resumen['archivos_fuente'][] = [
        'origen' => $fuente,
        'destino' => $archivo,
        'estado' => $estado,
        'adaptaciones_aplicadas' => count($agregar),
        'pendientes' => [],
        'motivo' => 'Las rutas fuente se integraron en un bloque administrado; el resto del archivo manual se conserva.',
    ];

    return $estado;
}

function frontend_configurar_api_env($ruta_frontend, array $config, array &$resumen)
{
    $env = $ruta_frontend . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($env) || is_link($env) || !is_readable($env)) {
        frontend_agregar_pendiente($resumen, null, 'No se encontró un .env legible; configure API_BASE_URL explícitamente antes de ejecutar el Frontend.');
        return false;
    }

    $base = $config['frontend_api_base_url'] ?? null;
    if (!is_string($base)
        || filter_var($base, FILTER_VALIDATE_URL) === false
        || !in_array(strtolower((string) parse_url($base, PHP_URL_SCHEME)), ['http', 'https'], true)
        || str_ends_with($base, '/')) {
        frontend_agregar_pendiente($resumen, null, 'frontend_api_base_url debe ser una URL HTTP/HTTPS explícita y no terminar en "/".');
        return false;
    }

    $contenido = file_get_contents($env);
    if ($contenido === false) {
        $resumen['errores'][] = 'No se pudo leer Frontend/.env.';
        return false;
    }
    preg_match_all('/^API_BASE_URL=(.*)$/m', $contenido, $coincidencias);
    if (count($coincidencias[0]) > 1) {
        frontend_agregar_pendiente($resumen, null, 'Frontend/.env contiene varias definiciones de API_BASE_URL.');
        return false;
    }
    if (!empty($coincidencias[0])) {
        $valor_actual = trim($coincidencias[1][0], " \t\n\r\0\x0B\"'");
        if ($valor_actual !== '') {
            $url_valida = filter_var($valor_actual, FILTER_VALIDATE_URL) !== false
                && in_array(strtolower((string) parse_url($valor_actual, PHP_URL_SCHEME)), ['http', 'https'], true)
                && !str_ends_with($valor_actual, '/');
            if (!$url_valida) {
                frontend_agregar_pendiente($resumen, null, 'Frontend/.env contiene un API_BASE_URL inválido o terminado en "/"; se conserva sin sobrescribir.');
            }
            return false;
        }
        $nuevo = preg_replace('/^API_BASE_URL=.*$/m', 'API_BASE_URL=' . $base, $contenido, 1);
    } else {
        $separador = $contenido === '' || str_ends_with($contenido, "\n") || str_ends_with($contenido, "\r")
            ? ''
            : (str_contains($contenido, "\r\n") ? "\r\n" : "\n");
        $nuevo = $contenido . $separador . 'API_BASE_URL=' . $base . (str_contains($contenido, "\r\n") ? "\r\n" : "\n");
    }

    if (!is_string($nuevo)) {
        $resumen['errores'][] = 'No se pudo preparar API_BASE_URL sin reemplazar otros valores del .env.';
        return false;
    }
    if ($nuevo === $contenido) {
        return false;
    }
    if (!guardar_archivo_atomico($env, $nuevo)) {
        $resumen['errores'][] = 'No se pudo agregar API_BASE_URL a Frontend/.env.';
        return false;
    }

    return true;
}

function generar_frontend_desde_fuentes($ruta_frontend, $ruta_backend, $config)
{
    $resumen = [
        'controladores' => [],
        'vistas' => [],
        'archivos_fuente' => [],
        'layout' => null,
        'css' => null,
        'rutas_web' => null,
        'env_actualizado' => false,
        'omitidas' => [],
        'advertencias' => [],
        'adaptaciones_pendientes' => [],
        'errores' => [],
    ];

    $analisis = analizar_migraciones($ruta_backend, $config);
    $resumen['advertencias'] = $analisis['advertencias'] ?? [];
    $guardia = guardia_generacion_migraciones($analisis);
    if ($guardia['bloqueada']) {
        $resumen['errores'][] = $guardia['mensaje'];
        return $resumen;
    }
    if (empty($analisis['tablas'])) {
        $resumen['errores'][] = 'No se detectaron tablas en las migraciones de Backend/database/migrations.';
        return $resumen;
    }

    $raiz_proyecto = realpath(dirname(CRUD_DIR, 2));
    $fuente_configurada = $config['fuente_frontend'] ?? 'pracExaLav/frontend';
    $raiz_fuente = realpath($raiz_proyecto . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fuente_configurada));
    $raiz_destino = realpath($ruta_frontend);
    if ($raiz_proyecto === false || $raiz_fuente === false || !is_dir($raiz_fuente)) {
        $resumen['errores'][] = "No se encontró la fuente frontend configurada '$fuente_configurada'.";
        return $resumen;
    }
    if ($raiz_destino === false || !is_dir($raiz_destino)) {
        $resumen['errores'][] = 'El directorio Frontend de destino no existe.';
        return $resumen;
    }
    if (frontend_rutas_iguales($raiz_fuente, $raiz_destino)) {
        $resumen['errores'][] = 'El destino coincide con pracExaLav/frontend; por seguridad no se modifica la referencia.';
        return $resumen;
    }

    $perfiles_config = $config['adaptaciones_frontend'] ?? [];
    if (!is_array($perfiles_config)) {
        $resumen['errores'][] = 'adaptaciones_frontend debe ser un mapa por tabla.';
        return $resumen;
    }

    $perfiles = [];
    foreach ($analisis['tablas'] as $tabla => $tabla_info) {
        if (!es_tabla_elegible_front($tabla, $tabla_info, $config)) {
            $resumen['omitidas'][] = "$tabla (tabla excluida, asociativa o nombre no válido)";
            continue;
        }

        $perfil = $perfiles_config[$tabla] ?? null;
        if (!is_array($perfil)) {
            frontend_agregar_pendiente($resumen, $tabla, 'No hay mapeo explícito de entidad, etiquetas, campos, relaciones, variables, URLs y textos.');
            continue;
        }
        $error_perfil = frontend_validar_perfil($tabla, $tabla_info, $perfil, $perfiles_config, $raiz_fuente);
        if ($error_perfil !== null) {
            frontend_agregar_pendiente($resumen, $tabla, $error_perfil);
            continue;
        }
        $perfil_fuente = $perfiles_config[$perfil['fuente']];
        $perfil = frontend_adaptar_perfil($perfil, $perfil_fuente);
        foreach ($perfil['campos'] as $nombre => &$campo) {
            if (!isset($campo['imagen'])) {
                $campo['imagen'] = false;
            }
        }
        unset($campo);
        $perfil['tabla'] = $tabla;
        $perfil['fuente_recurso'] = $perfil['fuente'];
        $perfil['clase_fuente'] = $perfil_fuente['clase_fuente'];
        $perfil['fuente'] = $perfil_fuente['fuente'];
        $perfil['nav_etiqueta_fuente'] = $perfil_fuente['nav_etiqueta'];
        $perfil['fuente_api'] = $perfil_fuente['api_url'];
        $perfiles[] = $perfil;
    }

    if ($perfiles === []) {
        if ($resumen['adaptaciones_pendientes'] === []) {
            $resumen['errores'][] = 'No hay entidades Frontend con un mapeo fuente seguro.';
        }
        return $resumen;
    }

    $rutas_unicas = [];
    $rutas_duplicadas = false;
    foreach ($perfiles as $perfil) {
        if (isset($rutas_unicas[$perfil['ruta']])) {
            frontend_agregar_pendiente($resumen, $perfil['tabla'], "La ruta '{$perfil['ruta']}' ya fue asignada a otra entidad.");
            $rutas_duplicadas = true;
        }
        $rutas_unicas[$perfil['ruta']] = true;
    }
    if ($rutas_duplicadas) {
        return $resumen;
    }

    $fuente_rutas = frontend_ruta_fuente($raiz_fuente, 'routes/web.php');
    $fuente_layout = frontend_ruta_fuente($raiz_fuente, 'resources/views/layouts/app.blade.php');
    $fuente_home = frontend_ruta_fuente($raiz_fuente, 'resources/views/home.blade.php');
    if ($fuente_rutas === null || $fuente_layout === null || $fuente_home === null) {
        $resumen['errores'][] = 'La fuente frontend no contiene routes/web.php, layout principal y Home legibles.';
        return $resumen;
    }

    $contenido_rutas = file_get_contents($fuente_rutas);
    $contenido_layout = file_get_contents($fuente_layout);
    $contenido_home = file_get_contents($fuente_home);
    $rutas_adaptadas = frontend_adaptar_rutas($contenido_rutas, $perfiles);
    $layout_adaptado = frontend_adaptar_layout($contenido_layout, $perfiles);
    $home_adaptado = frontend_adaptar_home($contenido_home, $perfiles, $config);
    if ($layout_adaptado['error'] === null) {
        $layout_adaptado = frontend_adaptar_nombre_app($layout_adaptado['contenido'], $config);
    }
    if ($home_adaptado['error'] === null) {
        $home_adaptado = frontend_adaptar_nombre_app($home_adaptado['contenido'], $config);
    }
    foreach ([
        'rutas_web' => $rutas_adaptadas,
        'layout' => $layout_adaptado,
        'home' => $home_adaptado,
    ] as $nombre => $adaptacion) {
        if ($adaptacion['error'] !== null) {
            $resumen['errores'][] = "No se pudo adaptar $nombre desde la fuente: {$adaptacion['error']}";
        }
    }
    if ($resumen['errores'] !== []) {
        return $resumen;
    }

    carpetas_permitidas([
        $ruta_frontend . '/app/Http/Controllers',
        $ruta_frontend . '/resources/views',
        $ruta_frontend . '/resources/css',
        $ruta_frontend . '/resources/js',
        $ruta_frontend . '/routes',
    ]);

    foreach ($perfiles as $perfil) {
        $campos_validos = [];
        foreach ($perfil['campos'] as $nombre => $campo) {
            $campos_validos[$nombre] = [
                'fuente' => $campo['fuente'],
                'etiqueta' => $campo['etiqueta'],
                'etiqueta_fuente' => $campo['etiqueta_fuente'],
                'tipo' => $campo['tipo'],
                'control' => $campo['control'],
                'imagen' => $campo['imagen'] ?? false,
                'relacion' => $campo['relacion'] ?? null,
            ];
        }
        $perfil['campos'] = $campos_validos;

        $ruta_controlador_fuente = "app/Http/Controllers/{$perfil['clase_fuente']}Controller.php";
        $nombre_controlador_destino = "app/Http/Controllers/{$perfil['clase_controlador']}.php";
        frontend_archivo_fuente(
            $raiz_fuente . DIRECTORY_SEPARATOR . $ruta_controlador_fuente,
            $ruta_frontend,
            $nombre_controlador_destino,
            frontend_pares_controlador($perfil),
            $resumen
        );
        $resumen['controladores'][] = [
            'clase' => $perfil['clase_controlador'],
            'archivo' => $nombre_controlador_destino,
            'estado' => end($resumen['archivos_fuente'])['estado'],
        ];

        foreach (['index', 'create', 'edit', 'show'] as $nombre_vista) {
            $ruta_vista_fuente = "resources/views/{$perfil['fuente']}/$nombre_vista.blade.php";
            $ruta_vista_destino = "resources/views/{$perfil['ruta']}/$nombre_vista.blade.php";
            frontend_archivo_fuente(
                $raiz_fuente . DIRECTORY_SEPARATOR . $ruta_vista_fuente,
                $ruta_frontend,
                $ruta_vista_destino,
                frontend_pares_vista($perfil),
                $resumen
            );
            $resumen['vistas'][] = [
                'vista' => "{$perfil['ruta']}.$nombre_vista",
                'archivo' => $ruta_vista_destino,
                'estado' => end($resumen['archivos_fuente'])['estado'],
            ];
        }
    }

    $layout_archivo = frontend_archivo_fuente(
        $fuente_layout,
        $ruta_frontend,
        'resources/views/layouts/app.blade.php',
        frontend_pares_bloque($contenido_layout, $layout_adaptado['contenido']),
        $resumen
    );
    $resumen['layout'] = $layout_archivo['estado'];
    frontend_archivo_fuente(
        $fuente_home,
        $ruta_frontend,
        'resources/views/home.blade.php',
        frontend_pares_bloque($contenido_home, $home_adaptado['contenido']),
        $resumen
    );

    $resumen['rutas_web'] = frontend_integrar_rutas_destino(
        $fuente_rutas,
        $ruta_frontend,
        $rutas_adaptadas['contenido'],
        $resumen
    );

    foreach ([
        'resources/css/app.css' => 'css',
        'resources/js/app.js' => 'javascript',
        'public/favicon.ico' => 'asset',
    ] as $archivo => $tipo) {
        $ruta_fuente = frontend_ruta_fuente($raiz_fuente, $archivo);
        if ($ruta_fuente === null) {
            frontend_agregar_pendiente($resumen, null, "No existe el asset fuente '$archivo'.");
            continue;
        }
        $resultado = frontend_archivo_fuente($ruta_fuente, $ruta_frontend, $archivo, [], $resumen);
        if ($tipo === 'css') {
            $resumen['css'] = $resultado['estado'];
        }
    }

    $resumen['env_actualizado'] = frontend_configurar_api_env($ruta_frontend, $config, $resumen);

    return $resumen;
}

function frontend_pares_bloque($original, $adaptado)
{
    if (!is_string($original) || !is_string($adaptado) || $original === $adaptado) {
        return [];
    }

    return [$original => $adaptado];
}
