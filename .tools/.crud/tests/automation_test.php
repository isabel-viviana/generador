<?php

define('CRUD_DIR', dirname(__DIR__));
require_once CRUD_DIR . '/src/util.php';
require_once CRUD_DIR . '/src/laravel.php';
require_once CRUD_DIR . '/src/migraciones.php';
require_once CRUD_DIR . '/src/backend.php';
require_once CRUD_DIR . '/src/frontend.php';
require_once CRUD_DIR . '/src/automation.php';

function automation_assert($condicion, $mensaje)
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function automation_limpiar($ruta)
{
    if (!is_dir($ruta)) {
        return;
    }
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterador as $elemento) {
        $elemento->isLink() || !$elemento->isDir()
            ? unlink($elemento->getPathname())
            : rmdir($elemento->getPathname());
    }
    rmdir($ruta);
}

function automation_copiar_archivo($origen, $destino)
{
    if (!is_file($origen) || is_link($origen)) {
        return false;
    }
    if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0777, true)) {
        return false;
    }
    return copy($origen, $destino);
}

$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-automation-' . bin2hex(random_bytes(6));
mkdir($fixture, 0777, true);

try {
    $backend = $fixture . '/Backend';
    mkdir($backend . '/config', 0777, true);
    $env_backend_ejemplo = "APP_NAME=Exercise\nAPP_KEY=\nDB_CONNECTION=mysql\nDB_HOST=database\nDB_PORT=3306\nDB_DATABASE=forge\nDB_USERNAME=forge\nDB_PASSWORD=\nCLOUDINARY_CLOUD_NAME=example-cloud\nCLOUDINARY_API_KEY=example-key\nCLOUDINARY_API_SECRET=example-secret\n";
    file_put_contents($backend . '/.env.example', $env_backend_ejemplo);
    $preparacion = crud_preparar_entorno_laravel($backend);
    $env = file_get_contents($backend . '/.env');
    automation_assert(count($preparacion['errores']) === 0, 'No se pudo preparar .env sin errores.');
    automation_assert(str_contains($env, 'APP_KEY=base64:') && strlen(explode('APP_KEY=base64:', $env)[1]) >= 44, 'No se generó una APP_KEY de Laravel.');
    automation_assert(
        str_contains($env, "CLOUDINARY_CLOUD_NAME=\n")
            && str_contains($env, "CLOUDINARY_API_KEY=\n")
            && str_contains($env, "CLOUDINARY_API_SECRET=\n")
            && file_get_contents($backend . '/.env.example') === $env_backend_ejemplo,
        'Se copiaron valores Cloudinary de referencia o se alteró .env.example.'
    );
    automation_assert(
        count(array_filter($preparacion['manual'], static fn($tarea) => str_contains($tarea, 'DB_DATABASE'))) === 1,
        'No se identificó el nombre de base de datos placeholder.'
    );
    $clave_generada = preg_match('/^APP_KEY=(.*)$/m', $env, $m) ? $m[1] : '';
    $segunda = crud_preparar_entorno_laravel($backend);
    automation_assert(
        preg_match('/^APP_KEY=(.*)$/m', file_get_contents($backend . '/.env'), $m)
            && $m[1] === $clave_generada
            && $segunda['errores'] === [],
        'La segunda ejecución reemplazó APP_KEY existente.'
    );
    echo "OK: .env se crea solo si falta y APP_KEY no se reemplaza\n";

    $backend_manual = $fixture . '/BackendManual';
    mkdir($backend_manual);
    file_put_contents($backend_manual . '/.env', "APP_KEY=base64:existing-secret-value\nDB_CONNECTION=mysql\nDB_HOST=db.local\nDB_PORT=3306\nDB_DATABASE=exercise\nDB_USERNAME=admin\nDB_PASSWORD=secret\n");
    crud_preparar_entorno_laravel($backend_manual);
    automation_assert(
        str_contains(file_get_contents($backend_manual . '/.env'), 'APP_KEY=base64:existing-secret-value')
            && str_contains(file_get_contents($backend_manual . '/.env'), 'DB_PASSWORD=secret'),
        'Se alteró una clave o credencial preexistente.'
    );
    echo "OK: se conservan APP_KEY y credenciales existentes\n";

    $api = crud_api_base_url([
        'frontend_api_base_url' => 'http://localhost:9000/api',
        'puerto_backend' => 8123,
    ]);
    automation_assert($api === 'http://localhost:8123/api', 'La API no se derivó del host/ruta y puerto configurados.');
    automation_assert(crud_api_base_url(['frontend_api_base_url' => 'javascript:alert(1)', 'puerto_backend' => 8001]) === null, 'Se aceptó una URL de API inválida.');
    $frontend_nuevo = $fixture . '/FrontendFresh';
    mkdir($frontend_nuevo);
    file_put_contents($frontend_nuevo . '/.env.example', "APP_NAME=Exercise\nAPI_BASE_URL=http://127.0.0.1:8000/api\nCUSTOM_VALUE=keep\n");
    $entorno_frontend_nuevo = crud_preparar_entorno_laravel($frontend_nuevo, false, $api);
    automation_assert(
        $entorno_frontend_nuevo['errores'] === []
            && str_contains(file_get_contents($frontend_nuevo . '/.env'), "API_BASE_URL={$api}\n")
            && str_contains(file_get_contents($frontend_nuevo . '/.env'), "CUSTOM_VALUE=keep\n"),
        'No se alineó API_BASE_URL al crear .env desde un ejemplo.'
    );
    $frontend_env = $fixture . '/Frontend';
    mkdir($frontend_env);
    file_put_contents($frontend_env . '/.env', "APP_NAME=Exercise\nAPI_BASE_URL=\nCUSTOM_VALUE=keep\n");
    $resumen_frontend = ['adaptaciones_pendientes' => [], 'errores' => []];
    automation_assert(
        frontend_configurar_api_env($frontend_env, ['frontend_api_base_url' => $api], $resumen_frontend),
        'No se completó el API_BASE_URL vacío.'
    );
    automation_assert(
        str_contains(file_get_contents($frontend_env . '/.env'), 'API_BASE_URL=http://localhost:8123/api')
            && str_contains(file_get_contents($frontend_env . '/.env'), 'CUSTOM_VALUE=keep'),
        'La configuración API no preservó otras variables del .env.'
    );
    file_put_contents($frontend_env . '/.env', "API_BASE_URL=http://localhost:9000/api\n");
    $resumen_frontend = ['adaptaciones_pendientes' => [], 'errores' => []];
    automation_assert(
        !frontend_configurar_api_env($frontend_env, ['frontend_api_base_url' => $api], $resumen_frontend)
            && file_get_contents($frontend_env . '/.env') === "API_BASE_URL=http://localhost:9000/api\n",
        'La configuración API sobrescribió un valor existente.'
    );
    echo "OK: URL API y puerto se validan desde configuración\n";

    $analisis = [
        'tablas' => [
            'products' => ['columnas' => ['photo' => ['es_imagen' => true], 'name' => ['es_imagen' => false]]],
            'users' => ['columnas' => ['avatar' => ['es_imagen' => true]]],
        ],
    ];
    automation_assert(
        crud_detectar_imagenes($analisis, ['tablas_excluidas' => ['users']]) === ['products.photo'],
        'La detección de imágenes incluyó tablas excluidas o omitió campos detectados.'
    );
    file_put_contents($backend . '/composer.json', json_encode(['require' => ['cloudinary/cloudinary_php' => '^3.3']]));
    file_put_contents($backend . '/config/services.php', "<?php\n\nreturn [\n    'cloudinary' => [],\n];\n");
    $cloudinary = crud_revisar_cloudinary($backend, ['products.photo']);
    automation_assert(
        $cloudinary['usado'] && !$cloudinary['sdk_instalado'] && $cloudinary['sdk_declarado'] && $cloudinary['config']
            && $cloudinary['variables_faltantes'] === ['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'],
        'La verificación Cloudinary no identificó SDK no instalado, configuración y credenciales pendientes.'
    );
    automation_assert(crud_revisar_cloudinary($backend, [])['usado'] === false, 'Se solicitó Cloudinary sin campos de imagen.');
    echo "OK: Cloudinary se revisa solo cuando hay campos de imagen y no inventa credenciales\n";

    $assets = $fixture . '/FrontendAssets';
    mkdir($assets);
    file_put_contents($assets . '/package.json', '{"scripts":{"build":"vite build"}}');
    automation_assert(crud_frontend_assets_pendientes($assets) === 'build', 'No se detectaron assets frontend faltantes.');
    mkdir($assets . '/public/build', 0777, true);
    file_put_contents($assets . '/public/build/manifest.json', '{}');
    automation_assert(crud_frontend_assets_pendientes($assets) === null, 'Se requirió npm aunque los assets compilados ya existen.');
    unlink($assets . '/public/build/manifest.json');
    $vite = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    automation_assert(is_resource($vite), 'No se pudo reservar un puerto Vite temporal.');
    $vite_port = (int) substr(strrchr(stream_socket_get_name($vite, false), ':'), 1);
    file_put_contents($assets . '/public/hot', 'http://127.0.0.1:' . $vite_port);
    automation_assert(crud_frontend_assets_pendientes($assets) === null, 'No se detectó Vite activo.');
    fclose($vite);
    automation_assert(crud_frontend_assets_pendientes($assets) === 'build', 'Se aceptó un archivo public/hot obsoleto.');
    echo "OK: Node/npm solo se requiere cuando faltan los assets del Frontend\n";

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    automation_assert(is_resource($socket), 'No se pudo reservar un puerto efímero para la prueba.');
    $direccion = stream_socket_get_name($socket, false);
    $puerto = (int) substr(strrchr($direccion, ':'), 1);
    automation_assert(!crud_puerto_disponible('127.0.0.1', $puerto), 'Se reportó disponible un puerto ocupado.');
    fclose($socket);
    automation_assert(crud_puerto_disponible('127.0.0.1', $puerto), 'Se reportó ocupado un puerto liberado.');
    echo "OK: los puertos ocupados y disponibles se distinguen\n";

    $raiz_prueba = $fixture . '/project';
    $crud_prueba = $raiz_prueba . '/.tools/.crud';
    mkdir($crud_prueba, 0777, true);
    $origen = dirname(__DIR__);
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterador as $elemento) {
        $relativo = substr($elemento->getPathname(), strlen($origen) + 1);
        $destino = $crud_prueba . DIRECTORY_SEPARATOR . $relativo;
        if ($elemento->isDir()) {
            if (!is_dir($destino)) {
                mkdir($destino, 0777, true);
            }
        } elseif (!$elemento->isLink()) {
            copy($elemento->getPathname(), $destino);
        }
    }
    $proceso = proc_open(
        [PHP_BINARY, $crud_prueba . '/generate.php', 'all'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $raiz_prueba
    );
    automation_assert(is_resource($proceso), 'No se pudo iniciar la prueba CLI de all.');
    $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    automation_assert($codigo !== 0 && str_contains($salida, 'No se encontró la carpeta Backend'), 'all no falló explícitamente ante un proyecto sin Backend.');
    automation_assert(!file_exists($raiz_prueba . '/Backend/.env'), 'all modificó un destino después de fallar la comprobación estructural.');
    echo "OK: all falla en preflight sin escribir en proyectos destino\n";

    $raiz_fuentes = $fixture . '/project-without-sources';
    $crud_fuentes = $raiz_fuentes . '/.tools/.crud';
    mkdir($crud_fuentes, 0777, true);
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterador as $elemento) {
        $relativo = substr($elemento->getPathname(), strlen($origen) + 1);
        $destino = $crud_fuentes . DIRECTORY_SEPARATOR . $relativo;
        if ($elemento->isDir()) {
            if (!is_dir($destino)) {
                mkdir($destino, 0777, true);
            }
        } elseif (!$elemento->isLink()) {
            copy($elemento->getPathname(), $destino);
        }
    }
    foreach (['Backend', 'Frontend'] as $nombre) {
        mkdir("$raiz_fuentes/$nombre/app", 0777, true);
        file_put_contents("$raiz_fuentes/$nombre/composer.json", '{"require":{"php":"^8.0","laravel/framework":"^11.0"}}');
        file_put_contents("$raiz_fuentes/$nombre/artisan", "<?php\n");
    }
    mkdir("$raiz_fuentes/Backend/database/migrations", 0777, true);
    file_put_contents(
        "$raiz_fuentes/Backend/database/migrations/2026_01_01_000000_create_products.php",
        "<?php\nreturn new class { public function up(): void { Schema::create('products', function (\$table) { \$table->id(); \$table->string('name'); }); } };\n"
    );
    $proceso = proc_open(
        [PHP_BINARY, $crud_fuentes . '/generate.php', 'all'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $raiz_fuentes
    );
    automation_assert(is_resource($proceso), 'No se pudo ejecutar all con proyectos temporales válidos.');
    $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    automation_assert(
        $codigo !== 0
            && str_contains($salida, 'Migraciones analizadas')
            && str_contains($salida, 'No están disponibles las fuentes configuradas'),
        'all no analizó migraciones antes de detenerse por falta de fuentes.'
    );
    automation_assert(
        !file_exists("$raiz_fuentes/Backend/.env") && !file_exists("$raiz_fuentes/Frontend/.env"),
        'all alteró entornos destino antes de comprobar que existieran las fuentes.'
    );
    echo "OK: all analiza las migraciones y valida fuentes antes de modificar destinos\n";

    $raiz_all = $fixture . '/all-success';
    $crud_all = $raiz_all . '/.tools/.crud';
    $raiz_repositorio = dirname(__DIR__, 3);
    $origenes = [
        'pracExaLav/backend' => $raiz_repositorio . '/pracExaLav/backend',
        'pracExaLav/frontend' => $raiz_repositorio . '/pracExaLav/frontend',
    ];
    mkdir($raiz_all, 0777, true);
    foreach ([$origen, $origenes['pracExaLav/backend'], $origenes['pracExaLav/frontend']] as $arbol) {
        $destino_arbol = $arbol === $origen
            ? $crud_all
            : $raiz_all . '/' . array_search($arbol, $origenes, true);
        mkdir($destino_arbol, 0777, true);
        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($arbol, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterador as $elemento) {
            $relativo = substr($elemento->getPathname(), strlen($arbol) + 1);
            $destino = $destino_arbol . DIRECTORY_SEPARATOR . $relativo;
            if ($elemento->isDir()) {
                if (!is_dir($destino)) {
                    mkdir($destino, 0777, true);
                }
            } elseif (!$elemento->isLink()) {
                automation_assert(copy($elemento->getPathname(), $destino), "No se pudo copiar {$relativo} al fixture.");
            }
        }
    }
    foreach (['Backend', 'Frontend'] as $nombre) {
        foreach ([
            'app/Http/Controllers',
            'app/Models',
            'config',
            'database/migrations',
            'public',
            'resources/css',
            'resources/js',
            'resources/views/layouts',
            'routes',
        ] as $directorio) {
            mkdir("$raiz_all/$nombre/$directorio", 0777, true);
        }
        $origen_app = $origenes[$nombre === 'Backend' ? 'pracExaLav/backend' : 'pracExaLav/frontend'];
        $archivos_proyecto = ['artisan', 'composer.json', 'composer.lock', '.env.example'];
        if ($nombre === 'Frontend') {
            $archivos_proyecto[] = 'package.json';
        }
        foreach ($archivos_proyecto as $archivo) {
            automation_assert(
                automation_copiar_archivo("$origen_app/$archivo", "$raiz_all/$nombre/$archivo"),
                "No se pudo preparar $nombre/$archivo para la prueba integrada."
            );
        }
        automation_copiar_archivo(
            "$origen_app/app/Http/Controllers/Controller.php",
            "$raiz_all/$nombre/app/Http/Controllers/Controller.php"
        );
    }
    automation_copiar_archivo(
        $origenes['pracExaLav/backend'] . '/config/services.php',
        "$raiz_all/Backend/config/services.php"
    );
    $iterador_migraciones = new DirectoryIterator($origenes['pracExaLav/backend'] . '/database/migrations');
    foreach ($iterador_migraciones as $migracion) {
        if ($migracion->isFile()) {
            automation_assert(
                automation_copiar_archivo(
                    $migracion->getPathname(),
                    "$raiz_all/Backend/database/migrations/" . $migracion->getFilename()
                ),
                'No se pudo preparar una migración para la prueba integrada.'
            );
        }
    }
    $proceso = proc_open(
        [PHP_BINARY, $crud_all . '/generate.php', 'all'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $raiz_all
    );
    automation_assert(is_resource($proceso), 'No se pudo ejecutar all con proyectos temporales compatibles.');
    $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    automation_assert(
        $codigo === 0
            && str_contains($salida, 'Backend generado sin errores')
            && str_contains($salida, 'Frontend generado sin errores')
            && str_contains($salida, 'API: http://127.0.0.1:8001/api')
            && str_contains($salida, 'CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET')
            && str_contains($salida, 'declarado, no instalado')
            && !str_contains($salida, 'Ajustar Frontend/.env')
            && !str_contains($salida, 'composer require cloudinary'),
        'all no completó generación o informó configuración API/Cloudinary redundante. Salida: ' . $salida
    );
    preg_match_all(
        '/^CLOUDINARY_(?:CLOUD_NAME|API_KEY|API_SECRET)=$/m',
        file_get_contents("$raiz_all/Backend/.env"),
        $variables_cloudinary_vacias
    );
    automation_assert(
        str_contains(file_get_contents("$raiz_all/Frontend/.env"), 'API_BASE_URL=http://127.0.0.1:8001/api')
            && count($variables_cloudinary_vacias[0]) === 3,
        'all no alineó la API o copió valores Cloudinary desde la referencia.'
    );
    echo "OK: all genera Backend/Frontend en una copia temporal y prepara .env sin reutilizar valores de referencia\n";

    foreach (['Backend', 'Frontend'] as $nombre) {
        mkdir("$raiz_prueba/$nombre/app", 0777, true);
        mkdir("$raiz_prueba/$nombre/vendor/laravel/framework", 0777, true);
        file_put_contents("$raiz_prueba/$nombre/composer.json", '{"require":{"php":"^8.0","laravel/framework":"^11.0"}}');
        file_put_contents("$raiz_prueba/$nombre/artisan", "<?php\n");
    }
    $config_path = $crud_prueba . '/config.php';
    $config = file_get_contents($config_path);
    $config = str_replace("'puerto_frontend' => 8002", "'puerto_frontend' => 8001", $config);
    file_put_contents($config_path, $config);
    $proceso = proc_open(
        [PHP_BINARY, $crud_prueba . '/generate.php', 'start'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $raiz_prueba
    );
    automation_assert(is_resource($proceso), 'No se pudo ejecutar start para probar los puertos duplicados.');
    $salida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proceso);
    automation_assert(
        $codigo !== 0 && str_contains($salida, 'no pueden utilizar el mismo puerto'),
        'start no rechazó explícitamente puertos duplicados.'
    );
    echo "OK: start valida estructura, dependencias y puertos antes de lanzar servicios\n";

    $raiz_guardia = $fixture . '/source-guard';
    mkdir($raiz_guardia . '/Backend/app/Models', 0777, true);
    mkdir($raiz_guardia . '/Backend/app/Http/Controllers', 0777, true);
    mkdir($raiz_guardia . '/Backend/routes', 0777, true);
    mkdir($raiz_guardia . '/Frontend/routes', 0777, true);
    mkdir($raiz_guardia . '/Frontend/resources/views/layouts', 0777, true);
    foreach ([
        '/Backend/routes/api.php',
        '/Backend/routes/web.php',
        '/Frontend/routes/web.php',
        '/Frontend/resources/views/layouts/app.blade.php',
        '/Frontend/resources/views/home.blade.php',
    ] as $archivo) {
        file_put_contents($raiz_guardia . $archivo, "<?php\n");
    }
    define('RAIZ', $raiz_guardia);
    $fuentes_destino = crud_fuentes_disponibles([
        'fuente_backend' => 'Backend',
        'fuente_frontend' => 'Frontend',
    ]);
    automation_assert(
        $fuentes_destino['backend'] === null && $fuentes_destino['frontend'] === null,
        'Se permitió que una fuente original coincida con el proyecto destino.'
    );
    echo "OK: all rechaza fuentes que coinciden o se solapan con destinos\n";
} finally {
    automation_limpiar($fixture);
}
