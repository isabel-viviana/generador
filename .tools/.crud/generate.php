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
require CRUD_DIR . '/src/laravel.php';
require CRUD_DIR . '/src/migraciones.php';
require CRUD_DIR . '/src/hosts.php';
require CRUD_DIR . '/src/backend.php';
require CRUD_DIR . '/src/frontend.php';

$config = require CRUD_DIR . '/config.php';
if (!is_array($config)) {
    mostrar_error('config.php no devuelve una configuración válida.');
    exit(2);
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
    $ruta = RAIZ . DIRECTORY_SEPARATOR . $nombre;
    $ruta_real = realpath($ruta);

    if ($ruta_real === false || !is_dir($ruta_real)) {
        $error = 'No se encontró la carpeta ' . $nombre . ' dentro de la raíz del proyecto.';
        return null;
    }

    if (!crud_ruta_dentro_de_raiz($ruta_real, RAIZ)) {
        $error = 'Ruta insegura: la carpeta ' . $nombre . ' resuelve fuera de la raíz del proyecto. Ejecución detenida.';
        return null;
    }

    return $ruta_real;
}

try {
    $raiz_calculada = realpath(dirname(CRUD_DIR, 2));
    if ($raiz_calculada === false
        || !is_dir($raiz_calculada)
        || !is_dir($raiz_calculada . DIRECTORY_SEPARATOR . '.tools')) {
        mostrar_error('No se pudo determinar la raíz del proyecto contenedor de .tools.');
        exit(2);
    }

    $raiz_normalizada = rtrim($raiz_calculada, '/\\');
    if ($raiz_normalizada === '') {
        $raiz_normalizada = DIRECTORY_SEPARATOR;
    }

    $crud_real = realpath(CRUD_DIR);
    $crud_esperado = rtrim($raiz_normalizada, '/\\') . DIRECTORY_SEPARATOR . '.tools' . DIRECTORY_SEPARATOR . '.crud';
    if ($crud_real === false || !is_dir($crud_real) || !crud_rutas_coinciden($crud_real, $crud_esperado)) {
        mostrar_error('La ubicación de .tools/.crud no coincide con la estructura esperada del proyecto. Ejecución detenida.');
        exit(2);
    }

    define('RAIZ', $raiz_normalizada);
    $comando = strtolower(trim($argv[1] ?? ''));
} catch (Throwable $e) {
    mostrar_error($e->getMessage());
    exit(2);
}

try {
    switch ($comando) {
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

            // Rutas API (Bloque 5)
            if (!empty($resumen['rutas_api'])) {
                mensaje('');
                mensaje('--- Rutas API (routes/api.php) ---');
                mensaje("  [{$resumen['rutas_api']}] Bloque de rutas API registrado con Route::apiResource()");
            }

            // Cloudinary (Bloque 5)
            if (!empty($resumen['cloudinary'])) {
                mensaje('');
                mensaje('--- Cloudinary ---');
                exito('Helper App\Support\Cloudinary asegurado.');
                exito('Entrada de servicio cloudinary configurada en config/services.php.');
            }

            // Configuración de entorno .env (Bloque 5)
            if (!empty($resumen['env_actualizado'])) {
                mensaje('');
                mensaje('--- Configuración .env ---');
                exito('Variables APP_URL y credenciales de Cloudinary actualizadas en Backend/.env.');
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

            // Errores
            if (!empty($resumen['errores'])) {
                mensaje('');
                mensaje('--- Errores ---');
                foreach ($resumen['errores'] as $err) {
                    mostrar_error($err);
                }
                exit(1);
            }

            mensaje('');
            exito('Backend generado correctamente (Modelos, Controladores, Rutas y Configuración).');
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
                mensaje("  [{$resumen['css']}] Estilos CSS: public/css/crud.css");
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
                exito('Variables BACKEND_API_URL y APP_URL actualizadas en Frontend/.env.');
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

            // Errores
            if (!empty($resumen['errores'])) {
                mensaje('');
                mensaje('--- Errores ---');
                foreach ($resumen['errores'] as $err) {
                    mostrar_error($err);
                }
                exit(1);
            }

            mensaje('');
            exito('Frontend generado correctamente (Controladores Web, Vistas Blade, CSS y Rutas).');
            exit(0);

        default:
            if ($comando !== '') {
                mostrar_error("Comando no reconocido: '$comando'");
            }
            mensaje('');
            mensaje('Uso:');
            mensaje('  generar back    Genera el Backend (API) a partir de las migraciones.');
            mensaje('  generar front   Genera el Frontend (Blade) que consume la API.');
            exit(1);
    }
} catch (Throwable $e) {
    mostrar_error($e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    exit(2);
}
