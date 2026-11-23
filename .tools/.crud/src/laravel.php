<?php
// Detección e inspección de proyectos Laravel (solo lectura).

// Normaliza cadenas de versión numéricas simples para comparaciones (ej: '8.2' -> '8.2.0')
function _normalizar_version_num($v)
{
    $partes = explode('.', ltrim($v, 'v'));
    while (count($partes) < 3) {
        $partes[] = '0';
    }
    return implode('.', array_slice($partes, 0, 3));
}

// Evalúa una única condición atómica de Composer frente a la versión instalada.
// Devuelve:
//   true  => compatible
//   false => incompatible
//   null  => operador o formato no reconocido (indeterminado)
function _evaluar_condicion_atomica_php($version_instalada, $condicion)
{
    $condicion = trim($condicion);
    if ($condicion === '' || $condicion === '*') {
        return true;
    }

    // Caret ^:
    // Regla de Composer SemVer:
    // Permite actualizaciones que no modifiquen el primer dígito no-cero (mayor, menor o parche).
    // Si la versión dada no especifica parche (ej. ^0.0 o ^0.1 o ^1.2):
    // - ^0.0   => >=0.0.0 <0.1.0  (el primer no-cero potencial o posición final no especificada se incrementa al siguiente nivel)
    // - ^0.1   => >=0.1.0 <0.2.0
    // - ^0.3.0 => >=0.3.0 <0.4.0
    // - ^0.0.3 => >=0.0.3 <0.0.4
    // - ^1.2   => >=1.2.0 <2.0.0
    // - ^8.2   => >=8.2.0 <9.0.0
    if (preg_match('/^\^([0-9]+(?:\.[0-9]+)*(?:-[a-zA-Z0-9.]+)?)$/', $condicion, $m)) {
        $raw = $m[1];
        $partes = explode('.', $raw);
        $min = _normalizar_version_num($raw);
        $num_partes = count($partes);

        $p0 = (int)($partes[0] ?? 0);
        $p1 = (int)($partes[1] ?? 0);
        $p2 = (int)($partes[2] ?? 0);

        if ($p0 !== 0) {
            $max = ($p0 + 1) . '.0.0';
        } elseif ($p1 !== 0) {
            $max = '0.' . ($p1 + 1) . '.0';
        } else {
            // $p0 === 0 && $p1 === 0
            if ($num_partes === 1) {
                // ^0 => <1.0.0
                $max = '1.0.0';
            } elseif ($num_partes === 2) {
                // ^0.0 => <0.1.0
                $max = '0.1.0';
            } else {
                // ^0.0.3 => <0.0.4
                $max = '0.0.' . ($p2 + 1);
            }
        }

        return (version_compare($version_instalada, $min, '>=') && version_compare($version_instalada, $max, '<'));
    }

    // Tilde ~: permite el último dígito especificado incrementarse
    // ~8.2    => >=8.2.0 <9.0.0 (equivalente a ~8.2.*)
    // ~8.2.0  => >=8.2.0 <8.3.0
    // ~0.3    => >=0.3.0 <1.0.0 (en composer ~0.3 especifica 2 dígitos, el último '3' se incrementa a nivel mayor: <1.0.0)
    // ~0.3.0  => >=0.3.0 <0.4.0
    if (preg_match('/^~([0-9]+(?:\.[0-9]+)*(?:-[a-zA-Z0-9.]+)?)$/', $condicion, $m)) {
        $raw = $m[1];
        $partes = explode('.', $raw);
        $min = _normalizar_version_num($raw);
        if (count($partes) >= 3) {
            $max = ((int)$partes[0]) . '.' . (((int)$partes[1]) + 1) . '.0';
        } else {
            $max = (((int)$partes[0]) + 1) . '.0.0';
        }

        return (version_compare($version_instalada, $min, '>=') && version_compare($version_instalada, $max, '<'));
    }

    // Comodín con punto y asterisco: 8.2.* => >=8.2.0 <8.3.0, 8.* => >=8.0.0 <9.0.0
    if (preg_match('/^([0-9]+(?:\.[0-9]+)*)\.\*$/', $condicion, $m)) {
        $raw = $m[1];
        $partes = explode('.', $raw);
        $min = _normalizar_version_num($raw);
        if (count($partes) >= 2) {
            $max = ((int)$partes[0]) . '.' . (((int)$partes[1]) + 1) . '.0';
        } else {
            $max = (((int)$partes[0]) + 1) . '.0.0';
        }

        return (version_compare($version_instalada, $min, '>=') && version_compare($version_instalada, $max, '<'));
    }

    // Operadores de comparación: >=, <=, >, <, !=, ==, =
    if (preg_match('/^(>=|<=|>|<|!=|==|=)\s*([0-9]+(?:\.[0-9]+)*(?:-[a-zA-Z0-9.]+)?)$/', $condicion, $m)) {
        $op = $m[1] === '=' ? '==' : $m[1];
        $target = _normalizar_version_num($m[2]);
        return version_compare($version_instalada, $target, $op);
    }

    // Versión exacta literal: '8.2' o '8.2.0'
    if (preg_match('/^[0-9]+(?:\.[0-9]+)*(?:-[a-zA-Z0-9.]+)?$/', $condicion)) {
        return version_compare($version_instalada, _normalizar_version_num($condicion), '==');
    }

    // No se reconoció el operador o formato
    return null;
}

