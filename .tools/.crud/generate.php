<?php
// Punto de entrada del generador CRUD.
// Se ejecuta desde generar.bat:  generar back  |  generar front

if (PHP_SAPI !== 'cli') {
    exit("Este script solo se puede ejecutar desde la consola.\n");
}

if (PHP_VERSION_ID < 80000) {
    echo "[ERROR] Se requiere PHP 8.0 o superior. Versión actual: " . PHP_VERSION . PHP_EOL;
    exit(2);
}

// Carpeta del generador (.tools/.crud).
define('CRUD_DIR', __DIR__);

require CRUD_DIR . '/src/util.php';
require CRUD_DIR . '/src/source_adaptation.php';
require CRUD_DIR . '/src/laravel.php';
require CRUD_DIR . '/src/migraciones.php';
require CRUD_DIR . '/src/hosts.php';
require CRUD_DIR . '/src/backend.php';
require CRUD_DIR . '/src/frontend.php';
require CRUD_DIR . '/src/automation.php';

$config = require CRUD_DIR . '/config.php';
if (!is_array($config)) {
    mostrar_error('config.php no devuelve una configuración válida.');
    exit(2);
}
$api_base_url = crud_api_base_url($config);
if ($api_base_url !== null) {
    $config['frontend_api_base_url'] = $api_base_url;
}

function crud_rutas_coinciden($ruta_a, $ruta_b)
{
    $ruta_a = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta_a), DIRECTORY_SEPARATOR);
    $ruta_b = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta_b), DIRECTORY_SEPARATOR);

    if (DIRECTORY_SEPARATOR === '\\') {
        return strcasecmp($ruta_a, $ruta_b) === 0;
    }

    return $ruta_a === $ruta_b;
}

function crud_ruta_dentro_de_raiz($ruta, $raiz)
{
    $ruta = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);
    $raiz = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $raiz), '/\\');
    $prefijo = ($raiz === '' ? DIRECTORY_SEPARATOR : $raiz . DIRECTORY_SEPARATOR);

    if (DIRECTORY_SEPARATOR === '\\') {
        return strncasecmp($ruta, $prefijo, strlen($prefijo)) === 0;
    }

    return str_starts_with($ruta, $prefijo);
}

function crud_resolver_directorio($nombre, &$error)
{
    global $config;
    $claves = [
        'Backend' => 'directorio_backend',
        'Frontend' => 'directorio_frontend',
    ];
    if (!isset($claves[$nombre])) {
        $error = 'Tipo de destino no válido.';
        return null;
    }

    $carpeta = $config[$claves[$nombre]] ?? strtolower($nombre);
    if (!is_string($carpeta) || trim($carpeta) === ''
        || $carpeta === '.' || $carpeta === '..'
        || str_contains($carpeta, '/') || str_contains($carpeta, '\\')) {
        $error = "La configuración {$claves[$nombre]} debe ser un nombre de carpeta relativo simple.";
        return null;
    }

    $ruta = RAIZ . DIRECTORY_SEPARATOR . $carpeta;
    $ruta_real = realpath($ruta);

    // Conserva compatibilidad con ejercicios que usan nombres de carpeta en mayúsculas iniciales.
    if ($ruta_real === false && $carpeta === strtolower($nombre)) {
        $ruta_real = realpath(RAIZ . DIRECTORY_SEPARATOR . $nombre);
    }

    if ($ruta_real === false || !is_dir($ruta_real)) {
        $error = 'No se encontró la carpeta ' . $carpeta . ' dentro del directorio padre del generador.';
        return null;
    }

    if (!crud_ruta_dentro_de_raiz($ruta_real, RAIZ)) {
        $error = 'Ruta insegura: la carpeta ' . $carpeta . ' resuelve fuera del directorio padre del generador. Ejecución detenida.';
        return null;
    }

    return $ruta_real;
}

