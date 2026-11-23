<?php

require_once __DIR__ . '/../src/frontend.php';

function frontend_fuente_assert($condicion, $mensaje)
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function frontend_fuente_fixture()
{
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-frontend-source-' . bin2hex(random_bytes(6));
    mkdir($ruta . '/Backend/database/migrations', 0777, true);
    mkdir($ruta . '/Frontend', 0777, true);
    file_put_contents($ruta . '/Frontend/.env', "APP_NAME=Test\nAPI_BASE_URL=\n");
    return $ruta;
}

function frontend_fuente_limpiar($ruta)
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

function frontend_fuente_php_lint($archivo)
{
    $salida = [];
    $codigo = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($archivo), $salida, $codigo);
    frontend_fuente_assert($codigo === 0, 'PHP generado no compila: ' . implode(' ', $salida));
}

function frontend_fuente_migraciones_referencia($backend, $fuente)
{
    foreach (glob($fuente . '/backend/database/migrations/2026_11_20_*.php') ?: [] as $migracion) {
        copy($migracion, $backend . '/database/migrations/' . basename($migracion));
    }
}

function frontend_fuente_configuracion($home_parrafo = null)
{
    $config = require __DIR__ . '/../config.php';
    $config['fuente_frontend'] = 'pracExaLav/frontend';
    $config['frontend_home_parrafo'] = $home_parrafo
        ?? $config['frontend_home_parrafo_fuente'];
    return $config;
}

$raiz_repositorio = dirname(__DIR__, 3);
$fuente = realpath($raiz_repositorio . '/pracExaLav');
frontend_fuente_assert($fuente !== false, 'No se encontró la fuente pracExaLav.');
$fuente_frontend = $fuente . '/frontend';
$huellas = [];
foreach ([
    'routes/web.php',
    'resources/views/layouts/app.blade.php',
    'resources/views/home.blade.php',
    'resources/css/app.css',
    'resources/js/app.js',
    'public/favicon.ico',
    'app/Http/Controllers/StudentController.php',
    'app/Http/Controllers/TeacherController.php',
    'app/Http/Controllers/ModuleController.php',
    'resources/views/students/index.blade.php',
    'resources/views/students/create.blade.php',
    'resources/views/students/edit.blade.php',
    'resources/views/students/show.blade.php',
    'resources/views/teachers/index.blade.php',
    'resources/views/teachers/create.blade.php',
    'resources/views/teachers/edit.blade.php',
    'resources/views/teachers/show.blade.php',
    'resources/views/modules/index.blade.php',
    'resources/views/modules/create.blade.php',
    'resources/views/modules/edit.blade.php',
    'resources/views/modules/show.blade.php',
] as $relativo) {
    $huellas[$relativo] = hash_file('sha256', $fuente_frontend . '/' . $relativo);
}

