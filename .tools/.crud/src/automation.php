<?php

function crud_api_base_url(array $config)
{
    $base = $config['frontend_api_base_url'] ?? null;
    if (!is_string($base) || filter_var($base, FILTER_VALIDATE_URL) === false) {
        return null;
    }

    $partes = parse_url($base);
    if (!is_array($partes)
        || !in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true)
        || empty($partes['host'])) {
        return null;
    }

    $puerto = $config['puerto_backend'] ?? ($partes['port'] ?? null);
    if (!is_int($puerto) && !(is_string($puerto) && ctype_digit($puerto))) {
        return null;
    }
    $puerto = (int) $puerto;
    if ($puerto < 1 || $puerto > 65535) {
        return null;
    }

    $host = str_contains($partes['host'], ':') ? '[' . $partes['host'] . ']' : $partes['host'];
    $path = rtrim($partes['path'] ?? '', '/');
    return $partes['scheme'] . '://' . $host . ':' . $puerto . $path;
}

function crud_host_local_valido($host)
{
    return is_string($host)
        && (strtolower($host) === 'localhost'
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
}

function crud_puerto_disponible($host, $puerto)
{
    if (!crud_host_local_valido($host) || !is_numeric($puerto)
        || (int) $puerto < 1 || (int) $puerto > 65535) {
        return false;
    }

    $socket = @stream_socket_server(
        'tcp://' . $host . ':' . (int) $puerto,
        $errno,
        $error,
        STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
    );
    if ($socket === false) {
        return false;
    }
    fclose($socket);
    return true;
}

function crud_detener_servidores(array &$procesos)
{
    foreach ($procesos as $proceso) {
        if (!is_resource($proceso)) {
            continue;
        }
        $estado = proc_get_status($proceso);
        if ($estado['running']) {
            proc_terminate($proceso);
        }
        proc_close($proceso);
    }
    $procesos = [];
}

function crud_leer_env($ruta)
{
    if (!is_file($ruta) || is_link($ruta) || !is_readable($ruta)) {
        return null;
    }
    $contenido = file_get_contents($ruta);
    if ($contenido === false) {
        return null;
    }

    $valores = [];
    foreach (preg_split('/\r\n|\n|\r/', $contenido) as $linea) {
        if (preg_match('/^\s*([A-Z][A-Z0-9_]*)=(.*)$/', $linea, $m)) {
            $valores[$m[1]][] = trim($m[2], " \t\"'");
        }
    }
    return ['contenido' => $contenido, 'valores' => $valores];
}

function crud_preparar_entorno_laravel($ruta_proyecto, $revisar_base_datos = true, $api_base_url = null)
{
    $resultado = ['errores' => [], 'avisos' => [], 'acciones' => [], 'manual' => []];
    $env = $ruta_proyecto . DIRECTORY_SEPARATOR . '.env';
    $ejemplo = $ruta_proyecto . DIRECTORY_SEPARATOR . '.env.example';

    if (is_link($env)) {
        $resultado['errores'][] = 'Se encontró un .env que es enlace simbólico; no se modifica.';
        return $resultado;
    }
    if (!file_exists($env)) {
        if (!is_file($ejemplo) || is_link($ejemplo) || !is_readable($ejemplo)) {
            $resultado['manual'][] = 'Crear ' . $ruta_proyecto . '/.env a partir de .env.example.';
            return $resultado;
        }
        $origen = file_get_contents($ejemplo);
        if ($origen === false) {
            $resultado['errores'][] = 'No se pudo leer .env.example.';
            return $resultado;
        }
        if ($revisar_base_datos) {
            $sin_credenciales = preg_replace(
                '/^(CLOUDINARY_(?:CLOUD_NAME|API_KEY|API_SECRET))=.*$/m',
                '$1=',
                $origen,
                -1,
                $credenciales_limpiadas
            );
            if (!is_string($sin_credenciales)) {
                $resultado['errores'][] = 'No se pudieron filtrar las variables Cloudinary al crear .env.';
                return $resultado;
            }
            if ($credenciales_limpiadas > 0) {
                $origen = $sin_credenciales;
                $resultado['acciones'][] = 'Se dejaron vacías las variables Cloudinary copiadas desde .env.example; use credenciales propias del ejercicio.';
            }
        } elseif (is_string($api_base_url)
            && filter_var($api_base_url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($api_base_url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            preg_match_all('/^API_BASE_URL=.*$/m', $origen, $coincidencias);
            if (count($coincidencias[0]) > 1) {
                $resultado['errores'][] = '.env.example contiene varias definiciones de API_BASE_URL.';
                return $resultado;
            }
            if ($coincidencias[0] !== []) {
                $origen = preg_replace(
                    '/^API_BASE_URL=.*$/m',
                    'API_BASE_URL=' . $api_base_url,
                    $origen,
                    1,
                    $reemplazos
                );
                if ($reemplazos !== 1 || !is_string($origen)) {
                    $resultado['errores'][] = 'No se pudo actualizar API_BASE_URL al crear .env.';
                    return $resultado;
                }
            } else {
                $separador = $origen === '' || str_ends_with($origen, "\n") || str_ends_with($origen, "\r")
                    ? ''
                    : (str_contains($origen, "\r\n") ? "\r\n" : "\n");
                $origen .= $separador . 'API_BASE_URL=' . $api_base_url
                    . (str_contains($origen, "\r\n") ? "\r\n" : "\n");
            }
        }
        $destino = @fopen($env, 'x');
        if ($destino === false) {
            if (is_resource($destino)) {
                fclose($destino);
            }
            $resultado['errores'][] = 'No se pudo crear .env sin sobrescribir un archivo existente.';
            return $resultado;
        }
        $escrito = fwrite($destino, $origen);
        fclose($destino);
        if ($escrito !== strlen($origen)) {
            @unlink($env);
            $resultado['errores'][] = 'No se pudo copiar .env.example completo a .env.';
            return $resultado;
        }
        $resultado['acciones'][] = 'Se creó .env desde .env.example; ningún archivo existente fue reemplazado.';
    }

    $actual = crud_leer_env($env);
    if ($actual === null) {
        $resultado['errores'][] = 'No se pudo leer .env de forma segura.';
        return $resultado;
    }
    $contenido = $actual['contenido'];
    $claves = $actual['valores']['APP_KEY'] ?? [];
    if (count($claves) > 1) {
        $resultado['manual'][] = 'Revisar APP_KEY: .env contiene definiciones duplicadas.';
    } elseif ($claves === [] || $claves[0] === '') {
        $clave = 'base64:' . base64_encode(random_bytes(32));
        if ($claves === []) {
            $separador = $contenido === '' || str_ends_with($contenido, "\n") || str_ends_with($contenido, "\r")
                ? ''
                : (str_contains($contenido, "\r\n") ? "\r\n" : "\n");
            $nuevo = $contenido . $separador . 'APP_KEY=' . $clave . (str_contains($contenido, "\r\n") ? "\r\n" : "\n");
        } else {
            $nuevo = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $clave, $contenido, 1, $reemplazos);
            if ($reemplazos !== 1 || !is_string($nuevo)) {
                $resultado['errores'][] = 'No se pudo preparar APP_KEY sin modificar otras variables.';
                return $resultado;
            }
        }
        if ($nuevo !== $contenido && !guardar_archivo_atomico($env, $nuevo)) {
            $resultado['errores'][] = 'No se pudo guardar APP_KEY en .env.';
            return $resultado;
        }
        $resultado['acciones'][] = 'Se generó APP_KEY porque estaba ausente o vacío.';
        $actual = crud_leer_env($env);
        if ($actual === null) {
            $resultado['errores'][] = 'No se pudo volver a leer .env después de generar APP_KEY.';
            return $resultado;
        }
    }

    if ($revisar_base_datos) {
        $connection = strtolower($actual['valores']['DB_CONNECTION'][0] ?? '');
        if ($connection !== 'mysql') {
            $resultado['manual'][] = 'Verificar DB_CONNECTION y los valores DB_* de ' . $ruta_proyecto . '/.env para la base de datos que utilizará el ejercicio.';
        } else {
            $faltantes = [];
            foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME'] as $variable) {
                $valor = $actual['valores'][$variable][0] ?? '';
                if ($valor === '' || in_array(strtolower($valor), ['database', 'forge', 'your_database', 'your_username'], true)) {
                    $faltantes[] = $variable;
                }
            }
            if ($faltantes !== []) {
                $resultado['manual'][] = 'Completar en ' . $ruta_proyecto . '/.env: ' . implode(', ', $faltantes) . '.';
            }
            $resultado['manual'][] = 'Confirmar DB_PASSWORD según la instalación local de MySQL; el generador no prueba ni altera credenciales.';
        }
    }

    return $resultado;
}

function crud_comprobar_comando($nombre)
{
    $path = getenv('PATH') ?: '';
    $separador = PATH_SEPARATOR;
    $extensiones = DIRECTORY_SEPARATOR === '\\'
        ? (explode(';', getenv('PATHEXT') ?: '.EXE;.BAT;.CMD;.COM'))
        : [''];
    foreach (explode($separador, $path) as $directorio) {
        if ($directorio === '') {
            continue;
        }
        foreach ($extensiones as $extension) {
            $archivo = rtrim($directorio, '/\\') . DIRECTORY_SEPARATOR . $nombre . $extension;
            if (is_file($archivo) && (DIRECTORY_SEPARATOR === '\\' || is_executable($archivo))) {
                return true;
            }
        }
    }
    return false;
}

function crud_frontend_assets_pendientes($ruta_frontend)
{
    $package_path = $ruta_frontend . DIRECTORY_SEPARATOR . 'package.json';
    if (!is_file($package_path) || !is_readable($package_path)) {
        return null;
    }
    $package = json_decode((string) file_get_contents($package_path), true);
    $scripts = is_array($package) ? ($package['scripts'] ?? []) : [];
    if (!is_array($scripts) || (!isset($scripts['build']) && !isset($scripts['dev']))) {
        return null;
    }
    if (is_file($ruta_frontend . '/public/build/manifest.json')) {
        return null;
    }
    $hot_path = $ruta_frontend . '/public/hot';
    if (is_file($hot_path) && is_readable($hot_path)) {
        $hot_url = trim((string) file_get_contents($hot_path));
        $hot = parse_url($hot_url);
        $host = strtolower($hot['host'] ?? '');
        $port = $hot['port'] ?? 80;
        if (in_array($host, ['localhost', '127.0.0.1'], true)
            && in_array(strtolower($hot['scheme'] ?? ''), ['http', 'https'], true)
            && is_int($port) && $port > 0 && $port < 65536) {
            $socket = @fsockopen($host === 'localhost' ? '127.0.0.1' : $host, $port, $errno, $error, 0.2);
            if (is_resource($socket)) {
                fclose($socket);
                return null;
            }
        }
    }
    return isset($scripts['build']) ? 'build' : 'dev';
}

function crud_detectar_imagenes(array $analisis, array $config = [])
{
    $resultado = [];
    foreach ($analisis['tablas'] ?? [] as $tabla => $informacion) {
        if (!es_tabla_elegible($tabla, $informacion, $config)) {
            continue;
        }
        foreach ($informacion['columnas'] ?? [] as $columna => $definicion) {
            if (!empty($definicion['es_imagen'])) {
                $resultado[] = $tabla . '.' . $columna;
            }
        }
    }
    return $resultado;
}

function crud_revisar_cloudinary($ruta_backend, array $imagenes)
{
    if ($imagenes === []) {
        return ['usado' => false, 'sdk' => null, 'config' => null, 'variables_faltantes' => []];
    }

    $composer_path = $ruta_backend . DIRECTORY_SEPARATOR . 'composer.json';
    $composer = is_file($composer_path) && is_readable($composer_path)
        ? json_decode((string) file_get_contents($composer_path), true)
        : [];
    $env = crud_leer_env($ruta_backend . DIRECTORY_SEPARATOR . '.env');
    $faltantes = [];
    $duplicadas = [];
    foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $variable) {
        $valores = $env['valores'][$variable] ?? [];
        $valor = $valores[0] ?? '';
        if (count($valores) > 1) {
            $duplicadas[] = $variable;
        } elseif ($valor === '' || preg_match('/^(tu_|your_|change_me|placeholder)/i', $valor)) {
            $faltantes[] = $variable;
        }
    }

    $services_path = $ruta_backend . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'services.php';
    $services = is_file($services_path) && is_readable($services_path)
        ? file_get_contents($services_path)
        : false;
    return [
        'usado' => true,
        'sdk_instalado' => is_dir($ruta_backend . '/vendor/cloudinary/cloudinary_php'),
        'sdk_declarado' => isset($composer['require']['cloudinary/cloudinary_php'])
            || isset($composer['require-dev']['cloudinary/cloudinary_php']),
        'config' => is_string($services)
            && preg_match('/^[ \t]*[\'"]cloudinary[\'"][ \t]*=>/m', $services) === 1,
        'variables_faltantes' => $faltantes,
        'variables_duplicadas' => $duplicadas,
    ];
}

