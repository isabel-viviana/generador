<?php

require_once __DIR__ . '/../src/migraciones.php';
require_once __DIR__ . '/../src/backend.php';

function backend_fuente_assert($condicion, $mensaje)
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function backend_fuente_fixture()
{
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-backend-source-' . bin2hex(random_bytes(6));
    mkdir($ruta . '/database/migrations', 0777, true);
    return $ruta;
}

function backend_fuente_limpiar($ruta)
{
    if (!is_dir($ruta)) {
        return;
    }
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterador as $elemento) {
        if ($elemento->isLink() || !$elemento->isDir()) {
            unlink($elemento->getPathname());
        } else {
            rmdir($elemento->getPathname());
        }
    }
    rmdir($ruta);
}

$raiz_repositorio = dirname(__DIR__, 3);
$fuente = realpath($raiz_repositorio . '/pracExaLav/backend');
backend_fuente_assert($fuente !== false, 'No se localizó la referencia pracExaLav/backend.');
$archivos_fuente = [
    'app/Models/Student.php',
    'app/Models/Teacher.php',
    'app/Models/Module.php',
    'app/Models/ModuleTeacher.php',
    'app/Http/Controllers/StudentController.php',
    'app/Http/Controllers/TeacherController.php',
    'app/Http/Controllers/ModuleController.php',
    'routes/api.php',
    'routes/web.php',
];
$huellas_fuente = [];
foreach ($archivos_fuente as $archivo) {
    $huellas_fuente[$archivo] = hash_file('sha256', $fuente . '/' . $archivo);
}