$fixture = frontend_fuente_fixture();
$backend = $fixture . '/Backend';
$frontend = $fixture . '/Frontend';
try {
    frontend_fuente_migraciones_referencia($backend, $fuente);
    $config = frontend_fuente_configuracion();
    $primera = generar_frontend_crud($frontend, $backend, $config);
    frontend_fuente_assert($primera['errores'] === [], 'Errores al adaptar el frontend original: ' . implode(' | ', $primera['errores']));
    frontend_fuente_assert($primera['adaptaciones_pendientes'] === [], 'Quedaron adaptaciones pendientes para las entidades originales: ' . json_encode($primera, JSON_UNESCAPED_UNICODE));
    frontend_fuente_assert(count($primera['controladores']) === 3 && count($primera['vistas']) === 12, 'No se copiaron los controladores y las doce vistas de referencia.');

    foreach (['Student', 'Teacher', 'Module'] as $clase) {
        $recurso = strtolower($clase) . 's';
        $controlador = "app/Http/Controllers/{$clase}Controller.php";
        $controlador_original = file_get_contents($fuente_frontend . '/' . $controlador);
        $controlador_esperado = str_replace(
            '$response->throw();',
            "if (\$response->notFound()) {\n            abort(404);\n        }\n        \$response->throw();",
            $controlador_original,
            $reemplazos_404
        );
        frontend_fuente_assert(
            $reemplazos_404 === 1
                && file_get_contents($frontend . '/' . $controlador) === $controlador_esperado,
            "El controlador $clase no preservó su contenido salvo el manejo de respuesta 404."
        );
        frontend_fuente_assert(
            str_contains(file_get_contents($frontend . '/' . $controlador), '$response->notFound()')
                && str_contains(file_get_contents($frontend . '/' . $controlador), 'abort(404);'),
            "El controlador $clase no propaga 404 de la API."
        );
        frontend_fuente_php_lint($frontend . '/' . $controlador);
        foreach (['index', 'create', 'edit', 'show'] as $vista) {
            $relativo = "resources/views/$recurso/$vista.blade.php";
            frontend_fuente_assert(
                file_get_contents($frontend . '/' . $relativo) === file_get_contents($fuente_frontend . '/' . $relativo),
                "La vista original $relativo no se conservó byte por byte."
            );
        }
        frontend_fuente_assert(
            str_contains(file_get_contents($frontend . '/.env'), 'API_BASE_URL=http://127.0.0.1:8001/api'),
            'No se completó el API_BASE_URL vacío sin reemplazar el resto del .env.'
        );
    }
    foreach ([
        'routes/web.php',
        'resources/views/layouts/app.blade.php',
        'resources/views/home.blade.php',
        'resources/css/app.css',
        'resources/js/app.js',
        'public/favicon.ico',
    ] as $relativo) {
        frontend_fuente_assert(
            file_get_contents($frontend . '/' . $relativo) === file_get_contents($fuente_frontend . '/' . $relativo),
            "$relativo no es idéntico a la fuente para las entidades originales."
        );
    }
    frontend_fuente_assert(!is_dir($frontend . '/resources/views/crud'), 'Se creó un directorio CRUD paralelo.');
    $student_controller = file_get_contents($frontend . '/app/Http/Controllers/StudentController.php');
    $student_create = file_get_contents($frontend . '/resources/views/students/create.blade.php');
    $student_edit = file_get_contents($frontend . '/resources/views/students/edit.blade.php');
    frontend_fuente_assert(str_contains($student_controller, "fetchDataFromApi(\$url . '/modules')"), 'Se perdió la consulta API para la relación fuente.');
    frontend_fuente_assert(str_contains($student_controller, 'Http::attach(') && str_contains($student_controller, "'_method' => 'PUT'"), 'Se perdió el envío multipart de imágenes o la actualización del recurso.');
    frontend_fuente_assert(str_contains($student_create, 'name="fotografia"') && str_contains($student_create, 'name="module_id"'), 'El formulario original perdió imagen o relación.');
    frontend_fuente_assert(str_contains($student_edit, "$" . "student['fotografia']") && str_contains($student_edit, '@method(\'PUT\')'), 'La edición no conserva la vista de imagen o el método HTTP.');
    $rutas_originales = file_get_contents($frontend . '/routes/web.php');
    foreach (['teachers', 'students', 'modules'] as $recurso) {
        frontend_fuente_assert(substr_count($rutas_originales, "Route::resource('$recurso'") === 1, "La ruta $recurso falta o está duplicada.");
    }

    $archivos_generados = [];
    foreach ($primera['archivos_fuente'] as $archivo) {
        $archivos_generados[$archivo['destino']] = hash_file('sha256', $archivo['destino']);
    }
    $segunda = generar_frontend_crud($frontend, $backend, $config);
    frontend_fuente_assert($segunda['errores'] === [], 'La segunda ejecución del frontend produjo errores.');
    frontend_fuente_assert(
        count(array_filter($segunda['archivos_fuente'], static fn($archivo) => $archivo['estado'] === 'sin_cambios')) === count($archivos_generados),
        'La segunda ejecución no fue idempotente para todos los archivos.'
    );
    foreach ($archivos_generados as $ruta => $hash) {
        frontend_fuente_assert(hash_file('sha256', $ruta) === $hash, "La segunda ejecución alteró $ruta.");
    }
    echo "OK: entidades de referencia, fidelidad byte a byte e idempotencia\n";
} finally {
    frontend_fuente_limpiar($fixture);
}

$fixture_producto = frontend_fuente_fixture();
$backend_producto = $fixture_producto . '/Backend';
$frontend_producto = $fixture_producto . '/Frontend';
file_put_contents($backend_producto . '/database/migrations/2026_11_23_000000_create_products.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('sku');
            $table->string('nombre');
            $table->timestamps();
        });
    }
};
MIGRATION
);

