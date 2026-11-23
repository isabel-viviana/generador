<?php
// Generador Backend: Modelos Eloquent, Controladores API CRUD, Rutas, Cloudinary y Configuración Laravel.

if (!defined('CRUD_DIR')) {
    define('CRUD_DIR', dirname(__DIR__));
}

require_once CRUD_DIR . '/src/util.php';
require_once CRUD_DIR . '/src/migraciones.php';
require_once CRUD_DIR . '/src/source_adaptation.php';

// Determina si una tabla es elegible para generación CRUD.
function es_tabla_elegible($nombre_tabla, $tabla_info, $config)
{
    $exclusiones_legacy = [
        'users', 'migrations', 'password_reset_tokens', 'password_resets',
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'personal_access_tokens'
    ];
    $tipo_clasificacion = $tabla_info['classification']['type'] ?? null;
    $tiene_exclusiones_explicitas = isset($config['tablas_excluidas']);
    $excluidas = $config['tablas_excluidas']
        ?? ($tipo_clasificacion === null ? $exclusiones_legacy : []);

    if (in_array($nombre_tabla, $excluidas, true)) {
        return false;
    }

    if ($tipo_clasificacion === 'pure_pivot'
        || ($tipo_clasificacion === 'framework_auxiliary' && !$tiene_exclusiones_explicitas)) {
        return false;
    }

    if ($tipo_clasificacion === null && !empty($tabla_info['es_pivote'])) {
        return false;
    }

    // Validar que el nombre de tabla y la clase resultante sean identificadores PHP válidos
    $clase = nombre_clase($nombre_tabla, $config);
    if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $clase)) {
        return false;
    }

    $palabras_reservadas = [
        'class', 'interface', 'trait', 'enum', 'function', 'fn', 'namespace',
        'self', 'static', 'parent', 'readonly', 'void', 'never', 'null', 'true', 'false', 'match'
    ];
    if (in_array(strtolower($clase), $palabras_reservadas, true)) {
        return false;
    }

    return true;
}

// Deriva reglas de validación exclusivamente de atributos y restricciones analizados.
function _inferir_regla_validacion($col_info, $es_update = false)
{
    $nullable = $col_info['nullable'] ?? null;
    $default_metadata = $col_info['default_metadata'] ?? null;
    $tiene_default = is_array($default_metadata)
        ? !empty($default_metadata['declarado'])
        : (array_key_exists('default', $col_info) && $col_info['default'] !== null);
    foreach ($col_info['modificadores'] ?? [] as $modificador) {
        if (in_array($modificador['metodo'] ?? null, ['useCurrent', 'useCurrentOnUpdate'], true)) {
            $tiene_default = true;
            break;
        }
    }

    $es_imagen = !empty($col_info['es_imagen']);
    $reglas = [];
    if ($es_update) {
        $reglas[] = 'sometimes';
        if ($nullable === true || $es_imagen) {
            $reglas[] = 'nullable';
        }
    } elseif ($nullable === true) {
        $reglas[] = 'nullable';
    } elseif ($nullable === false) {
        $reglas[] = $tiene_default ? 'sometimes' : 'required';
    } elseif ($tiene_default) {
        $reglas[] = 'sometimes';
    }

    $tipo = strtolower((string)($col_info['tipo_normalizado'] ?? $col_info['tipo'] ?? ''));
    $opciones = $col_info['opciones'] ?? [];
    $valores_enum = [];
    if ($es_imagen) {
        $reglas[] = 'image';
    } elseif (in_array($tipo, [
        'integer', 'tinyinteger', 'smallinteger', 'mediuminteger', 'biginteger',
        'unsignedinteger', 'unsignedtinyinteger', 'unsignedsmallinteger',
        'unsignedmediuminteger', 'unsignedbiginteger', 'foreignid', 'foreignidfor',
    ], true)) {
        $reglas[] = 'integer';
    } elseif ($tipo === 'boolean') {
        $reglas[] = 'boolean';
    } elseif (in_array($tipo, ['decimal', 'float', 'double'], true)) {
        $reglas[] = 'numeric';
    } elseif (in_array($tipo, ['date', 'datetime', 'datetimetz', 'timestamp', 'timestamptz'], true)) {
        $reglas[] = 'date';
    } elseif ($tipo === 'json') {
        $reglas[] = 'array';
    } elseif (in_array($tipo, ['string', 'char', 'text', 'mediumtext', 'longtext', 'enum', 'uuid', 'ulid'], true)) {
        $reglas[] = 'string';
        if (in_array($tipo, ['string', 'char'], true)) {
            $longitud = $opciones['longitud'] ?? null;
            $argumentos_tipo = $col_info['argumentos_tipo'] ?? [];
            if (is_numeric($longitud)) {
                $reglas[] = 'max:' . (int)$longitud;
            } elseif (count($argumentos_tipo) <= 1) {
                $reglas[] = 'max:255';
            }
        }
        if ($tipo === 'enum' && is_array($opciones['valores'] ?? null)) {
            $valores_enum = array_values($opciones['valores']);
        }
    }

    if (!empty($col_info['unsigned']) && in_array('integer', $reglas, true)) {
        $reglas[] = 'min:0';
    } elseif (!empty($col_info['unsigned']) && in_array('numeric', $reglas, true)) {
        $reglas[] = 'min:0';
    }

    return [
        'base' => $reglas,
        'valores_enum' => $valores_enum,
    ];
}

// Convierte exclusivamente relaciones verificadas por B04 a especificaciones de métodos Eloquent.
function obtener_relaciones_modelo($nombre_tabla, $tabla_info, $todas_tablas, $config, $relaciones_b04 = [])
{
    $relaciones = [
        'belongs_to' => [],
        'has_one' => [],
        'has_many' => [],
        'belongs_to_many' => [],
    ];
    $tipos_entidad = ['crud_entity', 'associative_entity'];
    if (!in_array($tabla_info['classification']['type'] ?? null, $tipos_entidad, true)) {
        return $relaciones;
    }

    $metodos_usados = [];
    $nombre_metodo = static function ($base, $desambiguador = '') use (&$metodos_usados) {
        $metodo = lcfirst(studly($base));
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $metodo)) {
            $metodo = 'relation';
        }
        $reservados = [
            'class', 'function', 'return', 'if', 'else', 'new', 'clone', 'throw', 'try', 'catch',
            'finally', 'while', 'for', 'foreach', 'switch', 'match', 'yield', 'static', 'self',
            'parent', 'use', 'namespace', 'trait', 'interface', 'enum', 'extends', 'implements',
            'public', 'protected', 'private', 'const', 'var', 'goto', 'echo', 'print', 'exit',
            'die', 'isset', 'empty', 'unset', 'list',
        ];
        if (in_array(strtolower($metodo), $reservados, true) || isset($metodos_usados[$metodo])) {
            $metodo .= $desambiguador === '' ? 'Relation' : 'By' . studly($desambiguador);
        }
        $sufijo = 2;
        $base_metodo = $metodo;
        while (isset($metodos_usados[$metodo]) || in_array(strtolower($metodo), $reservados, true)) {
            $metodo = $base_metodo . 'Relation' . $sufijo++;
        }
        $metodos_usados[$metodo] = true;
        return $metodo;
    };
    foreach ($relaciones_b04 as $relacion) {
        if (($relacion['status'] ?? null) !== 'complete'
            || ($relacion['from']['table'] ?? null) !== $nombre_tabla) {
            continue;
        }
        $tipo = $relacion['type'] ?? null;
        if (!in_array($tipo, ['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'], true)) {
            continue;
        }

        $tabla_relacionada = $relacion['to']['table'] ?? null;
        $info_relacionada = $tabla_relacionada === null ? null : ($todas_tablas[$tabla_relacionada] ?? null);
        if ($info_relacionada === null
            || !in_array($info_relacionada['classification']['type'] ?? null, $tipos_entidad, true)) {
            continue;
        }
        $clase = nombre_clase($tabla_relacionada, $config);
        $keys = $relacion['keys'] ?? [];

        if ($tipo === 'belongsTo') {
            $foreign_key = $keys['foreign']['columns'] ?? [];
            $owner_key = $keys['owner']['columns'] ?? [];
            if (($keys['foreign']['table'] ?? null) !== $nombre_tabla
                || ($keys['owner']['table'] ?? null) !== $tabla_relacionada
                || count($foreign_key) !== 1 || count($owner_key) !== 1) {
                continue;
            }
            $base = preg_replace('/_id$/', '', $foreign_key[0]) ?: singular($tabla_relacionada);
            $relaciones['belongs_to'][] = [
                'metodo' => $nombre_metodo($base, $foreign_key[0]),
                'clase' => $clase,
                'foreign_key' => $foreign_key[0],
                'owner_key' => $owner_key[0],
            ];
        } elseif ($tipo === 'hasOne' || $tipo === 'hasMany') {
            $foreign_key = $keys['foreign']['columns'] ?? [];
            $local_key = $keys['owner']['columns'] ?? [];
            if (($keys['foreign']['table'] ?? null) !== $tabla_relacionada
                || ($keys['owner']['table'] ?? null) !== $nombre_tabla
                || count($foreign_key) !== 1 || count($local_key) !== 1) {
                continue;
            }
            $collection = $tipo === 'hasOne' ? 'has_one' : 'has_many';
            $base = $tipo === 'hasOne'
                ? singular($tabla_relacionada)
                : plural(singular($tabla_relacionada));
            $relaciones[$collection][] = [
                'metodo' => $nombre_metodo($base, $foreign_key[0]),
                'clase' => $clase,
                'foreign_key' => $foreign_key[0],
                'local_key' => $local_key[0],
            ];
        } else {
            $foreign_key = $keys['foreign']['columns'] ?? [];
            $related_key = $keys['related']['columns'] ?? [];
            $owner_key = $keys['owner']['columns'] ?? [];
            $related_owner_key = $keys['related_owner']['columns'] ?? [];
            $pivot_table = $relacion['pivot_table'] ?? null;
            if (($keys['foreign']['table'] ?? null) !== $pivot_table
                || ($keys['related']['table'] ?? null) !== $pivot_table
                || count($foreign_key) !== 1 || count($related_key) !== 1
                || count($owner_key) !== 1 || count($related_owner_key) !== 1
                || $pivot_table === null) {
                continue;
            }
            $relaciones['belongs_to_many'][] = [
                'metodo' => $nombre_metodo(plural(singular($tabla_relacionada)), 'via_' . $pivot_table),
                'clase' => $clase,
                'pivot_table' => $pivot_table,
                'foreign_pivot_key' => $foreign_key[0],
                'related_pivot_key' => $related_key[0],
                'parent_key' => $owner_key[0],
                'related_key' => $related_owner_key[0],
            ];
        }
    }

    return $relaciones;
}

