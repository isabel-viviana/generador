<?php

require_once __DIR__ . '/../src/migraciones.php';
require_once __DIR__ . '/../src/backend.php';
require_once __DIR__ . '/../src/frontend.php';

function migraciones_assert($condicion, $mensaje)
{
    if (!$condicion) {
        throw new RuntimeException($mensaje);
    }
}

function migraciones_fixture()
{
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-migrations-' . bin2hex(random_bytes(6));
    mkdir($ruta . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations', 0777, true);
    return $ruta;
}

function migraciones_escribir($ruta, $nombre, $contenido)
{
    $destino = $ruta . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . $nombre;
    file_put_contents($destino, $contenido);
}

function migraciones_limpiar_fixture($ruta)
{
    foreach (glob($ruta . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $archivo) {
        unlink($archivo);
    }
    rmdir($ruta . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations');
    rmdir($ruta . DIRECTORY_SEPARATOR . 'database');
    rmdir($ruta);
}

function migraciones_fixture_con_archivos($archivos)
{
    $ruta = migraciones_fixture();
    foreach ($archivos as $nombre => $contenido) {
        migraciones_escribir($ruta, $nombre, $contenido);
    }
    return $ruta;
}

function migraciones_limpiar_arbol_temporal($ruta)
{
    if (!is_dir($ruta)) {
        return;
    }
    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterador as $elemento) {
        $elemento->isDir() ? rmdir($elemento->getPathname()) : unlink($elemento->getPathname());
    }
    rmdir($ruta);
}

$ruta = migraciones_fixture();
$marca = $ruta . DIRECTORY_SEPARATOR . 'migration-was-executed';
$marca_literal = var_export($marca, true);

try {
    migraciones_escribir($ruta, '2025_01_01_000000_create_schema.php', str_replace(
        '__MARKER__',
        $marca_literal,
        <<<'MIGRATION'
<?php
file_put_contents(__MARKER__, 'executed');

return new class {
    public function up(): void
    {
        Schema::create('modules', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('teachers', function ($table) {
            $table->id();
        });

        Schema::create('articles', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('nullable_text')->nullable();
            $table->string('no_default');
            $table->string('null_default')->default(null);
            $table->integer('rank')->default(7);
            $table->string('fotografia')->nullable();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('orphan_id');
            $table->timestamps();
            $table->softDeletes();
            $table->geography('shape');
        });

        Schema::create('string_keys', function ($table) {
            $table->string('external_key')->primary();
        });

        Schema::create('composite_keys', function ($table) {
            $table->string('left_key');
            $table->string('right_key');
            $table->primary(['left_key', 'right_key'], 'composite_primary');
            $table->unique(['left_key', 'right_key'], 'composite_unique');
            $table->index(['right_key', 'left_key'], 'composite_index');
        });

        Schema::create('module_teachers', function ($table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('session_like', function ($table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->integer('last_activity')->index();
        });

        Schema::create('users', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->rememberToken();
        });

        Schema::create('alterables', function ($table) {
            $table->id();
            $table->string('name', 80)->nullable()->default('before');
            $table->string('label', 64)->default('preserve');
            $table->integer('count')->default(2);
        });

        Schema::create('obsolete_pk', function ($table) {
            $table->bigIncrements('obsolete_id');
        });

        Schema::create('drop_composite_pk', function ($table) {
            $table->string('left_key');
            $table->string('right_key');
            $table->primary(['left_key', 'right_key']);
        });
    }
};
MIGRATION
    ));

    migraciones_escribir($ruta, '2025_01_02_000000_alter_schema.php', <<<'MIGRATION'
<?php

return new class {
    public function up(): void
    {
        Schema::table('alterables', function ($table) {
            $table->string('name', 120)->change();
            $table->string('label')->change();
            $table->dropColumn('count');
            $table->string('added_nullable')->nullable();
        });

        Schema::table('obsolete_pk', function ($table) {
            $table->dropPrimary();
        });

        Schema::table('drop_composite_pk', function ($table) {
            $table->dropColumn('left_key');
        });
    }
};
MIGRATION
    );

    $resultado = analizar_migraciones($ruta, ['campos_imagen' => ['fotografia']]);
    $tablas = $resultado['tablas'];

    migraciones_assert($resultado['contract_version'] === 1, 'No se publicó la versión del contrato.');
    migraciones_assert(isset($resultado['analysis']['status'], $resultado['analysis']['diagnostics']), 'Falta el estado o los diagnósticos del análisis.');
    migraciones_assert($resultado['analysis']['status'] === 'partial', 'La sintaxis desconocida no marcó el análisis como parcial.');
    migraciones_assert($resultado['analysis']['generation_safe'] === false, 'El análisis parcial no marcó el esquema como no seguro.');
    migraciones_assert($resultado['tablas'] === $resultado['tables'], 'El alias legacy tables/tablas diverge.');

    migraciones_assert($tablas['modules']['primary_key']['columnas'] === ['id'], 'No se detectó la PK id.');
    migraciones_assert($tablas['modules']['primary_key']['generacion'] === 'auto_increment', 'No se detectó el autoincremento de id().');
    migraciones_assert($tablas['string_keys']['primary_key']['columnas'] === ['external_key'], 'No se detectó una PK cuyo nombre no es id.');
    migraciones_assert($tablas['string_keys']['primary_key']['generacion'] === 'asignada', 'La PK string se clasificó como autoincremental.');
    migraciones_assert($tablas['string_keys']['columnas']['external_key']['tipo_normalizado'] === 'string', 'No se conservó el tipo de la PK string.');
    migraciones_assert($tablas['string_keys']['columnas']['external_key']['es_pk'] === true, 'Se perdió la PK legacy de una tabla sin FK.');

    $articles = $tablas['articles']['columnas'];
    migraciones_assert($articles['nullable_text']['nullable'] === true, 'No se reconoció nullable().');
    migraciones_assert($articles['no_default']['default_metadata']['declarado'] === false, 'La ausencia de default no se distinguió.');
    migraciones_assert($articles['null_default']['default_metadata']['declarado'] === true, 'default(null) no se distinguió de la ausencia de default.');
    migraciones_assert($articles['null_default']['default_metadata']['valor'] === null, 'Se perdió el valor explícito null.');
    migraciones_assert($articles['rank']['default_metadata']['valor'] === 7, 'No se conservó el default literal.');
    migraciones_assert($articles['email']['unique'] === true, 'No se reconoció unique simple.');
    migraciones_assert($articles['fotografia']['tipo_normalizado'] === 'string' && $articles['fotografia']['es_imagen'] === true, 'La clasificación de imagen perdió el tipo original.');
    migraciones_assert($articles['module_id']['fk']['referencia_tabla'] === 'modules', 'No se resolvió constrained() contra la tabla existente.');
    migraciones_assert($articles['module_id']['fk']['on_delete'] === 'cascade', 'No se conservó cascadeOnDelete().');
    migraciones_assert($tablas['articles']['fks'] === [[
        'columna' => 'module_id',
        'referencia_tabla' => 'modules',
        'referencia_columna' => 'id',
        'on_delete' => 'cascade',
    ]], 'Cambió la estructura legacy de fks.');
    migraciones_assert($articles['orphan_id']['fk'] === null, 'Se inventó una FK por el sufijo _id.');
    migraciones_assert($tablas['articles']['timestamps'] === true, 'No se reconocieron timestamps().');
    migraciones_assert($tablas['articles']['soft_deletes'] === true, 'No se reconoció softDeletes().');
    migraciones_assert(isset($tablas['articles']['columnas_desconocidas'][0]), 'No se conservó la columna de tipo desconocido.');
    migraciones_assert(!isset($articles['shape']), 'El tipo desconocido se convirtió en una columna inventada para los consumidores legacy.');

    $composite = $tablas['composite_keys'];
    migraciones_assert($composite['primary_key']['columnas'] === ['left_key', 'right_key'], 'No se reconoció la PK compuesta.');
    $indices = array_column($composite['indices'], null, 'nombre');
    migraciones_assert($indices['composite_unique']['tipo'] === 'unique', 'No se reconoció unique compuesto.');
    migraciones_assert($indices['composite_index']['columnas'] === ['right_key', 'left_key'], 'No se reconoció el índice compuesto.');
    migraciones_assert(count($tablas['articles']['indices']) > 0, 'No se registraron índices simples.');

    $moduleTeachers = $tablas['module_teachers'];
    migraciones_assert($moduleTeachers['es_pivote'] === false, 'Una tabla con PK propia fue clasificada como descartable.');
    migraciones_assert($moduleTeachers['pivot_assessment']['simple_pivot'] === false, 'No se reconoció que module_teachers tiene PK propia.');
    migraciones_assert(count($moduleTeachers['foreign_keys']) === 2, 'No se conservaron las dos FKs de module_teachers.');

    $session = $tablas['session_like'];
    migraciones_assert($session['primary_key']['columnas'] === ['id'], 'No se reconoció una PK string.');
    migraciones_assert(count($session['foreign_keys']) === 0, 'Se inventó una relación para user_id sin FK.');
    migraciones_assert(count(array_filter($session['indices'], fn($indice) => $indice['tipo'] === 'index')) === 2, 'No se conservaron los índices simples.');

    $alterables = $tablas['alterables'];
    migraciones_assert(!isset($alterables['columnas']['count']), 'dropColumn() no eliminó la columna.');
    migraciones_assert($alterables['columnas']['name']['opciones']['longitud'] === 120, 'change() no aplicó la longitud nueva.');
    migraciones_assert($alterables['columnas']['name']['nullable'] === true, 'change() no conservó nullable previo.');
    migraciones_assert($alterables['columnas']['name']['default_metadata']['valor'] === 'before', 'change() no conservó el default previo.');
    migraciones_assert($alterables['columnas']['label']['opciones']['longitud'] === 64, 'change() perdió una opción tipo no redeclarada.');
    migraciones_assert($alterables['columnas']['label']['default_metadata']['valor'] === 'preserve', 'change() no conservó el default sin cambios.');
    migraciones_assert(isset($alterables['columnas']['added_nullable']), 'Schema::table() no añadió la columna.');
    migraciones_assert(count($alterables['columnas']['name']['source_history']) === 2, 'No se registró la procedencia de change().');
    migraciones_assert($tablas['obsolete_pk']['primary_key']['generacion'] === 'ninguna', 'Se conservó una generación de PK después de dropPrimary().');
    migraciones_assert($tablas['obsolete_pk']['columnas']['obsolete_id']['es_pk'] === false, 'dropPrimary() dejó es_pk legacy activo.');
    migraciones_assert($tablas['drop_composite_pk']['primary_key'] === ['columnas' => [], 'generacion' => 'ninguna'], 'dropColumn() dejó componentes de una PK compuesta obsoletos.');
    migraciones_assert($tablas['drop_composite_pk']['columnas']['right_key']['es_pk'] === false, 'dropColumn() dejó es_pk legacy en una PK compuesta eliminada.');

    migraciones_assert(isset($tablas['users']['columnas']['remember_token']), 'No se reconoció rememberToken().');
    migraciones_assert($tablas['users']['columnas']['email']['unique'] === true, 'Se perdió unique legacy en una tabla sin FK.');
    migraciones_assert(!file_exists($marca), 'El analizador ejecutó código PHP de la migración.');

    echo "OK: contrato, claves, defaults, índices, FKs, timestamps, soft deletes, cambios, pivote y código estático\n";
} finally {
    migraciones_limpiar_fixture($ruta);
    if (file_exists($marca)) {
        unlink($marca);
    }
}

$sin_migraciones = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-migrations-empty-' . bin2hex(random_bytes(6));
mkdir($sin_migraciones);
try {
    $resultado = analizar_migraciones($sin_migraciones);
    migraciones_assert($resultado['analysis']['status'] === 'failed', 'La falta de directorio no produjo estado failed.');
    migraciones_assert($resultado['relationship_analysis']['status'] === 'failed' && $resultado['relations'] === [], 'El análisis fallido devolvió una estructura de relaciones inconsistente.');
    echo "OK: directorio de migraciones ausente produce análisis failed\n";
} finally {
    rmdir($sin_migraciones);
}

$migraciones_restricciones = migraciones_fixture_con_archivos([
    '2026_01_01_000000_create_constraints.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('users', function ($table) {
            $table->id();
        });
        Schema::create('profiles', function ($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('handle')->unique('profiles_handle_unique');
            $table->foreignId('owner_id')->constrained('users');
            $table->foreignId('manager_id')->constrained('users', 'id', 'custom_manager_fk');
        });
    }
};
MIGRATION,
    '2026_01_02_000000_drop_constraints.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::table('profiles', function ($table) {
            $table->dropUnique('profiles_email_unique');
            $table->dropUnique('profiles_handle_unique');
            $table->dropForeign('profiles_owner_id_foreign');
            $table->dropForeign('custom_manager_fk');
            $table->dropUnique('profiles_missing_unique');
            $table->dropForeign('profiles_missing_fk');
        });
    }
};
MIGRATION,
]);
try {
    $resultado = analizar_migraciones($migraciones_restricciones);
    $profiles = $resultado['tablas']['profiles'];
    migraciones_assert($resultado['analysis']['status'] === 'partial', 'Una restricción inexistente no marcó el análisis como parcial.');
    migraciones_assert($resultado['analysis']['migrations'][0]['status'] === 'complete', 'La migración inicial quedó marcada parcial sin motivo.');
    migraciones_assert($resultado['analysis']['migrations'][1]['status'] === 'partial', 'El estado de la migración de borrado no refleja su diagnóstico.');
    migraciones_assert(count(array_filter($profiles['indices'], static fn($indice) => $indice['tipo'] === 'unique')) === 0, 'dropUnique por nombre dejó índices únicos obsoletos.');
    migraciones_assert($profiles['columnas']['email']['unique'] === false && $profiles['columnas']['handle']['unique'] === false, 'dropUnique no actualizó el campo legacy unique.');
    migraciones_assert($profiles['foreign_keys'] === [] && $profiles['fks'] === [], 'dropForeign por nombre dejó FK legacy o nueva obsoleta.');
    migraciones_assert($profiles['columnas']['owner_id']['fk'] === null && $profiles['columnas']['manager_id']['fk'] === null, 'dropForeign no actualizó las relaciones legacy.');
    migraciones_assert(guardia_generacion_migraciones($resultado)['bloqueada'], 'El análisis parcial estructural permitió generación.');
    migraciones_assert(count(array_filter($resultado['diagnostics'], static fn($diagnostico) => str_contains($diagnostico['message'], 'No se encontró'))) === 2, 'No se diagnosticaron ambos borrados inexistentes.');
    echo "OK: dropUnique/dropForeign convencionales, explícitos, metadata legacy y migraciones\n";
} finally {
    migraciones_limpiar_fixture($migraciones_restricciones);
}