$config_producto = frontend_fuente_configuracion('Administra desde un solo lugar la información de productos.');
$config_producto['frontend_nombre_app'] = 'Gestión de Catálogo';
$config_producto['adaptaciones_frontend']['products'] = [
    'fuente' => 'modules',
    'clase_fuente' => 'Module',
    'singular_tecnico' => 'product',
    'plural_tecnico' => 'products',
    'clase_controlador' => 'ProductController',
    'ruta' => 'products',
    'api_url' => 'products',
    'variable_lista' => 'products',
    'variable_item' => 'product',
    'singular_interfaz' => 'Producto',
    'plural_interfaz' => 'Productos',
    'nav_etiqueta' => 'Productos',
    'home' => [
        'titulo' => 'Productos',
        'descripcion' => 'Consulta y administra el catálogo de productos.',
        'enlace' => 'Ir a productos',
    ],
    'campos' => [
        'sku' => ['fuente' => 'codigo', 'etiqueta' => 'SKU', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
        'nombre' => ['fuente' => 'nombre', 'etiqueta' => 'Título', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
    ],
    'relaciones' => [],
    'textos' => [
        'Módulos' => 'Productos',
        'módulos' => 'productos',
        'Módulo' => 'Producto',
        'módulo' => 'producto',
    ],
];

try {
    $generado = generar_frontend_crud($frontend_producto, $backend_producto, $config_producto);
    frontend_fuente_assert($generado['errores'] === [] && $generado['adaptaciones_pendientes'] === [], 'No se adaptó la entidad distinta: ' . json_encode($generado, JSON_UNESCAPED_UNICODE));
    $controlador_producto = file_get_contents($frontend_producto . '/app/Http/Controllers/ProductController.php');
    $vista_producto = file_get_contents($frontend_producto . '/resources/views/products/create.blade.php');
    frontend_fuente_assert(str_contains($controlador_producto, 'class ProductController'), 'No se adaptó la clase del controlador.');
    frontend_fuente_php_lint($frontend_producto . '/app/Http/Controllers/ProductController.php');
    frontend_fuente_assert(str_contains($controlador_producto, "'/products'") && str_contains($controlador_producto, "view('products.create'"), 'No se adaptaron URLs y nombres de vista.');
    frontend_fuente_assert(str_contains($controlador_producto, "'sku' =>") && str_contains($controlador_producto, "'nombre' =>"), 'No se mapearon los campos enviados y validados.');
    frontend_fuente_assert(!str_contains($controlador_producto, 'ModuleController') && !str_contains($controlador_producto, '/modules'), 'El controlador conserva referencias a la entidad fuente.');
    frontend_fuente_assert(str_contains($vista_producto, 'name="sku"') && str_contains($vista_producto, 'name="nombre"') && str_contains($vista_producto, '>Título</label>'), 'El formulario no adaptó los campos o la etiqueta explícita para un nombre conservado.');
    frontend_fuente_assert(str_contains($vista_producto, 'form-card') && str_contains($vista_producto, 'field-error'), 'El formulario perdió estructura o validación visual original.');
    frontend_fuente_assert(!str_contains($vista_producto, 'modules.') && !str_contains($vista_producto, 'Módulo'), 'La vista conserva referencias a la entidad fuente.');
    $home_producto = file_get_contents($frontend_producto . '/resources/views/home.blade.php');
    $layout_producto = file_get_contents($frontend_producto . '/resources/views/layouts/app.blade.php');
    $rutas_producto = file_get_contents($frontend_producto . '/routes/web.php');
    frontend_fuente_assert(str_contains($home_producto, "route('products.index')") && str_contains($home_producto, 'Consulta y administra el catálogo de productos.'), 'Home no conservó la estructura con el contenido explícito del recurso.');
    frontend_fuente_assert(str_contains($home_producto, '<h1>Gestión de Catálogo</h1>') && str_contains($layout_producto, '>Gestión de Catálogo</a>'), 'No se aplicó el nombre de interfaz explícito a Home y layout.');
    frontend_fuente_assert(str_contains($layout_producto, "route('products.index')") && !str_contains($layout_producto, "route('modules.index')"), 'La navegación conserva rutas residuales.');
    frontend_fuente_assert(substr_count($rutas_producto, "Route::resource('products'") === 1, 'La ruta del recurso falta o está duplicada.');
    frontend_fuente_assert(!str_contains($rutas_producto, 'ModuleController') && !str_contains($rutas_producto, "'modules'"), 'Las rutas conservan referencias fuente.');
    foreach (['resources/css/app.css', 'resources/js/app.js', 'public/favicon.ico'] as $relativo) {
        frontend_fuente_assert(file_get_contents($frontend_producto . '/' . $relativo) === file_get_contents($fuente_frontend . '/' . $relativo), "El asset $relativo no conserva los bytes fuente.");
    }
    frontend_fuente_assert(
        str_contains(file_get_contents($frontend_producto . '/.env'), 'API_BASE_URL=http://127.0.0.1:8001/api'),
        'La integración no configuró la URL base API requerida por el controlador fuente.'
    );
    echo "OK: nueva entidad products con alias de campos, etiquetas explícitas, rutas y estructura originales\n";
} finally {
    frontend_fuente_limpiar($fixture_producto);
}

$fixture_token_ausente = frontend_fuente_fixture();
$backend_token_ausente = $fixture_token_ausente . '/Backend';
$frontend_token_ausente = $fixture_token_ausente . '/Frontend';
file_put_contents($backend_token_ausente . '/database/migrations/2026_11_23_000000_create_products.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('sku');
            $table->string('nombre');
        });
    }
};
MIGRATION
);
$config_token_ausente = $config_producto;
$config_token_ausente['adaptaciones_frontend']['products']['textos']['Token que no existe'] = 'No debe generarse';
try {
    $pendiente_token = generar_frontend_crud($frontend_token_ausente, $backend_token_ausente, $config_token_ausente);
    frontend_fuente_assert(
        count($pendiente_token['adaptaciones_pendientes']) === 1
            && str_contains($pendiente_token['adaptaciones_pendientes'][0]['motivo'], 'Token que no existe')
            && !is_file($frontend_token_ausente . '/app/Http/Controllers/ProductController.php'),
        'Un token literal ausente debe reportarse sin generar una adaptación parcial.'
    );
    echo "OK: tokens no encontrados se reportan y no producen archivos parciales\n";
} finally {
    frontend_fuente_limpiar($fixture_token_ausente);
}