function crud_fuentes_disponibles(array $config)
{
    $backend = resolver_directorio_fuente_backend($config);
    $raiz = defined('RAIZ') ? RAIZ : dirname(CRUD_DIR, 2);
    $relativa = $config['fuente_frontend'] ?? 'pracExaLav/frontend';
    $frontend = realpath($raiz . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativa));
    if ($frontend === false || !is_dir($frontend)
        || !is_file($frontend . '/routes/web.php')
        || !is_file($frontend . '/resources/views/layouts/app.blade.php')
        || !is_file($frontend . '/resources/views/home.blade.php')) {
        return ['backend' => $backend, 'frontend' => null];
    }
    $destino_backend = realpath(RAIZ . DIRECTORY_SEPARATOR . 'Backend');
    $destino_frontend = realpath(RAIZ . DIRECTORY_SEPARATOR . 'Frontend');
    if ($backend === null || $destino_backend === false || $destino_frontend === false
        || frontend_rutas_iguales($destino_backend, $backend)
        || ruta_fuente_dentro_de_raiz($destino_backend, $backend)
        || ruta_fuente_dentro_de_raiz($backend, $destino_backend)
        || frontend_rutas_iguales($destino_frontend, $frontend)
        || ruta_fuente_dentro_de_raiz($destino_frontend, $frontend)
        || ruta_fuente_dentro_de_raiz($frontend, $destino_frontend)) {
        return ['backend' => null, 'frontend' => null];
    }
    return ['backend' => $backend, 'frontend' => $frontend];
}