$backend_referencia = backend_fuente_fixture();
foreach (glob($fuente . '/database/migrations/2026_11_20_*.php') ?: [] as $migracion_fuente) {
    copy($migracion_fuente, $backend_referencia . '/database/migrations/' . basename($migracion_fuente));
}
mkdir($backend_referencia . '/config', 0777, true);
copy($fuente . '/composer.json', $backend_referencia . '/composer.json');
file_put_contents($backend_referencia . '/config/services.php', "<?php\n\nreturn [\n    'mail' => [],\n];\n");
try {
    $resumen_referencia = generar_backend_crud($backend_referencia, ['fuente_backend' => $fuente]);
    $analisis_referencia = analizar_migraciones($backend_referencia);
    backend_fuente_assert($resumen_referencia['errores'] === [], 'Falló la adaptación de la migración real pracExaLav: ' . implode(' | ', $resumen_referencia['errores']));
    backend_fuente_assert(
        count($resumen_referencia['modelos']) === 3 && count($resumen_referencia['controladores']) === 3,
        'No se generaron los recursos originales Student, Teacher y Module. Generados: '
            . json_encode(['modelos' => $resumen_referencia['modelos'], 'controladores' => $resumen_referencia['controladores'], 'pendientes' => $resumen_referencia['adaptaciones_pendientes']])
    );
    foreach (['Student', 'Teacher', 'Module'] as $clase) {
        $tabla = strtolower($clase) . 's';
        $modelo_fuente = file_get_contents($fuente . "/app/Models/$clase.php");
        $pendientes_modelo = [];
        $adaptaciones_modelo = _backend_adaptaciones_modelo(
            $modelo_fuente,
            $tabla,
            $analisis_referencia['tablas'][$tabla],
            ['fuente_backend' => $fuente],
            _backend_relaciones_objetivo(
                $tabla,
                $analisis_referencia['tablas'][$tabla],
                $analisis_referencia['tablas'],
                ['fuente_backend' => $fuente],
                $analisis_referencia['relations']
            ),
            $pendientes_modelo
        );
        $controlador_fuente = $fuente . "/app/Http/Controllers/{$clase}Controller.php";
        $codigo_controlador_fuente = file_get_contents($controlador_fuente);
        $pendientes_controlador = [];
        $adaptaciones_controlador = _backend_adaptaciones_controlador(
            $codigo_controlador_fuente,
            $controlador_fuente,
            $fuente . "/app/Models/$clase.php",
            $tabla,
            ['fuente_backend' => $fuente],
            [],
            $analisis_referencia['tablas'][$tabla],
            $pendientes_controlador
        );
        backend_fuente_assert(
            $adaptaciones_modelo !== null
                && file_get_contents($backend_referencia . "/app/Models/$clase.php")
                    === adaptar_contenido_fuente($modelo_fuente, $adaptaciones_modelo)['contenido'],
            "El modelo $clase difiere de su fuente fuera de las adaptaciones autorizadas."
        );
        backend_fuente_assert(
            $adaptaciones_controlador !== null
                && file_get_contents($backend_referencia . "/app/Http/Controllers/{$clase}Controller.php")
                    === adaptar_contenido_fuente($codigo_controlador_fuente, $adaptaciones_controlador['adaptaciones'])['contenido'],
            "El controlador API $clase difiere de su fuente fuera de las adaptaciones autorizadas."
        );
    }
    backend_fuente_assert(
        file_get_contents($backend_referencia . '/routes/web.php') === file_get_contents($fuente . '/routes/web.php'),
        'routes/web.php no conserva exactamente los bytes de la fuente.'
    );
    $services_generado = file_get_contents($backend_referencia . '/config/services.php');
    preg_match('/^[ \t]*\'cloudinary\'[ \t]*=>[ \t]*\[[^\]]*\][ \t]*,?/m', file_get_contents($fuente . '/config/services.php'), $cloudinary_fuente);
    backend_fuente_assert(
        isset($cloudinary_fuente[0]) && str_contains($services_generado, $cloudinary_fuente[0])
            && !str_contains($services_generado, "'folder' =>"),
        'La configuración Cloudinary no conserva la entrada de la fuente.'
    );
    backend_fuente_assert(!file_exists($backend_referencia . '/app/Models/ModuleTeacher.php'), 'Se inventaron relaciones ausentes del modelo fuente ModuleTeacher.');
    backend_fuente_assert(count(array_filter(
        $resumen_referencia['adaptaciones_pendientes'],
        static fn($pendiente) => $pendiente['tabla'] === 'module_teachers'
    )) === 0, 'La tabla intermedia sin CRUD propio quedó como adaptación pendiente: ' . json_encode($resumen_referencia['adaptaciones_pendientes']));
    backend_fuente_assert(in_array(
        'module_teachers (tabla asociativa sin recurso CRUD propio en la fuente)',
        $resumen_referencia['omitidas'],
        true
    ), 'No se documentó la omisión segura de module_teachers.');
    backend_fuente_assert(!str_contains(file_get_contents($backend_referencia . '/routes/api.php'), "apiResource('module_teachers'"), 'Se registró CRUD API para la tabla intermedia.');
    echo "OK: generación de migraciones reales Student/Teacher/Module; ModuleTeacher se omite sin CRUD genérico\n";
} finally {
    backend_fuente_limpiar($backend_referencia);
}

$backend = backend_fuente_fixture();
file_put_contents($backend . '/database/migrations/2026_11_01_000000_create_catalog.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('categories', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('customers', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('orders', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->foreignId('customer_id')->constrained('customers');
        });
        Schema::create('tags', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
        Schema::create('customer_tag', function ($table) {
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('tag_id')->constrained('tags');
            $table->primary(['customer_id', 'tag_id']);
        });
    }
};
MIGRATION
);