$fixture_rutas = frontend_fuente_fixture();
$backend_rutas = $fixture_rutas . '/Backend';
$frontend_rutas = $fixture_rutas . '/Frontend';
file_put_contents($backend_rutas . '/database/migrations/2026_11_23_000002_create_products.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('sku');
            $table->string('nombre');
        });
    }
};
MIGRATION
);
mkdir($frontend_rutas . '/routes', 0777, true);
$ruta_manual = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/health', fn () => response()->json(['ok' => true]));\n";
file_put_contents($frontend_rutas . '/routes/web.php', $ruta_manual);
try {
    $integracion = generar_frontend_crud($frontend_rutas, $backend_rutas, $config_producto);
    frontend_fuente_assert($integracion['errores'] === [], 'No se integraron rutas en un web.php manual sin colisiones: ' . json_encode($integracion['errores']));
    $rutas_integradas = file_get_contents($frontend_rutas . '/routes/web.php');
    frontend_fuente_assert(str_contains($rutas_integradas, "Route::get('/health'"), 'La integración alteró la ruta manual de salud.');
    frontend_fuente_assert(substr_count($rutas_integradas, "Route::resource('products'") === 1, 'No se añadió exactamente una ruta resource.');
    frontend_fuente_assert(str_contains($rutas_integradas, "Route::view('/', 'home')->name('home');"), 'La Home original no se registró.');
    $segunda_integracion = generar_frontend_crud($frontend_rutas, $backend_rutas, $config_producto);
    frontend_fuente_assert($segunda_integracion['errores'] === [], 'La segunda integración de rutas produjo errores.');
    frontend_fuente_assert(file_get_contents($frontend_rutas . '/routes/web.php') === $rutas_integradas, 'La integración de rutas no es idempotente.');
    frontend_fuente_assert(substr_count($rutas_integradas, "Route::resource('products'") === 1, 'La segunda ejecución duplicó la ruta.');
    echo "OK: integración de rutas manuales aditiva, preservada e idempotente\n";
} finally {
    frontend_fuente_limpiar($fixture_rutas);
}