function crud_ejecutar_subcomando($comando)
{
    $proceso = proc_open(
        [PHP_BINARY, CRUD_DIR . DIRECTORY_SEPARATOR . 'generate.php', $comando],
        [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ],
        $pipes,
        RAIZ
    );
    if (!is_resource($proceso)) {
        mostrar_error('No se pudo iniciar la etapa ' . $comando . '.');
        return ['codigo' => 2];
    }
    return ['codigo' => proc_close($proceso)];
}

function crud_imprimir_entorno(array $resultado)
{
    foreach ($resultado['acciones'] as $accion) {
        exito($accion);
    }
    foreach ($resultado['avisos'] as $aviso) {
        aviso($aviso);
    }
    foreach ($resultado['manual'] as $manual) {
        aviso($manual);
    }
    foreach ($resultado['errores'] as $error) {
        mostrar_error($error);
    }
}

function crud_imprimir_pendientes(array $pendientes)
{
    mensaje('');
    mensaje('CONFIGURACIÓN MANUAL PENDIENTE');
    $pendientes = array_values(array_unique($pendientes));
    if ($pendientes === []) {
        aviso('No fue posible completar la detección de pasos manuales antes del error indicado.');
        return;
    }
    foreach ($pendientes as $pendiente) {
        aviso($pendiente);
    }
}