$migraciones_flecha = migraciones_fixture_con_archivos([
    '2026_02_01_000000_create_arrow.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('arrow_items', fn ($table) => $table->id());
    }
};
MIGRATION,
    '2026_02_02_000000_alter_arrow.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::table('arrow_items', fn ($table) => $table->string('description')->nullable());
    }
};
MIGRATION,
]);
try {
    $resultado = analizar_migraciones($migraciones_flecha);
    migraciones_assert($resultado['analysis']['status'] === 'complete', 'Callbacks flecha válidos no se analizaron completamente.');
    migraciones_assert($resultado['analysis']['generation_safe'] === true, 'El análisis completo se marcó como inseguro.');
    migraciones_assert(count($resultado['analysis']['migrations']) === 2, 'No se registraron ambas migraciones con callback flecha.');
    migraciones_assert($resultado['tablas']['arrow_items']['columnas']['id']['autoincrement'] === true, 'Se perdió la columna de Schema::create() con callback flecha.');
    migraciones_assert($resultado['tablas']['arrow_items']['columnas']['description']['nullable'] === true, 'Se perdió la columna de Schema::table() con callback flecha.');
    migraciones_assert(!guardia_generacion_migraciones($resultado)['bloqueada'], 'La guarda bloqueó un análisis completo de callbacks flecha.');

    migraciones_escribir($migraciones_flecha, '2026_02_03_000000_unsupported_arrow.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('unsupported_arrow', fn ($table) => condition ? $table->id() : null);
    }
};
MIGRATION
    );
    $parcial = analizar_migraciones($migraciones_flecha);
    migraciones_assert($parcial['analysis']['status'] === 'partial', 'La expresión flecha no soportada se informó como complete.');
    migraciones_assert(!isset($parcial['tablas']['unsupported_arrow']['columnas']['id']), 'La expresión flecha no soportada produjo una columna inventada.');
    migraciones_assert(guardia_generacion_migraciones($parcial)['bloqueada'], 'Una expresión flecha estructuralmente desconocida permitió generación.');
    echo "OK: callbacks flecha válidos y diagnóstico para expresión no analizable\n";
} finally {
    migraciones_limpiar_fixture($migraciones_flecha);
}

