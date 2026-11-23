<?php
// Configuración del generador CRUD.
// Modifique estos valores solo si lo necesita: los valores por defecto sirven para la mayoría de proyectos.

return [
    // Nombre del proyecto para los dominios locales ({proyecto}-backend.test).
    // Vacío = se usa el nombre de la carpeta raíz del proyecto.
    'proyecto' => '',

    // Tablas para las que NO se genera CRUD.
    // Quite 'users' de la lista si quiere generar CRUD para esa tabla.
    'tablas_excluidas' => [
        'users',
        'migrations',
        'password_reset_tokens',
        'password_resets',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'personal_access_tokens',
    ],

    // Nombres de columnas que se consideran imágenes.
    // El asterisco (*) significa "cualquier texto". Ejemplo: '*_imagen' coincide con 'foto_imagen'.
    'campos_imagen' => [
        'imagen', 'image', 'foto', 'photo', 'avatar', 'logo', 'portada', 'banner', 'miniatura',
        'imagen_*', '*_imagen', 'foto_*', '*_foto', 'img_*',
    ],

    // Nombre de clase forzado para tablas cuyo nombre no se puede convertir bien a singular.
    // Formato: 'nombre_tabla' => 'NombreClase'
    'clases' => [
        // 'tbl_cliente_data' => 'Cliente',
    ],

];