$fixture_laravel = frontend_fuente_fixture();
$backend_laravel = $fixture_laravel . '/Backend';
$frontend_laravel = $fixture_laravel . '/Frontend';
file_put_contents($backend_laravel . '/database/migrations/2026_11_23_000004_create_products.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('sku');
            $table->string('nombre');
        });
    }
};
MIGRATION
);
mkdir($frontend_laravel . '/routes', 0777, true);
$ruta_bienvenida_laravel = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', function () {\n    return view('welcome');\n});\n";
file_put_contents($frontend_laravel . '/routes/web.php', $ruta_bienvenida_laravel);
try {
    $adaptacion_laravel = generar_frontend_crud($frontend_laravel, $backend_laravel, $config_producto);
    frontend_fuente_assert($adaptacion_laravel['errores'] === [], 'No se integró la Home sobre la ruta welcome predeterminada de Laravel: ' . json_encode($adaptacion_laravel['errores']));
    $rutas_laravel = file_get_contents($frontend_laravel . '/routes/web.php');
    frontend_fuente_assert(!str_contains($rutas_laravel, "view('welcome')")
        && substr_count($rutas_laravel, "Route::view('/', 'home')->name('home');") === 1
        && substr_count($rutas_laravel, "Route::resource('products'") === 1,
        'La integración no sustituyó únicamente la Home welcome predeterminada.');
    $segunda_adaptacion_laravel = generar_frontend_crud($frontend_laravel, $backend_laravel, $config_producto);
    frontend_fuente_assert($segunda_adaptacion_laravel['errores'] === []
        && file_get_contents($frontend_laravel . '/routes/web.php') === $rutas_laravel,
        'La integración Laravel no fue idempotente.');
    echo "OK: reemplazo seguro de la ruta welcome predeterminada de Laravel e idempotencia\n";
} finally {
    frontend_fuente_limpiar($fixture_laravel);
}

$fixture_home_manual = frontend_fuente_fixture();
$backend_home_manual = $fixture_home_manual . '/Backend';
$frontend_home_manual = $fixture_home_manual . '/Frontend';
file_put_contents($backend_home_manual . '/database/migrations/2026_11_23_000005_create_products.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('sku');
            $table->string('nombre');
        });
    }
};
MIGRATION
);
mkdir($frontend_home_manual . '/routes', 0777, true);
$ruta_home_manual = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => response('manual'));";
file_put_contents($frontend_home_manual . '/routes/web.php', $ruta_home_manual);
try {
    $conflicto_home_manual = generar_frontend_crud($frontend_home_manual, $backend_home_manual, $config_producto);
    frontend_fuente_assert(count($conflicto_home_manual['errores']) === 1
        && str_contains($conflicto_home_manual['errores'][0], 'ya existe una ruta raíz manual distinta')
        && file_get_contents($frontend_home_manual . '/routes/web.php') === $ruta_home_manual,
        'Una Home manual se alteró o no se notificó el conflicto.');
    echo "OK: las rutas raíz manuales incompatibles permanecen intactas y se reportan\n";
} finally {
    frontend_fuente_limpiar($fixture_home_manual);
}

