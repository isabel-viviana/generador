<?php
// Generador Backend: Modelos Eloquent, Controladores API CRUD, Rutas, Cloudinary y Configuración Laravel.

if (!defined('CRUD_DIR')) {
    define('CRUD_DIR', dirname(__DIR__));
}

require_once CRUD_DIR . '/src/util.php';
require_once CRUD_DIR . '/src/migraciones.php';

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
        $use_cloudinary = "use App\Support\Cloudinary;\n";

        $store_lines = [];
        $update_lines = [];

        foreach ($campos_imagen as $campo_img) {
            $campo_literal = var_export($campo_img, true);
            $store_lines[] = "        if (\$request->hasFile($campo_literal)) {\n            \$data[$campo_literal] = Cloudinary::upload(\$request->file($campo_literal));\n        }";
            $update_lines[] = "        if (\$request->hasFile($campo_literal)) {\n            \$data[$campo_literal] = Cloudinary::upload(\$request->file($campo_literal));\n        } else {\n            unset(\$data[$campo_literal]); // Conserva la imagen existente\n        }";
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
    $tpl_path = CRUD_DIR . '/templates/back/cloudinary.tpl';
    if (!file_exists($tpl_path)) {
        throw new RuntimeException("Plantilla cloudinary.tpl no encontrada.");
    }

    $target_dir = $ruta_backend . '/app/Support';
    carpetas_permitidas(array_merge(carpetas_permitidas(), [$target_dir]));

    $codigo = file_get_contents($tpl_path);
    $ruta_archivo = $target_dir . '/Cloudinary.php';

    return escribir($ruta_archivo, $codigo);
}

// Configura config/services.php agregando la clave 'cloudinary' solo si no existe.
function configurar_services_cloudinary($ruta_backend, &$advertencias)
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

    $bloque_cloudinary = <<<PHP

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder' => env('CLOUDINARY_FOLDER', 'crud_uploads'),
    ],
PHP;

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

// ------------------------------------------------------------
// Flujo Principal Backend CRUD
// ------------------------------------------------------------

function generar_backend_crud($ruta_backend, $config)
{
    require_once CRUD_DIR . '/src/hosts.php';

    $resumen = [
        'modelos' => [],
        'controladores' => [],
        'omitidas' => [],
        'rutas_api' => null,
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

        // Verificar si la tabla tiene campos de imagen
        foreach ($tbl_info['columnas'] as $c_def) {
            if (!empty($c_def['es_imagen'])) {
                $hay_campos_imagen_global = true;
                break;
            }
        }

        // A. Generar Modelo Eloquent
        try {
            $codigo_modelo = generar_codigo_modelo($nom_tbl, $tbl_info, $tablas, $config, $relaciones_b04);
            $ruta_modelo = $ruta_backend . '/app/Models/' . $clase . '.php';
            $estado_m = escribir($ruta_modelo, $codigo_modelo);
            $resumen['modelos'][] = [
                'clase' => $clase,
                'archivo' => "app/Models/$clase.php",
                'estado' => $estado_m,
            ];
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error generando modelo $clase: " . $e->getMessage();
        }

        // B. Generar Controlador CRUD directamente en app/Http/Controllers/
        try {
            $codigo_ctrl = generar_codigo_controlador($nom_tbl, $tbl_info, $tablas, $config, $relaciones_b04);
            $ruta_ctrl = $ruta_backend . '/app/Http/Controllers/' . $clase . 'Controller.php';
            $estado_c = escribir($ruta_ctrl, $codigo_ctrl);
            $resumen['controladores'][] = [
                'clase' => $clase . 'Controller',
                'archivo' => "app/Http/Controllers/{$clase}Controller.php",
                'estado' => $estado_c,
            ];
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
            $estado_rutas = registrar_rutas_api($ruta_backend, $controladores_generados);
            $resumen['rutas_api'] = $estado_rutas;

            // Compatibilidad Laravel 11 y 12
            asegurar_registro_api_laravel11($ruta_backend, $resumen['advertencias']);
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error al registrar rutas API: " . $e->getMessage();
        }
    }

    // 4. Integrar Cloudinary si existen campos de imagen
    if ($hay_campos_imagen_global) {
        try {
            asegurar_helper_cloudinary($ruta_backend);
            configurar_services_cloudinary($ruta_backend, $resumen['advertencias']);
            $resumen['cloudinary'] = true;
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