// Comprueba la compatibilidad entre una versión de PHP instalada y la restricción de Composer.
// Devuelve:
//   true  => Confirmado compatible
//   false => Confirmado incompatible
//   null  => Indeterminado (la restricción o parte de ella no pudo interpretarse con seguridad)
function version_php_compatible($version_instalada, $restriccion)
{
    $restriccion = trim($restriccion ?? '');
    if ($restriccion === '' || $restriccion === '*') {
        return true;
    }

    $version_instalada = _normalizar_version_num($version_instalada);

    // Separar alternativas por pipe | o || (unión)
    $alternativas = preg_split('/\|{1,2}/', $restriccion);
    $al_menos_una_valida = false;
    $hay_indeterminado = false;

    foreach ($alternativas as $alt) {
        $alt = trim($alt);
        if ($alt === '') continue;

        // Separar condiciones conjuntas (AND) por comas o espacios
        $tokens = preg_split('/[\s,]+/', $alt);
        $rama_valida = true;
        $rama_indeterminada = false;

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') continue;

            $res = _evaluar_condicion_atomica_php($version_instalada, $token);
            if ($res === null) {
                $rama_indeterminada = true;
                break;
            } elseif ($res === false) {
                $rama_valida = false;
                break;
            }
        }

        if ($rama_indeterminada) {
            $hay_indeterminado = true;
        } elseif ($rama_valida) {
            $al_menos_una_valida = true;
            break; // Satisfecho por OR
        }
    }

    if ($al_menos_una_valida) {
        return true;
    }

    if ($hay_indeterminado) {
        return null; // Indeterminado
    }

    return false;
}