$fixture_relaciones = frontend_fuente_fixture();
$backend_relaciones = $fixture_relaciones . '/Backend';
$frontend_relaciones = $fixture_relaciones . '/Frontend';
file_put_contents($backend_relaciones . '/database/migrations/2026_11_23_000003_create_courses_and_enrollments.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('courses', function ($table) {
            $table->id();
            $table->string('sku');
            $table->string('title');
        });
        Schema::create('enrollments', function ($table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date');
            $table->string('file_code');
            $table->string('photo')->nullable();
            $table->foreignId('course_id')->constrained('courses');
        });
    }
};
MIGRATION
);
$config_relaciones = frontend_fuente_configuracion('Administra desde un solo lugar la información de cursos e inscripciones.');
$config_relaciones['frontend_nombre_app'] = 'Gestión de cursos';
$config_relaciones['adaptaciones_frontend']['courses'] = [
    'fuente' => 'modules',
    'clase_fuente' => 'Module',
    'singular_tecnico' => 'course',
    'plural_tecnico' => 'courses',
    'clase_controlador' => 'CourseController',
    'ruta' => 'courses',
    'api_url' => 'courses',
    'variable_lista' => 'courses',
    'variable_item' => 'course',
    'singular_interfaz' => 'Curso',
    'plural_interfaz' => 'Cursos',
    'nav_etiqueta' => 'Cursos',
    'home' => [
        'titulo' => 'Cursos',
        'descripcion' => 'Consulta y administra los cursos disponibles.',
        'enlace' => 'Ir a cursos',
    ],
    'campos' => [
        'sku' => ['fuente' => 'codigo', 'etiqueta' => 'Código', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
        'title' => ['fuente' => 'nombre', 'etiqueta' => 'Título', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
    ],
    'relaciones' => [],
    'textos' => [
        'Módulos' => 'Cursos', 'módulos' => 'cursos', 'Módulo' => 'Curso', 'módulo' => 'curso',
    ],
];
$config_relaciones['adaptaciones_frontend']['enrollments'] = [
    'fuente' => 'students',
    'clase_fuente' => 'Student',
    'singular_tecnico' => 'enrollment',
    'plural_tecnico' => 'enrollments',
    'clase_controlador' => 'EnrollmentController',
    'ruta' => 'enrollments',
    'api_url' => 'enrollments',
    'variable_lista' => 'enrollments',
    'variable_item' => 'enrollment',
    'singular_interfaz' => 'Inscripción',
    'plural_interfaz' => 'Inscripciones',
    'nav_etiqueta' => 'Inscripciones',
    'home' => [
        'titulo' => 'Inscripciones',
        'descripcion' => 'Consulta y administra las inscripciones de cursos.',
        'enlace' => 'Ir a inscripciones',
    ],
    'campos' => [
        'first_name' => ['fuente' => 'nombre', 'etiqueta' => 'Nombre', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
        'last_name' => ['fuente' => 'apellido', 'etiqueta' => 'Apellido', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
        'birth_date' => ['fuente' => 'fechaNacimiento', 'etiqueta' => 'Fecha de inscripción', 'tipo' => 'date', 'control' => 'date', 'imagen' => false],
        'file_code' => ['fuente' => 'expediente', 'etiqueta' => 'Código de inscripción', 'tipo' => 'string', 'control' => 'text', 'imagen' => false],
        'photo' => ['fuente' => 'fotografia', 'etiqueta' => 'Foto', 'tipo' => 'string', 'control' => 'file', 'imagen' => true],
        'course_id' => ['fuente' => 'module_id', 'etiqueta' => 'ID del curso', 'tipo' => 'foreignId', 'control' => 'select', 'imagen' => false, 'relacion' => 'course_id'],
    ],
    'relaciones' => [
        'course_id' => [
            'fuente' => 'modules', 'tabla' => 'courses', 'api_url' => 'courses',
            'variable_lista' => 'courses', 'variable_item' => 'course',
            'campo_etiqueta' => 'title', 'etiqueta' => 'Curso',
        ],
    ],
    'textos' => [
        'Aprendices' => 'Inscripciones',
        'aprendiz' => 'inscripción', 'aprendices' => 'inscripciones',
        'Estudiante' => 'Inscrito', 'estudiante' => 'inscrito',
        'Módulo' => 'Curso', 'Módulos' => 'Cursos', 'módulo' => 'curso', 'módulos' => 'cursos',
    ],
];
try {
    $adaptado_relacional = generar_frontend_crud($frontend_relaciones, $backend_relaciones, $config_relaciones);
    frontend_fuente_assert(
        $adaptado_relacional['errores'] === [] && $adaptado_relacional['adaptaciones_pendientes'] === [],
        'No se adaptaron imagen y relación configuradas explícitamente: ' . json_encode($adaptado_relacional, JSON_UNESCAPED_UNICODE)
    );
    $controlador_inscripcion = file_get_contents($frontend_relaciones . '/app/Http/Controllers/EnrollmentController.php');
    $crear_inscripcion = file_get_contents($frontend_relaciones . '/resources/views/enrollments/create.blade.php');
    $editar_inscripcion = file_get_contents($frontend_relaciones . '/resources/views/enrollments/edit.blade.php');
    frontend_fuente_php_lint($frontend_relaciones . '/app/Http/Controllers/EnrollmentController.php');
    frontend_fuente_assert(str_contains($controlador_inscripcion, "'/enrollments'") && str_contains($controlador_inscripcion, "'/courses'"), 'Los endpoints principal o relacional no se adaptaron.');
    frontend_fuente_assert(str_contains($controlador_inscripcion, "'photo'") && str_contains($controlador_inscripcion, "'course_id'"), 'No se adaptaron la carga multipart o la FK.');
    frontend_fuente_assert(str_contains($crear_inscripcion, 'name="photo"') && str_contains($crear_inscripcion, 'name="course_id"'), 'El formulario perdió el archivo o el select relacional.');
    frontend_fuente_assert(str_contains($crear_inscripcion, '$courses as $course') && str_contains($crear_inscripcion, '$course[\'title\']'), 'El select no usa la variable y etiqueta relacional configuradas.');
    frontend_fuente_assert(str_contains($editar_inscripcion, '$enrollment[\'photo\']') && str_contains($editar_inscripcion, 'image/*'), 'La vista de edición no muestra/carga la imagen original.');
    frontend_fuente_assert(!str_contains($controlador_inscripcion, '/students') && !str_contains($controlador_inscripcion, '/modules'), 'El controlador conserva URLs de las entidades fuente.');
    frontend_fuente_assert(!str_contains($controlador_inscripcion, 'StudentController') && !str_contains($crear_inscripcion, "route('students."), 'La adaptación conserva referencias a la entidad original.');
    echo "OK: entidad distinta con relación, select, imagen y multipart desde los archivos originales\n";
} finally {
    frontend_fuente_limpiar($fixture_relaciones);
}

$fixture_pendiente = frontend_fuente_fixture();
$backend_pendiente = $fixture_pendiente . '/Backend';
$frontend_pendiente = $fixture_pendiente . '/Frontend';
file_put_contents($backend_pendiente . '/database/migrations/2026_11_23_000001_create_unmapped.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('unmapped_items', function ($table) {
            $table->id();
            $table->string('unknown_field');
        });
    }
};
MIGRATION
);
try {
    $pendiente = generar_frontend_crud(
        $frontend_pendiente,
        $backend_pendiente,
        frontend_fuente_configuracion()
    );
    frontend_fuente_assert(count($pendiente['adaptaciones_pendientes']) === 1, 'Una entidad sin manifiesto no quedó pendiente.');
    frontend_fuente_assert(!is_dir($frontend_pendiente . '/app'), 'Se generaron archivos al faltar el manifiesto explícito.');
    echo "OK: entidades sin mapeo se registran pendientes sin generar vistas genéricas\n";
} finally {
    frontend_fuente_limpiar($fixture_pendiente);
}