$migraciones_schema_alias = migraciones_fixture_con_archivos([
    '2026_03_01_000000_schema_names.php' => <<<'MIGRATION'
<?php
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\{Schema as GroupedSchema};
use Illuminate\Support\Facades as Facades;

return new class {
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::create('fully_qualified_items', function ($table) {
            $table->id();
        });
        DbSchema::create('aliased_items', function ($table) {
            $table->id();
        });
        GroupedSchema::create('grouped_alias_items', function ($table) {
            $table->id();
        });
        Facades\Schema::create('namespace_alias_items', function ($table) {
            $table->id();
        });
    }
};
MIGRATION,
]);
try {
    $resultado = analizar_migraciones($migraciones_schema_alias);
    migraciones_assert($resultado['analysis']['status'] === 'complete', 'Una variante válida de Schema produjo análisis parcial.');
    migraciones_assert(array_keys($resultado['tablas']) === ['fully_qualified_items', 'aliased_items', 'grouped_alias_items', 'namespace_alias_items'], 'No se reconocieron todas las referencias a Schema.');
    migraciones_assert($resultado['tablas']['fully_qualified_items']['primary_key']['generacion'] === 'auto_increment', 'La referencia cualificada no produjo metadata correcta.');
    echo "OK: Schema cualificado, alias directo y alias agrupado\n";
} finally {
    migraciones_limpiar_fixture($migraciones_schema_alias);
}