try {
    $generador_calculado = realpath(dirname(CRUD_DIR, 2));
    $raiz_calculada = $generador_calculado === false ? false : realpath(dirname($generador_calculado));
    if ($raiz_calculada === false
        || !is_dir($raiz_calculada)
        || $generador_calculado === false
        || !is_dir($generador_calculado . DIRECTORY_SEPARATOR . '.tools')) {
        mostrar_error('No se pudo determinar la carpeta del generador y su directorio padre.');
        exit(2);
    }

    $generador_normalizado = rtrim($generador_calculado, '/\\');
    if ($generador_normalizado === '') {
        $generador_normalizado = DIRECTORY_SEPARATOR;
    }
    $raiz_normalizada = rtrim($raiz_calculada, '/\\');
    if ($raiz_normalizada === '') {
        $raiz_normalizada = DIRECTORY_SEPARATOR;
    }

    $crud_real = realpath(CRUD_DIR);
    $crud_esperado = rtrim($generador_normalizado, '/\\') . DIRECTORY_SEPARATOR . '.tools' . DIRECTORY_SEPARATOR . '.crud';
    if ($crud_real === false || !is_dir($crud_real) || !crud_rutas_coinciden($crud_real, $crud_esperado)) {
        mostrar_error('La ubicación de .tools/.crud no coincide con la estructura esperada del proyecto. Ejecución detenida.');
        exit(2);
    }

    define('GENERADOR_ROOT', $generador_normalizado);
    define('RAIZ', $raiz_normalizada);
    $comando = strtolower(trim($argv[1] ?? ''));
} catch (Throwable $e) {
    mostrar_error($e->getMessage());
    exit(2);
}