// Analiza una carpeta dada para determinar si es un proyecto Laravel válido y extraer metadatos.
function inspeccionar_laravel($ruta_proyecto, $php_actual = PHP_VERSION)
{
    $resultado = [
        'valido' => false,
        'error' => null,
        'advertencias' => [],
        'ruta' => $ruta_proyecto,
        'laravel_version' => null,
        'laravel_major' => null,
        'laravel_origen' => null,
        'dependencias_instaladas' => false,
        'php_requerido' => null,
        'php_instalado' => $php_actual,
        'php_compatible' => null,
        'soporta_laravel' => false,
    ];

    if (!is_dir($ruta_proyecto)) {
        $resultado['error'] = 'La carpeta no existe: ' . $ruta_proyecto;
        return $resultado;
    }

    $composer_json_path = $ruta_proyecto . DIRECTORY_SEPARATOR . 'composer.json';
    $artisan_path = $ruta_proyecto . DIRECTORY_SEPARATOR . 'artisan';
    $app_dir = $ruta_proyecto . DIRECTORY_SEPARATOR . 'app';

    // Comprobaciones estructurales esenciales de Laravel
    if (!file_exists($composer_json_path)) {
        $resultado['error'] = 'No se encontró composer.json en la carpeta.';
        return $resultado;
    }

    if (!file_exists($artisan_path)) {
        $resultado['error'] = 'No se encontró el archivo ejecutable artisan.';
        return $resultado;
    }

    if (!is_dir($app_dir)) {
        $resultado['error'] = 'No se encontró el directorio estructural app/.';
        return $resultado;
    }

    $composer_raw = @file_get_contents($composer_json_path);
    $composer_data = json_decode($composer_raw, true);
    if (!is_array($composer_data)) {
        $resultado['error'] = 'composer.json no contiene un formato JSON válido.';
        return $resultado;
    }

    // Verificar que declare laravel/framework
    $require = $composer_data['require'] ?? [];
    $declara_laravel = isset($require['laravel/framework']);

    if (!$declara_laravel) {
        $resultado['error'] = 'composer.json no incluye la dependencia "laravel/framework".';
        return $resultado;
    }

    // Comprobar existencia real de dependencias instaladas en vendor/
    $vendor_laravel = $ruta_proyecto . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'laravel' . DIRECTORY_SEPARATOR . 'framework';
    $resultado['dependencias_instaladas'] = is_dir($vendor_laravel);

    // PHP requerido
    $php_req = $require['php'] ?? null;
    $resultado['php_requerido'] = $php_req;
    if (!array_key_exists('php', $require)) {
        $resultado['advertencias'][] = 'Compatibilidad PHP indeterminada: composer.json no declara una restricción de PHP.';
    } elseif (!is_string($php_req) || trim($php_req) === '') {
        $resultado['advertencias'][] = 'Compatibilidad PHP indeterminada: la restricción de PHP declarada en composer.json está vacía o no es una cadena.';
    } else {
        $res_compat = version_php_compatible($php_actual, $php_req);
        $resultado['php_compatible'] = $res_compat;
        if ($res_compat === false) {
            $resultado['advertencias'][] = "PHP instalado ($php_actual) no cumple el requisito declarado en composer.json ($php_req).";
        } elseif ($res_compat === null) {
            $resultado['advertencias'][] = "Compatibilidad PHP indeterminada: no se pudo interpretar la restricción declarada en composer.json ($php_req).";
        }
    }

    // 1. Intentar leer versión bloqueada en composer.lock
    $composer_lock_path = $ruta_proyecto . DIRECTORY_SEPARATOR . 'composer.lock';
    $version_bloqueada = null;

    if (file_exists($composer_lock_path)) {
        $lock_raw = @file_get_contents($composer_lock_path);
        $lock_data = json_decode($lock_raw, true);
        if (is_array($lock_data) && isset($lock_data['packages']) && is_array($lock_data['packages'])) {
            foreach ($lock_data['packages'] as $pkg) {
                if (($pkg['name'] ?? '') === 'laravel/framework') {
                    $version_bloqueada = ltrim($pkg['version'] ?? '', 'v');
                    break;
                }
            }
        }
    }

    if ($version_bloqueada !== null) {
        $resultado['laravel_version'] = $version_bloqueada;
        $resultado['laravel_origen'] = 'composer.lock';

        if (!$resultado['dependencias_instaladas']) {
            $resultado['advertencias'][] = 'Laravel está bloqueado en composer.lock pero no se encontró la carpeta vendor/ (dependencias no instaladas).';
        }
    } else {
        // No hay composer.lock o no contiene laravel/framework
        if (file_exists($composer_lock_path)) {
            $resultado['advertencias'][] = 'composer.lock presente pero "laravel/framework" no se encuentra instalado en él (dependencias incompletas).';
        } else {
            $resultado['advertencias'][] = 'No se encontró composer.lock (versión exacta no bloqueada).';
        }

        if (!$resultado['dependencias_instaladas']) {
            $resultado['advertencias'][] = 'Carpeta vendor/ no encontrada; dependencias no instaladas en el proyecto.';
        }

        $resultado['laravel_version'] = $require['laravel/framework'];
        $resultado['laravel_origen'] = 'composer.json';
    }

    // Evaluación informativa de la versión de Laravel
    $version_str = $resultado['laravel_version'];

    if ($resultado['laravel_origen'] === 'composer.lock') {
        if (preg_match('/^([0-9]+)\./', $version_str, $m)) {
            $resultado['laravel_major'] = (int)$m[1];
            $resultado['soporta_laravel'] = true;
        } else {
            $resultado['advertencias'][] = 'No se pudo extraer la versión mayor de Laravel desde composer.lock.';
        }
    } else {
        // En composer.json solo tenemos la restricción declarada
        // Detectar si fija o acota claramente una sola versión mayor
        if (preg_match('/^[\^~]?\s*([0-9]+)(?:\.[0-9]+)*$/', trim($version_str), $m)) {
            $resultado['laravel_major'] = (int)$m[1];
            $resultado['soporta_laravel'] = true;
            $resultado['advertencias'][] = 'Versión de Laravel deducida de composer.json (' . $version_str . '); no confirmada con composer.lock.';
        } elseif (preg_match('/^([0-9]+)\.\*$/', trim($version_str), $m)) {
            $resultado['laravel_major'] = (int)$m[1];
            $resultado['soporta_laravel'] = true;
            $resultado['advertencias'][] = 'Versión de Laravel deducida de composer.json (' . $version_str . '); no confirmada con composer.lock.';
        } else {
            // Restricción ambigua o multiversión (ej: ^10.0 || ^11.0, >=10.0)
            $resultado['soporta_laravel'] = false;
            $resultado['advertencias'][] = 'Restricción de Laravel en composer.json (' . $version_str . ') es ambigua o no verificable con certeza; soporte no confirmado.';
        }
    }

    $resultado['valido'] = true;
    return $resultado;
}