$migraciones_constrained = migraciones_fixture_con_archivos([
    '2026_04_01_000000_constrained.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('categories', function ($table) {
            $table->id();
        });
        Schema::create('categorys', function ($table) {
            $table->id();
        });
        Schema::create('links', function ($table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
            $table->foreignId('plain_id');
        });
        Schema::create('news', function ($table) {
            $table->id();
        });
        Schema::create('newses', function ($table) {
            $table->id();
        });
        Schema::create('news_links', function ($table) {
            $table->id();
            $table->foreignId('news_id')->constrained();
        });
    }
};
MIGRATION,
]);
try {
    $resultado = analizar_migraciones($migraciones_constrained);
    migraciones_assert($resultado['tablas']['links']['foreign_keys'][0]['tabla_referenciada'] === 'categories', 'category_id no usó la pluralización regular correcta.');
    migraciones_assert(count($resultado['tablas']['links']['foreign_keys']) === 1, 'Se inventó una FK para plain_id.');
    migraciones_assert(count($resultado['tablas']['links']['fks']) === 1, 'La FK legacy de category_id no se conservó.');
    migraciones_assert($resultado['tablas']['news_links']['foreign_keys'][0]['tabla_referenciada'] === null, 'Se eligió un destino arbitrario para una pluralización ambigua.');
    migraciones_assert($resultado['tablas']['news_links']['fks'] === [], 'Una FK ambigua se filtró al contrato legacy.');
    migraciones_assert($resultado['tablas']['news_links']['analysis']['status'] === 'partial', 'La relación ambigua no produjo diagnóstico parcial.');
    migraciones_assert($resultado['analysis']['status'] === 'partial', 'La relación ambigua no actualizó el estado global.');
    migraciones_assert($resultado['analysis']['migrations'][0]['status'] === 'partial', 'El estado del archivo no refleja el diagnóstico de constrained().');
    echo "OK: constrained(), pluralización conservadora y ausencia de FK falsa\n";
} finally {
    migraciones_limpiar_fixture($migraciones_constrained);
}

$migraciones_pivotes = migraciones_fixture_con_archivos([
    '2026_05_01_000000_pivot_assessment.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('roles', function ($table) {
            $table->id();
        });
        Schema::create('users', function ($table) {
            $table->id();
        });
        Schema::create('role_user', function ($table) {
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
        });
        Schema::create('assignment_links', function ($table) {
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('user_id')->constrained('users');
            $table->integer('priority');
        });
        Schema::create('module_teachers', function ($table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules');
            $table->foreignId('teacher_id')->constrained('teachers');
            $table->timestamps();
        });
    }
};
MIGRATION,
    '2026_05_02_000000_referenced_tables.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('modules', function ($table) {
            $table->id();
        });
        Schema::create('teachers', function ($table) {
            $table->id();
        });
    }
};
MIGRATION,
]);
try {
    $resultado = analizar_migraciones($migraciones_pivotes);
    migraciones_assert($resultado['tablas']['role_user']['es_pivote'] === true, 'No se confirmó el pivote simple con evidencia estructural.');
    migraciones_assert($resultado['tablas']['role_user']['pivot_assessment']['simple_pivot'] === true, 'Falta evidencia explícita del pivote simple.');
    migraciones_assert($resultado['tablas']['assignment_links']['es_pivote'] === false, 'Una tabla ambigua fue descartada como pivote.');
    migraciones_assert($resultado['tablas']['assignment_links']['pivot_assessment']['simple_pivot'] === null, 'La evaluación ambigua no quedó indeterminada.');
    migraciones_assert($resultado['tablas']['module_teachers']['es_pivote'] === false, 'module_teachers con PK propia se clasificó como pivote descartable.');
    migraciones_assert($resultado['tablas']['module_teachers']['pivot_assessment']['estado'] === 'entidad_asociativa', 'module_teachers no se mantuvo como entidad asociativa.');
    migraciones_assert(!es_tabla_elegible('role_user', $resultado['tablas']['role_user'], []), 'Backend permitiría CRUD para un pivote confirmado.');
    migraciones_assert(!es_tabla_elegible_front('role_user', $resultado['tablas']['role_user'], []), 'Frontend permitiría CRUD para un pivote confirmado.');
    migraciones_assert(es_tabla_elegible('assignment_links', $resultado['tablas']['assignment_links'], []), 'Backend descartó una tabla asociativa no confirmada como pivote.');
    echo "OK: pivote confirmado, caso ambiguo y module_teachers asociativa\n";
} finally {
    migraciones_limpiar_fixture($migraciones_pivotes);
}