$config = [
    'fuente_backend' => $fuente,
    'proyecto' => 'source-adaptation-test',
    'adaptaciones_backend' => [],
];
foreach (['products', 'categories', 'customers'] as $tabla) {
    $config['adaptaciones_backend'][$tabla] = [
        'modelo' => 'ModuleTeacher.php',
        'controlador_api' => 'ModuleController.php',
        'campos' => ['name' => 'nombre', 'code' => 'codigo'],
    ];
}
$config['adaptaciones_backend']['customers']['modelo'] = 'Module.php';
$config['adaptaciones_backend']['orders'] = [
    'modelo' => 'Student.php',
    'controlador_api' => 'ModuleController.php',
    'campos' => ['name' => 'nombre', 'code' => 'codigo'],
    'validaciones' => ['customer_id' => 'required|integer'],
];
$config['adaptaciones_backend']['tags'] = [
    'modelo' => 'Teacher.php',
    'controlador_api' => 'ModuleController.php',
    'campos' => ['name' => 'nombre', 'code' => 'codigo'],
];

try {
    $resumen = generar_backend_crud($backend, $config);
    backend_fuente_assert($resumen['errores'] === [], 'Errores al adaptar fuentes: ' . implode(' | ', $resumen['errores']));
    backend_fuente_assert(count($resumen['modelos']) === 5, 'No se adaptaron todos los modelos del fixture: ' . json_encode([
        'modelos' => $resumen['modelos'],
        'pendientes' => $resumen['adaptaciones_pendientes'],
    ], JSON_UNESCAPED_SLASHES));
    backend_fuente_assert(count($resumen['controladores']) === 5, 'No se adaptaron todos los controladores API.');
    backend_fuente_assert(count($resumen['mapeo_fuentes']) >= 6, 'No se registró el mapeo de archivos fuente.');

    $analisis_destino = analizar_migraciones($backend, $config);
    $pendientes_diff = [];
    $mapa_modelo_producto = _backend_adaptaciones_modelo(
        file_get_contents($fuente . '/app/Models/ModuleTeacher.php'),
        'products',
        $analisis_destino['tablas']['products'],
        $config,
        _backend_relaciones_objetivo('products', $analisis_destino['tablas']['products'], $analisis_destino['tablas'], $config, $analisis_destino['relations']),
        $pendientes_diff
    );
    $diff_modelo = adaptar_contenido_fuente(
        file_get_contents($fuente . '/app/Models/ModuleTeacher.php'),
        $mapa_modelo_producto
    );
    backend_fuente_assert(
        file_get_contents($backend . '/app/Models/Product.php') === $diff_modelo['contenido'],
        'El diff del modelo contiene cambios fuera de las adaptaciones autorizadas.'
    );
    $pendientes_diff = [];
    $mapa_controlador_producto = _backend_adaptaciones_controlador(
        file_get_contents($fuente . '/app/Http/Controllers/ModuleController.php'),
        $fuente . '/app/Http/Controllers/ModuleController.php',
        $fuente . '/app/Models/ModuleTeacher.php',
        'products',
        $config,
        $config['adaptaciones_backend']['products'],
        $analisis_destino['tablas']['products'],
        $pendientes_diff
    );
    $diff_controlador = adaptar_contenido_fuente(
        file_get_contents($fuente . '/app/Http/Controllers/ModuleController.php'),
        $mapa_controlador_producto['adaptaciones']
    );
    backend_fuente_assert(
        file_get_contents($backend . '/app/Http/Controllers/ProductController.php') === $diff_controlador['contenido'],
        'El diff del controlador contiene cambios fuera de las adaptaciones autorizadas.'
    );

    foreach (['Product', 'Category', 'Customer', 'Order', 'Tag'] as $clase) {
        $ruta_modelo = $backend . "/app/Models/$clase.php";
        $ruta_controlador = $backend . "/app/Http/Controllers/{$clase}Controller.php";
        backend_fuente_assert(is_file($ruta_modelo) && is_file($ruta_controlador), "Falta la salida de $clase.");
        $controlador = file_get_contents($ruta_controlador);
        foreach (['Student', 'Teacher', 'Module'] as $residuo) {
            backend_fuente_assert(!str_contains($controlador, $residuo), "$clase Controller conserva referencia a $residuo.");
        }
        foreach (['index', 'store', 'show', 'update', 'destroy'] as $metodo) {
            backend_fuente_assert(str_contains($controlador, "function $metodo("), "$clase Controller perdió $metodo().");
        }
        backend_fuente_assert(str_contains($controlador, 'return response()->json('), "$clase Controller perdió respuestas JSON.");
        backend_fuente_assert(str_contains($controlador, "'name' =>") && str_contains($controlador, "'code' =>"), "$clase Controller no adaptó sus campos validados.");
        $salida = [];
        $codigo = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($ruta_controlador), $salida, $codigo);
        backend_fuente_assert($codigo === 0, "$clase Controller no pasó php -l: " . implode(' ', $salida));
        $modelo = file_get_contents($ruta_modelo);
        foreach (['Student', 'Teacher', 'Module'] as $residuo) {
            backend_fuente_assert(!str_contains($modelo, $residuo), "$clase conserva referencia a $residuo en el modelo.");
        }
    }

    $modelo_producto = file_get_contents($backend . '/app/Models/Product.php');
    backend_fuente_assert(str_contains($modelo_producto, 'class Product extends Model'), 'El modelo no conserva la estructura de la clase fuente adaptada.');
    backend_fuente_assert(str_contains($modelo_producto, "'name'") && str_contains($modelo_producto, "'code'"), 'El fillable no sigue las columnas de la migración.');
    backend_fuente_assert(!str_contains($modelo_producto, 'HasFactory') && !str_contains($modelo_producto, 'casts'), 'Se inventó un trait o casts ausentes de la fuente.');

    $modelo_orden = file_get_contents($backend . '/app/Models/Order.php');
    backend_fuente_assert(str_contains($modelo_orden, 'use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;'), 'No se conservó HasFactory de la fuente Student.');
    backend_fuente_assert(str_contains($modelo_orden, 'function customer()'), 'No se adaptó el nombre del método de relación fuente.');
    backend_fuente_assert(str_contains($modelo_orden, 'belongsTo(Customer::class)'), 'No se adaptó belongsTo según las convenciones originales.');
    $modelo_cliente = file_get_contents($backend . '/app/Models/Customer.php');
    backend_fuente_assert(str_contains($modelo_cliente, 'function orders()')
        && str_contains($modelo_cliente, "hasMany(Order::class, 'customer_id', 'id')"), 'No se adaptó hasMany desde el método fuente.');
    backend_fuente_assert(str_contains($modelo_cliente, 'function tags()')
        && str_contains($modelo_cliente, "belongsToMany(Tag::class, 'customer_tag', 'customer_id', 'tag_id')"), 'No se adaptó belongsToMany desde el método fuente.');
    $controlador_orden = file_get_contents($backend . '/app/Http/Controllers/OrderController.php');
    backend_fuente_assert(str_contains($controlador_orden, "'customer_id' => 'required|integer'"), 'No se añadió la regla explícita solicitada para customer_id.');

    $rutas = file_get_contents($backend . '/routes/api.php');
    foreach (['products', 'categories', 'customers', 'orders', 'tags'] as $recurso) {
        backend_fuente_assert(substr_count($rutas, "Route::apiResource('$recurso'") === 1, "La ruta $recurso falta o está duplicada.");
    }
    foreach (['StudentController', 'TeacherController', 'ModuleController'] as $residuo) {
        backend_fuente_assert(!str_contains($rutas, $residuo), "routes/api.php conserva $residuo.");
    }
    backend_fuente_assert(file_get_contents($backend . '/routes/web.php') === file_get_contents($fuente . '/routes/web.php'), 'La ruta Web no conserva exactamente la fuente disponible.');
    backend_fuente_assert(count(array_filter(
        $resumen['adaptaciones_pendientes'],
        static fn($pendiente) => $pendiente['tipo'] === 'controladores Web'
    )) === 0, 'La ausencia esperada de controladores Web bloqueó la generación.');
    backend_fuente_assert(count(array_filter(
        $resumen['advertencias'],
        static fn($advertencia) => str_contains($advertencia, 'No existen controladores Web CRUD')
    )) === 1, 'No se informó como advertencia la separación del CRUD Web.');

    $rutas_antes = $rutas;
    $segunda = generar_backend_crud($backend, $config);
    backend_fuente_assert(file_get_contents($backend . '/routes/api.php') === $rutas_antes, 'La segunda ejecución duplicó o alteró rutas.');
    backend_fuente_assert($segunda['rutas_api'] === 'sin_cambios', 'La segunda ejecución de rutas no fue idempotente.');

    $config_sin_tags = $config;
    $config_sin_tags['tablas_excluidas'] = ['tags'];
    generar_backend_crud($backend, $config_sin_tags);
    $rutas_actualizadas = file_get_contents($backend . '/routes/api.php');
    backend_fuente_assert(!str_contains($rutas_actualizadas, "Route::apiResource('tags'")
        && !str_contains($rutas_actualizadas, 'TagController;'), 'La reconciliación dejó una ruta o import generado obsoleto.');

    foreach ($huellas_fuente as $archivo => $huella) {
        backend_fuente_assert(hash_file('sha256', $fuente . '/' . $archivo) === $huella, "La referencia fue modificada: $archivo.");
    }
    echo "OK: adaptación fiel desde fuentes reales, cinco entidades, relaciones, validaciones explícitas y rutas idempotentes\n";
} finally {
    backend_fuente_limpiar($backend);
}