// Genera el código fuente de un Modelo Eloquent respetando la estructura limpia definida.
function generar_codigo_modelo($nombre_tabla, $tabla_info, $todas_tablas, $config, $relaciones_b04 = [])
{
    $plantilla_path = CRUD_DIR . '/templates/back/model.tpl';
    if (!file_exists($plantilla_path)) {
        throw new RuntimeException("Plantilla no encontrada: $plantilla_path");
    }
    $tpl = file_get_contents($plantilla_path);

    $clase_nombre = nombre_clase($nombre_tabla, $config);

    $primary_key = array_values($tabla_info['primary_key']['columnas'] ?? []);
    if (count($primary_key) !== 1) {
        throw new RuntimeException("No se puede generar el modelo Eloquent de '$nombre_tabla' sin una PK simple analizada.");
    }

    // 1. Fillable: excluir claves y campos gestionados por Eloquent.
    $columnas_fillable = [];
    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if (in_array($c_nom, $primary_key, true) || !empty($c_def['es_pk']) || !empty($c_def['autoincrement'])
            || in_array($c_nom, ['created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        $columnas_fillable[] = '        ' . var_export($c_nom, true) . ',';
    }
    $fillable_str = implode("\n", $columnas_fillable);

    // 2. Tabla y clave primaria explícitas solo cuando Eloquent no puede inferirlas.
    $tabla_esperada_plural = strtolower(snake(plural(singular($clase_nombre))));
    $tabla_property = '';
    if (!empty($config['clases'][$nombre_tabla]) || $nombre_tabla !== $tabla_esperada_plural) {
        $tabla_property = '    protected $table = ' . var_export($nombre_tabla, true) . ";\n\n";
    }
    $key_properties = '';
    if ($primary_key[0] !== 'id') {
        $key_properties .= '    protected $primaryKey = ' . var_export($primary_key[0], true) . ";\n";
    }
    $primary_column = $tabla_info['columnas'][$primary_key[0]] ?? [];
    $primary_type = $primary_column['tipo_normalizado'] ?? null;
    if (in_array($primary_type, ['string', 'uuid', 'ulid'], true)) {
        $key_properties .= "    protected \$keyType = 'string';\n";
    }
    if (($tabla_info['primary_key']['generacion'] ?? null) !== 'auto_increment') {
        $key_properties .= "    public \$incrementing = false;\n";
    }
    if ($key_properties !== '') {
        $key_properties .= "\n";
    }

    $columnas = $tabla_info['columnas'] ?? [];
    $tipos_fecha = ['datetime', 'timestamp'];
    $tiene_timestamps = isset($columnas['created_at'], $columnas['updated_at'])
        && in_array($columnas['created_at']['tipo_normalizado'] ?? null, $tipos_fecha, true)
        && in_array($columnas['updated_at']['tipo_normalizado'] ?? null, $tipos_fecha, true);
    $timestamps_property = '    public $timestamps = ' . ($tiene_timestamps ? 'true' : 'false') . ";\n\n";

    $usa_soft_deletes = !empty($tabla_info['soft_deletes'])
        && isset($columnas['deleted_at'])
        && in_array($columnas['deleted_at']['tipo_normalizado'] ?? null, $tipos_fecha, true);
    $soft_deletes_import = $usa_soft_deletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n" : '';
    $soft_deletes_trait = $usa_soft_deletes ? "    use SoftDeletes;\n\n" : '';

    $casts = [];
    foreach ($columnas as $nombre_columna => $columna) {
        if (in_array($nombre_columna, ['created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        $tipo = $columna['tipo_normalizado'] ?? null;
        $tipo_laravel = $columna['tipo_laravel'] ?? $columna['tipo'] ?? null;
        $cast = null;
        if ($tipo === 'integer') {
            $cast = 'integer';
        } elseif ($tipo === 'boolean') {
            $cast = 'boolean';
        } elseif ($tipo === 'json') {
            $cast = 'array';
        } elseif ($tipo === 'date') {
            $cast = 'date';
        } elseif (in_array($tipo, ['datetime', 'timestamp'], true)) {
            $cast = 'datetime';
        } elseif ($tipo_laravel === 'float') {
            $cast = 'float';
        } elseif ($tipo_laravel === 'double') {
            $cast = 'double';
        } elseif ($tipo_laravel === 'decimal' && array_key_exists('escala', $columna['opciones'] ?? [])) {
            $cast = 'decimal:' . (int)$columna['opciones']['escala'];
        }
        if ($cast !== null) {
            $casts[] = '        ' . var_export($nombre_columna, true) . ' => ' . var_export($cast, true) . ',';
        }
    }
    $casts_str = $casts === [] ? '' : "\n    protected \$casts = [\n" . implode("\n", $casts) . "\n    ];\n";

    // 3. Solo generar relaciones completas y verificadas por B04.
    $relaciones = obtener_relaciones_modelo($nombre_tabla, $tabla_info, $todas_tablas, $config, $relaciones_b04);
    $metodos_relaciones = [];

    $usa_belongs_to = !empty($relaciones['belongs_to']);
    $usa_has_one = !empty($relaciones['has_one']);
    $usa_has_many = !empty($relaciones['has_many']);
    $usa_belongs_to_many = !empty($relaciones['belongs_to_many']);

    foreach ($relaciones['belongs_to'] as $rel) {
        $related_class = strpos($rel['clase'], '\\') === false
            ? $rel['clase'] . '::class'
            : '\\' . ltrim($rel['clase'], '\\') . '::class';
        $foreign_key = var_export($rel['foreign_key'], true);
        $owner_key = var_export($rel['owner_key'], true);
        $metodos_relaciones[] = <<<PHP
    public function {$rel['metodo']}(): BelongsTo
    {
        return \$this->belongsTo({$related_class}, {$foreign_key}, {$owner_key});
    }
PHP;
    }

    foreach ($relaciones['has_one'] as $rel) {
        $related_class = strpos($rel['clase'], '\\') === false
            ? $rel['clase'] . '::class'
            : '\\' . ltrim($rel['clase'], '\\') . '::class';
        $foreign_key = var_export($rel['foreign_key'], true);
        $local_key = var_export($rel['local_key'], true);
        $metodos_relaciones[] = <<<PHP
    public function {$rel['metodo']}(): HasOne
    {
        return \$this->hasOne({$related_class}, {$foreign_key}, {$local_key});
    }
PHP;
    }

    foreach ($relaciones['has_many'] as $rel) {
        $related_class = strpos($rel['clase'], '\\') === false
            ? $rel['clase'] . '::class'
            : '\\' . ltrim($rel['clase'], '\\') . '::class';
        $foreign_key = var_export($rel['foreign_key'], true);
        $local_key = var_export($rel['local_key'], true);
        $metodos_relaciones[] = <<<PHP
    public function {$rel['metodo']}(): HasMany
    {
        return \$this->hasMany({$related_class}, {$foreign_key}, {$local_key});
    }
PHP;
    }

    foreach ($relaciones['belongs_to_many'] as $rel) {
        $related_class = strpos($rel['clase'], '\\') === false
            ? $rel['clase'] . '::class'
            : '\\' . ltrim($rel['clase'], '\\') . '::class';
        $pivot_table = var_export($rel['pivot_table'], true);
        $foreign_pivot_key = var_export($rel['foreign_pivot_key'], true);
        $related_pivot_key = var_export($rel['related_pivot_key'], true);
        $parent_key = var_export($rel['parent_key'], true);
        $related_key = var_export($rel['related_key'], true);
        $metodos_relaciones[] = <<<PHP
    public function {$rel['metodo']}(): BelongsToMany
    {
        return \$this->belongsToMany(
            {$related_class},
            {$pivot_table},
            {$foreign_pivot_key},
            {$related_pivot_key},
            {$parent_key},
            {$related_key}
        );
    }
PHP;
    }

    // Importaciones necesarias de relaciones
    $imports = [];
    if ($usa_belongs_to) {
        $imports[] = "use Illuminate\\Database\\Eloquent\\Relations\\BelongsTo;";
    }
    if ($usa_has_one) {
        $imports[] = "use Illuminate\\Database\\Eloquent\\Relations\\HasOne;";
    }
    if ($usa_has_many) {
        $imports[] = "use Illuminate\\Database\\Eloquent\\Relations\\HasMany;";
    }
    if ($usa_belongs_to_many) {
        $imports[] = "use Illuminate\\Database\\Eloquent\\Relations\\BelongsToMany;";
    }

    $use_relations = !empty($imports) ? implode("\n", $imports) . "\n" : "";
    $relations_str = !empty($metodos_relaciones) ? "\n\n" . implode("\n\n", $metodos_relaciones) : "";
    $use_soft_deletes = $soft_deletes_import;

    return str_replace(
        [
            '{{CLASS_NAME}}',
            '{{TABLE_PROPERTY}}',
            '{{KEY_PROPERTIES}}',
            '{{TIMESTAMPS_PROPERTY}}',
            '{{SOFT_DELETES_TRAIT}}',
            '{{FILLABLE}}',
            '{{CASTS}}',
            '{{RELATIONS}}',
            '{{USE_RELATIONS}}',
            '{{USE_SOFT_DELETES}}',
        ],
        [
            $clase_nombre,
            $tabla_property,
            $key_properties,
            $timestamps_property,
            $soft_deletes_trait,
            $fillable_str,
            $casts_str,
            $relations_str,
            $use_relations,
            $use_soft_deletes,
        ],
        $tpl
    );
}

// Genera el código fuente de un Controlador CRUD directamente en App\Http\Controllers.
function generar_codigo_controlador($nombre_tabla, $tabla_info, $todas_tablas, $config, $relaciones_b04 = [])
{
    $plantilla_path = CRUD_DIR . '/templates/back/controller.tpl';
    if (!file_exists($plantilla_path)) {
        throw new RuntimeException("Plantilla no encontrada: $plantilla_path");
    }
    $tpl = file_get_contents($plantilla_path);

    $clase_nombre = nombre_clase($nombre_tabla, $config);

    // Identificar si la tabla posee campos de imagen
    $campos_imagen = [];
    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if (!empty($c_def['es_imagen'])) {
            $campos_imagen[] = $c_nom;
        }
    }
    $tiene_imagen = !empty($campos_imagen);

    // 1. Restricciones verificadas para validación.
    $claves_primarias = array_values($tabla_info['primary_key']['columnas'] ?? []);
    $clave_primaria = $claves_primarias[0] ?? 'id';
    $restricciones_unicas = [];
    foreach ($tabla_info['indices'] ?? [] as $indice) {
        $columnas_indice = array_values($indice['columnas'] ?? []);
        if (($indice['tipo'] ?? null) !== 'unique' || $columnas_indice === []) {
            continue;
        }
        if (count($columnas_indice) > 1) {
            $completa_y_no_nullable = true;
            foreach ($columnas_indice as $columna_indice) {
                $info_columna = $tabla_info['columnas'][$columna_indice] ?? null;
                $default_indice = $info_columna['default_metadata'] ?? [];
                $default_dinamico = false;
                foreach ($info_columna['modificadores'] ?? [] as $modificador) {
                    if (in_array($modificador['metodo'] ?? null, ['useCurrent', 'useCurrentOnUpdate'], true)) {
                        $default_dinamico = true;
                        break;
                    }
                }
                if ($info_columna === null || ($info_columna['nullable'] ?? null) !== false
                    || $default_dinamico
                    || (($default_indice['tipo'] ?? null) === 'expresion' && !empty($default_indice['declarado']))) {
                    $completa_y_no_nullable = false;
                    break;
                }
            }
            if (!$completa_y_no_nullable) {
                continue;
            }
        }
        foreach ($columnas_indice as $columna_indice) {
            if (!isset($tabla_info['columnas'][$columna_indice])) {
                continue;
            }
            $otras_columnas = array_values(array_diff($columnas_indice, [$columna_indice]));
            if (!in_array($otras_columnas, $restricciones_unicas[$columna_indice] ?? [], true)) {
                $restricciones_unicas[$columna_indice][] = $otras_columnas;
            }
        }
    }
    foreach ($tabla_info['columnas'] as $nombre_columna => $columna) {
        if (!empty($columna['unique'])) {
            if (!in_array([], $restricciones_unicas[$nombre_columna] ?? [], true)) {
                $restricciones_unicas[$nombre_columna][] = [];
            }
        }
    }

    $foreign_validations = [];
    $foreign_keys = $tabla_info['foreign_keys'] ?? [];
    if ($foreign_keys === []) {
        foreach ($tabla_info['fks'] ?? [] as $fk_legacy) {
            $foreign_keys[] = [
                'columnas' => [$fk_legacy['columna'] ?? null],
                'tabla_referenciada' => $fk_legacy['referencia_tabla'] ?? null,
                'columnas_referenciadas' => [$fk_legacy['referencia_columna'] ?? null],
            ];
        }
    }
    foreach ($foreign_keys as $foreign_key) {
        $columnas_fk = $foreign_key['columnas'] ?? [];
        $columnas_referenciadas = $foreign_key['columnas_referenciadas'] ?? [];
        $tabla_referenciada = $foreign_key['tabla_referenciada'] ?? null;
        if (count($columnas_fk) !== 1 || count($columnas_referenciadas) !== 1
            || !is_string($columnas_fk[0]) || !isset($tabla_info['columnas'][$columnas_fk[0]])
            || !is_string($tabla_referenciada) || $tabla_referenciada === ''
            || !is_string($columnas_referenciadas[0]) || $columnas_referenciadas[0] === '') {
            continue;
        }
        $foreign_validations[$columnas_fk[0]][] = [
            'table' => $tabla_referenciada,
            'column' => $columnas_referenciadas[0],
        ];
    }

    // 2. Reglas para store y update; no se validan PK ni columnas administradas por Eloquent.
    $store_rules = [];
    $update_rules = [];
    $usa_rule = false;

    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if (in_array($c_nom, $claves_primarias, true) || !empty($c_def['es_pk']) || !empty($c_def['autoincrement'])
            || in_array($c_nom, ['created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }

        $crear_regla = static function ($es_update) use (
            $c_nom,
            $c_def,
            $nombre_tabla,
            $clave_primaria,
            $tabla_info,
            $restricciones_unicas,
            $foreign_validations,
            &$usa_rule
        ) {
            $regla_info = _inferir_regla_validacion($c_def, $es_update);
            $expresiones = array_map(static fn($regla) => var_export($regla, true), $regla_info['base']);
            if ($regla_info['valores_enum'] !== []) {
                $expresiones[] = 'Rule::in(' . var_export($regla_info['valores_enum'], true) . ')';
                $usa_rule = true;
            }
            foreach ($foreign_validations[$c_nom] ?? [] as $foreign_validation) {
                $expresiones[] = 'Rule::exists('
                    . var_export($foreign_validation['table'], true) . ', '
                    . var_export($foreign_validation['column'], true) . ')';
                $usa_rule = true;
            }
            foreach ($restricciones_unicas[$c_nom] ?? [] as $otras_columnas) {
                $unique = 'Rule::unique(' . var_export($nombre_tabla, true) . ', ' . var_export($c_nom, true) . ')';
                foreach ($otras_columnas as $otra_columna) {
                    if ($es_update) {
                        $valor = '$request->input(' . var_export($otra_columna, true)
                            . ', $item->getAttribute(' . var_export($otra_columna, true) . '))';
                    } else {
                        $metadata_default = $tabla_info['columnas'][$otra_columna]['default_metadata'] ?? [];
                        $fallback = !empty($metadata_default['declarado']) && ($metadata_default['tipo'] ?? null) === 'literal'
                            ? ', ' . var_export($metadata_default['valor'] ?? null, true)
                            : '';
                        $valor = '$request->input(' . var_export($otra_columna, true) . $fallback . ')';
                    }
                    $unique .= '->where(' . var_export($otra_columna, true) . ', ' . $valor . ')';
                }
                if ($es_update) {
                    $unique .= '->ignore($item->getKey(), ' . var_export($clave_primaria, true) . ')';
                }
                $expresiones[] = $unique;
                $usa_rule = true;
            }
            return '            ' . var_export($c_nom, true) . ' => [' . implode(', ', $expresiones) . '],';
        };

        $store_rules[] = $crear_regla(false);
        $update_rules[] = $crear_regla(true);
    }

    $store_validation_str = implode("\n", $store_rules);
    $update_validation_str = implode("\n", $update_rules);

    // 2. Relaciones belongsTo para eager loading en index y show
    $relaciones = obtener_relaciones_modelo($nombre_tabla, $tabla_info, $todas_tablas, $config, $relaciones_b04);
    $with_list = [];
    if (!empty($relaciones['belongs_to'])) {
        foreach ($relaciones['belongs_to'] as $rel) {
            $with_list[] = "'{$rel['metodo']}'";
        }
    }

    if (!empty($with_list)) {
        $index_query = "{$clase_nombre}::with([" . implode(', ', $with_list) . "])->get()";
        $show_query = "{$clase_nombre}::with([" . implode(', ', $with_list) . "])->find(\$id)";
    } else {
        $index_query = "{$clase_nombre}::get()";
        $show_query = "{$clase_nombre}::find(\$id)";
    }

    // 3. Lógica de Cloudinary para store() y update()
    $use_cloudinary = '';
    $cloudinary_store_upload = '';
    $cloudinary_update_upload = '';

    if ($tiene_imagen) {
        $use_cloudinary = "use Cloudinary\Cloudinary;\n";

        $store_lines = [];
        $update_lines = [];

        foreach ($campos_imagen as $campo_img) {
            $campo_literal = var_export($campo_img, true);
            $store_lines[] = "        \$file = \$request->file($campo_literal);\n        unset(\$data[$campo_literal]);\n        if (\$file) {\n            \$cloudinary = new Cloudinary([\n                'cloud' => [\n                    'cloud_name' => config('services.cloudinary.cloud_name'),\n                    'api_key' => config('services.cloudinary.api_key'),\n                    'api_secret' => config('services.cloudinary.api_secret'),\n                ],\n            ]);\n            \$upload = \$cloudinary->uploadApi()->upload(\$file->getRealPath(), ['folder' => " . var_export($nombre_tabla, true) . "]);\n            \$data[$campo_literal] = \$upload['secure_url'];\n        }";
            $update_lines[] = "        \$file = \$request->file($campo_literal);\n        unset(\$data[$campo_literal]);\n        if (\$file) {\n            \$cloudinary = new Cloudinary([\n                'cloud' => [\n                    'cloud_name' => config('services.cloudinary.cloud_name'),\n                    'api_key' => config('services.cloudinary.api_key'),\n                    'api_secret' => config('services.cloudinary.api_secret'),\n                ],\n            ]);\n            \$upload = \$cloudinary->uploadApi()->upload(\$file->getRealPath(), ['folder' => " . var_export($nombre_tabla, true) . "]);\n            \$data[$campo_literal] = \$upload['secure_url'];\n        }";
        }

        $cloudinary_store_upload = "\n" . implode("\n", $store_lines) . "\n";
        $cloudinary_update_upload = "\n" . implode("\n", $update_lines) . "\n";
    }

    return str_replace(
        [
            '{{CLASS_NAME}}',
            '{{STORE_VALIDATION_RULES}}',
            '{{UPDATE_VALIDATION_RULES}}',
            '{{INDEX_QUERY}}',
            '{{SHOW_QUERY}}',
            '{{USE_CLOUDINARY}}',
            '{{CLOUDINARY_STORE_UPLOAD}}',
            '{{CLOUDINARY_UPDATE_UPLOAD}}',
            '{{USE_VALIDATION_RULE}}',
        ],
        [
            $clase_nombre,
            $store_validation_str,
            $update_validation_str,
            $index_query,
            $show_query,
            $use_cloudinary,
            $cloudinary_store_upload,
            $cloudinary_update_upload,
            $usa_rule ? "use Illuminate\\Validation\\Rule;\n" : '',
        ],
        $tpl
    );
}

// ------------------------------------------------------------
// Registro de Rutas API y Compatibilidad Laravel 10, 11 y 12
// ------------------------------------------------------------

// Registra el bloque de rutas API en routes/api.php con namespace App\Http\Controllers.
function registrar_rutas_api($ruta_backend, array $controladores_generados)
{
    $ruta_api_php = $ruta_backend . '/routes/api.php';
    $lineas_rutas = [];

    foreach ($controladores_generados as $item) {
        $recurso = strtolower($item['tabla']);
        $clase_ctrl = $item['clase_ctrl'];
        $lineas_rutas[] = "Route::apiResource('$recurso', \\App\\Http\\Controllers\\{$clase_ctrl}::class);";
    }

    $crlf = "\n";
    $marcador_inicio = '// <crud-generator>';
    $marcador_fin = '// </crud-generator>';
    $bloque_nuevo = $marcador_inicio . $crlf . implode($crlf, $lineas_rutas) . $crlf . $marcador_fin;

    if (!file_exists($ruta_api_php)) {
        $contenido_inicial = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n" . $bloque_nuevo . "\n";
        escribir_archivo_sin_marca($ruta_api_php, $contenido_inicial);
        return 'creado';
    }

    $contenido_actual = file_get_contents($ruta_api_php);

    $tiene_inicio = strpos($contenido_actual, $marcador_inicio) !== false;
    $tiene_fin = strpos($contenido_actual, $marcador_fin) !== false;

    if ($tiene_inicio && $tiene_fin) {
        $patron = '/' . preg_quote($marcador_inicio, '/') . '.*?' . preg_quote($marcador_fin, '/') . '/s';
        $nuevo_contenido = preg_replace($patron, $bloque_nuevo, $contenido_actual, 1);

        if (substr_count($nuevo_contenido, $marcador_inicio) > 1 || substr_count($nuevo_contenido, $marcador_fin) > 1) {
            $nuevo_contenido = preg_replace('/' . preg_quote($marcador_inicio, '/') . '.*?' . preg_quote($marcador_fin, '/') . '/s', '', $nuevo_contenido);
            $nuevo_contenido = rtrim($nuevo_contenido) . "\n\n" . $bloque_nuevo . "\n";
        }

        if ($nuevo_contenido === $contenido_actual) {
            return 'sin_cambios';
        }

        escribir_archivo_sin_marca($ruta_api_php, $nuevo_contenido);
        return 'actualizado';
    } elseif ($tiene_inicio || $tiene_fin) {
        $limpio = str_replace([$marcador_inicio, $marcador_fin], '', $contenido_actual);
        $nuevo_contenido = rtrim($limpio) . "\n\n" . $bloque_nuevo . "\n";
        escribir_archivo_sin_marca($ruta_api_php, $nuevo_contenido);
        return 'actualizado';
    } else {
        $nuevo_contenido = rtrim($contenido_actual) . "\n\n" . $bloque_nuevo . "\n";
        escribir_archivo_sin_marca($ruta_api_php, $nuevo_contenido);
        return 'actualizado';
    }
}

// Configura el registro de routes/api.php en bootstrap/app.php (Laravel 11 y 12) de forma mínima y segura.
function asegurar_registro_api_laravel11($ruta_backend, &$advertencias)
{
    $bootstrap_app = $ruta_backend . '/bootstrap/app.php';
    if (!file_exists($bootstrap_app)) {
        return false;
    }

    $contenido = file_get_contents($bootstrap_app);

    if (preg_match('/api\s*:/', $contenido) || strpos($contenido, 'routes/api.php') !== false) {
        return true;
    }

    if (strpos($contenido, 'Application::configure') === false) {
        return true;
    }

    if (preg_match('/(withRouting\s*\([^)]*web\s*:\s*__DIR__\s*\.\s*\'[^\']+\'[^,)]*)(,?)/s', $contenido, $m)) {
        $coincidencia = $m[0];
        $linea_api = "\n        api: __DIR__.'/../routes/api.php',";
        
        $reemplazo = rtrim($m[1], ',') . ',' . $linea_api;
        $nuevo_contenido = str_replace($coincidencia, $reemplazo, $contenido);

        $temp_test = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_boot_' . uniqid() . '.php';
        file_put_contents($temp_test, $nuevo_contenido);
        $lint = @exec("php -l \"$temp_test\"");
        @unlink($temp_test);

        if (strpos($lint, 'No syntax errors') !== false) {
            escribir_archivo_sin_marca($bootstrap_app, $nuevo_contenido);
            return true;
        } else {
            $advertencias[] = "No se pudo actualizar bootstrap/app.php automáticamente debido a riesgo de sintaxis. Registre 'api: __DIR__.\'/../routes/api.php\'' manualmente.";
            return false;
        }
    }

    $advertencias[] = "No se encontró el bloque withRouting() en bootstrap/app.php para registrar routes/api.php automáticamente.";
    return false;
}

// Genera la clase helper App\Support\Cloudinary si hay tablas con imágenes.
function asegurar_helper_cloudinary($ruta_backend)
{
    // La referencia usa el SDK oficial Cloudinary\Cloudinary directamente en
    // los controladores. No se genera un helper HTTP alternativo.
    return 'omitido';
}

function asegurar_dependencia_cloudinary($ruta_backend)
{
    $composer_path = $ruta_backend . '/composer.json';
    if (!file_exists($composer_path)) {
        throw new RuntimeException('No se encontró composer.json en el Backend.');
    }
    $contenido = file_get_contents($composer_path);
    $composer = json_decode($contenido, true);
    if (!is_array($composer)) {
        throw new RuntimeException('composer.json del Backend no contiene JSON válido.');
    }
    if (isset($composer['require']['cloudinary/cloudinary_php'])) {
        return true;
    }
    $composer['require']['cloudinary/cloudinary_php'] = '^3.3';
    $nuevo = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    escribir_archivo_sin_marca($composer_path, $nuevo);
    return true;
}

// Configura config/services.php agregando la clave 'cloudinary' solo si no existe.
function configurar_services_cloudinary($ruta_backend, &$advertencias, $ruta_services_fuente = null)
{
    $services_path = $ruta_backend . '/config/services.php';
    if (!file_exists($services_path)) {
        $advertencias[] = "Archivo config/services.php no encontrado para configurar Cloudinary.";
        return false;
    }

    $contenido = file_get_contents($services_path);

    if (strpos($contenido, "'cloudinary'") !== false || strpos($contenido, '"cloudinary"') !== false) {
        return true;
    }

    if (!is_string($ruta_services_fuente)
        || is_link($ruta_services_fuente)
        || !is_file($ruta_services_fuente)
        || !is_readable($ruta_services_fuente)) {
        $advertencias[] = "No se encontró config/services.php legible en la fuente Backend para adaptar Cloudinary.";
        return false;
    }
    $contenido_fuente = file_get_contents($ruta_services_fuente);
    if ($contenido_fuente === false
        || !preg_match('/^[ \t]*\'cloudinary\'[ \t]*=>[ \t]*\[[^\]]*\][ \t]*,?/m', $contenido_fuente, $coincidencia)
        || !str_contains($coincidencia[0], "'cloud_name'")
        || !str_contains($coincidencia[0], "'api_key'")
        || !str_contains($coincidencia[0], "'api_secret'")) {
        $advertencias[] = "La fuente config/services.php no contiene una entrada Cloudinary estática adaptable.";
        return false;
    }

    $bloque_cloudinary = $coincidencia[0];

    $pos_ultimo_corchete = strrpos($contenido, '];');
    if ($pos_ultimo_corchete === false) {
        $advertencias[] = "No se pudo identificar el retorno de arreglo en config/services.php para añadir Cloudinary.";
        return false;
    }

    $nuevo_contenido = substr($contenido, 0, $pos_ultimo_corchete) . $bloque_cloudinary . "\n" . substr($contenido, $pos_ultimo_corchete);

    $temp_test = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_serv_' . uniqid() . '.php';
    file_put_contents($temp_test, $nuevo_contenido);
    $lint = @exec("php -l \"$temp_test\"");
    @unlink($temp_test);

    if (strpos($lint, 'No syntax errors') !== false) {
        escribir_archivo_sin_marca($services_path, $nuevo_contenido);
        return true;
    } else {
        $advertencias[] = "No se pudo actualizar config/services.php automáticamente debido a error de sintaxis en inserción.";
        return false;
    }
}

// Configura las variables de entorno en .env sin sobrescribir valores existentes.
function configurar_env_backend($ruta_backend, $nombre_proyecto, $hay_imagenes, &$advertencias)
{
    $env_path = $ruta_backend . '/.env';
    if (!file_exists($env_path)) {
        $advertencias[] = "Archivo .env no encontrado en Backend.";
        return false;
    }

    $contenido = file_get_contents($env_path);
    $crlf = (strpos($contenido, "\r\n") !== false) ? "\r\n" : "\n";
    $cambio = false;

    // APP_URL con dominio local
    $dominio_back = slug($nombre_proyecto) . '-backend.test';
    $url_local = "http://{$dominio_back}:8001";

    if (preg_match('/^APP_URL=(.*)$/m', $contenido, $m)) {
        $valor_actual = trim($m[1], " '\"");
        if ($valor_actual === '' || $valor_actual === 'http://localhost') {
            $contenido = preg_replace('/^APP_URL=.*$/m', "APP_URL={$url_local}", $contenido);
            $cambio = true;
        }
    } else {
        $contenido = rtrim($contenido) . $crlf . "APP_URL={$url_local}" . $crlf;
        $cambio = true;
    }

    // Variables de Cloudinary
    if ($hay_imagenes) {
        $vars_faltantes = [];
        $variables_requeridas = [
            'CLOUDINARY_CLOUD_NAME' => '',
            'CLOUDINARY_API_KEY' => '',
            'CLOUDINARY_API_SECRET' => '',
            'CLOUDINARY_FOLDER' => 'crud_uploads',
        ];

        foreach ($variables_requeridas as $var => $default_val) {
            if (!preg_match('/^' . preg_quote($var, '/') . '=/m', $contenido)) {
                $vars_faltantes[] = "{$var}={$default_val}";
            }
        }

        if (!empty($vars_faltantes)) {
            $bloque_env = $crlf . "# Cloudinary Credentials" . $crlf . implode($crlf, $vars_faltantes) . $crlf;
            $contenido = rtrim($contenido) . $bloque_env;
            $cambio = true;
        }
    }

    if ($cambio) {
        escribir_archivo_sin_marca($env_path, $contenido);
        return true;
    }

    return false;
}

function resolver_directorio_fuente_backend($config)
{
    $ruta_configurada = $config['fuente_backend'] ?? 'pracExaLav/backend';
    if (!is_string($ruta_configurada) || trim($ruta_configurada) === '') {
        return null;
    }

    $base = defined('RAIZ') ? RAIZ : dirname(CRUD_DIR, 2);
    $ruta = preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $ruta_configurada)
        ? $ruta_configurada
        : $base . DIRECTORY_SEPARATOR . $ruta_configurada;
    $real = realpath($ruta);
    if ($real === false || !is_dir($real)
        || !is_dir($real . '/app/Models')
        || !is_dir($real . '/app/Http/Controllers')
        || !is_file($real . '/routes/api.php')
        || !is_file($real . '/routes/web.php')) {
        return null;
    }

    return $real;
}

function _ruta_archivo_fuente_backend($raiz, $ruta_relativa)
{
    $ruta = ruta_relativa_fuente_segura($ruta_relativa);
    if ($ruta === null) {
        return null;
    }
    $archivo = $raiz . DIRECTORY_SEPARATOR . $ruta;
    if (is_link($archivo) || !is_file($archivo) || !is_readable($archivo)) {
        return null;
    }
    $real = realpath($archivo);
    $raiz_real = realpath($raiz);
    if ($real === false || $raiz_real === false || !ruta_fuente_dentro_de_raiz($real, $raiz_real)) {
        return null;
    }
    return $real;
}

function _backend_nombre_tabla_fuente($clase)
{
    $tablas_conocidas = [
        'Student' => 'students',
        'Teacher' => 'teachers',
        'Module' => 'modules',
        'ModuleTeacher' => 'module_teachers',
    ];
    return $tablas_conocidas[$clase] ?? strtolower(snake(plural(singular($clase))));
}

function _backend_metodos_relacion_fuente($codigo)
{
    preg_match_all(
        '/^[ \t]*public function ([a-zA-Z_][a-zA-Z0-9_]*)\(\)\s*\{\s*return \$this->(belongsTo|hasOne|hasMany|belongsToMany)\((.*?)\);\s*\}/ms',
        $codigo,
        $coincidencias,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    $metodos = [];
    foreach ($coincidencias as $coincidencia) {
        $metodos[] = [
            'nombre' => $coincidencia[1][0],
            'tipo' => $coincidencia[2][0],
            'llamada' => $coincidencia[2][0],
            'argumentos' => $coincidencia[3][0],
            'codigo' => $coincidencia[0][0],
        ];
    }
    return $metodos;
}

function _backend_campos_fillable_fuente($codigo)
{
    if (!preg_match('/protected\s+\$fillable\s*=\s*\[(.*?)\];/s', $codigo, $coincidencia)) {
        return [];
    }
    preg_match_all("/'((?:\\\\\\\\.|[^'])*)'/", $coincidencia[1], $campos);
    return array_values(array_map(
        static fn($campo) => str_replace(["\\\\'", '\\\\\\\\'], ["'", '\\\\'], $campo),
        $campos[1]
    ));
}

function _backend_relaciones_objetivo($nombre_tabla, $tabla_info, $todas_tablas, $config, $relaciones_b04)
{
    $relaciones_b04_modelo = array_values(array_filter(
        $relaciones_b04,
        static function ($relacion) use ($nombre_tabla, $todas_tablas, $relaciones_b04) {
            if (($relacion['type'] ?? null) !== 'hasMany'
                || ($relacion['status'] ?? null) !== 'complete'
                || ($relacion['from']['table'] ?? null) !== $nombre_tabla) {
                return true;
            }

            $tabla_relacionada = $relacion['to']['table'] ?? null;
            if (($todas_tablas[$tabla_relacionada]['classification']['type'] ?? null) !== 'associative_entity') {
                return true;
            }

            foreach ($relaciones_b04 as $relacion_pivote) {
                if (($relacion_pivote['type'] ?? null) === 'belongsToMany'
                    && ($relacion_pivote['status'] ?? null) === 'complete'
                    && ($relacion_pivote['from']['table'] ?? null) === $nombre_tabla
                    && ($relacion_pivote['pivot_table'] ?? null) === $tabla_relacionada) {
                    return false;
                }
            }

            return true;
        }
    ));

    $relaciones = obtener_relaciones_modelo(
        $nombre_tabla,
        $tabla_info,
        $todas_tablas,
        $config,
        $relaciones_b04_modelo
    );
    return $relaciones;
}

function _backend_perfil_relaciones(array $relaciones)
{
    $perfil = [];
    foreach (['belongs_to', 'has_one', 'has_many', 'belongs_to_many'] as $grupo) {
        $tipo = [
            'belongs_to' => 'belongsTo',
            'has_one' => 'hasOne',
            'has_many' => 'hasMany',
            'belongs_to_many' => 'belongsToMany',
        ][$grupo];
        $perfil[$tipo] = count($relaciones[$grupo] ?? []);
    }
    return $perfil;
}

function _backend_fuentes_entidad($fuente, $nombre_tabla, $tabla_info, $todas_tablas, $config, $relaciones_b04)
{
    $ajustes = $config['adaptaciones_backend'][$nombre_tabla] ?? [];
    if (!is_array($ajustes)) {
        return ['error' => "La configuración adaptaciones_backend.$nombre_tabla debe ser un arreglo."];
    }
    $relaciones = _backend_relaciones_objetivo(
        $nombre_tabla,
        $tabla_info,
        $todas_tablas,
        $config,
        $relaciones_b04
    );
    $perfil_objetivo = _backend_perfil_relaciones($relaciones);

    $modelo_configurado = $ajustes['modelo'] ?? null;
    if ($modelo_configurado !== null) {
        $ruta_modelo = _ruta_archivo_fuente_backend($fuente, 'app/Models/' . $modelo_configurado);
        if ($ruta_modelo === null || basename($ruta_modelo) !== $modelo_configurado) {
            return ['error' => "La fuente de modelo configurada para '$nombre_tabla' no existe o no es segura."];
        }
    } else {
        $clase = nombre_clase($nombre_tabla, $config);
        $candidato = _ruta_archivo_fuente_backend($fuente, "app/Models/$clase.php");
        if ($candidato !== null && $clase !== 'User') {
            $ruta_modelo = $candidato;
        } else {
            $rutas_modelos = glob($fuente . '/app/Models/*.php') ?: [];
            $coincidencias = [];
            foreach ($rutas_modelos as $ruta_candidata) {
                if (basename($ruta_candidata) === 'User.php' || is_link($ruta_candidata)) {
                    continue;
                }
                $codigo_candidato = @file_get_contents($ruta_candidata);
                if ($codigo_candidato === false) {
                    continue;
                }
                $perfil_fuente = _backend_perfil_relaciones([
                    'belongs_to' => array_fill(0, count(array_filter(
                        _backend_metodos_relacion_fuente($codigo_candidato),
                        static fn($metodo) => $metodo['tipo'] === 'belongsTo'
                    )), []),
                    'has_one' => array_fill(0, count(array_filter(
                        _backend_metodos_relacion_fuente($codigo_candidato),
                        static fn($metodo) => $metodo['tipo'] === 'hasOne'
                    )), []),
                    'has_many' => array_fill(0, count(array_filter(
                        _backend_metodos_relacion_fuente($codigo_candidato),
                        static fn($metodo) => $metodo['tipo'] === 'hasMany'
                    )), []),
                    'belongs_to_many' => array_fill(0, count(array_filter(
                        _backend_metodos_relacion_fuente($codigo_candidato),
                        static fn($metodo) => $metodo['tipo'] === 'belongsToMany'
                    )), []),
                ]);
                if ($perfil_fuente === $perfil_objetivo) {
                    $coincidencias[] = $ruta_candidata;
                }
            }
            if ($coincidencias === []) {
                return [
                    'error' => "No hay un modelo de referencia con las relaciones necesarias para '$nombre_tabla'.",
                    'relaciones' => $relaciones,
                    'ajustes' => $ajustes,
                ];
            }
            usort($coincidencias, static function ($a, $b) use ($perfil_objetivo) {
                $preferencia = $perfil_objetivo === _backend_perfil_relaciones([
                    'belongs_to' => [],
                    'has_one' => [],
                    'has_many' => [],
                    'belongs_to_many' => [],
                ]) ? 'ModuleTeacher.php' : '';
                return (basename($a) === $preferencia ? -1 : 0)
                    <=> (basename($b) === $preferencia ? -1 : 0);
            });
            $ruta_modelo = $coincidencias[0];
        }
    }

    $controlador_configurado = $ajustes['controlador_api'] ?? null;
    if ($controlador_configurado !== null) {
        $ruta_controlador = _ruta_archivo_fuente_backend(
            $fuente,
            'app/Http/Controllers/' . $controlador_configurado
        );
        if ($ruta_controlador === null || basename($ruta_controlador) !== $controlador_configurado) {
            return ['error' => "La fuente de controlador API configurada para '$nombre_tabla' no existe o no es segura."];
        }
    } else {
        $clase = nombre_clase($nombre_tabla, $config);
        $candidato = _ruta_archivo_fuente_backend($fuente, "app/Http/Controllers/{$clase}Controller.php");
        $ruta_controlador = $candidato
            ?? _ruta_archivo_fuente_backend($fuente, 'app/Http/Controllers/ModuleController.php');
    }

    if ($ruta_controlador === null) {
        return ['error' => "No se encontró un controlador API original para '$nombre_tabla'."];
    }

    return [
        'modelo' => $ruta_modelo,
        'controlador_api' => $ruta_controlador,
        'relaciones' => $relaciones,
        'ajustes' => $ajustes,
    ];
}

function _backend_fuente_tiene_recurso_api_explicito($fuente, $nombre_tabla, $clase, $ajustes = [])
{
    $controlador = $ajustes['controlador_api'] ?? ($clase . 'Controller.php');
    if (!is_string($controlador) || basename($controlador) !== $controlador) {
        return false;
    }

    $ruta_controlador = _ruta_archivo_fuente_backend(
        $fuente,
        'app/Http/Controllers/' . $controlador
    );
    $ruta_api = _ruta_archivo_fuente_backend($fuente, 'routes/api.php');
    if ($ruta_controlador === null || $ruta_api === null) {
        return false;
    }

    $codigo_rutas = file_get_contents($ruta_api);
    if ($codigo_rutas === false) {
        return false;
    }
    $clase_controlador = pathinfo($controlador, PATHINFO_FILENAME);
    $patron = '/Route::apiResource\(\s*([\'"])'
        . preg_quote($nombre_tabla, '/')
        . '\1\s*,\s*'
        . preg_quote($clase_controlador, '/')
        . '::class\s*\)/';

    return preg_match($patron, $codigo_rutas) === 1;
}

function _backend_generar_props_modelo($nombre_tabla, $clase, $tabla_info, $config)
{
    $primary_key = array_values($tabla_info['primary_key']['columnas'] ?? []);
    if (count($primary_key) !== 1) {
        return null;
    }
    $propiedades = '';
    $tabla_eloquent = strtolower(snake(plural(singular($clase))));
    if (!empty($config['clases'][$nombre_tabla]) || $nombre_tabla !== $tabla_eloquent) {
        $propiedades .= '    protected $table = ' . var_export($nombre_tabla, true) . ";\n";
    }
    if ($primary_key[0] !== 'id') {
        $propiedades .= '    protected $primaryKey = ' . var_export($primary_key[0], true) . ";\n";
    }
    $tipo_pk = $tabla_info['columnas'][$primary_key[0]]['tipo_normalizado'] ?? null;
    if (in_array($tipo_pk, ['string', 'uuid', 'ulid'], true)) {
        $propiedades .= "    protected \$keyType = 'string';\n";
    }
    if (($tabla_info['primary_key']['generacion'] ?? null) !== 'auto_increment') {
        $propiedades .= "    public \$incrementing = false;\n";
    }
    $columnas = $tabla_info['columnas'] ?? [];
    if (!isset($columnas['created_at'], $columnas['updated_at']) && !str_contains($nombre_tabla, "\0")) {
        $propiedades .= "    public \$timestamps = false;\n";
    }
    return $propiedades === '' ? '' : $propiedades . "\n";
}

function _backend_codigo_relacion_adaptada(array $relacion)
{
    $tipo = $relacion['tipo'];
    $clase = $relacion['clase'] . '::class';
    $keys = $relacion['keys'] ?? [];
    if ($tipo === 'belongsTo') {
        $foreign = $keys['foreign_key'];
        $owner = $keys['owner_key'];
        $args = [$clase];
        if ($foreign !== snake($relacion['clase']) . '_id' || $owner !== 'id') {
            $args[] = var_export($foreign, true);
            $args[] = var_export($owner, true);
        }
    } elseif (in_array($tipo, ['hasOne', 'hasMany'], true)) {
        $foreign = $keys['foreign_key'];
        $local = $keys['local_key'];
        $args = [$clase, var_export($foreign, true), var_export($local, true)];
    } else {
        $args = [
            $clase,
            var_export($keys['pivot_table'], true),
            var_export($keys['foreign_pivot_key'], true),
            var_export($keys['related_pivot_key'], true),
        ];
        if (($keys['parent_key'] ?? 'id') !== 'id' || ($keys['related_key'] ?? 'id') !== 'id') {
            $args[] = var_export($keys['parent_key'], true);
            $args[] = var_export($keys['related_key'], true);
        }
    }
    return '$this->' . $tipo . '(' . implode(', ', $args) . ')';
}

function _backend_adaptaciones_modelo($codigo, $nombre_tabla, $tabla_info, $config, array $relaciones, &$pendientes)
{
    $clase = nombre_clase($nombre_tabla, $config);
    if (!preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+Model\s*\{/', $codigo, $cabecera)) {
        $pendientes[] = 'La clase fuente no tiene una declaración Model reconocible.';
        return null;
    }
    $clase_fuente = $cabecera[1];
    $metodos_fuente = _backend_metodos_relacion_fuente($codigo);
    $metodos_objetivo = [];
    foreach ([
        'belongs_to' => 'belongsTo',
        'has_one' => 'hasOne',
        'has_many' => 'hasMany',
        'belongs_to_many' => 'belongsToMany',
    ] as $grupo => $tipo) {
        foreach ($relaciones[$grupo] ?? [] as $relacion) {
            $metodos_objetivo[$tipo][] = [
                'tipo' => $tipo,
                'metodo' => $relacion['metodo'],
                'clase' => $relacion['clase'],
                'keys' => $tipo === 'belongsTo' ? [
                    'foreign_key' => $relacion['foreign_key'],
                    'owner_key' => $relacion['owner_key'],
                ] : ($tipo === 'hasOne' || $tipo === 'hasMany' ? [
                    'foreign_key' => $relacion['foreign_key'],
                    'local_key' => $relacion['local_key'],
                ] : [
                    'pivot_table' => $relacion['pivot_table'],
                    'foreign_pivot_key' => $relacion['foreign_pivot_key'],
                    'related_pivot_key' => $relacion['related_pivot_key'],
                    'parent_key' => $relacion['parent_key'],
                    'related_key' => $relacion['related_key'],
                ]),
            ];
        }
    }

    $conteo_fuente = array_count_values(array_column($metodos_fuente, 'tipo'));
    foreach (['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'] as $tipo) {
        if (($conteo_fuente[$tipo] ?? 0) !== count($metodos_objetivo[$tipo] ?? [])) {
            $pendientes[] = "La fuente contiene " . ($conteo_fuente[$tipo] ?? 0) . " métodos $tipo y el esquema requiere " . count($metodos_objetivo[$tipo] ?? []) . '; no se eliminan ni inventan relaciones.';
        }
    }
    if ($pendientes !== []) {
        return null;
    }

    $adaptaciones = [];
    $header_nuevo = preg_replace('/\b' . preg_quote($clase_fuente, '/') . '\b/', $clase, $cabecera[0], 1);
    $propiedades = _backend_generar_props_modelo($nombre_tabla, $clase, $tabla_info, $config);
    if ($propiedades === null) {
        $pendientes[] = 'No se pudo adaptar una clave primaria que no sea simple.';
        return null;
    }
    $header_nuevo = preg_replace('/\{$/', "{\n" . $propiedades, $header_nuevo, 1);
    $adaptaciones[$cabecera[0]] = $header_nuevo;

    $fillable = [];
    $primary_keys = $tabla_info['primary_key']['columnas'] ?? [];
    foreach ($tabla_info['columnas'] ?? [] as $campo => $definicion) {
        if (in_array($campo, $primary_keys, true) || !empty($definicion['es_pk'])
            || !empty($definicion['autoincrement'])
            || in_array($campo, ['created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        $fillable[] = "        " . var_export($campo, true) . ',';
    }
    $bloque_fillable = "    protected \$fillable = [\n" . implode("\n", $fillable) . "\n    ];";
    if (preg_match('/[ \t]*protected\s+\$fillable\s*=\s*\[.*?\];/s', $codigo, $fillable_fuente)) {
        $adaptaciones[$fillable_fuente[0]] = $bloque_fillable;
    } elseif (str_contains($codigo, "    //\n")) {
        $adaptaciones["    //\n"] = $bloque_fillable . "\n";
    } else {
        $pendientes[] = 'La fuente no tiene un bloque fillable adaptable ni un punto de inserción reconocido.';
        return null;
    }

    $usados = [];
    foreach ($metodos_fuente as $metodo) {
        $objetivo = array_shift($metodos_objetivo[$metodo['tipo']]);
        $codigo_metodo = preg_replace(
            '/public function\s+' . preg_quote($metodo['nombre'], '/') . '\s*\(\)/',
            'public function ' . $objetivo['metodo'] . '()',
            $metodo['codigo'],
            1
        );
        $llamada = _backend_codigo_relacion_adaptada($objetivo);
        $codigo_metodo = preg_replace(
            '/\$this->' . preg_quote($metodo['tipo'], '/') . '\s*\(.*?\)/s',
            $llamada,
            $codigo_metodo,
            1
        );
        if ($codigo_metodo === null || isset($usados[$metodo['codigo']])) {
            $pendientes[] = 'No se pudo adaptar un método de relación fuente de forma inequívoca.';
            return null;
        }
        $usados[$metodo['codigo']] = true;
        $adaptaciones[$metodo['codigo']] = $codigo_metodo;
    }

    return $adaptaciones;
}

function _backend_campos_controlador_adaptados($nombre_tabla, $tabla_info, array $ajustes, array $campos_fuente, &$pendientes)
{
    $mapeo = $ajustes['campos'] ?? [];
    if (!is_array($mapeo)) {
        $pendientes[] = 'El mapeo de campos debe ser un arreglo campo destino => campo fuente.';
        return null;
    }
    if ($mapeo === []) {
        foreach ($campos_fuente as $campo) {
            $mapeo[$campo] = $campo;
        }
    }
    $campos_destino = [];
    foreach ($tabla_info['columnas'] ?? [] as $campo => $definicion) {
        if (in_array($campo, $tabla_info['primary_key']['columnas'] ?? [], true)
            || !empty($definicion['es_pk']) || !empty($definicion['autoincrement'])
            || in_array($campo, ['created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        $campos_destino[] = $campo;
    }

    $mapeo_normalizado = [];
    foreach ($mapeo as $destino => $fuente) {
        if (!is_string($destino) || !is_string($fuente)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $destino)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fuente)
            || !in_array($destino, $campos_destino, true)
            || !in_array($fuente, $campos_fuente, true)) {
            $pendientes[] = "El mapeo de campo '$destino' => '$fuente' no corresponde con la migración o la fuente.";
            return null;
        }
        $mapeo_normalizado[$destino] = $fuente;
    }
    if (count(array_unique(array_values($mapeo_normalizado))) !== count($mapeo_normalizado)) {
        $pendientes[] = 'Cada campo de la fuente debe mapearse a un único campo destino.';
        return null;
    }
    foreach ($campos_destino as $campo) {
        if (!isset($mapeo_normalizado[$campo]) && !array_key_exists($campo, $ajustes['validaciones'] ?? [])) {
            $pendientes[] = "El campo '$nombre_tabla.$campo' requiere un mapeo a un campo validado por la fuente o reglas explícitas en adaptaciones_backend.";
        }
    }
    foreach ($campos_fuente as $campo) {
        if (!in_array($campo, $mapeo_normalizado, true)) {
            $pendientes[] = "El campo fuente '$campo' no tiene una correspondencia destino; no se elimina su validación.";
        }
    }
    if ($pendientes !== []) {
        return null;
    }

    return $mapeo_normalizado;
}

function _backend_adaptaciones_literales_controlador($codigo, $tabla_fuente, $tabla_destino, array $mapeo_campos, $clase_fuente, $nombre_tabla)
{
    $mapeo_inverso = array_flip($mapeo_campos);
    $reemplazos_nombre = [];
    if ($nombre_tabla !== $tabla_fuente) {
        $reemplazos_nombre = [
            'Student' => ['estudiante'],
            'Teacher' => ['profesor', 'docente'],
            'Module' => ['modulo', 'módulo'],
        ][$clase_fuente] ?? [];
    }

    $adaptaciones = [];
    foreach (token_get_all($codigo) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || $token[1][0] !== "'") {
            continue;
        }
        $raw = $token[1];
        $valor = preg_replace_callback(
            '/\\\\([\\\\\'])/',
            static fn($coincidencia) => $coincidencia[1],
            substr($raw, 1, -1)
        );
        if ($nombre_tabla !== $tabla_fuente) {
            $valor = preg_replace(
                '/(?<![A-Za-z0-9_])' . preg_quote($tabla_fuente, '/') . '(?![A-Za-z0-9_])/',
                $tabla_destino,
                $valor
            );
            foreach ($mapeo_inverso as $campo_fuente => $campo_destino) {
                $valor = preg_replace(
                    '/(?<![A-Za-z0-9_])' . preg_quote($campo_fuente, '/') . '(?![A-Za-z0-9_])/',
                    $campo_destino,
                    $valor
                );
            }
            foreach ($reemplazos_nombre as $palabra) {
                $valor = str_replace($palabra, 'registro', $valor);
            }
        }
        if ($valor !== substr($raw, 1, -1)) {
            $adaptaciones[$raw] = var_export($valor, true);
        }
    }
    return $adaptaciones;
}

function _backend_adaptaciones_controlador($codigo, $controlador_fuente, $modelo_fuente, $nombre_tabla, $config, $ajustes, $tabla_info, &$pendientes)
{
    $clase = nombre_clase($nombre_tabla, $config);
    $clase_controlador_fuente = basename($controlador_fuente, '.php');
    $clase_fuente = preg_replace('/Controller$/', '', $clase_controlador_fuente);
    if (!preg_match('/use App\\\\Models\\\\([A-Za-z_][A-Za-z0-9_]*);/', $codigo, $import_modelo)
        || !preg_match('/class\s+([A-Za-z_][A-Za-z0-9_]*)\s+extends\s+Controller/', $codigo, $declaracion)) {
        $pendientes[] = 'El controlador API fuente no tiene imports/declaración reconocibles.';
        return null;
    }
    $clase_modelo_fuente = $import_modelo[1];
    $ruta_modelo_controlador = dirname(dirname(dirname($controlador_fuente))) . '/Models/' . $clase_modelo_fuente . '.php';
    if (!is_file($ruta_modelo_controlador) || is_link($ruta_modelo_controlador)) {
        $pendientes[] = "No se encontró el modelo fuente '$clase_modelo_fuente' referenciado por el controlador.";
        return null;
    }
    $codigo_modelo_controlador = @file_get_contents($ruta_modelo_controlador);
    if ($codigo_modelo_controlador === false) {
        $pendientes[] = "No se pudo leer el modelo '$clase_modelo_fuente' requerido por el controlador.";
        return null;
    }
    $campos_fuente = _backend_campos_fillable_fuente($codigo_modelo_controlador);
    $mapeo_campos = _backend_campos_controlador_adaptados(
        $nombre_tabla,
        $tabla_info,
        $ajustes,
        $campos_fuente,
        $pendientes
    );
    if ($mapeo_campos === null) {
        return null;
    }

    $validaciones_nuevas = $ajustes['validaciones'] ?? [];
    if (!is_array($validaciones_nuevas)) {
        $pendientes[] = 'Las validaciones explícitas deben ser un arreglo de campo => reglas.';
        return null;
    }
    $reglas_nuevas = '';
    foreach ($validaciones_nuevas as $campo => $reglas) {
        if (!is_string($campo) || !in_array($campo, array_keys($tabla_info['columnas'] ?? []), true)
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $campo)
            || (!is_string($reglas) && !is_array($reglas))
            || (is_array($reglas) && array_filter($reglas, static fn($regla) => !is_string($regla)) !== [])) {
            $pendientes[] = "Las reglas explícitas para '$campo' no son válidas o el campo no existe en la migración.";
            return null;
        }
        $reglas_nuevas .= "            " . var_export($campo, true) . ' => ' . var_export($reglas, true) . ",\n";
    }

    $adaptaciones = [];
    $adaptaciones_literales = _backend_adaptaciones_literales_controlador(
        $codigo,
        _backend_nombre_tabla_fuente($clase_modelo_fuente),
        $nombre_tabla,
        $mapeo_campos,
        $clase_modelo_fuente,
        $nombre_tabla
    );
    $adaptaciones = array_merge($adaptaciones, $adaptaciones_literales);
    if ($reglas_nuevas !== '') {
        preg_match_all(
            '/(?:\$request->validate|request\(\)->validate)\(\[\s*.*?\s*\]\);/s',
            $codigo,
            $bloques_validacion
        );
        if (empty($bloques_validacion[0])) {
            $pendientes[] = 'El controlador fuente no tiene bloques validate() seguros para añadir reglas explícitas.';
            return null;
        }
        foreach ($bloques_validacion[0] as $bloque_fuente) {
            $bloque_base = adaptar_contenido_fuente($bloque_fuente, $adaptaciones_literales);
            if ($bloque_base['error'] !== null) {
                $pendientes[] = $bloque_base['error'];
                return null;
            }
            $fin_reglas = strrpos($bloque_base['contenido'], ']);');
            if ($fin_reglas === false) {
                $pendientes[] = 'No se encontró el cierre de un bloque validate() fuente.';
                return null;
            }
            $bloque_adaptado = substr($bloque_base['contenido'], 0, $fin_reglas)
                . "\n" . $reglas_nuevas . substr($bloque_base['contenido'], $fin_reglas);
            $adaptaciones[$bloque_fuente] = $bloque_adaptado;
        }
    }
    $import = $import_modelo[0];
    $adaptaciones[$import] = 'use App\\Models\\' . $clase . ';';
    $adaptaciones[$declaracion[0]] = str_replace($declaracion[1], $clase . 'Controller', $declaracion[0]);
    $adaptaciones[$clase_modelo_fuente . '::'] = $clase . '::';
    $variable_singular = lcfirst($clase_modelo_fuente);
    $variable_plural = lcfirst(studly(_backend_nombre_tabla_fuente($clase_modelo_fuente)));
    $adaptaciones['$' . $variable_plural] = '$' . lcfirst(studly($nombre_tabla));
    $adaptaciones['$' . $variable_singular] = '$' . lcfirst($clase);
    return [
        'adaptaciones' => $adaptaciones,
        'clase_fuente' => $clase_fuente,
        'campos' => $mapeo_campos,
    ];
}

function _backend_extraer_rutas_api_adaptadas($codigo_fuente, array $controladores)
{
    preg_match_all('/^[ \t]*use App\\\\Http\\\\Controllers\\\\[A-Za-z_][A-Za-z0-9_]*Controller;[ \t]*\r?$/m', $codigo_fuente, $imports, PREG_OFFSET_CAPTURE);
    preg_match_all('/^[ \t]*Route::apiResource\([^\r\n]*\);[ \t]*\r?$/m', $codigo_fuente, $rutas, PREG_OFFSET_CAPTURE);
    if (empty($imports[0]) || empty($rutas[0])) {
        return null;
    }

    $inicio_imports = $imports[0][0][1];
    $fin_imports = $imports[0][count($imports[0]) - 1][1] + strlen($imports[0][count($imports[0]) - 1][0]);
    $bloque_imports = substr($codigo_fuente, $inicio_imports, $fin_imports - $inicio_imports);
    $nuevos_imports = implode("\n", array_map(
        static fn($controlador) => 'use App\\Http\\Controllers\\' . $controlador['clase_ctrl'] . ';',
        $controladores
    ));

    $inicio_rutas = $rutas[0][0][1];
    $fin_rutas = $rutas[0][count($rutas[0]) - 1][1] + strlen($rutas[0][count($rutas[0]) - 1][0]);
    if (substr($codigo_fuente, $fin_rutas, 2) === "\r\n") {
        $fin_rutas += 2;
    } elseif (substr($codigo_fuente, $fin_rutas, 1) === "\n") {
        $fin_rutas++;
    }
    $bloque_rutas = substr($codigo_fuente, $inicio_rutas, $fin_rutas - $inicio_rutas);
    $lineas = array_map(
        static fn($controlador) => "Route::apiResource('" . $controlador['tabla'] . "', " . $controlador['clase_ctrl'] . "::class);",
        $controladores
    );
    $nuevas_rutas = '// <crud-generator>' . "\n"
        . implode("\n", $lineas) . "\n"
        . '// </crud-generator>' . "\n";

    return [
        $bloque_imports => $nuevos_imports,
        $bloque_rutas => $nuevas_rutas,
    ];
}

function _backend_bloque_rutas_controladores(array $controladores, $linea = "\n")
{
    $rutas = array_map(
        static fn($controlador) => "Route::apiResource('" . $controlador['tabla'] . "', " . $controlador['clase_ctrl'] . "::class);",
        $controladores
    );
    return '// <crud-generator>' . $linea
        . ($rutas === [] ? '' : implode($linea, $rutas) . $linea)
        . '// </crud-generator>';
}

function registrar_rutas_api_desde_fuente($ruta_backend, $ruta_fuente, array $controladores)
{
    $codigo_fuente = @file_get_contents($ruta_fuente);
    if ($codigo_fuente === false) {
        return ['estado' => 'error', 'motivo' => 'No se pudo leer routes/api.php de la referencia.'];
    }
    $adaptaciones = _backend_extraer_rutas_api_adaptadas($codigo_fuente, $controladores);
    if ($adaptaciones === null) {
        return ['estado' => 'error', 'motivo' => 'No se reconoció el bloque de rutas API de la fuente original.'];
    }

    $esperado = adaptar_contenido_fuente($codigo_fuente, $adaptaciones);
    if ($esperado['error'] !== null) {
        return ['estado' => 'error', 'motivo' => $esperado['error']];
    }
    $destino = $ruta_backend . '/routes/api.php';
    if (!file_exists($destino)) {
        $copia = copiar_archivo_fuente($ruta_fuente, $ruta_backend, 'routes/api.php', $adaptaciones);
        return ['estado' => $copia['estado'], 'motivo' => $copia['motivo'], 'resultado' => $copia];
    }
    if (is_link($destino) || !is_file($destino) || !is_readable($destino)) {
        return ['estado' => 'conflicto', 'motivo' => 'El destino routes/api.php no es un archivo regular legible; se conserva.'];
    }
    $actual = file_get_contents($destino);
    if ($actual === $esperado['contenido']) {
        return ['estado' => 'sin_cambios', 'motivo' => 'Las rutas adaptadas ya están registradas.'];
    }
    if ($actual === $codigo_fuente) {
        $copia = copiar_archivo_fuente($ruta_fuente, $ruta_backend, 'routes/api.php', $adaptaciones);
        return ['estado' => $copia['estado'], 'motivo' => $copia['motivo'], 'resultado' => $copia];
    }

    $inicio = strpos($actual, '// <crud-generator>');
    $fin = strpos($actual, '// </crud-generator>');
    if ($inicio !== false && $fin !== false && $fin > $inicio
        && substr_count($actual, '// <crud-generator>') === 1
        && substr_count($actual, '// </crud-generator>') === 1) {
        $fin_bloque = $fin + strlen('// </crud-generator>');
        $sin_bloque = substr($actual, 0, $inicio) . substr($actual, $fin_bloque);
        $bloque_anterior = substr($actual, $inicio, $fin_bloque - $inicio);
        $rutas_sin_bloque = [];
        preg_match_all(
            '/Route::apiResource\([ \t]*[\'"]([^\'"]+)[\'"][ \t]*,[ \t]*([A-Za-z_][A-Za-z0-9_]*)::class[ \t]*\);/',
            $sin_bloque,
            $rutas_sin_bloque,
            PREG_SET_ORDER
        );
        foreach ($controladores as $controlador) {
            $coincidentes = array_values(array_filter(
                $rutas_sin_bloque,
                static fn($ruta) => $ruta[1] === $controlador['tabla']
            ));
            if (count($coincidentes) > 1 || $coincidentes !== []
                && $coincidentes[0][2] !== $controlador['clase_ctrl']) {
                return ['estado' => 'conflicto', 'motivo' => "La ruta '{$controlador['tabla']}' está duplicada o apunta a otro controlador fuera del bloque generado; se conserva."];
            }
        }
        $faltantes = array_values(array_filter($controladores, static function ($controlador) use ($rutas_sin_bloque) {
            foreach ($rutas_sin_bloque as $ruta) {
                if ($ruta[1] === $controlador['tabla'] && $ruta[2] === $controlador['clase_ctrl']) {
                    return false;
                }
            }
            return true;
        }));
        $linea = str_contains($actual, "\r\n") ? "\r\n" : "\n";
        $bloque = _backend_bloque_rutas_controladores($faltantes, $linea);

        preg_match_all(
            '/Route::apiResource\([ \t]*[\'"][^\'"]+[\'"][ \t]*,[ \t]*([A-Za-z_][A-Za-z0-9_]*)::class[ \t]*\);/',
            $bloque_anterior,
            $clases_anteriores
        );
        $clases_usadas_afuera = array_column($rutas_sin_bloque, 2);
        foreach (array_unique($clases_anteriores[1] ?? []) as $clase_anterior) {
            if (!in_array($clase_anterior, $clases_usadas_afuera, true)
                && !in_array($clase_anterior, array_column($controladores, 'clase_ctrl'), true)) {
                $actual = preg_replace(
                    '/^[ \t]*use App\\\\Http\\\\Controllers\\\\' . preg_quote($clase_anterior, '/') . ';[ \t]*\r?\n/m',
                    '',
                    $actual
                );
            }
        }
        foreach ($controladores as $controlador) {
            $import = 'use App\\Http\\Controllers\\' . $controlador['clase_ctrl'] . ';';
            if (!str_contains($actual, $import)) {
                $patron_import = '/(^use Illuminate\\\\Support\\\\Facades\\\\Route;[ \t]*\r?\n)/m';
                $actual_con_import = preg_replace(
                    $patron_import,
                    '$1' . $import . $linea,
                    $actual,
                    1,
                    $reemplazos_import
                );
                if ($reemplazos_import !== 1 || $actual_con_import === null) {
                    return ['estado' => 'conflicto', 'motivo' => 'No se encontró el import de Route como punto seguro para actualizar imports generados; se conserva.'];
                }
                $actual = $actual_con_import;
            }
        }
        $inicio = strpos($actual, '// <crud-generator>');
        $fin = strpos($actual, '// </crud-generator>');
        $fin_bloque = $fin + strlen('// </crud-generator>');
        $nuevo = substr($actual, 0, $inicio) . $bloque . substr($actual, $fin_bloque);
        if ($nuevo === $actual) {
            return ['estado' => 'sin_cambios', 'motivo' => 'Las rutas adaptadas ya están registradas.'];
        }
        return escribir_archivo_sin_marca($destino, $nuevo)
            ? ['estado' => 'actualizado', 'motivo' => 'Se actualizó únicamente el bloque de rutas generado.']
            : ['estado' => 'error', 'motivo' => 'No se pudo actualizar el bloque generado de rutas.'];
    }

    $rutas_existentes = [];
    preg_match_all('/Route::apiResource\([ \t]*[\'"]([^\'"]+)[\'"][ \t]*,[ \t]*([A-Za-z_][A-Za-z0-9_]*)::class[ \t]*\);/', $actual, $rutas_existentes, PREG_SET_ORDER);
    foreach ($rutas_existentes as $ruta_existente) {
        foreach ($controladores as $controlador) {
            if ($ruta_existente[1] === $controlador['tabla']
                && $ruta_existente[2] !== $controlador['clase_ctrl']) {
                return ['estado' => 'conflicto', 'motivo' => "La ruta '{$controlador['tabla']}' ya apunta a otro controlador; se conserva."];
            }
        }
    }
    foreach ($controladores as $controlador) {
        $repetidas = count(array_filter(
            $rutas_existentes,
            static fn($ruta_existente) => $ruta_existente[1] === $controlador['tabla']
        ));
        if ($repetidas > 1) {
            return ['estado' => 'conflicto', 'motivo' => "La ruta '{$controlador['tabla']}' aparece duplicada; no se modifica."];
        }
    }

    $faltantes = array_values(array_filter($controladores, static function ($controlador) use ($rutas_existentes) {
        foreach ($rutas_existentes as $ruta_existente) {
            if ($ruta_existente[1] === $controlador['tabla']
                && $ruta_existente[2] === $controlador['clase_ctrl']) {
                return false;
            }
        }
        return true;
    }));
    if ($faltantes === []) {
        return ['estado' => 'sin_cambios', 'motivo' => 'Las rutas de destino ya existen sin duplicados.'];
    }

    $imports_nuevos = [];
    foreach ($faltantes as $controlador) {
        $import = 'use App\\Http\\Controllers\\' . $controlador['clase_ctrl'] . ';';
        if (!str_contains($actual, $import)) {
            $imports_nuevos[] = $import;
        }
    }
    $ultimo_import = null;
    if (preg_match_all('/^[ \t]*use [^;\r\n]+;[ \t]*\r?$/m', $actual, $coincidencias, PREG_OFFSET_CAPTURE)) {
        $ultimo = $coincidencias[0][count($coincidencias[0]) - 1];
        $ultimo_import = $ultimo[1] + strlen($ultimo[0]);
    }
    $ultima_ruta = null;
    if (preg_match_all('/^[ \t]*Route::[^\r\n]*;[ \t]*\r?$/m', $actual, $coincidencias, PREG_OFFSET_CAPTURE)) {
        $ultima = $coincidencias[0][count($coincidencias[0]) - 1];
        $ultima_ruta = $ultima[1] + strlen($ultima[0]);
    }
    $insercion_import = $ultimo_import;
    if ($insercion_import === null || $ultima_ruta !== null && $insercion_import > $ultima_ruta) {
        return ['estado' => 'conflicto', 'motivo' => 'No se encontró un bloque de imports seguro para añadir las rutas; se conserva el archivo.'];
    }
    $linea = substr($actual, $insercion_import, 2) === "\r\n" ? "\r\n" : "\n";
    $texto_imports = $imports_nuevos === [] ? '' : $linea . implode($linea, $imports_nuevos);
    $actual = substr($actual, 0, $insercion_import) . $texto_imports . substr($actual, $insercion_import);
    $ancla_ruta = $ultima_ruta;
    if ($ancla_ruta !== null && $insercion_import <= $ancla_ruta) {
        $ancla_ruta += strlen($texto_imports);
    } else {
        $ancla_ruta = $insercion_import + strlen($texto_imports);
    }
    $bloque_rutas = '// <crud-generator>' . $linea
        . implode($linea, array_map(
            static fn($controlador) => "Route::apiResource('" . $controlador['tabla'] . "', " . $controlador['clase_ctrl'] . "::class);",
            $faltantes
        ))
        . $linea . '// </crud-generator>' . $linea;
    $actual = substr($actual, 0, $ancla_ruta) . $linea . $bloque_rutas . substr($actual, $ancla_ruta);
    return escribir_archivo_sin_marca($destino, $actual)
        ? ['estado' => 'actualizado', 'motivo' => 'Se añadieron rutas en el bloque reconocido junto a imports/rutas existentes.']
        : ['estado' => 'error', 'motivo' => 'No se pudo guardar routes/api.php.'];
}

// ------------------------------------------------------------
// Flujo Principal Backend CRUD
// ------------------------------------------------------------

function generar_backend_crud($ruta_backend, $config)
{
    require_once CRUD_DIR . '/src/hosts.php';

    $resumen = [
        'modelos' => [],
        'controladores' => [],
        'mapeo_fuentes' => [],
        'adaptaciones_pendientes' => [],
        'omitidas' => [],
        'rutas_api' => null,
        'rutas_web' => null,
        'cloudinary' => false,
        'env_actualizado' => false,
        'hosts' => null,
        'errores' => [],
        'advertencias' => [],
    ];

    // 1. Analizar migraciones
    $analisis = analizar_migraciones($ruta_backend, $config);
    $resumen['advertencias'] = $analisis['advertencias'] ?? [];
    $guardia = guardia_generacion_migraciones($analisis);
    if ($guardia['bloqueada']) {
        $resumen['errores'][] = $guardia['mensaje'];
        return $resumen;
    }

    $tablas = $analisis['tablas'];

    if (empty($tablas)) {
        $resumen['advertencias'][] = 'No se detectaron tablas en las migraciones de database/migrations.';
        return $resumen;
    }

    $fuente_backend = resolver_directorio_fuente_backend($config);
    if ($fuente_backend === null) {
        $resumen['errores'][] = 'No se encontró una fuente backend válida. Configure fuente_backend con el proyecto pracExaLav/backend verificado.';
        return $resumen;
    }
    $fuente_real = realpath($fuente_backend);
    $destino_real = realpath($ruta_backend);
    if ($fuente_real === false || $destino_real === false
        || $destino_real === $fuente_real
        || ruta_fuente_dentro_de_raiz($destino_real, $fuente_real)) {
        $resumen['errores'][] = 'Generación detenida: el destino coincide con la fuente o está dentro de pracExaLav/backend.';
        return $resumen;
    }

    // Permitir escritura en Backend directamente en app/Http/Controllers (PROHIBIDO crear Api/)
    carpetas_permitidas([
        $ruta_backend . '/app/Models',
        $ruta_backend . '/app/Http/Controllers',
        $ruta_backend . '/app/Support',
        $ruta_backend . '/routes',
        $ruta_backend . '/config',
        $ruta_backend,
    ]);

    $controladores_generados = [];
    $hay_campos_imagen_global = false;
    $relaciones_b04 = $analisis['relations'] ?? [];

    // 2. Generar Modelos y Controladores por tabla elegible
    foreach ($tablas as $nom_tbl => $tbl_info) {
        $tipo_clasificacion = $tbl_info['classification']['type'] ?? null;
        if ($tipo_clasificacion !== null && !in_array($tipo_clasificacion, ['crud_entity', 'associative_entity'], true)) {
            $resumen['omitidas'][] = "$nom_tbl (clasificación B03: $tipo_clasificacion)";
            continue;
        }
        if ($tipo_clasificacion === 'associative_entity'
            && !_backend_fuente_tiene_recurso_api_explicito(
                $fuente_backend,
                $nom_tbl,
                nombre_clase($nom_tbl, $config),
                $config['adaptaciones_backend'][$nom_tbl] ?? []
            )) {
            $resumen['omitidas'][] = "$nom_tbl (tabla asociativa sin recurso CRUD propio en la fuente)";
            continue;
        }
        if (count($tbl_info['primary_key']['columnas'] ?? []) !== 1) {
            $resumen['omitidas'][] = "$nom_tbl (Eloquent requiere una PK simple)";
            continue;
        }
        if (!es_tabla_elegible($nom_tbl, $tbl_info, $config)) {
            $motivo = !empty($tbl_info['es_pivote']) || $tipo_clasificacion === 'pure_pivot'
                ? 'tabla pivote'
                : 'tabla excluida en configuración';
            $resumen['omitidas'][] = "$nom_tbl ($motivo)";
            continue;
        }

        $clase = nombre_clase($nom_tbl, $config);
        $fuentes_entidad = _backend_fuentes_entidad(
            $fuente_backend,
            $nom_tbl,
            $tbl_info,
            $tablas,
            $config,
            $relaciones_b04
        );
        if (isset($fuentes_entidad['error'])) {
            $resumen['adaptaciones_pendientes'][] = [
                'tabla' => $nom_tbl,
                'tipo' => 'modelo/controlador API',
                'motivo' => $fuentes_entidad['error'],
            ];
            continue;
        }

        // Verificar si la tabla tiene campos de imagen
        foreach ($tbl_info['columnas'] as $c_def) {
            if (!empty($c_def['es_imagen'])) {
                $hay_campos_imagen_global = true;
                break;
            }
        }

        // A. Adaptar el Modelo Eloquent fuente.
        try {
            $ruta_modelo = $ruta_backend . '/app/Models/' . $clase . '.php';
            $codigo_fuente_modelo = @file_get_contents($fuentes_entidad['modelo']);
            if ($codigo_fuente_modelo === false) {
                throw new RuntimeException('No se pudo leer el modelo original.');
            }
            $pendientes_modelo = [];
            $adaptaciones_modelo = _backend_adaptaciones_modelo(
                $codigo_fuente_modelo,
                $nom_tbl,
                $tbl_info,
                $config,
                $fuentes_entidad['relaciones'],
                $pendientes_modelo
            );
            if ($adaptaciones_modelo === null) {
                foreach ($pendientes_modelo as $motivo) {
                    $resumen['adaptaciones_pendientes'][] = [
                        'tabla' => $nom_tbl,
                        'tipo' => 'modelo',
                        'motivo' => $motivo,
                    ];
                }
                continue;
            }
            $copia_modelo = copiar_archivo_fuente(
                $fuentes_entidad['modelo'],
                $ruta_backend,
                'app/Models/' . $clase . '.php',
                $adaptaciones_modelo
            );
            $estado_m = $copia_modelo['estado'];
            $resumen['mapeo_fuentes'][] = [
                'origen' => str_replace($fuente_backend . DIRECTORY_SEPARATOR, '', $fuentes_entidad['modelo']),
                'destino' => "app/Models/$clase.php",
                'tipo' => 'Modelo Eloquent adaptable',
                'adaptaciones_permitidas' => 'Clase, fillable, claves Eloquent y relaciones de igual cardinalidad.',
            ];
            $resumen['modelos'][] = [
                'clase' => $clase,
                'archivo' => "app/Models/$clase.php",
                'estado' => $estado_m,
            ];
            foreach ($copia_modelo['pendientes'] as $pendiente) {
                $resumen['adaptaciones_pendientes'][] = [
                    'tabla' => $nom_tbl,
                    'tipo' => 'modelo',
                    'motivo' => $pendiente['motivo'],
                ];
            }
            if (in_array($estado_m, ['conflicto', 'error'], true)) {
                $resumen['errores'][] = "No se pudo adaptar el modelo $clase: {$copia_modelo['motivo']}";
                continue;
            }
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error generando modelo $clase: " . $e->getMessage();
            continue;
        }

        // B. Adaptar el controlador API original sin sustituir sus reglas ni respuestas.
        try {
            $codigo_fuente_controlador = @file_get_contents($fuentes_entidad['controlador_api']);
            if ($codigo_fuente_controlador === false) {
                throw new RuntimeException('No se pudo leer el controlador API original.');
            }
            if (str_contains($codigo_fuente_controlador, 'Cloudinary\\Cloudinary')
                || str_contains($codigo_fuente_controlador, 'new Cloudinary(')) {
                $hay_campos_imagen_global = true;
            }
            $pendientes_controlador = [];
            $adaptaciones_controlador = _backend_adaptaciones_controlador(
                $codigo_fuente_controlador,
                $fuentes_entidad['controlador_api'],
                $fuentes_entidad['modelo'],
                $nom_tbl,
                $config,
                $fuentes_entidad['ajustes'],
                $tbl_info,
                $pendientes_controlador
            );
            if ($adaptaciones_controlador === null) {
                foreach ($pendientes_controlador as $motivo) {
                    $resumen['adaptaciones_pendientes'][] = [
                        'tabla' => $nom_tbl,
                        'tipo' => 'controlador API',
                        'motivo' => $motivo,
                    ];
                }
                continue;
            }
            $ruta_ctrl = $ruta_backend . '/app/Http/Controllers/' . $clase . 'Controller.php';
            $copia_controlador = copiar_archivo_fuente(
                $fuentes_entidad['controlador_api'],
                $ruta_backend,
                'app/Http/Controllers/' . $clase . 'Controller.php',
                $adaptaciones_controlador['adaptaciones']
            );
            $estado_c = $copia_controlador['estado'];
            $resumen['mapeo_fuentes'][] = [
                'origen' => str_replace($fuente_backend . DIRECTORY_SEPARATOR, '', $fuentes_entidad['controlador_api']),
                'destino' => "app/Http/Controllers/{$clase}Controller.php",
                'tipo' => 'Controlador API adaptable',
                'adaptaciones_permitidas' => 'Clase/modelo/variables, nombres de campos mapeados, recurso y validaciones explícitas.',
            ];
            $resumen['controladores'][] = [
                'clase' => $clase . 'Controller',
                'archivo' => "app/Http/Controllers/{$clase}Controller.php",
                'estado' => $estado_c,
            ];
            foreach ($copia_controlador['pendientes'] as $pendiente) {
                $resumen['adaptaciones_pendientes'][] = [
                    'tabla' => $nom_tbl,
                    'tipo' => 'controlador API',
                    'motivo' => $pendiente['motivo'],
                ];
            }
            if (in_array($estado_c, ['conflicto', 'error'], true)) {
                $resumen['errores'][] = "No se pudo adaptar el controlador {$clase}Controller: {$copia_controlador['motivo']}";
                continue;
            }
            $controladores_generados[] = [
                'tabla' => $nom_tbl,
                'clase_ctrl' => $clase . 'Controller',
            ];
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error generando controlador {$clase}Controller: " . $e->getMessage();
        }
    }

    // 3. Registrar Rutas API en routes/api.php
    if (!empty($controladores_generados)) {
        try {
            $ruta_api_fuente = _ruta_archivo_fuente_backend($fuente_backend, 'routes/api.php');
            $resultado_rutas = registrar_rutas_api_desde_fuente($ruta_backend, $ruta_api_fuente, $controladores_generados);
            $resumen['rutas_api'] = $resultado_rutas['estado'];
            $resumen['mapeo_fuentes'][] = [
                'origen' => 'routes/api.php',
                'destino' => 'routes/api.php',
                'tipo' => 'Rutas API adaptables',
                'adaptaciones_permitidas' => 'Imports y declaraciones apiResource; otras rutas se conservan.',
            ];
            if (in_array($resultado_rutas['estado'], ['conflicto', 'error'], true)) {
                $resumen['errores'][] = 'No se pudieron adaptar las rutas API: ' . $resultado_rutas['motivo'];
            } else {
                asegurar_registro_api_laravel11($ruta_backend, $resumen['advertencias']);
            }
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error al registrar rutas API: " . $e->getMessage();
        }
    }

    // La referencia Backend solo contiene la ruta web de bienvenida, no CRUD web.
    // Se copia esa ruta exacta si falta; la ausencia de CRUD Web es esperada en esta fase.
    $ruta_web_fuente = _ruta_archivo_fuente_backend($fuente_backend, 'routes/web.php');
    $copia_rutas_web = copiar_archivo_fuente($ruta_web_fuente, $ruta_backend, 'routes/web.php');
    $resumen['rutas_web'] = $copia_rutas_web['estado'];
    $resumen['mapeo_fuentes'][] = [
        'origen' => 'routes/web.php',
        'destino' => 'routes/web.php',
        'tipo' => 'Rutas Web no CRUD',
        'adaptaciones_permitidas' => 'Copia exacta; la fuente solo declara la página de bienvenida.',
    ];
    if (in_array($copia_rutas_web['estado'], ['conflicto', 'error'], true)) {
        $resumen['adaptaciones_pendientes'][] = [
            'tabla' => null,
            'tipo' => 'rutas Web',
            'motivo' => $copia_rutas_web['motivo'],
        ];
    }
    $resumen['advertencias'][] = 'No existen controladores Web CRUD en la fuente Backend; el CRUD Web se genera por separado desde la fuente Frontend.';

    // 4. Integrar Cloudinary si existen campos de imagen
    if ($hay_campos_imagen_global) {
        try {
            asegurar_dependencia_cloudinary($ruta_backend);
            $ruta_services_fuente = _ruta_archivo_fuente_backend($fuente_backend, 'config/services.php');
            $services_adaptado = configurar_services_cloudinary(
                $ruta_backend,
                $resumen['advertencias'],
                $ruta_services_fuente
            );
            if ($ruta_services_fuente !== null) {
                $resumen['mapeo_fuentes'][] = [
                    'origen' => 'config/services.php',
                    'destino' => 'config/services.php',
                    'tipo' => 'Configuración Cloudinary',
                    'adaptaciones_permitidas' => 'Entrada cloudinary original, solo cuando el destino aún no la define.',
                ];
            }
            if (!$services_adaptado) {
                $resumen['adaptaciones_pendientes'][] = [
                    'tabla' => null,
                    'tipo' => 'configuración Cloudinary',
                    'motivo' => 'No se pudo adaptar la entrada requerida desde config/services.php de la fuente.',
                ];
            }
            $resumen['cloudinary'] = $services_adaptado;
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error al configurar Cloudinary: " . $e->getMessage();
        }
    }

    // 5. Configurar .env de Backend
    $nom_proy = nombre_proyecto($config);
    try {
        $env_ok = configurar_env_backend($ruta_backend, $nom_proy, $hay_campos_imagen_global, $resumen['advertencias']);
        $resumen['env_actualizado'] = $env_ok;
    } catch (Throwable $e) {
        $resumen['advertencias'][] = "No se pudo actualizar .env: " . $e->getMessage();
    }

    // 6. Configurar Dominio Local en Hosts de Windows
    try {
        $resumen_hosts = configurar_hosts_local($nom_proy);
        $resumen['hosts'] = $resumen_hosts;
        if (!$resumen_hosts['exito'] && $resumen_hosts['estado'] === 'requiere_admin') {
            $resumen['advertencias'][] = "Hosts: agregue manualmente la siguiente línea a C:\\Windows\\System32\\drivers\\etc\\hosts:\n        " . implode("\n        ", $resumen_hosts['lineas_manuales']);
        }
    } catch (Throwable $e) {
        $resumen['advertencias'][] = "Error al verificar hosts: " . $e->getMessage();
    }

    return $resumen;
}