function crud_ejecutar_all($config)
{
    $resumen = ['manual' => [], 'errores' => [], 'backend' => false, 'frontend' => false];
    mensaje('GENERACIÓN DEL PROYECTO');
    exito('PHP ' . PHP_VERSION . ' disponible.');

    $rutas = [];
    foreach (['Backend', 'Frontend'] as $nombre) {
        $error = null;
        $ruta = crud_resolver_directorio($nombre, $error);
        if ($ruta === null) {
            $resumen['errores'][] = $error;
            mostrar_error($error);
            continue;
        }
        $info = inspeccionar_laravel($ruta);
        if (!$info['valido']) {
            $error = "$nombre no es un proyecto Laravel válido: " . ($info['error'] ?? 'estructura incompleta');
            $resumen['errores'][] = $error;
            mostrar_error($error);
            continue;
        }
        $rutas[$nombre] = $ruta;
        exito("$nombre detectado (Laravel {$info['laravel_version']}).");
        foreach ($info['advertencias'] as $advertencia) {
            aviso($advertencia);
        }
        if ($info['php_compatible'] === false) {
            $error = "PHP " . PHP_VERSION . " no cumple el requisito " . $info['php_requerido'] . " de $nombre.";
            $resumen['errores'][] = $error;
            mostrar_error($error);
            continue;
        }
        if (!$info['dependencias_instaladas']) {
            $resumen['manual'][] = "Instalar dependencias de $nombre: cd $nombre && composer install.";
            aviso("No se encontró vendor/ en $nombre; el generador no instala dependencias automáticamente.");
        }
    }
    if ($resumen['errores'] !== []) {
        crud_imprimir_pendientes($resumen['manual']);
        return 1;
    }

    if (!crud_comprobar_comando('composer')) {
        aviso('Composer no se encontró en PATH; la generación de código no lo necesita, pero será necesario para instalar dependencias.');
        $resumen['manual'][] = 'Instalar Composer y ejecutar composer install en Backend y Frontend.';
    } else {
        exito('Composer detectado.');
    }

    $assets_pendientes = crud_frontend_assets_pendientes($rutas['Frontend']);
    if ($assets_pendientes !== null) {
        if (!crud_comprobar_comando('node') || !crud_comprobar_comando('npm')) {
            $resumen['manual'][] = 'Instalar Node.js/npm para preparar los assets del Frontend.';
            aviso('El Frontend necesita assets npm, pero Node.js o npm no se encontró en PATH.');
        } elseif (!is_dir($rutas['Frontend'] . '/node_modules')) {
            $resumen['manual'][] = $assets_pendientes === 'build'
                ? 'Compilar assets: cd Frontend && npm install && npm run build.'
                : 'Instalar assets y mantener Vite activo: cd Frontend && npm install && npm run dev.';
            aviso('No se encontró node_modules; el generador no instala paquetes automáticamente.');
        } elseif ($assets_pendientes === 'build') {
            $resumen['manual'][] = 'Compilar assets: cd Frontend && npm run build.';
            aviso('No se encontró public/build/manifest.json; revise npm run build antes de probar las vistas.');
        } else {
            $resumen['manual'][] = 'Iniciar Vite en otra terminal: cd Frontend && npm run dev.';
            aviso('No se encontró public/build/manifest.json ni public/hot; se requiere el servidor Vite.');
        }
    } elseif (is_file($rutas['Frontend'] . '/public/build/manifest.json')) {
        exito('Assets compilados detectados en Frontend/public/build.');
    }

    $analisis = analizar_migraciones($rutas['Backend'], $config);
    $guardia = guardia_generacion_migraciones($analisis);
    if ($guardia['bloqueada']) {
        mostrar_error($guardia['mensaje']);
        foreach ($analisis['advertencias'] ?? [] as $advertencia) {
            aviso($advertencia);
        }
        crud_imprimir_pendientes($resumen['manual']);
        return 1;
    }
    if (($analisis['tablas'] ?? []) === []) {
        mostrar_error('No hay tablas analizadas en Backend/database/migrations.');
        crud_imprimir_pendientes($resumen['manual']);
        return 1;
    }
    $columnas = 0;
    $foreign_keys = 0;
    foreach ($analisis['tablas'] as $tabla) {
        $columnas += count($tabla['columnas'] ?? []);
        $foreign_keys += count($tabla['foreign_keys'] ?? []);
    }
    exito('Migraciones analizadas: ' . count($analisis['analysis']['migrations'] ?? [])
        . ' archivo(s), ' . count($analisis['tablas']) . ' tabla(s), ' . $columnas . ' columna(s), '
        . $foreign_keys . ' clave(s) foránea(s), ' . count($analisis['relations'] ?? []) . ' relación(es).');
    foreach ($analisis['advertencias'] ?? [] as $advertencia) {
        aviso($advertencia);
    }

    $fuentes = crud_fuentes_disponibles($config);
    if ($fuentes['backend'] === null || $fuentes['frontend'] === null) {
        $error = 'No están disponibles las fuentes configuradas. Se requieren las fuentes originales de Backend y Frontend; revise fuente_backend y fuente_frontend en .tools/.crud/config.php. No se generó ni modificó código destino.';
        mostrar_error($error);
        crud_imprimir_pendientes($resumen['manual']);
        return 1;
    }
    exito('Fuentes originales configuradas detectadas.');

    $imagenes = crud_detectar_imagenes($analisis, $config);
    if ($imagenes === []) {
        exito('No se detectaron columnas de imagen; Cloudinary no es necesario.');
    } else {
        exito('Campos de imagen detectados: ' . implode(', ', $imagenes) . '.');
    }

    $api = crud_api_base_url($config);
    if ($api === null) {
        mostrar_error('frontend_api_base_url o puerto_backend no es válido; revise .tools/.crud/config.php.');
        crud_imprimir_pendientes($resumen['manual']);
        return 1;
    }
    foreach (['puerto_backend', 'puerto_frontend'] as $clave) {
        $puerto = $config[$clave] ?? null;
        if (!is_numeric($puerto) || (int) $puerto < 1 || (int) $puerto > 65535) {
            mostrar_error("El valor $clave debe ser un puerto TCP entre 1 y 65535.");
            crud_imprimir_pendientes($resumen['manual']);
            return 1;
        }
    }
    $host_local = $config['host_local'] ?? '127.0.0.1';
    if (!crud_host_local_valido($host_local)) {
        mostrar_error('host_local debe ser localhost o una dirección IPv4 válida.');
        crud_imprimir_pendientes($resumen['manual']);
        return 1;
    }

    foreach (['Backend', 'Frontend'] as $nombre) {
        $preparacion = crud_preparar_entorno_laravel(
            $rutas[$nombre],
            $nombre === 'Backend',
            $nombre === 'Frontend' ? $api : null
        );
        crud_imprimir_entorno($preparacion);
        $resumen['manual'] = array_merge($resumen['manual'], $preparacion['manual']);
        if ($preparacion['errores'] !== []) {
            crud_imprimir_pendientes($resumen['manual']);
            return 1;
        }
    }

    exito('URL de API configurada: ' . $api . '.');
    foreach (['puerto_backend', 'puerto_frontend'] as $clave) {
        $puerto = $config[$clave] ?? null;
        if (!crud_puerto_disponible($host_local, $puerto)) {
            aviso("El puerto configurado {$puerto} ({$clave}) está ocupado o no está disponible; start no iniciará los servidores.");
        }
    }

    mensaje('');
    mensaje('Adaptando Backend...');
    $backend = crud_ejecutar_subcomando('back');
    if ($backend['codigo'] !== 0) {
        mostrar_error('Backend no finalizó correctamente; se omitió la etapa Frontend.');
        crud_imprimir_pendientes($resumen['manual']);
        return $backend['codigo'] ?: 1;
    }
    $resumen['backend'] = true;
    exito('Backend generado sin errores ni adaptaciones pendientes.');

    mensaje('');
    mensaje('Adaptando Frontend...');
    $frontend = crud_ejecutar_subcomando('front');
    if ($frontend['codigo'] !== 0) {
        mostrar_error('Frontend no finalizó correctamente; revise conflictos y adaptaciones pendientes.');
        crud_imprimir_pendientes($resumen['manual']);
        return $frontend['codigo'] ?: 1;
    }
    $resumen['frontend'] = true;
    exito('Frontend generado sin errores ni adaptaciones pendientes.');

    $cloudinary = crud_revisar_cloudinary($rutas['Backend'], $imagenes);
    if ($cloudinary['usado']) {
        mensaje('');
        mensaje('--- Cloudinary ---');
        mensaje('SDK: ' . ($cloudinary['sdk_instalado']
            ? '[OK] instalado'
            : ($cloudinary['sdk_declarado'] ? '[!] declarado, no instalado' : '[!] no instalado ni declarado')));
        mensaje('Configuración config/services.php: ' . ($cloudinary['config'] ? '[OK]' : '[!] falta'));
        if (!$cloudinary['sdk_instalado'] && !$cloudinary['sdk_declarado']) {
            $resumen['manual'][] = 'Instalar Cloudinary en Backend: cd Backend && composer require cloudinary/cloudinary_php:^3.3.';
        }
        if (!$cloudinary['config']) {
            $resumen['manual'][] = 'Revisar la entrada services.cloudinary en Backend/config/services.php.';
        }
        if ($cloudinary['variables_faltantes'] !== []) {
            $resumen['manual'][] = 'Completar credenciales en Backend/.env: ' . implode(', ', $cloudinary['variables_faltantes']) . '.';
        }
        if ($cloudinary['variables_duplicadas'] !== []) {
            $resumen['manual'][] = 'Resolver definiciones duplicadas en Backend/.env: ' . implode(', ', $cloudinary['variables_duplicadas']) . '.';
        }
        $resumen['manual'][] = 'Probar manualmente una carga de imagen con credenciales Cloudinary válidas.';
        aviso('La subida real no se ha probado; requiere credenciales válidas y conectividad.');
    }

    $env_frontend = crud_leer_env($rutas['Frontend'] . DIRECTORY_SEPARATOR . '.env');
    $api_en_env = $env_frontend['valores']['API_BASE_URL'] ?? [];
    if (count($api_en_env) !== 1 || rtrim($api_en_env[0], '/') !== $api) {
        $resumen['manual'][] = 'Ajustar Frontend/.env para que tenga una sola API_BASE_URL=' . $api . '; se preservó cualquier valor no vacío preexistente.';
    }
    $resumen['manual'][] = 'Configurar/verificar la conexión a MySQL en Backend/.env.';
    $resumen['manual'][] = 'Desde Backend, ejecutar php artisan migrate después de confirmar la base de datos; no se ejecutó automáticamente.';

    crud_imprimir_pendientes($resumen['manual']);
    mensaje('');
    mensaje('PROYECTO');
    mensaje('Backend: http://' . $host_local . ':' . (int) $config['puerto_backend']);
    mensaje('Frontend: http://' . $host_local . ':' . (int) $config['puerto_frontend']);
    mensaje('API: ' . $api);
    mensaje('Inicie ambos servicios con: php .tools/.crud/generate.php start');
    return 0;
}