$backend_conflicto = backend_fuente_fixture();
file_put_contents($backend_conflicto . '/database/migrations/2026_11_02_000000_create_products.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('code');
        });
    }
};
MIGRATION
);
mkdir($backend_conflicto . '/app/Models', 0777, true);
file_put_contents($backend_conflicto . '/app/Models/Product.php', 'manual product model');
try {
    $resumen = generar_backend_crud($backend_conflicto, $config);
    backend_fuente_assert(file_get_contents($backend_conflicto . '/app/Models/Product.php') === 'manual product model', 'Se sobrescribió un modelo manual.');
    backend_fuente_assert(!empty($resumen['errores']), 'No se informó el conflicto con el modelo manual existente.');
    echo "OK: conflictos con archivos manuales preservan el contenido y se reportan\n";
} finally {
    backend_fuente_limpiar($backend_conflicto);
}

$backend_rutas_manuales = backend_fuente_fixture();
mkdir($backend_rutas_manuales . '/routes', 0777, true);
carpetas_permitidas([$backend_rutas_manuales . '/routes']);
$ruta_manual = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/health', fn () => response()->json(['ok' => true]));\n";
file_put_contents($backend_rutas_manuales . '/routes/api.php', $ruta_manual);
try {
    $controlador = [['tabla' => 'products', 'clase_ctrl' => 'ProductController']];
    $registro = registrar_rutas_api_desde_fuente(
        $backend_rutas_manuales,
        $fuente . '/routes/api.php',
        $controlador
    );
    backend_fuente_assert($registro['estado'] === 'actualizado', 'No se integró la ruta en el archivo manual reconocido.');
    $rutas_manuales = file_get_contents($backend_rutas_manuales . '/routes/api.php');
    backend_fuente_assert(str_contains($rutas_manuales, "Route::get('/health'")
        && substr_count($rutas_manuales, "Route::apiResource('products'") === 1, 'La integración alteró la ruta manual o duplicó la ruta API.');
    $registro_segundo = registrar_rutas_api_desde_fuente(
        $backend_rutas_manuales,
        $fuente . '/routes/api.php',
        $controlador
    );
    backend_fuente_assert($registro_segundo['estado'] === 'sin_cambios', 'La integración en rutas manuales no es idempotente.');
    echo "OK: rutas API manuales reconocidas, preservadas e idempotentes\n";
} finally {
    backend_fuente_limpiar($backend_rutas_manuales);
}