try {
    switch ($comando) {
        case 'all':
            exit(crud_ejecutar_all($config));

        case 'start':
            exit(crud_iniciar_servidores($config));

        case 'back':
            $error_ruta = null;
            $dir_back = crud_resolver_directorio('Backend', $error_ruta);
            if ($dir_back === null) {
                mostrar_error($error_ruta);
                exit(1);
            }

            // 1. Inspección de proyecto Laravel (Bloque 2)
            $info = inspeccionar_laravel($dir_back);
            if (!$info['valido']) {
                mostrar_error('La carpeta Backend no parece ser un proyecto Laravel válido.');
                if ($info['error']) {
                    mostrar_error($info['error']);
                }
                exit(1);
            }

            exito('Proyecto Backend encontrado');
            if ($info['laravel_origen'] === 'composer.lock') {
                exito('Laravel bloqueado en lock: ' . $info['laravel_version']);
            } else {
                aviso('Laravel declarado en composer.json: ' . $info['laravel_version']);
            }

            if ($info['dependencias_instaladas']) {
                exito('Dependencias Laravel detectadas en vendor/');
            } else {
                aviso('Dependencias de Laravel no encontradas en vendor/');
            }

            exito('PHP instalado: ' . $info['php_instalado']);
            exito('Estructura Laravel básica válida');

            foreach ($info['advertencias'] as $adv) {
                aviso($adv);
            }

            mensaje('');
            mensaje('Iniciando generación de Modelos y Controladores Backend...');

            // 2. Generación de Modelos y Controladores (Bloque 4)
            $resumen = generar_backend_crud($dir_back, $config);

            // Reporte de Modelos
            if (!empty($resumen['modelos'])) {
                mensaje('');
                mensaje('--- Modelos Eloquent ---');
                foreach ($resumen['modelos'] as $m) {
                    mensaje("  [{$m['estado']}] {$m['clase']} ({$m['archivo']})");
                }
            }

            // Reporte de Controladores
            if (!empty($resumen['controladores'])) {
                mensaje('');
                mensaje('--- Controladores API ---');
                foreach ($resumen['controladores'] as $c) {
                    mensaje("  [{$c['estado']}] {$c['clase']} ({$c['archivo']})");
                }
            }

            if (!empty($resumen['mapeo_fuentes'])) {
                mensaje('');
                mensaje('--- Fuentes backend y destinos ---');
                foreach ($resumen['mapeo_fuentes'] as $mapeo) {
                    mensaje("  [{$mapeo['tipo']}] {$mapeo['origen']} -> {$mapeo['destino']}");
                    mensaje("    Adaptaciones: {$mapeo['adaptaciones_permitidas']}");
                }
            }

            // Rutas API (Bloque 5)
            if (!empty($resumen['rutas_api'])) {
                mensaje('');
                mensaje('--- Rutas API (routes/api.php) ---');
                mensaje("  [{$resumen['rutas_api']}] Rutas API adaptadas desde la referencia");
            }
            if (!empty($resumen['rutas_web'])) {
                mensaje("  [{$resumen['rutas_web']}] routes/web.php (copia fuente exacta; sin CRUD Web en Backend)");
            }

            // Cloudinary (Bloque 5)
            if (!empty($resumen['cloudinary'])) {
                mensaje('');
                mensaje('--- Cloudinary ---');
                exito('Entrada Cloudinary integrada en config/services.php.');
            }

            // Configuración de entorno .env (Bloque 5)
            if (!empty($resumen['env_actualizado'])) {
                mensaje('');
                mensaje('--- Configuración .env ---');
                exito('APP_URL y, si aplica, nombres de variables Cloudinary verificados en Backend/.env; complete las credenciales manualmente.');
            }

            // Dominio Local Hosts (Bloque 5)
            if (!empty($resumen['hosts'])) {
                $h = $resumen['hosts'];
                mensaje('');
                mensaje('--- Dominio Local (.test) ---');
                if ($h['exito']) {
                    exito("Dominio local configurado: {$h['dominio']} -> {$h['ip']} [{$h['estado']}]");
                } elseif ($h['estado'] === 'requiere_admin') {
                    aviso("Dominio local: agregue '{$h['ip']} {$h['dominio']}' a su archivo hosts como Administrador.");
                }
            }

            // Tablas omitidas
            if (!empty($resumen['omitidas'])) {
                mensaje('');
                mensaje('--- Tablas omitidas ---');
                foreach ($resumen['omitidas'] as $om) {
                    aviso("Omitida: $om");
                }
            }

            // Advertencias del analizador de migraciones
            if (!empty($resumen['advertencias'])) {
                mensaje('');
                mensaje('--- Advertencias de migraciones ---');
                foreach ($resumen['advertencias'] as $adv_mig) {
                    aviso($adv_mig);
                }
            }

            if (!empty($resumen['adaptaciones_pendientes'])) {
                mensaje('');
                mensaje('--- Adaptaciones pendientes (sin cambios destructivos) ---');
                foreach ($resumen['adaptaciones_pendientes'] as $pendiente) {
                    $tabla = $pendiente['tabla'] === null ? '' : " [{$pendiente['tabla']}]";
                    aviso($pendiente['tipo'] . $tabla . ': ' . $pendiente['motivo']);
                }
            }

            // Errores
            if (!empty($resumen['errores'])) {
                mensaje('');
                mensaje('--- Errores ---');
                foreach ($resumen['errores'] as $err) {
                    mostrar_error($err);
                }
                exit(1);
            }

            if (!empty($resumen['adaptaciones_pendientes'])) {
                mensaje('');
                aviso('Backend adaptado parcialmente; resuelva las adaptaciones pendientes antes de considerarlo completo.');
                exit(1);
            }

            mensaje('');
            exito('Backend adaptado desde las fuentes originales de Modelos, Controladores y Rutas.');
            exit(0);

        case 'front':
            $error_ruta = null;
            $dir_front = crud_resolver_directorio('Frontend', $error_ruta);
            if ($dir_front === null) {
                mostrar_error($error_ruta);
                exit(1);
            }

            $info = inspeccionar_laravel($dir_front);
            if (!$info['valido']) {
                mostrar_error('La carpeta Frontend no parece ser un proyecto Laravel válido.');
                if ($info['error']) {
                    mostrar_error($info['error']);
                }
                exit(1);
            }

            exito('Proyecto Frontend encontrado');
            if ($info['laravel_origen'] === 'composer.lock') {
                exito('Laravel bloqueado en lock: ' . $info['laravel_version']);
            } else {
                aviso('Laravel declarado en composer.json: ' . $info['laravel_version']);
            }

            if ($info['dependencias_instaladas']) {
                exito('Dependencias Laravel detectadas en vendor/');
            } else {
                aviso('Dependencias de Laravel no encontradas en vendor/');
            }

            exito('PHP instalado: ' . $info['php_instalado']);
            exito('Estructura Laravel básica válida');

            foreach ($info['advertencias'] as $adv) {
                aviso($adv);
            }

            // Verificar existencia de Backend para obtener las tablas
            $error_ruta = null;
            $dir_back = crud_resolver_directorio('Backend', $error_ruta);
            if ($dir_back === null) {
                mostrar_error($error_ruta);
                exit(1);
            }

            $info_backend = inspeccionar_laravel($dir_back);
            if (!$info_backend['valido']) {
                mostrar_error('La carpeta Backend no parece ser un proyecto Laravel válido.');
                if ($info_backend['error']) {
                    mostrar_error($info_backend['error']);
                }
                exit(1);
            }

            foreach ($info_backend['advertencias'] as $adv) {
                if (stripos($adv, 'PHP') !== false) {
                    aviso($adv);
                }
            }

            mensaje('');
            mensaje('Iniciando generación de Frontend CRUD Blade...');

            $resumen = generar_frontend_crud($dir_front, $dir_back, $config);

            // Reporte de Controladores Web
            if (!empty($resumen['controladores'])) {
                mensaje('');
                mensaje('--- Controladores Web ---');
                foreach ($resumen['controladores'] as $c) {
                    mensaje("  [{$c['estado']}] {$c['clase']} ({$c['archivo']})");
                }
            }

            // Reporte de Vistas Blade
            if (!empty($resumen['vistas'])) {
                mensaje('');
                mensaje('--- Vistas Blade CRUD ---');
                foreach ($resumen['vistas'] as $v) {
                    mensaje("  [{$v['estado']}] {$v['vista']} ({$v['archivo']})");
                }
            }

            // Layout y CSS
            if (!empty($resumen['layout'])) {
                mensaje('');
                mensaje('--- Layout y Estilos ---');
                mensaje("  [{$resumen['layout']}] Layout base: resources/views/layouts/app.blade.php");
            }
            if (!empty($resumen['css'])) {
                mensaje("  [{$resumen['css']}] Estilos CSS originales: resources/css/app.css");
            }
            if (!empty($resumen['archivos_fuente'])) {
                mensaje('');
                mensaje('--- Archivos fuente frontend ---');
                foreach ($resumen['archivos_fuente'] as $archivo) {
                    mensaje("  [{$archivo['estado']}] {$archivo['origen']} -> {$archivo['destino']} (tokens: {$archivo['adaptaciones_aplicadas']})");
                }
            }

            // Rutas Web
            if (!empty($resumen['rutas_web'])) {
                mensaje('');
                mensaje('--- Rutas Web (routes/web.php) ---');
                mensaje("  [{$resumen['rutas_web']}] Rutas registradas con Route::resource()");
            }

            // Configuración .env
            if (!empty($resumen['env_actualizado'])) {
                mensaje('');
                mensaje('--- Configuración .env ---');
                exito('API_BASE_URL se agregó/completó en Frontend/.env sin sustituir otros valores.');
            }

            // Tablas omitidas
            if (!empty($resumen['omitidas'])) {
                mensaje('');
                mensaje('--- Tablas omitidas ---');
                foreach ($resumen['omitidas'] as $om) {
                    aviso("Omitida: $om");
                }
            }

            // Advertencias
            if (!empty($resumen['advertencias'])) {
                mensaje('');
                mensaje('--- Advertencias ---');
                foreach ($resumen['advertencias'] as $adv_mig) {
                    aviso($adv_mig);
                }
            }

            if (!empty($resumen['adaptaciones_pendientes'])) {
                mensaje('');
                mensaje('--- Adaptaciones pendientes (sin inventar etiquetas ni comportamiento) ---');
                foreach ($resumen['adaptaciones_pendientes'] as $pendiente) {
                    $tabla = $pendiente['tabla'] === null ? '' : " [{$pendiente['tabla']}]";
                    aviso($pendiente['tipo'] . $tabla . ': ' . $pendiente['motivo']);
                }
            }

            // Errores
            if (!empty($resumen['errores'])) {
                mensaje('');
                mensaje('--- Errores ---');
                foreach ($resumen['errores'] as $err) {
                    mostrar_error($err);
                }
                exit(1);
            }

            if (!empty($resumen['adaptaciones_pendientes'])) {
                mensaje('');
                aviso('Frontend adaptado parcialmente; complete los mapeos pendientes antes de considerarlo generado.');
                exit(1);
            }

            mensaje('');
            exito('Frontend adaptado desde pracExaLav/frontend; el diseño, las vistas, CSS y JavaScript proceden de las fuentes originales.');
            exit(0);

        default:
            if ($comando !== '') {
                mostrar_error("Comando no reconocido: '$comando'");
            }
            mensaje('');
            mensaje('Uso:');
            mensaje('  all             Verifica el proyecto y genera Backend y Frontend en orden.');
            mensaje('  generar back    Genera el Backend (API) a partir de las migraciones.');
            mensaje('  generar front   Genera el Frontend (Blade) que consume la API.');
            mensaje('  start           Inicia Backend y Frontend, comprobando puertos y disponibilidad.');
            exit(1);
    }
} catch (Throwable $e) {
    mostrar_error($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    exit(2);
}