$migraciones_parcial = migraciones_fixture_con_archivos([
    '2026_06_01_000000_unknown_column.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('partial_items', function ($table) {
            $table->id();
            $table->geography('shape');
        });
    }
};
MIGRATION,
]);
$frontend_temporal = migraciones_fixture();
file_put_contents($migraciones_parcial . '/keep.txt', 'backend sentinel');
file_put_contents($frontend_temporal . '/keep.txt', 'frontend sentinel');
try {
    $resultado = analizar_migraciones($migraciones_parcial);
    migraciones_assert($resultado['analysis']['status'] === 'partial', 'El fixture de guarda no produjo partial.');
    $backend = generar_backend_crud($migraciones_parcial, []);
    $frontend = generar_frontend_crud($frontend_temporal, $migraciones_parcial, []);
    migraciones_assert(!empty($backend['errores'][0]) && str_contains($backend['errores'][0], 'Generación bloqueada'), 'Backend no bloqueó un análisis estructural parcial.');
    migraciones_assert(!empty($frontend['errores'][0]) && str_contains($frontend['errores'][0], 'Generación bloqueada'), 'Frontend no bloqueó un análisis estructural parcial.');
    migraciones_assert(!is_dir($migraciones_parcial . '/app') && !is_dir($frontend_temporal . '/app'), 'La guarda creó carpetas de generación.');
    migraciones_assert(file_get_contents($migraciones_parcial . '/keep.txt') === 'backend sentinel', 'La guarda alteró un archivo del Backend.');
    migraciones_assert(file_get_contents($frontend_temporal . '/keep.txt') === 'frontend sentinel', 'La guarda alteró un archivo del Frontend.');

    $fallido = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-failed-' . bin2hex(random_bytes(6));
    mkdir($fallido);
    try {
        $resultado_fallido = analizar_migraciones($fallido);
        migraciones_assert($resultado_fallido['analysis']['status'] === 'failed', 'La falta de migraciones no produjo failed.');
        migraciones_assert($resultado_fallido['analysis']['generation_safe'] === false, 'El análisis failed se marcó como seguro.');
        migraciones_assert(guardia_generacion_migraciones($resultado_fallido)['bloqueada'], 'La guarda no bloqueó el análisis failed.');
        $backend_fallido = generar_backend_crud($fallido, []);
        $frontend_fallido = generar_frontend_crud($frontend_temporal, $fallido, []);
        migraciones_assert(str_contains($backend_fallido['errores'][0] ?? '', 'Generación bloqueada'), 'Backend no bloqueó el análisis failed.');
        migraciones_assert(str_contains($frontend_fallido['errores'][0] ?? '', 'Generación bloqueada'), 'Frontend no bloqueó el análisis failed.');
        migraciones_assert(!is_dir($fallido . '/app') && !is_dir($frontend_temporal . '/app'), 'El análisis failed creó carpetas de generación.');
        migraciones_assert(guardia_generacion_migraciones([
            'tablas' => [],
            'analysis' => [
                'status' => 'partial',
                'generation_safe' => true,
                'diagnostics' => [['severity' => 'warning', 'impact' => 'informational', 'message' => 'Aviso informativo.']],
            ],
        ])['bloqueada'] === false, 'La guarda bloqueó un diagnóstico marcado informativo.');
    } finally {
        rmdir($fallido);
    }
    echo "OK: guardas Backend/Frontend, análisis failed y archivos intactos\n";
} finally {
    migraciones_limpiar_arbol_temporal($migraciones_parcial);
    migraciones_limpiar_arbol_temporal($frontend_temporal);
}

$raiz_generacion_completa = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crud-complete-' . bin2hex(random_bytes(6));
$backend_completo = $raiz_generacion_completa . DIRECTORY_SEPARATOR . 'Backend';
$frontend_completo = $raiz_generacion_completa . DIRECTORY_SEPARATOR . 'Frontend';
$migrations_completo = $backend_completo . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
mkdir($migrations_completo, 0777, true);
mkdir($frontend_completo, 0777, true);
migraciones_escribir($backend_completo, '2026_07_01_000000_create_complete_items.php', <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('complete_items', function ($table) {
            $table->id();
            $table->string('name');
        });
    }
};
MIGRATION
);
try {
    $analisis_completo = analizar_migraciones($backend_completo);
    migraciones_assert($analisis_completo['analysis']['status'] === 'complete', 'El fixture de generación no produjo análisis complete.');
    migraciones_assert($analisis_completo['relationship_analysis']['status'] === 'complete' && $analisis_completo['relations'] === [], 'Una tabla sin FKs no produjo un análisis de relaciones limpio.');
    migraciones_assert(!guardia_generacion_migraciones($analisis_completo)['bloqueada'], 'La guarda bloqueó una generación válida.');
    $backend_generado = generar_backend_crud($backend_completo, ['nombre_proyecto' => 'audit-test']);
    $frontend_generado = generar_frontend_crud($frontend_completo, $backend_completo, ['nombre_proyecto' => 'audit-test']);
    migraciones_assert(count($backend_generado['modelos']) === 1 && count($backend_generado['controladores']) === 0, 'Backend no protegió una entidad sin mapeo de validaciones fuente.');
    migraciones_assert(count(array_filter(
        $backend_generado['adaptaciones_pendientes'],
        static fn($pendiente) => $pendiente['tipo'] === 'controlador API'
    )) === 1, 'Backend no registró el mapeo de validación faltante.');
    migraciones_assert(
        $frontend_generado['controladores'] === []
            && $frontend_generado['vistas'] === []
            && count($frontend_generado['adaptaciones_pendientes']) === 1,
        'Frontend generó un diseño genérico sin manifiesto fuente explícito: ' . json_encode($frontend_generado, JSON_UNESCAPED_UNICODE)
    );
    $modelo_generado = file_get_contents($backend_completo . '/app/Models/CompleteItem.php');
    migraciones_assert(str_contains($modelo_generado, 'name') && !is_dir($frontend_completo . '/resources/views/complete_items'), 'Backend válido o frontend pendiente se generó incorrectamente.');
    echo "OK: Backend complete y Frontend queda pendiente sin mapeo de fuente\n";
} finally {
    migraciones_limpiar_arbol_temporal($raiz_generacion_completa);
}