$fixture_manual = frontend_fuente_fixture();
$backend_manual = $fixture_manual . '/Backend';
$frontend_manual = $fixture_manual . '/Frontend';
frontend_fuente_migraciones_referencia($backend_manual, $fuente);
mkdir($frontend_manual . '/app/Http/Controllers', 0777, true);
$contenido_manual = 'contenido manual del controlador';
file_put_contents($frontend_manual . '/app/Http/Controllers/StudentController.php', $contenido_manual);
try {
    $conflicto = generar_frontend_crud($frontend_manual, $backend_manual, frontend_fuente_configuracion());
    frontend_fuente_assert(
        file_get_contents($frontend_manual . '/app/Http/Controllers/StudentController.php') === $contenido_manual,
        'Se sobrescribió un archivo manual.'
    );
    frontend_fuente_assert(!empty($conflicto['errores']), 'No se reportó el conflicto con el archivo manual.');
    echo "OK: los archivos manuales no se sobrescriben y el conflicto se informa\n";
} finally {
    frontend_fuente_limpiar($fixture_manual);
}

foreach ($huellas as $relativo => $hash) {
    frontend_fuente_assert(hash_file('sha256', $fuente_frontend . '/' . $relativo) === $hash, "La referencia pracExaLav fue modificada: $relativo.");
}
echo "OK: la referencia frontend permanece intacta\n";
