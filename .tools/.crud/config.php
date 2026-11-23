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
        'imagen', 'image', 'foto', 'fotografia', 'photo', 'avatar', 'logo', 'portada', 'banner', 'miniatura',
        'imagen_*', '*_imagen', 'foto_*', '*_foto', 'img_*',
    ],

    // Nombre de clase forzado para tablas cuyo nombre no se puede convertir bien a singular.
    // Formato: 'nombre_tabla' => 'NombreClase'
    'clases' => [
        // 'tbl_cliente_data' => 'Cliente',
    ],

    // Proyectos destino, relativos al directorio padre de la carpeta del generador.
    'directorio_backend' => 'backend',
    'directorio_frontend' => 'frontend',

    // Proyectos de referencia, relativos a la carpeta del generador.
    'fuente_backend' => 'pracExaLav/backend',

    // Proyecto frontend original. Se lee como fuente y nunca se modifica.
    'fuente_frontend' => 'pracExaLav/frontend',

    // Texto completo de la introducción original de Home. Para una adaptación
    // se cambia el destino explícitamente; el texto fuente se valida byte a byte.
    'frontend_nombre_app_fuente' => 'Gestión Académica',
    'frontend_nombre_app' => 'Gestión Académica',
    'frontend_home_parrafo_fuente' => 'Administra desde un solo lugar la información de docentes, aprendices y módulos del sistema.',
    'frontend_home_parrafo' => 'Administra desde un solo lugar la información de docentes, aprendices y módulos del sistema.',
    'frontend_api_base_url' => 'http://127.0.0.1:8001/api',

    // Puertos de los servidores artisan serve. El puerto Backend también se
    // utiliza para derivar frontend_api_base_url sin cambiar su host/ruta.
    'host_local' => '127.0.0.1',
    'puerto_backend' => 8001,
    'puerto_frontend' => 8002,

    // Mapeos explícitos de entidad sobre controladores y vistas reales de pracExaLav.
    // Una entidad nueva debe mapear todos los campos, en orden, a una fuente
    // compatible. Las diferencias de estructura quedan pendientes, no se inventan.
    'adaptaciones_frontend' => [
        'students' => [
            'fuente' => 'students',
            'clase_fuente' => 'Student',
            'singular_tecnico' => 'student',
            'plural_tecnico' => 'students',
            'clase_controlador' => 'StudentController',
            'ruta' => 'students',
            'api_url' => 'students',
            'variable_lista' => 'students',
            'variable_item' => 'student',
            'singular_interfaz' => 'Aprendiz',
            'plural_interfaz' => 'Aprendices',
            'nav_etiqueta' => 'Aprendices',
            'home' => [
                'titulo' => 'Aprendices',
                'descripcion' => 'Consulta y administra los registros de aprendices.',
                'enlace' => 'Ir a aprendices',
            ],
            'campos' => [
                'nombre' => ['fuente' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
                'apellido' => ['fuente' => 'apellido', 'etiqueta' => 'Apellido', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
                'fechaNacimiento' => ['fuente' => 'fechaNacimiento', 'etiqueta' => 'Fecha de nacimiento', 'tipo' => 'date', 'control' => 'date', 'imagen' => false],
                'expediente' => ['fuente' => 'expediente', 'etiqueta' => 'Expediente', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
                'fotografia' => ['fuente' => 'fotografia', 'etiqueta' => 'Fotografía', 'tipo' => 'string', 'control' => 'file', 'imagen' => true],
                'module_id' => ['fuente' => 'module_id', 'etiqueta' => 'ID del módulo', 'tipo' => 'foreignId', 'control' => 'select', 'imagen' => false, 'relacion' => 'module_id'],
            ],
            'relaciones' => [
                'module_id' => [
                    'fuente' => 'modules', 'tabla' => 'modules', 'api_url' => 'modules',
                    'variable_lista' => 'modules', 'variable_item' => 'module',
                    'campo_etiqueta' => 'nombre', 'etiqueta' => 'Módulo',
                ],
            ],
            'textos' => [],
            'textos_requeridos' => ['Aprendices', 'aprendiz', 'aprendices', 'Estudiante', 'estudiante'],
        ],
        'teachers' => [
            'fuente' => 'teachers',
            'clase_fuente' => 'Teacher',
            'singular_tecnico' => 'teacher',
            'plural_tecnico' => 'teachers',
            'clase_controlador' => 'TeacherController',
            'ruta' => 'teachers',
            'api_url' => 'teachers',
            'variable_lista' => 'teachers',
            'variable_item' => 'teacher',
            'singular_interfaz' => 'Docente',
            'plural_interfaz' => 'Docentes',
            'nav_etiqueta' => 'Docentes',
            'home' => [
                'titulo' => 'Docentes',
                'descripcion' => 'Consulta y administra los registros de docentes.',
                'enlace' => 'Ir a docentes',
            ],
            'campos' => [
                'nombre' => ['fuente' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
                'apellido' => ['fuente' => 'apellido', 'etiqueta' => 'Apellido', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
                'telefono' => ['fuente' => 'telefono', 'etiqueta' => 'Teléfono', 'tipo' => 'integer', 'control' => 'text', 'imagen' => false],
                'cedula' => ['fuente' => 'cedula', 'etiqueta' => 'Cédula', 'tipo' => 'integer', 'control' => 'text', 'imagen' => false],
            ],
            'relaciones' => [],
            'textos' => [],
            'textos_requeridos' => ['Docente', 'Docentes', 'docente', 'docentes'],
        ],
        'modules' => [
            'fuente' => 'modules',
            'clase_fuente' => 'Module',
            'singular_tecnico' => 'module',
            'plural_tecnico' => 'modules',
            'clase_controlador' => 'ModuleController',
            'ruta' => 'modules',
            'api_url' => 'modules',
            'variable_lista' => 'modules',
            'variable_item' => 'module',
            'singular_interfaz' => 'Módulo',
            'plural_interfaz' => 'Módulos',
            'nav_etiqueta' => 'Módulos',
            'home' => [
                'titulo' => 'Módulos',
                'descripcion' => 'Consulta y administra los módulos académicos.',
                'enlace' => 'Ir a módulos',
            ],
            'campos' => [
                'codigo' => ['fuente' => 'codigo', 'etiqueta' => 'Código', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
                'nombre' => ['fuente' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
            ],
            'relaciones' => [],
            'textos' => [],
            'textos_requeridos' => ['Módulo', 'Módulos', 'módulo', 'módulos'],
        ],
    ],

    // Adaptaciones autorizadas para entidades cuyo esquema no coincide por nombre
    // con los campos de los controladores originales.
    // Los valores de "campos" son: campo destino => campo de la fuente API.
    // "validaciones" declara explícitamente reglas para campos nuevos del destino.
    'adaptaciones_backend' => [
        // 'products' => [
        //     'modelo' => 'ModuleTeacher.php',
        //     'controlador_api' => 'ModuleController.php',
        //     'campos' => ['name' => 'nombre', 'sku' => 'codigo'],
        //     'validaciones' => ['description' => 'nullable|string'],
        // ],
    ],

];
