<?php

if (!defined('CRUD_DIR')) {
    define('CRUD_DIR', dirname(__DIR__));
}

require_once CRUD_DIR . '/src/util.php';
require_once CRUD_DIR . '/src/migraciones.php';
require_once CRUD_DIR . '/src/frontend_source.php';

function es_tabla_elegible_front($nombre_tabla, $tabla_info, $config)
{
    $exclusiones_legacy = [
        'users', 'migrations', 'password_reset_tokens', 'password_resets',
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'personal_access_tokens',
    ];
    $tipo_clasificacion = $tabla_info['classification']['type'] ?? null;
    $tiene_exclusiones_explicitas = isset($config['tablas_excluidas']);
    $excluidas = $config['tablas_excluidas']
        ?? ($tipo_clasificacion === null ? $exclusiones_legacy : []);

    if (in_array($nombre_tabla, $excluidas, true)) {
        return false;
    }

    if (in_array($tipo_clasificacion, ['pure_pivot', 'associative_entity'], true)
        || ($tipo_clasificacion === 'framework_auxiliary' && !$tiene_exclusiones_explicitas)) {
        return false;
    }
    if ($tipo_clasificacion === null && !empty($tabla_info['es_pivote'])) {
        return false;
    }

    $clase = nombre_clase($nombre_tabla, $config);
    if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $clase)) {
        return false;
    }

    $palabras_reservadas = [
        'class', 'interface', 'trait', 'enum', 'function', 'fn', 'namespace',
        'self', 'static', 'parent', 'readonly', 'void', 'never', 'null', 'true', 'false',
    ];

    return !in_array(strtolower($clase), $palabras_reservadas, true);
}

function generar_frontend_crud($ruta_frontend, $ruta_backend, $config)
{
    return generar_frontend_desde_fuentes($ruta_frontend, $ruta_backend, $config);
}