function crud_iniciar_servidores($config)
{
    $rutas = [];
    foreach (['Backend', 'Frontend'] as $nombre) {
        $error = null;
        $ruta = crud_resolver_directorio($nombre, $error);
        if ($ruta === null) {
            mostrar_error($error);
            return 1;
        }
        $info = inspeccionar_laravel($ruta);
        if (!$info['valido'] || !$info['dependencias_instaladas']) {
            mostrar_error("$nombre requiere un proyecto Laravel válido con dependencias instaladas (vendor/).");
            if (!empty($info['error'])) {
                mostrar_error($info['error']);
            }
            return 1;
        }
        if ($info['php_compatible'] === false) {
            mostrar_error("PHP " . PHP_VERSION . " no cumple el requisito " . $info['php_requerido'] . " de $nombre.");
            return 1;
        }
        $rutas[$nombre] = $ruta;
    }

    $assets_pendientes = crud_frontend_assets_pendientes($rutas['Frontend']);
    if ($assets_pendientes !== null) {
        mostrar_error('No hay assets compilados ni servidor Vite activo. '
            . ($assets_pendientes === 'build'
                ? 'Ejecute npm install y npm run build en Frontend.'
                : 'Inicie npm run dev en Frontend y vuelva a ejecutar start.'));
        return 1;
    }

    $api = crud_api_base_url($config);
    $host = $config['host_local'] ?? '127.0.0.1';
    $puertos = [
        'Backend' => $config['puerto_backend'] ?? null,
        'Frontend' => $config['puerto_frontend'] ?? null,
    ];
    if (!crud_host_local_valido($host)) {
        mostrar_error('host_local debe ser localhost o una dirección IPv4 válida.');
        return 1;
    }
    if ((int) $puertos['Backend'] === (int) $puertos['Frontend']) {
        mostrar_error('Backend y Frontend no pueden utilizar el mismo puerto.');
        return 1;
    }
    foreach ($puertos as $nombre => $puerto) {
        if (!crud_puerto_disponible($host, $puerto)) {
            mostrar_error("El puerto configurado para $nombre ($puerto) no está disponible. Libérelo o cambie la configuración.");
            return 1;
        }
    }
    if ($api === null) {
        mostrar_error('La URL API o puerto_backend no es válido en config.php.');
        return 1;
    }

    $procesos = [];
    register_shutdown_function(static function () use (&$procesos) {
        crud_detener_servidores($procesos);
    });
    if (function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () {
            exit(130);
        });
        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, static function () {
                exit(143);
            });
        }
    }
    foreach (['Backend', 'Frontend'] as $nombre) {
        $proceso = proc_open(
            [PHP_BINARY, 'artisan', 'serve', '--host=' . $host, '--port=' . $puertos[$nombre]],
            [
                0 => ['file', 'php://stdin', 'r'],
                1 => ['file', 'php://stdout', 'w'],
                2 => ['file', 'php://stderr', 'w'],
            ],
            $pipes,
            $rutas[$nombre]
        );
        if (!is_resource($proceso)) {
            crud_detener_servidores($procesos);
            mostrar_error("No se pudo iniciar el servidor $nombre.");
            return 1;
        }
        $procesos[$nombre] = $proceso;

        $listo = false;
        $limite = microtime(true) + 5;
        while (microtime(true) < $limite) {
            $estado = proc_get_status($proceso);
            if (!$estado['running']) {
                break;
            }
            $socket = @fsockopen($host, (int) $puertos[$nombre], $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                $listo = true;
                break;
            }
            usleep(100000);
        }
        if (!$listo) {
            crud_detener_servidores($procesos);
            mostrar_error("El servidor $nombre no confirmó disponibilidad en el puerto {$puertos[$nombre]}.");
            return 1;
        }
    }

    exito('Backend: http://' . $host . ':' . $puertos['Backend']);
    exito('Frontend: http://' . $host . ':' . $puertos['Frontend']);
    exito('API utilizada por Frontend: ' . $api);
    mensaje('Presione Ctrl+C para detener ambos servidores.');

    while (true) {
        foreach ($procesos as $nombre => $proceso) {
            $estado = proc_get_status($proceso);
            if (!$estado['running']) {
                crud_detener_servidores($procesos);
                mostrar_error("El servidor $nombre terminó inesperadamente (código {$estado['exitcode']}).");
                return 1;
            }
        }
        usleep(250000);
    }
}