$migraciones_clasificacion = migraciones_fixture_con_archivos([
    '2026_08_01_000000_classification.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('students', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
        });
        Schema::create('roles', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('modules', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('teachers', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('role_user', function ($table) {
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('user_id')->constrained('users');
            $table->primary(['role_id', 'user_id']);
        });
        Schema::create('role_user_links', function ($table) {
            $table->foreignId('role_id')->constrained('roles');
            $table->foreignId('user_id')->constrained('users');
            $table->unique(['role_id', 'user_id']);
            $table->geography('shape');
        });
        Schema::create('module_teachers', function ($table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules');
            $table->foreignId('teacher_id')->constrained('teachers');
            $table->timestamps();
        });
        Schema::create('migrations', function ($table) {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        Schema::create('password_reset_tokens', function ($table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('password_resets', function ($table) {
            $table->string('email')->index();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('sessions', function ($table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::create('cache_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', function ($table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Schema::create('failed_jobs', function ($table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        Schema::create('personal_access_tokens', function ($table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('loose_imports', function ($table) {
            $table->string('source');
            $table->text('payload');
        });
        Schema::create('review_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->geography('shape');
        });
    }
};
MIGRATION,
]);
try {
    $resultado_clasificacion = analizar_migraciones($migraciones_clasificacion);
    $tablas_clasificadas = $resultado_clasificacion['tablas'];
    migraciones_assert($tablas_clasificadas['students']['classification']['type'] === 'crud_entity', 'Una entidad con PK y campos de negocio no se clasificó como CRUD.');
    migraciones_assert($tablas_clasificadas['role_user']['classification']['type'] === 'pure_pivot', 'El pivote puro no recibió la clasificación estructural.');
    migraciones_assert($tablas_clasificadas['role_user_links']['es_pivote'] === true, 'No se reprodujo el caso legacy de pivote con columna desconocida.');
    migraciones_assert($tablas_clasificadas['role_user_links']['classification']['type'] === 'ambiguous', 'Una columna desconocida no invalidó la clasificación conservadora del pivote.');
    migraciones_assert(es_tabla_elegible('role_user_links', $tablas_clasificadas['role_user_links'], []), 'Backend descartó una tabla ambigua por el indicador legacy es_pivote.');
    migraciones_assert($tablas_clasificadas['module_teachers']['classification']['type'] === 'associative_entity', 'module_teachers con PK propia no se clasificó como entidad asociativa.');
    migraciones_assert(es_tabla_elegible('module_teachers', $tablas_clasificadas['module_teachers'], []), 'Backend excluyó una entidad asociativa con PK propia.');
    migraciones_assert(!es_tabla_elegible_front('module_teachers', $tablas_clasificadas['module_teachers'], []), 'Frontend debe excluir la entidad asociativa de module_teachers, como la referencia.');
    foreach (['migrations', 'password_reset_tokens', 'password_resets', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $tabla_framework) {
        migraciones_assert($tablas_clasificadas[$tabla_framework]['classification']['type'] === 'framework_auxiliary', "$tabla_framework no se reconoció por su firma estructural.");
        migraciones_assert(!es_tabla_elegible($tabla_framework, $tablas_clasificadas[$tabla_framework], []), "Backend dejó elegible la tabla auxiliar $tabla_framework.");
        migraciones_assert(!es_tabla_elegible_front($tabla_framework, $tablas_clasificadas[$tabla_framework], []), "Frontend dejó elegible la tabla auxiliar $tabla_framework.");
    }
    migraciones_assert($tablas_clasificadas['personal_access_tokens']['classification']['type'] === 'framework_auxiliary', 'No se reconoció la firma morphs conocida de personal_access_tokens.');
    migraciones_assert($tablas_clasificadas['personal_access_tokens']['classification']['requires_review'] === true, 'La morfología no analizada no quedó señalada para revisión.');
    migraciones_assert($tablas_clasificadas['users']['classification']['type'] === 'crud_entity', 'La tabla de aplicación users se clasificó automáticamente como auxiliar.');
    migraciones_assert(es_tabla_elegible('users', $tablas_clasificadas['users'], []), 'Backend excluyó una tabla users con estructura de entidad CRUD.');
    migraciones_assert(es_tabla_elegible('sessions', $tablas_clasificadas['sessions'], ['tablas_excluidas' => []]), 'Se perdió la posibilidad de sobrescribir las exclusiones predeterminadas.');
    migraciones_assert($tablas_clasificadas['loose_imports']['classification']['type'] === 'ambiguous', 'Una tabla sin PK se clasificó como entidad sin evidencia suficiente.');
    migraciones_assert($tablas_clasificadas['review_items']['classification']['type'] === 'ambiguous', 'Una tabla con esquema no reconocido no quedó ambigua.');
    migraciones_assert($tablas_clasificadas['review_items']['classification']['requires_review'] === true, 'La tabla ambigua no requiere revisión.');
    migraciones_assert(!es_tabla_elegible('legacy_pivot', ['es_pivote' => true], []), 'Se perdió compatibilidad de elegibilidad con el campo legacy es_pivote.');
    echo "OK: clasificación estructural de entidades, pivotes, auxiliares y casos ambiguos\n";
} finally {
    migraciones_limpiar_arbol_temporal($migraciones_clasificacion);
}

$migraciones_lookalike = migraciones_fixture_con_archivos([
    '2026_08_02_000000_cache_is_an_entity.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('cache', function ($table) {
            $table->id();
            $table->string('title');
            $table->text('content');
        });
    }
};
MIGRATION,
]);
try {
    $resultado_lookalike = analizar_migraciones($migraciones_lookalike);
    $cache_aplicacion = $resultado_lookalike['tablas']['cache'];
    migraciones_assert($cache_aplicacion['classification']['type'] === 'crud_entity', 'El nombre cache bastó para clasificar una entidad como auxiliar.');
    migraciones_assert(es_tabla_elegible('cache', $cache_aplicacion, []), 'Backend excluyó una entidad legítima por su nombre reservado de framework.');
    migraciones_assert(es_tabla_elegible_front('cache', $cache_aplicacion, []), 'Frontend excluyó una entidad legítima por su nombre reservado de framework.');
    echo "OK: una coincidencia de nombre sin firma framework no excluye una entidad\n";
} finally {
    migraciones_limpiar_fixture($migraciones_lookalike);
}

$migraciones_relaciones = migraciones_fixture_con_archivos([
    '2026_09_01_000000_relationships.php' => <<<'MIGRATION'
<?php
return new class {
    public function up(): void
    {
        Schema::create('departments', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('employees', function ($table) {
            $table->id();
            $table->unsignedBigInteger('department_id');
            $table->foreign('department_id', 'employees_department_custom_fk')
                ->references('id')->on('departments')->cascadeOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('employees');
            $table->foreignId('unverified_id');
            $table->boolean('is_active');
            $table->json('preferences');
            $table->decimal('salary', 8, 2);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('profiles', function ($table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees');
            $table->string('display_name');
        });
        Schema::create('notes', function ($table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees');
            $table->string('department_name');
            $table->foreign('department_name')->references('name')->on('departments');
            $table->text('body');
        });
        Schema::create('skills', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('employee_skill', function ($table) {
            $table->foreignId('employee_id')->constrained('employees');
            $table->foreignId('skill_id')->constrained('skills');
            $table->primary(['employee_id', 'skill_id']);
        });
        Schema::create('modules', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('teachers', function ($table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('module_teachers', function ($table) {
            $table->id();
            $table->foreignId('module_id')->constrained('modules');
            $table->foreignId('teacher_id')->constrained('teachers');
            $table->timestamps();
        });
        Schema::create('missing_links', function ($table) {
            $table->id();
            $table->foreign('department_id', 'missing_department_fk')
                ->references('id')->on('unmigrated_departments');
        });
        Schema::create('broken_links', function ($table) {
            $table->id();
            $table->foreign('missing_department_id')
                ->references('id')->on('departments');
        });
        Schema::create('unresolved_links', function ($table) {
            $table->id();
            $table->foreignId('category_id')->constrained();
        });
        Schema::create('composite_parents', function ($table) {
            $table->string('left_key');
            $table->string('right_key');
            $table->primary(['left_key', 'right_key']);
        });
        Schema::create('composite_children', function ($table) {
            $table->id();
            $table->string('parent_left');
            $table->string('parent_right');
            $table->foreign(['parent_left', 'parent_right'])
                ->references(['left_key', 'right_key'])->on('composite_parents');
        });
    }
};
MIGRATION,
]);
try {
    $resultado_relaciones = analizar_migraciones($migraciones_relaciones);
    $relaciones = $resultado_relaciones['relations'];
    $buscar_relacion = static function ($tipo, $desde, $hacia) use ($relaciones) {
        foreach ($relaciones as $relacion) {
            if ($relacion['type'] === $tipo
                && $relacion['from']['table'] === $desde
                && $relacion['to']['table'] === $hacia) {
                return $relacion;
            }
        }
        return null;
    };

    $employee_belongs_to_department = $buscar_relacion('belongsTo', 'employees', 'departments');
    migraciones_assert($employee_belongs_to_department !== null, 'No se produjo belongsTo desde employees hacia departments.');
    migraciones_assert($employee_belongs_to_department['foreign_key']['name'] === 'employees_department_custom_fk', 'Se perdió el nombre personalizado de FK.');
    migraciones_assert($employee_belongs_to_department['foreign_key']['on_delete'] === 'cascade', 'Se perdió onDelete de la FK.');
    migraciones_assert(in_array(['column' => 'name', 'type' => 'string'], $employee_belongs_to_department['form_fields']['select_field_candidates'], true), 'No se expuso el campo real candidato para el select.');
    migraciones_assert($buscar_relacion('hasMany', 'departments', 'employees') !== null, 'No se produjo la relación inversa hasMany.');
    migraciones_assert($buscar_relacion('belongsTo', 'employees', 'employees') !== null, 'No se conservó la FK autorreferente como belongsTo.');
    migraciones_assert($buscar_relacion('hasOne', 'employees', 'profiles') !== null, 'No se determinó hasOne desde la FK unique.');
    migraciones_assert($buscar_relacion('hasMany', 'employees', 'notes') !== null, 'No se determinó hasMany desde una FK no única.');
    $non_unique_target = $buscar_relacion('belongsTo', 'notes', 'departments');
    migraciones_assert($non_unique_target !== null && $non_unique_target['status'] === 'partial', 'No se señaló la clave destino que no es PK ni unique.');
    migraciones_assert($buscar_relacion('hasMany', 'departments', 'notes') === null, 'Se generó relación inversa con clave destino no única.');

    $m2m_employee_skill = $buscar_relacion('belongsToMany', 'employees', 'skills');
    migraciones_assert($m2m_employee_skill !== null && $m2m_employee_skill['pivot_table'] === 'employee_skill', 'No se derivó belongsToMany desde el pivote puro.');
    migraciones_assert($buscar_relacion('belongsToMany', 'skills', 'employees') !== null, 'No se generó la dirección inversa belongsToMany.');
    $module_teachers_m2m = $buscar_relacion('belongsToMany', 'modules', 'teachers');
    migraciones_assert($module_teachers_m2m !== null && $module_teachers_m2m['pivot_table'] === 'module_teachers', 'module_teachers no produjo belongsToMany con su PK propia.');
    migraciones_assert($buscar_relacion('hasMany', 'modules', 'module_teachers') !== null, 'No se conservaron relaciones hacia la entidad asociativa.');

    $modelo_employee = generar_codigo_modelo(
        'employees',
        $resultado_relaciones['tablas']['employees'],
        $resultado_relaciones['tablas'],
        [],
        $relaciones
    );
    migraciones_assert(str_contains($modelo_employee, 'public $timestamps = true;'), 'El modelo no activó timestamps detectados.');
    migraciones_assert(str_contains($modelo_employee, 'use SoftDeletes;') && str_contains($modelo_employee, 'use Illuminate\\Database\\Eloquent\\SoftDeletes;'), 'El modelo no configuró SoftDeletes detectado.');
    migraciones_assert(str_contains($modelo_employee, "'is_active' => 'boolean'") && str_contains($modelo_employee, "'preferences' => 'array'") && str_contains($modelo_employee, "'salary' => 'decimal:2'"), 'Los casts no corresponden a tipos y escala declarados.');
    preg_match('/protected \$fillable = \[(.*?)\];/s', $modelo_employee, $fillable_employee);
    migraciones_assert(isset($fillable_employee[1]) && str_contains($fillable_employee[1], "'department_id'")
        && !str_contains($fillable_employee[1], "'id'") && !str_contains($fillable_employee[1], "'created_at'")
        && !str_contains($fillable_employee[1], "'deleted_at'"), 'El fillable incluye PK o columnas administradas.');
    migraciones_assert(str_contains($modelo_employee, "belongsTo(Department::class, 'department_id', 'id')"), 'El modelo no usa las claves B04 de belongsTo.');
    migraciones_assert(str_contains($modelo_employee, 'function profile(): HasOne') && str_contains($modelo_employee, "hasOne(Profile::class, 'employee_id', 'id')"), 'El modelo no generó hasOne desde la FK unique.');
    migraciones_assert(str_contains($modelo_employee, 'function notes(): HasMany') && str_contains($modelo_employee, "hasMany(Note::class, 'employee_id', 'id')"), 'El modelo no generó hasMany desde B04.');
    migraciones_assert(str_contains($modelo_employee, 'function skills(): BelongsToMany')
        && str_contains($modelo_employee, "'employee_skill',\n            'employee_id',\n            'skill_id',\n            'id',\n            'id'"), 'El modelo no generó belongsToMany con todas las claves del pivote.');
    migraciones_assert(str_contains($modelo_employee, "belongsTo(Employee::class, 'manager_id', 'id')"), 'No se generó la FK autorreferente declarada.');
    $modelo_module_teachers = generar_codigo_modelo(
        'module_teachers',
        $resultado_relaciones['tablas']['module_teachers'],
        $resultado_relaciones['tablas'],
        [],
        $relaciones
    );
    migraciones_assert(str_contains($modelo_module_teachers, 'belongsTo(Module::class') && str_contains($modelo_module_teachers, 'belongsTo(Teacher::class'), 'La entidad asociativa no generó sus belongsTo.');
    migraciones_assert(!str_contains($modelo_module_teachers, 'belongsToMany('), 'La entidad asociativa se generó como pivote muchos-a-muchos.');

    $missing_relation = $buscar_relacion('ambiguous', 'missing_links', 'unmigrated_departments');
    migraciones_assert($missing_relation !== null, 'No se reportó el destino FK ausente.');
    $incomplete_relation = $buscar_relacion('ambiguous', 'broken_links', 'departments');
    migraciones_assert($incomplete_relation !== null && $incomplete_relation['status'] === 'incomplete', 'No se reportó una FK con columna de origen ausente.');
    $unresolved_relation = $buscar_relacion('ambiguous', 'unresolved_links', null);
    migraciones_assert($unresolved_relation !== null, 'No se reportó el constrained() sin destino comprobable.');
    migraciones_assert($buscar_relacion('unsupported', 'composite_children', 'composite_parents') !== null, 'La FK compuesta no se marcó como no soportada por relaciones Eloquent estándar.');
    foreach ($relaciones as $relacion) {
        migraciones_assert(!in_array('unverified_id', $relacion['keys']['foreign']['columns'] ?? [], true), 'Se inventó una relación por el sufijo _id sin FK.');
    }
    migraciones_assert(in_array([
        'table' => 'employees',
        'column' => 'unverified_id',
        'reason' => 'column_name_alone_is_not_foreign_key_evidence',
    ], $resultado_relaciones['relationship_analysis']['unverified_columns'], true), 'No se documentó la columna _id sin evidencia de FK.');
    migraciones_assert($resultado_relaciones['relationship_analysis']['status'] === 'partial', 'Las relaciones incompletas no marcaron el análisis parcial.');
    foreach ($resultado_relaciones['tablas'] as $tabla) {
        foreach (array_merge($tabla['relationships']['outgoing'], $tabla['relationships']['incoming']) as $id_relacion) {
            migraciones_assert(count(array_filter($relaciones, static fn($relacion) => $relacion['id'] === $id_relacion)) === 1, 'Un índice de relación por tabla no apunta a una relación global única.');
        }
    }
    echo "OK: B04 FK, relaciones inversas, cardinalidad, pivotes, campos de formulario y diagnósticos\n";
} finally {
    migraciones_limpiar_fixture($migraciones_relaciones);
}

require_once __DIR__ . '/backend_source_adaptation_test.php';
