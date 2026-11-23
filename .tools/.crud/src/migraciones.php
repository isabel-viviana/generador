<?php
// Analizador estático de migraciones de Laravel en PHP nativo (solo lectura).

// Comprueba si un nombre de columna coincide con los patrones configurados para imágenes.
function _es_campo_imagen($nombre_columna, $patrones_config)
{
    $nombre = strtolower($nombre_columna);
    foreach ($patrones_config as $patron) {
        $patron = strtolower(trim($patron));
        if (strpos($patron, '*') !== false) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($patron, '/')) . '$/i';
            if (preg_match($regex, $nombre)) {
                return true;
            }
        } elseif ($nombre === $patron) {
            return true;
        }
    }
    return false;
}

// Extrae el valor literal de una cadena de argumento entre comillas ('texto' o "texto") o null si no es literal.
function _extraer_literal_string($texto)
{
    $texto = trim($texto);
    if (preg_match('/^[\'"](.*)[\'"]$/s', $texto, $m)) {
        return $m[1];
    }
    return null;
}

// Extrae literales separados por comas al nivel exterior de paréntesis, respetando comillas y corchetes.
function _parsear_argumentos_literales($args_str)
{
    $args_str = trim($args_str);
    if ($args_str === '') {
        return [];
    }

    $args = [];
    $actual = '';
    $en_comilla = false;
    $tipo_comilla = '';
    $nivel_corchetes = 0;
    $nivel_parentesis = 0;
    $len = strlen($args_str);

    for ($i = 0; $i < $len; $i++) {
        $ch = $args_str[$i];

        if ($en_comilla) {
            $actual .= $ch;
            if ($ch === $tipo_comilla && ($i === 0 || $args_str[$i - 1] !== '\\')) {
                $en_comilla = false;
            }
            continue;
        }

        if ($ch === '\'' || $ch === '"') {
            $en_comilla = true;
            $tipo_comilla = $ch;
            $actual .= $ch;
            continue;
        }

        if ($ch === '[') {
            $nivel_corchetes++;
        } elseif ($ch === ']') {
            $nivel_corchetes--;
        } elseif ($ch === '(') {
            $nivel_parentesis++;
        } elseif ($ch === ')') {
            $nivel_parentesis--;
        }

        if ($ch === ',' && $nivel_corchetes === 0 && $nivel_parentesis === 0) {
            $args[] = trim($actual);
            $actual = '';
            continue;
        }

        $actual .= $ch;
    }

    if (trim($actual) !== '') {
        $args[] = trim($actual);
    }

    return $args;
}

function _extraer_lista_literales($valor)
{
    $valor = trim($valor);
    if (preg_match('/^\[(.*)\]$/s', $valor, $m)) {
        $argumentos = _parsear_argumentos_literales($m[1]);
        $valores = [];
        foreach ($argumentos as $argumento) {
            $literal = _extraer_literal_string($argumento);
            if ($literal === null) {
                return null;
            }
            $valores[] = $literal;
        }
        return $valores;
    }

    $literal = _extraer_literal_string($valor);
    return $literal === null ? null : [$literal];
}

function _normalizar_tipo_migracion($tipo)
{
    $mapa = [
        'id' => ['integer', true, 'big'],
        'increments' => ['integer', true, 'medium'],
        'bigIncrements' => ['integer', true, 'big'],
        'mediumIncrements' => ['integer', true, 'medium'],
        'smallIncrements' => ['integer', true, 'small'],
        'tinyIncrements' => ['integer', true, 'tiny'],
        'string' => ['string', false, null],
        'char' => ['string', false, null],
        'text' => ['text', false, null],
        'mediumText' => ['text', false, null],
        'longText' => ['text', false, null],
        'integer' => ['integer', false, 'medium'],
        'unsignedInteger' => ['integer', true, 'medium'],
        'tinyInteger' => ['integer', false, 'tiny'],
        'unsignedTinyInteger' => ['integer', true, 'tiny'],
        'smallInteger' => ['integer', false, 'small'],
        'unsignedSmallInteger' => ['integer', true, 'small'],
        'mediumInteger' => ['integer', false, 'medium'],
        'unsignedMediumInteger' => ['integer', true, 'medium'],
        'bigInteger' => ['integer', false, 'big'],
        'unsignedBigInteger' => ['integer', true, 'big'],
        'decimal' => ['decimal', false, null],
        'float' => ['decimal', false, null],
        'double' => ['decimal', false, null],
        'boolean' => ['boolean', false, null],
        'date' => ['date', false, null],
        'dateTime' => ['datetime', false, null],
        'dateTimeTz' => ['datetime', false, null],
        'time' => ['time', false, null],
        'timeTz' => ['time', false, null],
        'timestamp' => ['timestamp', false, null],
        'timestampTz' => ['timestamp', false, null],
        'foreignId' => ['integer', true, 'big'],
        'foreignUuid' => ['uuid', false, null],
        'foreignUlid' => ['ulid', false, null],
        'uuid' => ['uuid', false, null],
        'ulid' => ['ulid', false, null],
        'enum' => ['enum', false, null],
        'set' => ['set', false, null],
        'json' => ['json', false, null],
        'jsonb' => ['json', false, null],
        'binary' => ['binary', false, null],
        'ipAddress' => ['string', false, null],
        'macAddress' => ['string', false, null],
        'year' => ['integer', false, 'small'],
    ];

    if (!isset($mapa[$tipo])) {
        return null;
    }

    return [
        'canonical' => $mapa[$tipo][0],
        'unsigned' => $mapa[$tipo][1],
        'width' => $mapa[$tipo][2],
    ];
}

// Desglosa las llamadas encadenadas ->metodo(...) de una sentencia sin confundir flechas o paréntesis dentro de strings.
function _desglosar_llamadas_encadenadas($sentencia)
{
    $llamadas = [];
    $len = strlen($sentencia);
    $en_comilla = false;
    $tipo_comilla = '';
    $i = 0;

    while ($i < $len) {
        $ch = $sentencia[$i];

        if ($en_comilla) {
            if ($ch === $tipo_comilla && ($i === 0 || $sentencia[$i - 1] !== '\\')) {
                $en_comilla = false;
            }
            $i++;
            continue;
        }

        if ($ch === '\'' || $ch === '"') {
            $en_comilla = true;
            $tipo_comilla = $ch;
            $i++;
            continue;
        }

        // Buscar inicio de llamada '->'
        if ($ch === '-' && ($i + 1 < $len) && $sentencia[$i + 1] === '>') {
            $i += 2;
            // Leer nombre del método
            $nombre_metodo = '';
            while ($i < $len && preg_match('/[a-zA-Z0-9_]/', $sentencia[$i])) {
                $nombre_metodo .= $sentencia[$i];
                $i++;
            }

            if ($nombre_metodo === '') {
                continue;
            }

            // Omitir espacios entre nombre de método y '(' si los hay
            while ($i < $len && ($sentencia[$i] === ' ' || $sentencia[$i] === "\t" || $sentencia[$i] === "\r" || $sentencia[$i] === "\n")) {
                $i++;
            }

            // Si tiene argumentos (...)
            $args_raw = '';
            if ($i < $len && $sentencia[$i] === '(') {
                $nivel = 1;
                $i++;
                $comilla_args = false;
                $t_comilla_args = '';

                while ($i < $len && $nivel > 0) {
                    $c = $sentencia[$i];
                    if ($comilla_args) {
                        $args_raw .= $c;
                        if ($c === $t_comilla_args && ($i === 0 || $sentencia[$i - 1] !== '\\')) {
                            $comilla_args = false;
                        }
                        $i++;
                        continue;
                    }

                    if ($c === '\'' || $c === '"') {
                        $comilla_args = true;
                        $t_comilla_args = $c;
                        $args_raw .= $c;
                        $i++;
                        continue;
                    }

                    if ($c === '(') {
                        $nivel++;
                    } elseif ($c === ')') {
                        $nivel--;
                        if ($nivel === 0) {
                            $i++;
                            break;
                        }
                    }

                    $args_raw .= $c;
                    $i++;
                }
            }

            $llamadas[] = [
                'metodo' => $nombre_metodo,
                'args_raw' => $args_raw,
            ];
            continue;
        }

        $i++;
    }

    return $llamadas;
}

// Analiza una sentencia de definición sobre $table (ej: $table->string('nombre', 100)->nullable();)
function _analizar_sentencia_columna($sentencia, $patrones_imagen, $archivo, $linea_aprox, &$advertencias)
{
    $sentencia = rtrim(trim($sentencia), ';');
    $llamadas = _desglosar_llamadas_encadenadas($sentencia);
    if (empty($llamadas)) {
        if ($sentencia === '') {
            return null;
        }
        $advertencias[] = "[$archivo:~L$linea_aprox] Operación no reconocida; se conserva como sintaxis no analizada.";
        return [
            'tipo_accion' => 'unknown_operation',
            'expresion' => $sentencia,
        ];
    }

    $primera_llamada = $llamadas[0];
    $metodo_tipo = $primera_llamada['metodo'];
    $args = _parsear_argumentos_literales($primera_llamada['args_raw']);
    $tipos_especiales = ['timestamps', 'nullableTimestamps', 'timestampsTz', 'nullableTimestampsTz'];
    if (in_array($metodo_tipo, $tipos_especiales, true)) {
        return ['tipo_accion' => 'timestamps', 'con_zona_horaria' => str_ends_with($metodo_tipo, 'Tz')];
    }
    if (in_array($metodo_tipo, ['softDeletes', 'softDeletesTz'], true)) {
        return ['tipo_accion' => 'soft_deletes', 'con_zona_horaria' => str_ends_with($metodo_tipo, 'Tz')];
    }
    if ($metodo_tipo === 'rememberToken') {
        return [
            'tipo_accion' => 'columna',
            'nombre' => 'remember_token',
            'tipo' => 'string',
            'tipo_normalizado' => 'string',
            'opciones' => ['longitud' => 100],
            'unsigned' => false,
            'width' => null,
            'es_pk' => false,
            'autoincrement' => false,
            'nullable' => true,
            'nullable_declarado' => true,
            'default' => null,
            'default_metadata' => ['declarado' => false, 'tipo' => 'ausente', 'valor' => null, 'expresion' => null],
            'unique' => false,
            'es_imagen' => false,
            'fk' => null,
            'indices' => [],
            'modificadores_no_reconocidos' => [],
        ];
    }

    $acciones_indices = ['primary', 'unique', 'index', 'fullText', 'spatialIndex'];
    if (in_array($metodo_tipo, $acciones_indices, true)) {
        $columnas = _extraer_lista_literales($args[0] ?? '');
        if ($columnas === null) {
            $advertencias[] = "[$archivo:~L$linea_aprox] No se pudieron resolver las columnas de ->$metodo_tipo().";
            return ['tipo_accion' => 'unknown_operation', 'expresion' => $sentencia];
        }
        $tipos_indice = [
            'primary' => 'primary',
            'unique' => 'unique',
            'index' => 'index',
            'fullText' => 'fulltext',
            'spatialIndex' => 'spatial',
        ];
        return [
            'tipo_accion' => 'index',
            'indice' => [
                'tipo' => $tipos_indice[$metodo_tipo],
                'columnas' => $columnas,
                'nombre' => _extraer_literal_string($args[1] ?? ''),
                'unico' => in_array($metodo_tipo, ['primary', 'unique'], true),
            ],
        ];
    }

    $acciones_eliminar_indice = [
        'dropPrimary' => 'primary',
        'dropUnique' => 'unique',
        'dropIndex' => 'index',
        'dropFullText' => 'fulltext',
        'dropSpatialIndex' => 'spatial',
    ];
    if (isset($acciones_eliminar_indice[$metodo_tipo])) {
        $valor = $args[0] ?? '';
        $es_lista_columnas = str_starts_with(trim($valor), '[');
        return [
            'tipo_accion' => 'drop_index',
            'tipo_indice' => $acciones_eliminar_indice[$metodo_tipo],
            'columnas' => $es_lista_columnas ? _extraer_lista_literales($valor) : null,
            'nombre' => $es_lista_columnas ? null : _extraer_literal_string($valor),
        ];
    }

    if ($metodo_tipo === 'dropColumn') {
        $columnas = [];
        foreach ($args as $argumento) {
            $lista = _extraer_lista_literales($argumento);
            if ($lista !== null) {
                $columnas = array_merge($columnas, $lista);
            }
        }
        return ['tipo_accion' => 'drop_column', 'columnas' => $columnas];
    }
    if ($metodo_tipo === 'renameColumn') {
        $desde = _extraer_literal_string($args[0] ?? '');
        $hacia = _extraer_literal_string($args[1] ?? '');
        if ($desde !== null && $hacia !== null) {
            return ['tipo_accion' => 'rename_column', 'de' => $desde, 'a' => $hacia];
        }
        $advertencias[] = "[$archivo:~L$linea_aprox] renameColumn() contiene nombres no literales.";
        return ['tipo_accion' => 'unknown_operation', 'expresion' => $sentencia];
    }

    if ($metodo_tipo === 'foreign') {
        $columnas_fk = _extraer_lista_literales($args[0] ?? '');
        $referencia_columnas = ['id'];
        $tabla_referenciada = null;
        $on_delete = null;
        $on_update = null;
        for ($i = 1; $i < count($llamadas); $i++) {
            $mod = $llamadas[$i]['metodo'];
            $mod_args = _parsear_argumentos_literales($llamadas[$i]['args_raw']);
            if ($mod === 'references') {
                $referencia_columnas = _extraer_lista_literales($mod_args[0] ?? '') ?? [];
            } elseif ($mod === 'on') {
                $tabla_referenciada = _extraer_literal_string($mod_args[0] ?? '');
            } elseif ($mod === 'onDelete') {
                $on_delete = _extraer_literal_string($mod_args[0] ?? '');
            } elseif ($mod === 'onUpdate') {
                $on_update = _extraer_literal_string($mod_args[0] ?? '');
            } elseif ($mod === 'cascadeOnDelete') {
                $on_delete = 'cascade';
            } elseif ($mod === 'nullOnDelete') {
                $on_delete = 'set null';
            } elseif ($mod === 'restrictOnDelete') {
                $on_delete = 'restrict';
            } elseif ($mod === 'noActionOnDelete') {
                $on_delete = 'no action';
            } elseif ($mod === 'cascadeOnUpdate') {
                $on_update = 'cascade';
            } elseif ($mod === 'nullOnUpdate') {
                $on_update = 'set null';
            } elseif ($mod === 'restrictOnUpdate') {
                $on_update = 'restrict';
            } elseif ($mod === 'noActionOnUpdate') {
                $on_update = 'no action';
            }
        }
        if ($columnas_fk === null || $tabla_referenciada === null || empty($referencia_columnas)) {
            $advertencias[] = "[$archivo:~L$linea_aprox] Foreign key con columnas o destino no literal; no se crea una relación inferida.";
            return ['tipo_accion' => 'unknown_operation', 'expresion' => $sentencia];
        }
        return [
            'tipo_accion' => 'foreign_key',
            'foreign_key' => [
                'columnas' => $columnas_fk,
                'tabla_referenciada' => $tabla_referenciada,
                'columnas_referenciadas' => $referencia_columnas,
                'on_delete' => $on_delete,
                'on_update' => $on_update,
                'resolucion' => 'explicita',
                'nombre' => _extraer_literal_string($args[1] ?? ''),
            ],
        ];
    }

    if (in_array($metodo_tipo, ['dropForeign'], true)) {
        $valor = $args[0] ?? '';
        $es_lista_columnas = str_starts_with(trim($valor), '[');
        return [
            'tipo_accion' => 'drop_foreign_key',
            'columnas' => $es_lista_columnas ? _extraer_lista_literales($valor) : null,
            'nombre' => $es_lista_columnas ? null : _extraer_literal_string($valor),
        ];
    }

    $info_tipo = _normalizar_tipo_migracion($metodo_tipo);
    if ($metodo_tipo === 'foreignIdFor') {
        $nombre_columna = _extraer_literal_string($args[1] ?? '');
        if ($nombre_columna === null) {
            $advertencias[] = "[$archivo:~L$linea_aprox] foreignIdFor() requiere resolución de modelo no ejecutable; no se infiere una FK.";
            return ['tipo_accion' => 'unknown_column', 'nombre' => null, 'tipo_laravel' => $metodo_tipo, 'expresion' => $sentencia];
        }
        $info_tipo = _normalizar_tipo_migracion('foreignId');
    } elseif (in_array($metodo_tipo, ['id', 'increments', 'bigIncrements', 'mediumIncrements', 'smallIncrements', 'tinyIncrements'], true)) {
        $nombre_columna = _extraer_literal_string($args[0] ?? '') ?? 'id';
    } else {
        $nombre_columna = _extraer_literal_string($args[0] ?? '');
    }

    if ($info_tipo === null) {
        $advertencias[] = "[$archivo:~L$linea_aprox] Tipo de columna no reconocido o no soportado: '->$metodo_tipo()'.";
        return [
            'tipo_accion' => 'unknown_column',
            'nombre' => $nombre_columna,
            'tipo_laravel' => $metodo_tipo,
            'expresion' => $sentencia,
        ];
    }
    if ($nombre_columna === null) {
        $advertencias[] = "[$archivo:~L$linea_aprox] Nombre no literal en '->$metodo_tipo()'; no se crea una columna inventada.";
        return ['tipo_accion' => 'unknown_column', 'nombre' => null, 'tipo_laravel' => $metodo_tipo, 'expresion' => $sentencia];
    }

    $opciones = [];
    if (in_array($metodo_tipo, ['string', 'char'], true) && isset($args[1]) && is_numeric($args[1])) {
        $opciones['longitud'] = (int)$args[1];
    } elseif ($metodo_tipo === 'decimal') {
        if (isset($args[1]) && is_numeric($args[1])) {
            $opciones['precision'] = (int)$args[1];
        }
        if (isset($args[2]) && is_numeric($args[2])) {
            $opciones['escala'] = (int)$args[2];
        }
    } elseif (in_array($metodo_tipo, ['enum', 'set'], true)) {
        $opciones['valores'] = isset($args[1]) ? _extraer_lista_literales($args[1]) : null;
    }
    $opciones_declaradas = array_keys($opciones);
    if (in_array($metodo_tipo, ['enum', 'set'], true) && isset($args[1]) && $opciones['valores'] === null) {
        $advertencias[] = "[$archivo:~L$linea_aprox] Valores no literales en '->$metodo_tipo()'; se conserva el tipo sin inventar sus opciones.";
    }

    $nullable = null;
    $nullable_declarado = false;
    $default_metadata = ['declarado' => false, 'tipo' => 'ausente', 'valor' => null, 'expresion' => null];
    $default_legacy = null;
    $unique = false;
    $es_pk = in_array($metodo_tipo, ['id', 'increments', 'bigIncrements', 'mediumIncrements', 'smallIncrements', 'tinyIncrements'], true);
    $autoincrement = $es_pk;
    $auto_declarado = $es_pk;
    $unsigned = $info_tipo['unsigned'];
    $change = false;
    $foreign_key = null;
    $indices = [];
    $modificadores = [];
    $modificadores_no_reconocidos = [];
    $modificadores_conocidos = [
        'nullable', 'default', 'unique', 'primary', 'index', 'fullText', 'spatialIndex',
        'autoIncrement', 'unsigned', 'change', 'constrained', 'onDelete', 'onUpdate',
        'cascadeOnDelete', 'nullOnDelete', 'restrictOnDelete', 'noActionOnDelete',
        'cascadeOnUpdate', 'nullOnUpdate', 'restrictOnUpdate', 'noActionOnUpdate',
        'comment', 'after', 'first', 'charset', 'collation', 'useCurrent',
        'useCurrentOnUpdate', 'storedAs', 'virtualAs', 'generatedAs', 'always',
    ];

    for ($i = 1; $i < count($llamadas); $i++) {
        $mod = $llamadas[$i]['metodo'];
        $mod_args = _parsear_argumentos_literales($llamadas[$i]['args_raw']);
        $modificadores[] = ['metodo' => $mod, 'argumentos' => $mod_args];
        if (!in_array($mod, $modificadores_conocidos, true)) {
            $modificadores_no_reconocidos[] = $mod;
            continue;
        }

        if ($mod === 'nullable') {
            $nullable_declarado = true;
            if (empty($mod_args) || strtolower(trim($mod_args[0])) === 'true') {
                $nullable = true;
            } elseif (strtolower(trim($mod_args[0])) === 'false') {
                $nullable = false;
            } else {
                $nullable = null;
                $advertencias[] = "[$archivo:~L$linea_aprox] nullable() contiene una expresión no literal.";
            }
        } elseif ($mod === 'default') {
            $default_metadata['declarado'] = true;
            $valor = trim($mod_args[0] ?? '');
            $literal_string = _extraer_literal_string($valor);
            if ($literal_string !== null) {
                $default_metadata = ['declarado' => true, 'tipo' => 'literal', 'valor' => $literal_string, 'expresion' => null];
                $default_legacy = $literal_string;
            } elseif (strtolower($valor) === 'null') {
                $default_metadata = ['declarado' => true, 'tipo' => 'literal', 'valor' => null, 'expresion' => null];
            } elseif (strtolower($valor) === 'true' || strtolower($valor) === 'false') {
                $default_legacy = strtolower($valor) === 'true';
                $default_metadata = ['declarado' => true, 'tipo' => 'literal', 'valor' => $default_legacy, 'expresion' => null];
            } elseif ($valor !== '' && is_numeric($valor)) {
                $default_legacy = strpos($valor, '.') !== false ? (float)$valor : (int)$valor;
                $default_metadata = ['declarado' => true, 'tipo' => 'literal', 'valor' => $default_legacy, 'expresion' => null];
            } else {
                $default_metadata = ['declarado' => true, 'tipo' => 'expresion', 'valor' => null, 'expresion' => $valor];
                $advertencias[] = "[$archivo:~L$linea_aprox] Default dinámico o no reconocido en '$nombre_columna'; se conserva como expresión.";
            }
        } elseif ($mod === 'unique' || $mod === 'index' || $mod === 'fullText' || $mod === 'spatialIndex') {
            $tipo_indice = $mod === 'unique' ? 'unique' : ($mod === 'fullText' ? 'fulltext' : ($mod === 'spatialIndex' ? 'spatial' : 'index'));
            $indices[] = [
                'tipo' => $tipo_indice,
                'columnas' => [$nombre_columna],
                'nombre' => _extraer_literal_string($mod_args[0] ?? ''),
                'unico' => $tipo_indice === 'unique',
            ];
            if ($mod === 'unique') {
                $unique = true;
            }
        } elseif ($mod === 'primary') {
            $es_pk = true;
            $indices[] = [
                'tipo' => 'primary',
                'columnas' => [$nombre_columna],
                'nombre' => _extraer_literal_string($mod_args[0] ?? ''),
                'unico' => true,
            ];
        } elseif ($mod === 'autoIncrement') {
            $autoincrement = true;
            $auto_declarado = true;
        } elseif ($mod === 'unsigned') {
            $unsigned = true;
        } elseif ($mod === 'change') {
            $change = true;
        } elseif ($mod === 'constrained') {
            $tabla_ref = _extraer_literal_string($mod_args[0] ?? '');
            $columna_ref = _extraer_literal_string($mod_args[1] ?? '') ?? 'id';
            $foreign_key = [
                'columnas' => [$nombre_columna],
                'tabla_referenciada' => $tabla_ref,
                'columnas_referenciadas' => [$columna_ref],
                'on_delete' => null,
                'on_update' => null,
                'resolucion' => $tabla_ref === null ? 'convencion_pendiente' : 'explicita',
                'nombre' => _extraer_literal_string($mod_args[2] ?? ''),
            ];
        } elseif (in_array($mod, ['onDelete', 'onUpdate'], true)) {
            if ($foreign_key === null) {
                $advertencias[] = "[$archivo:~L$linea_aprox] $mod() no está asociado a una FK declarada.";
            } else {
                $foreign_key[$mod === 'onDelete' ? 'on_delete' : 'on_update'] =
                    _extraer_literal_string($mod_args[0] ?? '');
            }
        } elseif (in_array($mod, [
            'cascadeOnDelete', 'nullOnDelete', 'restrictOnDelete', 'noActionOnDelete',
            'cascadeOnUpdate', 'nullOnUpdate', 'restrictOnUpdate', 'noActionOnUpdate',
        ], true)) {
            if ($foreign_key === null) {
                $advertencias[] = "[$archivo:~L$linea_aprox] $mod() no está asociado a una FK declarada.";
            } else {
                $es_delete = str_ends_with($mod, 'OnDelete');
                $accion = str_starts_with($mod, 'cascade') ? 'cascade'
                    : (str_starts_with($mod, 'null') ? 'set null'
                        : (str_starts_with($mod, 'restrict') ? 'restrict' : 'no action'));
                $foreign_key[$es_delete ? 'on_delete' : 'on_update'] = $accion;
            }
        }
    }

    if (!empty($modificadores_no_reconocidos)) {
        $advertencias[] = "[$archivo:~L$linea_aprox] Modificadores no reconocidos en '$nombre_columna': " . implode(', ', $modificadores_no_reconocidos) . '.';
    }
    if ($foreign_key !== null && $foreign_key['tabla_referenciada'] === null && $metodo_tipo === 'foreignIdFor') {
        $advertencias[] = "[$archivo:~L$linea_aprox] No se puede resolver estáticamente el modelo de foreignIdFor() sin inventar su tabla.";
    }

    return [
        'tipo_accion' => $change ? 'alter_column' : 'columna',
        'nombre' => $nombre_columna,
        'tipo' => $metodo_tipo,
        'tipo_normalizado' => $info_tipo['canonical'],
        'opciones' => $opciones,
        'opciones_declaradas' => $opciones_declaradas,
        'argumentos_tipo' => $args,
        'unsigned' => $unsigned,
        'width' => $info_tipo['width'],
        'es_pk' => $es_pk,
        'autoincrement' => $auto_declarado ? $autoincrement : null,
        'nullable' => $nullable,
        'nullable_declarado' => $nullable_declarado,
        'default' => $default_legacy,
        'default_metadata' => $default_metadata,
        'unique' => $unique,
        'es_imagen' => _es_campo_imagen($nombre_columna, $patrones_imagen),
        'fk' => $foreign_key,
        'indices' => $indices,
        'modificadores' => $modificadores,
        'modificadores_no_reconocidos' => $modificadores_no_reconocidos,
        'expresion' => $sentencia,
    ];
}

// Analiza el contenido PHP de un archivo de migración tokenizado.
function _analizar_archivo_migracion($ruta_archivo, $patrones_imagen, &$advertencias)
{
    $nombre_archivo = basename($ruta_archivo);
    $codigo = @file_get_contents($ruta_archivo);
    if ($codigo === false) {
        $advertencias[] = "[$nombre_archivo] No se pudo leer el archivo de migración.";
        return [];
    }

    $tokens = token_get_all($codigo);
    $total_tokens = count($tokens);
    $aliases_schema = _aliases_schema_migracion($tokens);

    // 1. Localizar el cuerpo del método up()
    $en_up = false;
    $nivel_llaves = 0;
    $nivel_llaves_up = 0;
    $tokens_up = [];

    for ($i = 0; $i < $total_tokens; $i++) {
        $token = $tokens[$i];

        if (is_array($token)) {
            if ($token[0] === T_FUNCTION) {
                $j = $i + 1;
                while ($j < $total_tokens && is_array($tokens[$j]) && ($tokens[$j][0] === T_WHITESPACE || $tokens[$j][0] === T_COMMENT || $tokens[$j][0] === T_DOC_COMMENT)) {
                    $j++;
                }
                if ($j < $total_tokens && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && strtolower($tokens[$j][1]) === 'up') {
                    $en_up = true;
                    $nivel_llaves_up = $nivel_llaves;
                }
            }
        } else {
            if ($token === '{') {
                $nivel_llaves++;
            } elseif ($token === '}') {
                $nivel_llaves--;
                if ($en_up && $nivel_llaves === $nivel_llaves_up) {
                    $en_up = false;
                    break;
                }
            }
        }

        if ($en_up) {
            $tokens_up[] = $token;
        }
    }

    if (empty($tokens_up)) {
        $advertencias[] = "[$nombre_archivo] No se encontró un método up() analizable.";
        return [];
    }

    // 2. Extraer bloques Schema::create y Schema::table dentro de up()
    $operaciones = [];
    $total_up = count($tokens_up);

    for ($i = 0; $i < $total_up; $i++) {
        $t = $tokens_up[$i];

        // Buscar T_STRING 'DB' :: statement
        if (is_array($t) && $t[0] === T_STRING && $t[1] === 'DB') {
            $linea = $t[2];
            $advertencias[] = "[$nombre_archivo:~L$linea] Sentencia DB:: detectada en up(); no se interpretará código SQL crudo.";
        }

        // Buscar estructuras dinámicas o bucles fuera o dentro de Schema (foreach, for, while)
        if (is_array($t) && in_array($t[0], [T_FOREACH, T_FOR, T_WHILE], true)) {
            $linea = $t[2];
            $advertencias[] = "[$nombre_archivo:~L$linea] Estructura de control o bucle dinámico detectado ({$t[1]}); se omite para evitar inconsistencias.";
        }

        // Buscar Schema::create o Schema::table
        if (_es_token_schema($t, $aliases_schema)) {
            $j = $i + 1;
            while ($j < $total_up && is_array($tokens_up[$j]) && ($tokens_up[$j][0] === T_WHITESPACE)) {
                $j++;
            }
            if ($j < $total_up && is_array($tokens_up[$j]) && $tokens_up[$j][0] === T_DOUBLE_COLON) {
                $j++;
                while ($j < $total_up && is_array($tokens_up[$j]) && ($tokens_up[$j][0] === T_WHITESPACE)) {
                    $j++;
                }
                if ($j < $total_up && is_array($tokens_up[$j]) && $tokens_up[$j][0] === T_STRING && in_array(strtolower($tokens_up[$j][1]), ['create', 'table'], true)) {
                    $tipo_schema = strtolower($tokens_up[$j][1]); // 'create' o 'table'
                    $linea_schema = $tokens_up[$j][2];

                    // Avanzar hasta '(' y capturar el primer argumento (nombre de la tabla)
                    $k = $j + 1;
                    while ($k < $total_up && $tokens_up[$k] !== '(') {
                        $k++;
                    }
                    $k++; // después de '('

                    $nombre_tabla = null;
                    while ($k < $total_up && $tokens_up[$k] !== ',') {
                        if (is_array($tokens_up[$k]) && $tokens_up[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                            $nombre_tabla = _extraer_literal_string($tokens_up[$k][1]);
                        }
                        $k++;
                    }

                    if (!$nombre_tabla) {
                        $advertencias[] = "[$nombre_archivo:~L$linea_schema] Schema::$tipo_schema con nombre de tabla dinámico o no identificable; se omite.";
                        continue;
                    }

                    $callback_flecha = _capturar_callback_flecha($tokens_up, $k + 1, $total_up);
                    if ($callback_flecha !== null) {
                        $operaciones[] = [
                            'tipo_schema' => $tipo_schema,
                            'tabla' => $nombre_tabla,
                            'cuerpo' => $callback_flecha['cuerpo'],
                            'archivo' => $nombre_archivo,
                            'linea' => $linea_schema,
                            'callback_analizable' => $callback_flecha['analizable'],
                        ];
                        $i = $callback_flecha['fin'];
                        continue;
                    }

                    // Localizar el inicio del closure: function (...) { ... }
                    while ($k < $total_up && $tokens_up[$k] !== '{') {
                        $k++;
                    }
                    if ($k >= $total_up) {
                        $operaciones[] = [
                            'tipo_schema' => $tipo_schema,
                            'tabla' => $nombre_tabla,
                            'cuerpo' => '',
                            'archivo' => $nombre_archivo,
                            'linea' => $linea_schema,
                            'callback_analizable' => false,
                        ];
                        continue;
                    }

                    // Capturar el cuerpo del closure respetando llaves
                    $nivel_closure = 1;
                    $k++; // dentro de {
                    $cuerpo_closure = '';

                    while ($k < $total_up && $nivel_closure > 0) {
                        $tok = $tokens_up[$k];
                        if ($tok === '{') {
                            $nivel_closure++;
                            $cuerpo_closure .= '{';
                        } elseif ($tok === '}') {
                            $nivel_closure--;
                            if ($nivel_closure > 0) {
                                $cuerpo_closure .= '}';
                            }
                        } else {
                            $cuerpo_closure .= is_array($tok) ? $tok[1] : $tok;
                        }
                        $k++;
                    }

                    $operaciones[] = [
                        'tipo_schema' => $tipo_schema,
                        'tabla' => $nombre_tabla,
                        'cuerpo' => $cuerpo_closure,
                        'archivo' => $nombre_archivo,
                        'linea' => $linea_schema,
                    ];

                    $i = $k;
                } elseif ($j < $total_up && is_array($tokens_up[$j]) && $tokens_up[$j][0] === T_STRING) {
                    $advertencias[] = "[$nombre_archivo:~L{$tokens_up[$j][2]}] Schema::{$tokens_up[$j][1]}() no está soportado; el efecto no se aplica al esquema.";
                }
            }
        }
    }

    return $operaciones;
}

function _nueva_tabla_migracion($nombre, $creada = false)
{
    return [
        'nombre' => $nombre,
        'columnas' => [],
        'primary_key' => ['columnas' => [], 'generacion' => 'ninguna'],
        'indices' => [],
        'foreign_keys' => [],
        'fks' => [],
        'timestamps' => false,
        'timestamps_metadata' => ['presentes' => false, 'created_at' => null, 'updated_at' => null, 'zona_horaria' => null],
        'soft_deletes' => false,
        'soft_deletes_metadata' => ['presentes' => false, 'columna' => null, 'zona_horaria' => null],
        'es_pivote' => false,
        'pivot_assessment' => ['estado' => 'indeterminado', 'evidencia' => []],
        'archivos_origen' => [],
        'fuentes' => ['creada_en' => null, 'modificada_en' => []],
        'columnas_desconocidas' => [],
        'operaciones_no_reconocidas' => [],
        'analysis' => ['status' => 'complete', 'diagnostics' => []],
        'creada_en_migraciones' => $creada,
    ];
}

function _clasificar_tabla_analizada($tabla)
{
    $nombre = $tabla['nombre'] ?? '';
    $columnas = array_keys($tabla['columnas'] ?? []);
    $primary_key = array_values($tabla['primary_key']['columnas'] ?? []);
    $desconocidas = $tabla['columnas_desconocidas'] ?? [];
    $operaciones_no_reconocidas = $tabla['operaciones_no_reconocidas'] ?? [];
    $estado_analisis = $tabla['analysis']['status'] ?? 'partial';

    $perfiles_framework = [
        'migrations' => [
            'required' => ['migration', 'batch'],
            'allowed' => ['id', 'migration', 'batch'],
            'primary_keys' => [['id']],
            'types' => ['id' => ['integer'], 'migration' => ['string'], 'batch' => ['integer']],
        ],
        'password_reset_tokens' => [
            'required' => ['email', 'token'],
            'allowed' => ['email', 'token', 'created_at'],
            'primary_keys' => [[], ['email']],
            'types' => ['email' => ['string'], 'token' => ['string'], 'created_at' => ['timestamp']],
        ],
        'password_resets' => [
            'required' => ['email', 'token'],
            'allowed' => ['email', 'token', 'created_at'],
            'primary_keys' => [[], ['email']],
            'types' => ['email' => ['string'], 'token' => ['string'], 'created_at' => ['timestamp']],
        ],
        'sessions' => [
            'required' => ['id', 'payload', 'last_activity'],
            'allowed' => ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
            'primary_keys' => [['id']],
            'types' => [
                'id' => ['string'], 'user_id' => ['integer'], 'ip_address' => ['string'],
                'user_agent' => ['text'], 'payload' => ['text'], 'last_activity' => ['integer'],
            ],
        ],
        'cache' => [
            'required' => ['key', 'value', 'expiration'],
            'allowed' => ['key', 'value', 'expiration'],
            'primary_keys' => [['key']],
            'types' => ['key' => ['string'], 'value' => ['text'], 'expiration' => ['integer']],
        ],
        'cache_locks' => [
            'required' => ['key', 'owner', 'expiration'],
            'allowed' => ['key', 'owner', 'expiration'],
            'primary_keys' => [['key']],
            'types' => ['key' => ['string'], 'owner' => ['string'], 'expiration' => ['integer']],
        ],
        'jobs' => [
            'required' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
            'allowed' => ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'],
            'primary_keys' => [['id']],
            'types' => [
                'id' => ['integer'], 'queue' => ['string'], 'payload' => ['text'], 'attempts' => ['integer'],
                'reserved_at' => ['integer'], 'available_at' => ['integer'], 'created_at' => ['integer'],
            ],
        ],
        'job_batches' => [
            'required' => ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'created_at'],
            'allowed' => ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'failed_job_ids', 'options', 'cancelled_at', 'created_at', 'finished_at'],
            'primary_keys' => [['id']],
            'types' => [
                'id' => ['string'], 'name' => ['string'], 'total_jobs' => ['integer'], 'pending_jobs' => ['integer'],
                'failed_jobs' => ['integer'], 'failed_job_ids' => ['text'], 'options' => ['text'],
                'cancelled_at' => ['integer'], 'created_at' => ['integer'], 'finished_at' => ['integer'],
            ],
        ],
        'failed_jobs' => [
            'required' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
            'allowed' => ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'],
            'primary_keys' => [['id']],
            'types' => [
                'id' => ['integer'], 'uuid' => ['string', 'uuid'], 'connection' => ['text'], 'queue' => ['text'],
                'payload' => ['text'], 'exception' => ['text'], 'failed_at' => ['timestamp'],
            ],
        ],
        'personal_access_tokens' => [
            'required' => ['id', 'name', 'token', 'abilities', 'created_at', 'updated_at'],
            'allowed' => ['id', 'name', 'token', 'abilities', 'last_used_at', 'expires_at', 'created_at', 'updated_at'],
            'primary_keys' => [['id']],
            'expected_unknown' => ['morphs:tokenable', 'nullableMorphs:tokenable'],
            'types' => [
                'id' => ['integer'], 'name' => ['string'], 'token' => ['string'], 'abilities' => ['text'],
                'last_used_at' => ['timestamp'], 'expires_at' => ['timestamp'],
                'created_at' => ['timestamp'], 'updated_at' => ['timestamp'],
            ],
        ],
    ];

    if (isset($perfiles_framework[$nombre])) {
        $perfil = $perfiles_framework[$nombre];
        $columnas_esperadas = array_diff($perfil['required'], $columnas) === [];
        $sin_columnas_extra = array_diff($columnas, $perfil['allowed']) === [];
        $pk_esperada = in_array($primary_key, $perfil['primary_keys'], true);
        $tipos_esperados = true;
        foreach ($perfil['types'] as $nombre_columna => $tipos) {
            $tipo_columna = $tabla['columnas'][$nombre_columna]['tipo_normalizado'] ?? null;
            if (!in_array($tipo_columna, $tipos, true)) {
                $tipos_esperados = false;
                break;
            }
        }
        $fks_ausentes = empty($tabla['foreign_keys']);
        $morfologias_esperadas = $perfil['expected_unknown'] ?? [];
        $desconocidas_esperadas = count($desconocidas) === 0;
        if ($morfologias_esperadas !== [] && count($desconocidas) === 1) {
            $desconocida = $desconocidas[0];
            $firma = ($desconocida['tipo_laravel'] ?? '') . ':' . ($desconocida['nombre'] ?? '');
            $desconocidas_esperadas = in_array($firma, $morfologias_esperadas, true);
        }
        $analisis_completo_o_morfologia_conocida = $estado_analisis === 'complete'
            || ($morfologias_esperadas !== [] && $desconocidas_esperadas
                && count($desconocidas) === 1 && $estado_analisis === 'partial');

        if ($columnas_esperadas && $sin_columnas_extra && $pk_esperada && $tipos_esperados && $fks_ausentes
            && $desconocidas_esperadas && empty($operaciones_no_reconocidas)
            && $analisis_completo_o_morfologia_conocida) {
            return [
                'type' => 'framework_auxiliary',
                'confidence' => $desconocidas === [] ? 'high' : 'medium',
                'evidence' => [
                    'canonical_framework_table_name',
                    'framework_column_signature',
                    'expected_primary_key',
                ],
                'requires_review' => $desconocidas !== [],
            ];
        }
    }

    $schema_conocido = $desconocidas === [] && $operaciones_no_reconocidas === [];
    if ($schema_conocido && ($tabla['pivot_assessment']['simple_pivot'] ?? null) === true
        && !empty($tabla['es_pivote']) && $estado_analisis === 'complete') {
        return [
            'type' => 'pure_pivot',
            'confidence' => 'high',
            'evidence' => array_values(array_unique(array_merge(
                ['two_resolved_foreign_keys', 'no_own_primary_key', 'no_business_columns'],
                $tabla['pivot_assessment']['evidencia'] ?? []
            ))),
            'requires_review' => false,
        ];
    }

    if ($schema_conocido && ($tabla['pivot_assessment']['estado'] ?? null) === 'entidad_asociativa'
        && $primary_key !== []) {
        return [
            'type' => 'associative_entity',
            'confidence' => 'high',
            'evidence' => ['two_resolved_foreign_keys', 'own_primary_key'],
            'requires_review' => false,
        ];
    }

    if ($schema_conocido && $primary_key !== []) {
        $foreign_key_columns = [];
        foreach ($tabla['foreign_keys'] ?? [] as $foreign_key) {
            $foreign_key_columns = array_merge($foreign_key_columns, $foreign_key['columnas'] ?? []);
        }
        $structural_columns = array_merge(
            $primary_key,
            $foreign_key_columns,
            ['created_at', 'updated_at', 'deleted_at']
        );
        $business_columns = array_diff($columnas, array_unique($structural_columns));
        if ($business_columns !== []) {
            return [
                'type' => 'crud_entity',
                'confidence' => 'high',
                'evidence' => ['primary_key_present', 'business_columns_present'],
                'requires_review' => false,
            ];
        }
    }

    $evidencia = [];
    if ($primary_key === []) {
        $evidencia[] = 'primary_key_not_confirmed';
    }
    if (!$schema_conocido) {
        $evidencia[] = 'unrecognized_schema_operations';
    }
    if ($estado_analisis !== 'complete') {
        $evidencia[] = 'analysis_not_complete';
    }
    if ($evidencia === []) {
        $evidencia[] = 'insufficient_structural_evidence';
    }
    return [
        'type' => 'ambiguous',
        'confidence' => 'low',
        'evidence' => $evidencia,
        'requires_review' => true,
    ];
}

function _clave_unica_en_tabla_relaciones($tabla, $columnas)
{
    $normalizar = static function ($valores) {
        $valores = array_values($valores);
        sort($valores, SORT_STRING);
        return $valores;
    };
    $columnas_normalizadas = $normalizar($columnas);
    if ($columnas_normalizadas !== [] && $columnas_normalizadas === $normalizar($tabla['primary_key']['columnas'] ?? [])) {
        return true;
    }
    foreach ($tabla['indices'] ?? [] as $indice) {
        if (in_array($indice['tipo'] ?? null, ['primary', 'unique'], true)
            && $columnas_normalizadas === $normalizar($indice['columnas'] ?? [])) {
            return true;
        }
    }
    return false;
}

function _campos_candidatos_selector_relacion($tabla)
{
    $candidatos = [];
    $claves = $tabla['primary_key']['columnas'] ?? [];
    foreach ($tabla['columnas'] ?? [] as $nombre => $columna) {
        if (in_array($nombre, $claves, true) || !empty($columna['es_imagen'])) {
            continue;
        }
        if (in_array($columna['tipo_normalizado'] ?? null, ['string', 'text', 'enum'], true)) {
            $candidatos[] = [
                'column' => $nombre,
                'type' => $columna['tipo_normalizado'],
            ];
        }
    }
    return $candidatos;
}

function _agregar_relacion_estructural(&$relaciones, &$tablas, $relacion, $tabla_salida, $tabla_entrada = null)
{
    $relaciones[] = $relacion;
    if (isset($tablas[$tabla_salida])) {
        $tablas[$tabla_salida]['relationships']['outgoing'][] = $relacion['id'];
    }
    if ($tabla_entrada !== null && isset($tablas[$tabla_entrada])) {
        $tablas[$tabla_entrada]['relationships']['incoming'][] = $relacion['id'];
    }
}

function _analizar_relaciones_estructurales(&$tablas)
{
    $relaciones = [];
    $diagnosticos = [];
    $columnas_no_verificadas = [];
    $foreign_keys_resueltas = [];

    foreach ($tablas as &$tabla) {
        $tabla['relationships'] = ['outgoing' => [], 'incoming' => []];
    }
    unset($tabla);

    foreach ($tablas as $nombre_origen => $tabla_origen) {
        if (($tabla_origen['analysis']['status'] ?? null) === 'partial') {
            $diagnosticos[] = [
                'code' => 'source_schema_partial',
                'severity' => 'warning',
                'message' => "El esquema de '$nombre_origen' es parcial; las relaciones derivadas pueden estar incompletas.",
                'table' => $nombre_origen,
            ];
        }

        $columnas_con_fk = [];
        foreach ($tabla_origen['foreign_keys'] ?? [] as $indice_fk => $fk) {
            $columnas_fk = array_values($fk['columnas'] ?? []);
            $columnas_referenciadas = array_values($fk['columnas_referenciadas'] ?? []);
            $nombre_destino = $fk['tabla_referenciada'] ?? null;
            $id_fk = 'fk:' . $nombre_origen . ':' . $indice_fk;
            $columnas_con_fk = array_merge($columnas_con_fk, $columnas_fk);
            $relacion = [
                'id' => $id_fk,
                'type' => 'ambiguous',
                'status' => 'ambiguous',
                'from' => ['table' => $nombre_origen, 'columns' => $columnas_fk],
                'to' => ['table' => $nombre_destino, 'columns' => $columnas_referenciadas],
                'keys' => [
                    'foreign' => ['table' => $nombre_origen, 'columns' => $columnas_fk],
                    'owner' => ['table' => $nombre_destino, 'columns' => $columnas_referenciadas],
                ],
                'foreign_key' => [
                    'name' => $fk['nombre'] ?? null,
                    'on_delete' => $fk['on_delete'] ?? null,
                    'on_update' => $fk['on_update'] ?? null,
                    'resolution' => $fk['resolucion'] ?? null,
                    'source' => $fk['source'] ?? null,
                ],
                'evidence' => ['migration_foreign_key'],
                'form_fields' => [
                    'foreign_key_columns' => $columnas_fk,
                    'related_key_columns' => $columnas_referenciadas,
                    'select_field_candidates' => [],
                ],
                'endpoint_classifications' => [
                    'from' => $tabla_origen['classification']['type'] ?? 'ambiguous',
                    'to' => isset($tablas[$nombre_destino])
                        ? ($tablas[$nombre_destino]['classification']['type'] ?? 'ambiguous')
                        : null,
                ],
            ];

            if ($columnas_fk === [] || $columnas_referenciadas === []
                || count($columnas_fk) !== count($columnas_referenciadas)) {
                $relacion['status'] = 'incomplete';
                $diagnosticos[] = [
                    'code' => 'foreign_key_columns_incomplete',
                    'severity' => 'warning',
                    'message' => "La FK '$id_fk' no contiene pares completos de columnas.",
                    'table' => $nombre_origen,
                    'foreign_key' => $fk['nombre'] ?? null,
                ];
            } elseif ($nombre_destino === null || !isset($tablas[$nombre_destino])) {
                $relacion['status'] = 'ambiguous';
                $diagnosticos[] = [
                    'code' => $nombre_destino === null ? 'foreign_key_target_unresolved' : 'foreign_key_target_missing',
                    'severity' => 'warning',
                    'message' => $nombre_destino === null
                        ? "No se pudo determinar el destino de la FK '$id_fk'; no se infirió por el nombre de columna."
                        : "La FK '$id_fk' apunta a la tabla no analizada '$nombre_destino'.",
                    'table' => $nombre_origen,
                    'foreign_key' => $fk['nombre'] ?? null,
                ];
            } else {
                $origen_columnas_existentes = count(array_intersect($columnas_fk, array_keys($tabla_origen['columnas'] ?? []))) === count($columnas_fk);
                $destino_columnas_existentes = count(array_intersect($columnas_referenciadas, array_keys($tablas[$nombre_destino]['columnas'] ?? []))) === count($columnas_referenciadas);
                if (!$origen_columnas_existentes || !$destino_columnas_existentes) {
                    $relacion['status'] = 'incomplete';
                    $diagnosticos[] = [
                        'code' => 'foreign_key_column_missing',
                        'severity' => 'warning',
                        'message' => "La FK '$id_fk' referencia columnas ausentes del esquema analizado.",
                        'table' => $nombre_origen,
                        'foreign_key' => $fk['nombre'] ?? null,
                    ];
                } elseif (count($columnas_fk) > 1) {
                    $relacion['type'] = 'unsupported';
                    $relacion['status'] = 'unsupported';
                    $relacion['evidence'][] = 'composite_foreign_key';
                    $diagnosticos[] = [
                        'code' => 'composite_relation_unsupported',
                        'severity' => 'warning',
                        'message' => "La FK compuesta '$id_fk' se conserva, pero no se convierte a una relación Eloquent estándar.",
                        'table' => $nombre_origen,
                        'foreign_key' => $fk['nombre'] ?? null,
                    ];
                } else {
                    $destino_unico = _clave_unica_en_tabla_relaciones($tablas[$nombre_destino], $columnas_referenciadas);
                    $relacion['type'] = 'belongsTo';
                    $relacion['status'] = $destino_unico ? 'complete' : 'partial';
                    $relacion['evidence'][] = 'resolved_target_columns';
                    $relacion['form_fields']['select_field_candidates'] = _campos_candidatos_selector_relacion($tablas[$nombre_destino]);
                    if ($destino_unico) {
                        $relacion['evidence'][] = 'target_key_unique';
                    } else {
                        $diagnosticos[] = [
                            'code' => 'foreign_key_target_not_unique',
                            'severity' => 'warning',
                            'message' => "La clave destino de '$id_fk' no aparece como PK o índice unique en el esquema analizado.",
                            'table' => $nombre_origen,
                            'foreign_key' => $fk['nombre'] ?? null,
                        ];
                    }
                    $foreign_keys_resueltas[$nombre_origen][] = [
                        'id' => $id_fk,
                        'fk' => $fk,
                        'target_table' => $nombre_destino,
                        'status' => $relacion['status'],
                    ];
                }
            }

            _agregar_relacion_estructural(
                $relaciones,
                $tablas,
                $relacion,
                $nombre_origen,
                isset($tablas[$nombre_destino]) ? $nombre_destino : null
            );
        }

        foreach ($tabla_origen['columnas'] ?? [] as $nombre_columna => $_columna) {
            if (preg_match('/_id$/', $nombre_columna) && !in_array($nombre_columna, $columnas_con_fk, true)) {
                $columnas_no_verificadas[] = [
                    'table' => $nombre_origen,
                    'column' => $nombre_columna,
                    'reason' => 'column_name_alone_is_not_foreign_key_evidence',
                ];
            }
        }
    }

    foreach ($foreign_keys_resueltas as $nombre_hijo => $fks) {
        foreach ($fks as $fk_info) {
            if ($fk_info['status'] !== 'complete') {
                continue;
            }
            $fk = $fk_info['fk'];
            $nombre_padre = $fk_info['target_table'];
            $columnas_fk = array_values($fk['columnas']);
            $columnas_referenciadas = array_values($fk['columnas_referenciadas']);
            $fk_unica = _clave_unica_en_tabla_relaciones($tablas[$nombre_hijo], $columnas_fk);
            $id_inversa = 'inverse:' . $fk_info['id'];
            $relacion_inversa = [
                'id' => $id_inversa,
                'type' => $fk_unica ? 'hasOne' : 'hasMany',
                'status' => 'complete',
                'from' => ['table' => $nombre_padre, 'columns' => $columnas_referenciadas],
                'to' => ['table' => $nombre_hijo, 'columns' => $columnas_fk],
                'keys' => [
                    'foreign' => ['table' => $nombre_hijo, 'columns' => $columnas_fk],
                    'owner' => ['table' => $nombre_padre, 'columns' => $columnas_referenciadas],
                ],
                'foreign_key' => [
                    'name' => $fk['nombre'] ?? null,
                    'on_delete' => $fk['on_delete'] ?? null,
                    'on_update' => $fk['on_update'] ?? null,
                    'resolution' => $fk['resolucion'] ?? null,
                    'source' => $fk['source'] ?? null,
                ],
                'evidence' => [
                    'migration_foreign_key',
                    $fk_unica ? 'foreign_columns_unique' : 'foreign_columns_not_unique',
                ],
                'form_fields' => [
                    'foreign_key_columns' => $columnas_fk,
                    'related_key_columns' => $columnas_referenciadas,
                    'select_field_candidates' => _campos_candidatos_selector_relacion($tablas[$nombre_hijo]),
                ],
                'endpoint_classifications' => [
                    'from' => $tablas[$nombre_padre]['classification']['type'] ?? 'ambiguous',
                    'to' => $tablas[$nombre_hijo]['classification']['type'] ?? 'ambiguous',
                ],
            ];
            _agregar_relacion_estructural($relaciones, $tablas, $relacion_inversa, $nombre_padre, $nombre_hijo);
        }
    }

    // Las tablas asociativas con clave primaria propia (por ejemplo
    // module_teachers) siguen siendo entidades CRUD, pero también producen
    // belongsToMany en los dos modelos que conectan.
    foreach ($tablas as $nombre_asociativa => $tabla_asociativa) {
        if (($tabla_asociativa['classification']['type'] ?? null) !== 'associative_entity') {
            continue;
        }
        $partes = $foreign_keys_resueltas[$nombre_asociativa] ?? [];
        if (count($partes) !== 2
            || $partes[0]['status'] !== 'complete'
            || $partes[1]['status'] !== 'complete'
            || $partes[0]['target_table'] === $partes[1]['target_table']) {
            continue;
        }
        $fk_a = $partes[0]['fk'];
        $fk_b = $partes[1]['fk'];
        if (count($fk_a['columnas'] ?? []) !== 1 || count($fk_b['columnas'] ?? []) !== 1) {
            continue;
        }
        $tabla_a = $partes[0]['target_table'];
        $tabla_b = $partes[1]['target_table'];
        $columnas = array_keys($tabla_asociativa['columnas'] ?? []);
        $pk = array_values($tabla_asociativa['primary_key']['columnas'] ?? []);
        $fks = array_values(array_unique(array_merge(
            $fk_a['columnas'] ?? [],
            $fk_b['columnas'] ?? []
        )));
        $estructural = array_values(array_unique(array_merge($pk, $fks, ['created_at', 'updated_at', 'deleted_at'])));
        $negocio = array_values(array_diff($columnas, $estructural));
        if ($negocio !== []) {
            continue;
        }

        foreach ([[0, 1], [1, 0]] as [$indice_origen, $indice_destino]) {
            $fk_origen = $partes[$indice_origen]['fk'];
            $fk_destino = $partes[$indice_destino]['fk'];
            $tabla_origen = $partes[$indice_origen]['target_table'];
            $tabla_destino = $partes[$indice_destino]['target_table'];
            $id = 'manyToMany:' . $nombre_asociativa . ':' . $tabla_origen . ':' . $tabla_destino;
            $relacion = [
                'id' => $id,
                'type' => 'belongsToMany',
                'status' => 'complete',
                'from' => ['table' => $tabla_origen, 'columns' => $fk_origen['columnas_referenciadas']],
                'to' => ['table' => $tabla_destino, 'columns' => $fk_destino['columnas_referenciadas']],
                'pivot_table' => $nombre_asociativa,
                'keys' => [
                    'foreign' => ['table' => $nombre_asociativa, 'columns' => $fk_origen['columnas']],
                    'related' => ['table' => $nombre_asociativa, 'columns' => $fk_destino['columnas']],
                    'owner' => ['table' => $tabla_origen, 'columns' => $fk_origen['columnas_referenciadas']],
                    'related_owner' => ['table' => $tabla_destino, 'columns' => $fk_destino['columnas_referenciadas']],
                ],
                'foreign_keys' => [$partes[$indice_origen]['id'], $partes[$indice_destino]['id']],
                'evidence' => ['own_primary_key_associative_entity', 'two_resolved_foreign_keys', 'no_business_columns'],
                'form_fields' => [
                    'select_field_candidates' => _campos_candidatos_selector_relacion($tablas[$tabla_destino]),
                ],
                'endpoint_classifications' => [
                    'from' => $tablas[$tabla_origen]['classification']['type'] ?? 'ambiguous',
                    'to' => $tablas[$tabla_destino]['classification']['type'] ?? 'ambiguous',
                ],
            ];
            _agregar_relacion_estructural($relaciones, $tablas, $relacion, $tabla_origen, $tabla_destino);
        }
    }

    foreach ($tablas as $nombre_pivote => $tabla_pivote) {
        if (($tabla_pivote['classification']['type'] ?? null) !== 'pure_pivot') {
            continue;
        }
        $partes = $foreign_keys_resueltas[$nombre_pivote] ?? [];
        $tipos_extremos_validos = count($partes) === 2
            && in_array($tablas[$partes[0]['target_table']]['classification']['type'] ?? null, ['crud_entity', 'associative_entity'], true)
            && in_array($tablas[$partes[1]['target_table']]['classification']['type'] ?? null, ['crud_entity', 'associative_entity'], true);
        if (count($partes) !== 2 || $partes[0]['status'] !== 'complete' || $partes[1]['status'] !== 'complete'
            || $partes[0]['target_table'] === $partes[1]['target_table']
            || count($partes[0]['fk']['columnas'] ?? []) !== 1
            || count($partes[1]['fk']['columnas'] ?? []) !== 1
            || !$tipos_extremos_validos) {
            $diagnosticos[] = [
                'code' => 'pure_pivot_relationships_incomplete',
                'severity' => 'warning',
                'message' => "El pivote puro '$nombre_pivote' no tiene exactamente dos FKs resolubles hacia entidades distintas y clasificadas.",
                'table' => $nombre_pivote,
            ];
            continue;
        }

        foreach ([[0, 1], [1, 0]] as [$indice_origen, $indice_destino]) {
            $fk_origen = $partes[$indice_origen]['fk'];
            $fk_destino = $partes[$indice_destino]['fk'];
            $tabla_origen = $partes[$indice_origen]['target_table'];
            $tabla_destino = $partes[$indice_destino]['target_table'];
            $id = 'manyToMany:' . $nombre_pivote . ':' . $tabla_origen . ':' . $tabla_destino;
            $relacion = [
                'id' => $id,
                'type' => 'belongsToMany',
                'status' => 'complete',
                'from' => ['table' => $tabla_origen, 'columns' => $fk_origen['columnas_referenciadas']],
                'to' => ['table' => $tabla_destino, 'columns' => $fk_destino['columnas_referenciadas']],
                'pivot_table' => $nombre_pivote,
                'keys' => [
                    'foreign' => ['table' => $nombre_pivote, 'columns' => $fk_origen['columnas']],
                    'related' => ['table' => $nombre_pivote, 'columns' => $fk_destino['columnas']],
                    'owner' => ['table' => $tabla_origen, 'columns' => $fk_origen['columnas_referenciadas']],
                    'related_owner' => ['table' => $tabla_destino, 'columns' => $fk_destino['columnas_referenciadas']],
                ],
                'foreign_keys' => [
                    $partes[$indice_origen]['id'],
                    $partes[$indice_destino]['id'],
                ],
                'evidence' => ['b03_pure_pivot_classification', 'two_resolved_foreign_keys'],
                'form_fields' => [
                    'select_field_candidates' => _campos_candidatos_selector_relacion($tablas[$tabla_destino]),
                ],
                'endpoint_classifications' => [
                    'from' => $tablas[$tabla_origen]['classification']['type'] ?? 'ambiguous',
                    'to' => $tablas[$tabla_destino]['classification']['type'] ?? 'ambiguous',
                ],
            ];
            _agregar_relacion_estructural($relaciones, $tablas, $relacion, $tabla_origen, $tabla_destino);
        }
    }

    return [
        'status' => $diagnosticos === [] ? 'complete' : 'partial',
        'diagnostics' => $diagnosticos,
        'unverified_columns' => $columnas_no_verificadas,
        'relations' => $relaciones,
    ];
}

function _diagnostico_migracion($mensaje, $tabla = null)
{
    $diagnostico = [
        'severity' => 'warning',
        'code' => 'migration_analysis_incomplete',
        'message' => $mensaje,
        'source' => ['file' => null, 'line' => null],
        'table' => $tabla,
        'column' => null,
        'impact' => 'structural',
    ];
    if (preg_match('/^\[([^:\]]+)(?::~?L([0-9]+))?\]/', $mensaje, $m)) {
        $diagnostico['source']['file'] = $m[1];
        $diagnostico['source']['line'] = isset($m[2]) ? (int)$m[2] : null;
    }
    return $diagnostico;
}

function guardia_generacion_migraciones($resultado)
{
    $estado = $resultado['analysis']['status'] ?? null;
    if (!in_array($estado, ['complete', 'partial', 'failed'], true)) {
        return [
            'bloqueada' => true,
            'mensaje' => 'Generación bloqueada: el analizador no devolvió un estado válido.',
        ];
    }
    if (!isset($resultado['tablas']) || !is_array($resultado['tablas'])
        || !isset($resultado['analysis']['diagnostics']) || !is_array($resultado['analysis']['diagnostics'])) {
        return [
            'bloqueada' => true,
            'mensaje' => 'Generación bloqueada: faltan tablas o diagnósticos válidos del análisis.',
        ];
    }
    if ($estado === 'failed') {
        return [
            'bloqueada' => true,
            'mensaje' => 'Generación bloqueada: no se pudo analizar el esquema de migraciones.',
        ];
    }

    if (($resultado['analysis']['generation_safe'] ?? null) === false) {
        return [
            'bloqueada' => true,
            'mensaje' => 'Generación bloqueada: el análisis marcó el esquema como inseguro para generar CRUD.',
        ];
    }

    $diagnosticos = $resultado['analysis']['diagnostics'];
    if ($estado === 'partial' && $diagnosticos === []) {
        return [
            'bloqueada' => true,
            'mensaje' => 'Generación bloqueada: el análisis es parcial y no explica los datos incompletos.',
        ];
    }
    foreach ($diagnosticos as $diagnostico) {
        if (($diagnostico['severity'] ?? null) === 'error'
            || in_array($diagnostico['impact'] ?? null, ['failed', 'critical', 'structural'], true)
            || ($estado === 'partial' && ($diagnostico['impact'] ?? null) !== 'informational')) {
            $detalle = trim((string)($diagnostico['message'] ?? ''));
            return [
                'bloqueada' => true,
                'mensaje' => 'Generación bloqueada: el análisis de migraciones puede alterar la estructura CRUD.'
                    . ($detalle === '' ? '' : ' ' . $detalle),
            ];
        }
    }

    return ['bloqueada' => false, 'mensaje' => null];
}

function _registrar_import_schema(&$aliases, $import, $prefix = '')
{
    $import = trim($import);
    if ($import === '' || preg_match('/^(function|const)\s+/i', $import)) {
        return;
    }

    $alias = null;
    if (preg_match('/\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*)$/i', $import, $match)) {
        $alias = $match[1];
        $import = trim(substr($import, 0, -strlen($match[0])));
    }
    $import = ltrim($prefix . ltrim($import, '\\'), '\\');
    $segmentos = explode('\\', $import);
    $basename = end($segmentos);
    if (strcasecmp($basename, 'Schema') === 0) {
        $aliases[$alias ?? $basename] = true;
    } elseif ($alias !== null && strcasecmp($import, 'Illuminate\\Support\\Facades') === 0) {
        $aliases[$alias . '\\Schema'] = true;
    }
}

function _aliases_schema_migracion($tokens)
{
    $aliases = ['Schema' => true];
    $total = count($tokens);
    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_RETURN, T_CLASS], true)) {
            break;
        }
        if (!is_array($token) || $token[0] !== T_USE) {
            continue;
        }

        $import = '';
        for ($j = $i + 1; $j < $total; $j++) {
            $part = $tokens[$j];
            if ($part === ';') {
                $i = $j;
                break;
            }
            $import .= is_array($part) ? $part[1] : $part;
        }
        $import = trim($import);
        if (preg_match('/^(.+?)\{(.*)\}$/s', $import, $group)) {
            foreach (explode(',', $group[2]) as $item) {
                _registrar_import_schema($aliases, $item, trim($group[1]));
            }
        } else {
            foreach (explode(',', $import) as $item) {
                _registrar_import_schema($aliases, $item);
            }
        }
    }
    return $aliases;
}

function _es_token_schema($token, $aliases)
{
    if (!is_array($token)) {
        return false;
    }
    if ($token[0] === T_STRING) {
        return isset($aliases[$token[1]]);
    }
    $name_tokens = array_filter([
        defined('T_NAME_QUALIFIED') ? T_NAME_QUALIFIED : null,
        defined('T_NAME_FULLY_QUALIFIED') ? T_NAME_FULLY_QUALIFIED : null,
        defined('T_NAME_RELATIVE') ? T_NAME_RELATIVE : null,
    ], static fn($id) => $id !== null);
    if (!in_array($token[0], $name_tokens, true)) {
        return false;
    }
    if (isset($aliases[$token[1]])) {
        return true;
    }
    $name = strtolower(ltrim($token[1], '\\'));
    if (str_starts_with($name, 'namespace\\')) {
        $name = substr($name, strlen('namespace\\'));
    }
    return in_array($name, ['schema', 'illuminate\\support\\facades\\schema'], true);
}

function _texto_tokens_migracion($tokens, $inicio, $fin)
{
    $texto = '';
    for ($i = $inicio; $i < $fin; $i++) {
        $texto .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
    }
    return $texto;
}

function _capturar_callback_flecha($tokens, $inicio, $total)
{
    while ($inicio < $total && is_array($tokens[$inicio])
        && in_array($tokens[$inicio][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $inicio++;
    }
    if ($inicio < $total && is_array($tokens[$inicio]) && $tokens[$inicio][0] === T_STATIC) {
        $inicio++;
        while ($inicio < $total && is_array($tokens[$inicio]) && $tokens[$inicio][0] === T_WHITESPACE) {
            $inicio++;
        }
    }
    if ($inicio >= $total || !is_array($tokens[$inicio]) || $tokens[$inicio][0] !== T_FN) {
        return null;
    }

    $flecha = $inicio + 1;
    while ($flecha < $total
        && (!is_array($tokens[$flecha]) || $tokens[$flecha][0] !== T_DOUBLE_ARROW)) {
        $flecha++;
    }
    if ($flecha >= $total) {
        return ['cuerpo' => '', 'fin' => $flecha, 'analizable' => false];
    }

    $parentesis = 1;
    $corchetes = 0;
    $llaves = 0;
    $fin = $flecha + 1;
    for (; $fin < $total; $fin++) {
        $token = $tokens[$fin];
        if (!is_array($token)) {
            if ($token === '(') {
                $parentesis++;
            } elseif ($token === ')') {
                if ($parentesis === 1 && $corchetes === 0 && $llaves === 0) {
                    break;
                }
                $parentesis--;
            } elseif ($token === '[') {
                $corchetes++;
            } elseif ($token === ']') {
                $corchetes--;
            } elseif ($token === '{') {
                $llaves++;
            } elseif ($token === '}') {
                $llaves--;
            } elseif ($token === ',' && $parentesis === 1 && $corchetes === 0 && $llaves === 0) {
                break;
            }
        }
    }

    $cuerpo = trim(_texto_tokens_migracion($tokens, $flecha + 1, $fin));
    $analizable = preg_match(
        '/^\$[a-zA-Z_][a-zA-Z0-9_]*(?:\s*->\s*[a-zA-Z_][a-zA-Z0-9_]*\s*\((?:[^()]|\([^()]*\))*\))+$/s',
        $cuerpo
    ) === 1;
    return [
        'cuerpo' => $cuerpo,
        'fin' => $fin,
        'analizable' => $analizable,
    ];
}

function _nombre_restriccion_laravel($tabla, $columnas, $tipo)
{
    $nombre = strtolower($tabla . '_' . implode('_', $columnas) . '_' . $tipo);
    return str_replace(['-', '.'], '_', $nombre);
}

function _agregar_indice_migracion(&$tabla, $indice, $fuente = null)
{
    if (($indice['nombre'] ?? null) === null && !empty($indice['columnas'])) {
        $indice['nombre'] = _nombre_restriccion_laravel($tabla['nombre'], $indice['columnas'], $indice['tipo']);
    }
    $indice['source'] = $fuente;
    foreach ($tabla['indices'] as $existente) {
        if ($existente['tipo'] === $indice['tipo']
            && $existente['columnas'] === $indice['columnas']
            && $existente['nombre'] === $indice['nombre']) {
            return;
        }
    }
    $tabla['indices'][] = $indice;
    if ($indice['tipo'] === 'primary') {
        foreach ($tabla['columnas'] as &$columna_existente) {
            $columna_existente['es_pk'] = false;
        }
        unset($columna_existente);
        $tabla['primary_key'] = [
            'columnas' => $indice['columnas'],
            'generacion' => 'asignada',
        ];
        foreach ($indice['columnas'] as $columna) {
            if (isset($tabla['columnas'][$columna])) {
                $tabla['columnas'][$columna]['es_pk'] = true;
            }
        }
    }
    if ($indice['tipo'] === 'unique' && count($indice['columnas']) === 1) {
        $columna = $indice['columnas'][0];
        if (isset($tabla['columnas'][$columna])) {
            $tabla['columnas'][$columna]['unique'] = true;
        }
    }
}

function _agregar_fk_migracion(&$tabla, $foreign_key, $fuente)
{
    if (($foreign_key['nombre'] ?? null) === null && !empty($foreign_key['columnas'])) {
        $foreign_key['nombre'] = _nombre_restriccion_laravel($tabla['nombre'], $foreign_key['columnas'], 'foreign');
    }
    $foreign_key['source'] = $fuente;
    $tabla['foreign_keys'][] = $foreign_key;

    if (count($foreign_key['columnas']) === 1
        && count($foreign_key['columnas_referenciadas']) === 1
        && $foreign_key['tabla_referenciada'] !== null) {
        $columna = $foreign_key['columnas'][0];
        $legacy = [
            'columna' => $columna,
            'referencia_tabla' => $foreign_key['tabla_referenciada'],
            'referencia_columna' => $foreign_key['columnas_referenciadas'][0],
            'on_delete' => $foreign_key['on_delete'],
        ];
        $tabla['fks'][] = $legacy;
        if (isset($tabla['columnas'][$columna])) {
            $tabla['columnas'][$columna]['fk'] = [
                'referencia_tabla' => $legacy['referencia_tabla'],
                'referencia_columna' => $legacy['referencia_columna'],
                'on_delete' => $legacy['on_delete'],
                'on_update' => $foreign_key['on_update'],
            ];
        }
    }
}

function _columna_migracion($res, $fuente, $anterior = null)
{
    $es_cambio = ($res['tipo_accion'] ?? '') === 'alter_column';
    $columna = $anterior ?? [];
    $columna['nombre'] = $res['nombre'];
    $columna['tipo'] = $res['tipo'];
    $columna['tipo_laravel'] = $res['tipo'];
    $columna['tipo_normalizado'] = $res['tipo_normalizado'];
    $columna['opciones'] = $res['opciones'];
    if ($es_cambio && isset($anterior['opciones'])
        && ($anterior['tipo_normalizado'] ?? null) === $res['tipo_normalizado']) {
        $columna['opciones'] = array_replace(
            $anterior['opciones'],
            array_intersect_key($res['opciones'], array_flip($res['opciones_declaradas'] ?? []))
        );
    }
    $columna['argumentos_tipo'] = $res['argumentos_tipo'] ?? [];
    $columna['modificadores'] = $res['modificadores'] ?? [];
    $columna['unsigned'] = $res['unsigned'];
    $columna['width'] = $res['width'];
    $columna['es_pk'] = !empty($res['es_pk']) || (!empty($anterior['es_pk']) && $es_cambio);
    $columna['autoincrement'] = $res['autoincrement'] !== null
        ? $res['autoincrement']
        : ($anterior['autoincrement'] ?? false);
    $columna['nullable'] = $res['nullable_declarado']
        ? $res['nullable']
        : ($anterior['nullable'] ?? false);
    $columna['nullable_conocido'] = $columna['nullable'] !== null;

    if (!empty($res['default_metadata']['declarado'])) {
        $columna['default_metadata'] = $res['default_metadata'];
        $columna['default'] = $res['default'];
    } elseif (!$es_cambio || !isset($anterior['default_metadata'])) {
        $columna['default_metadata'] = ['declarado' => false, 'tipo' => 'ausente', 'valor' => null, 'expresion' => null];
        $columna['default'] = null;
    }
    $columna['unique'] = !empty($res['unique']) || (!empty($anterior['unique']) && $es_cambio);
    $columna['es_imagen'] = $res['es_imagen'];
    $columna['fk'] = $res['fk'] ?? ($anterior['fk'] ?? null);
    $columna['modificadores_no_reconocidos'] = $res['modificadores_no_reconocidos'];
    $columna['source'] = $fuente;
    $columna['source_history'] = $anterior['source_history'] ?? [];
    $columna['source_history'][] = $fuente;
    return $columna;
}

function _candidatos_tabla_constrained($nombre_columna)
{
    if (!preg_match('/^(.*)_id$/', $nombre_columna, $m)) {
        return [];
    }
    $base = $m[1];
    $irregulares = [
        'person' => 'people',
        'child' => 'children',
        'man' => 'men',
        'woman' => 'women',
        'mouse' => 'mice',
        'goose' => 'geese',
        'foot' => 'feet',
        'tooth' => 'teeth',
        'ox' => 'oxen',
    ];
    if (isset($irregulares[$base])) {
        return [$irregulares[$base]];
    }

    if (in_array($base, ['news', 'series', 'species', 'fish', 'sheep', 'deer', 'data', 'equipment', 'information'], true)) {
        return [];
    }

    if (preg_match('/(sis|[zfo])$/i', $base)) {
        return [];
    }

    if (preg_match('/[^aeiou]y$/i', $base)) {
        return [substr($base, 0, -1) . 'ies'];
    }
    if (preg_match('/(s|x|z|ch|sh)$/i', $base)) {
        return [$base . 'es'];
    }
    return [$base . 's'];
}

// Analiza migraciones sin ejecutar código y conserva el contrato legado para Backend/Frontend.
function analizar_migraciones($ruta_backend, $config = [])
{
    $advertencias = [];
    $tablas = [];
    $todas_operaciones = [];
    $migraciones = [];
    $ruta_migrations = rtrim($ruta_backend, '\\/') . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

    if (!is_dir($ruta_migrations)) {
        $mensaje = "No se encontró el directorio de migraciones: $ruta_migrations";
        $diagnostico = _diagnostico_migracion($mensaje);
        $diagnostico['severity'] = 'error';
        $diagnostico['code'] = 'migrations_directory_missing';
        $diagnostico['impact'] = 'failed';
        return [
            'contract_version' => 1,
            'analysis' => ['status' => 'failed', 'generation_safe' => false, 'migrations' => [], 'diagnostics' => [$diagnostico]],
            'tablas' => [],
            'tables' => [],
            'advertencias' => [$mensaje],
            'diagnostics' => [$diagnostico],
            'relationship_analysis' => [
                'status' => 'failed',
                'diagnostics' => [$diagnostico],
                'unverified_columns' => [],
            ],
            'relations' => [],
        ];
    }

    $archivos = glob($ruta_migrations . DIRECTORY_SEPARATOR . '*.php') ?: [];
    sort($archivos, SORT_STRING);
    $patrones_imagen = $config['campos_imagen'] ?? [
        'foto', 'imagen', 'image', 'photo', 'avatar', 'logo', 'portada', 'banner', 'miniatura',
        'imagen_*', '*_imagen', 'foto_*', '*_foto', 'img_*',
    ];

    foreach ($archivos as $archivo) {
        $inicio_advertencias = count($advertencias);
        $operaciones = _analizar_archivo_migracion($archivo, $patrones_imagen, $advertencias);
        $migraciones[] = [
            'archivo' => basename($archivo),
            'operaciones' => count($operaciones),
            'status' => count($advertencias) === $inicio_advertencias ? 'complete' : 'partial',
        ];
        foreach ($operaciones as $operacion) {
            $todas_operaciones[] = $operacion;
        }
    }

    foreach ($todas_operaciones as $op) {
        $nombre_tabla = $op['tabla'];
        $tipo_schema = $op['tipo_schema'];
        $archivo = $op['archivo'];
        $fuente = ['file' => $archivo, 'line' => $op['linea'], 'line_precision' => 'approximate'];

        if (!isset($tablas[$nombre_tabla])) {
            $tablas[$nombre_tabla] = _nueva_tabla_migracion($nombre_tabla, $tipo_schema === 'create');
        } elseif ($tipo_schema === 'create' && $tablas[$nombre_tabla]['creada_en_migraciones']) {
            $mensaje = "[$archivo:~L{$op['linea']}] La tabla '$nombre_tabla' aparece creada más de una vez.";
            $advertencias[] = $mensaje;
            $tablas[$nombre_tabla]['analysis']['status'] = 'partial';
            $tablas[$nombre_tabla]['analysis']['diagnostics'][] = _diagnostico_migracion($mensaje, $nombre_tabla);
        } elseif ($tipo_schema === 'create') {
            $tablas[$nombre_tabla]['creada_en_migraciones'] = true;
        }

        if ($tipo_schema === 'create' && $tablas[$nombre_tabla]['fuentes']['creada_en'] === null) {
            $tablas[$nombre_tabla]['fuentes']['creada_en'] = $fuente;
        } elseif ($tipo_schema === 'table') {
            $tablas[$nombre_tabla]['fuentes']['modificada_en'][] = $fuente;
            if (!$tablas[$nombre_tabla]['creada_en_migraciones']) {
                $mensaje = "[$archivo:~L{$op['linea']}] Schema::table modifica '$nombre_tabla' sin una creación previa analizada.";
                $advertencias[] = $mensaje;
                $tablas[$nombre_tabla]['analysis']['status'] = 'partial';
                $tablas[$nombre_tabla]['analysis']['diagnostics'][] = _diagnostico_migracion($mensaje, $nombre_tabla);
            }
        }
        if (!in_array($archivo, $tablas[$nombre_tabla]['archivos_origen'], true)) {
            $tablas[$nombre_tabla]['archivos_origen'][] = $archivo;
        }
        if (array_key_exists('callback_analizable', $op) && !$op['callback_analizable']) {
            $mensaje = "[$archivo:~L{$op['linea']}] Callback de Schema no analizable; el esquema de '$nombre_tabla' queda parcial.";
            $advertencias[] = $mensaje;
            $tablas[$nombre_tabla]['analysis']['status'] = 'partial';
            $tablas[$nombre_tabla]['analysis']['diagnostics'][] = _diagnostico_migracion($mensaje, $nombre_tabla);
            $tablas[$nombre_tabla]['operaciones_no_reconocidas'][] = [
                'expression' => $op['cuerpo'],
                'source' => $fuente,
            ];
            continue;
        }

        $sentencias = [];
        $actual = '';
        $en_comilla = false;
        $tipo_comilla = '';
        $cuerpo = $op['cuerpo'];
        for ($idx = 0, $len = strlen($cuerpo); $idx < $len; $idx++) {
            $ch = $cuerpo[$idx];
            if ($en_comilla) {
                $actual .= $ch;
                if ($ch === $tipo_comilla && ($idx === 0 || $cuerpo[$idx - 1] !== '\\')) {
                    $en_comilla = false;
                }
                continue;
            }
            if ($ch === '\'' || $ch === '"') {
                $en_comilla = true;
                $tipo_comilla = $ch;
                $actual .= $ch;
            } elseif ($ch === ';') {
                if (trim($actual) !== '') {
                    $sentencias[] = trim($actual);
                }
                $actual = '';
            } else {
                $actual .= $ch;
            }
        }
        if (trim($actual) !== '') {
            $sentencias[] = trim($actual);
        }

        foreach ($sentencias as $sentencia) {
            $inicio_advertencias = count($advertencias);
            if (preg_match('/\b(for|foreach|while|if|switch)\s*\(/i', $sentencia)) {
                $advertencias[] = "[$archivo:~L{$op['linea']}] Lógica condicional no evaluada en la tabla '$nombre_tabla'; el resultado es parcial.";
                $tablas[$nombre_tabla]['operaciones_no_reconocidas'][] = [
                    'expression' => $sentencia,
                    'source' => $fuente,
                ];
                $tablas[$nombre_tabla]['analysis']['status'] = 'partial';
                continue;
            }

            $res = _analizar_sentencia_columna($sentencia, $patrones_imagen, $archivo, $op['linea'], $advertencias);
            if ($res === null) {
                continue;
            }
            $tipo_accion = $res['tipo_accion'] ?? 'unknown_operation';
            if ($tipo_accion === 'columna' || $tipo_accion === 'alter_column') {
                if ($res['nombre'] === null) {
                    $tipo_accion = 'unknown_column';
                } else {
                    $nombre_columna = $res['nombre'];
                    $anterior = $tablas[$nombre_tabla]['columnas'][$nombre_columna] ?? null;
                    if ($tipo_accion === 'alter_column' && $anterior === null) {
                        $advertencias[] = "[$archivo:~L{$op['linea']}] change() sobre '$nombre_tabla.$nombre_columna' sin definición previa; no se inventan los atributos anteriores.";
                        $tablas[$nombre_tabla]['analysis']['status'] = 'partial';
                    }
                    $tablas[$nombre_tabla]['columnas'][$nombre_columna] = _columna_migracion($res, $fuente, $anterior);
                    if ($res['es_pk']) {
                        if (!in_array($nombre_columna, $tablas[$nombre_tabla]['primary_key']['columnas'], true)) {
                            $tablas[$nombre_tabla]['primary_key']['columnas'][] = $nombre_columna;
                        }
                        _agregar_indice_migracion($tablas[$nombre_tabla], [
                            'tipo' => 'primary',
                            'columnas' => [$nombre_columna],
                            'nombre' => null,
                            'unico' => true,
                        ], $fuente);
                        if ($res['autoincrement'] === true) {
                            $tablas[$nombre_tabla]['primary_key']['generacion'] = 'auto_increment';
                        } elseif ($tablas[$nombre_tabla]['primary_key']['generacion'] === 'ninguna') {
                            $tablas[$nombre_tabla]['primary_key']['generacion'] = 'asignada';
                        }
                    }
                    foreach ($res['indices'] as $indice) {
                        _agregar_indice_migracion($tablas[$nombre_tabla], $indice, $fuente);
                    }
                    if ($res['fk'] !== null) {
                        _agregar_fk_migracion($tablas[$nombre_tabla], $res['fk'], $fuente);
                    }
                }
            }

            if ($tipo_accion === 'timestamps') {
                $tablas[$nombre_tabla]['timestamps'] = true;
                $tablas[$nombre_tabla]['timestamps_metadata'] = [
                    'presentes' => true,
                    'created_at' => 'created_at',
                    'updated_at' => 'updated_at',
                    'zona_horaria' => $res['con_zona_horaria'],
                ];
                foreach (['created_at', 'updated_at'] as $nombre_columna) {
                    if (!isset($tablas[$nombre_tabla]['columnas'][$nombre_columna])) {
                        $def = [
                            'tipo_accion' => 'columna', 'nombre' => $nombre_columna, 'tipo' => 'timestamp',
                            'tipo_normalizado' => 'timestamp', 'opciones' => [], 'unsigned' => false, 'width' => null,
                            'es_pk' => false, 'autoincrement' => false, 'nullable' => true, 'nullable_declarado' => true,
                            'default' => null, 'default_metadata' => ['declarado' => false, 'tipo' => 'ausente', 'valor' => null, 'expresion' => null],
                            'unique' => false, 'es_imagen' => false, 'fk' => null, 'indices' => [],
                            'modificadores_no_reconocidos' => [],
                        ];
                        $tablas[$nombre_tabla]['columnas'][$nombre_columna] = _columna_migracion($def, $fuente);
                    }
                }
            } elseif ($tipo_accion === 'soft_deletes') {
                $tablas[$nombre_tabla]['soft_deletes'] = true;
                $tablas[$nombre_tabla]['soft_deletes_metadata'] = [
                    'presentes' => true,
                    'columna' => 'deleted_at',
                    'zona_horaria' => $res['con_zona_horaria'],
                ];
                if (!isset($tablas[$nombre_tabla]['columnas']['deleted_at'])) {
                    $def = [
                        'tipo_accion' => 'columna', 'nombre' => 'deleted_at', 'tipo' => 'timestamp',
                        'tipo_normalizado' => 'timestamp', 'opciones' => [], 'unsigned' => false, 'width' => null,
                        'es_pk' => false, 'autoincrement' => false, 'nullable' => true, 'nullable_declarado' => true,
                        'default' => null, 'default_metadata' => ['declarado' => false, 'tipo' => 'ausente', 'valor' => null, 'expresion' => null],
                        'unique' => false, 'es_imagen' => false, 'fk' => null, 'indices' => [],
                        'modificadores_no_reconocidos' => [],
                    ];
                    $tablas[$nombre_tabla]['columnas']['deleted_at'] = _columna_migracion($def, $fuente);
                }
            } elseif ($tipo_accion === 'index') {
                _agregar_indice_migracion($tablas[$nombre_tabla], $res['indice'], $fuente);
            } elseif ($tipo_accion === 'foreign_key') {
                _agregar_fk_migracion($tablas[$nombre_tabla], $res['foreign_key'], $fuente);
            } elseif ($tipo_accion === 'drop_column') {
                foreach ($res['columnas'] as $eliminada) {
                    unset($tablas[$nombre_tabla]['columnas'][$eliminada]);
                    foreach ($tablas[$nombre_tabla]['foreign_keys'] as $i => $fk) {
                        if (in_array($eliminada, $fk['columnas'], true)) {
                            unset($tablas[$nombre_tabla]['foreign_keys'][$i]);
                        }
                    }
                    foreach ($tablas[$nombre_tabla]['indices'] as $i => $indice) {
                        if (in_array($eliminada, $indice['columnas'], true)) {
                            if ($indice['tipo'] === 'primary') {
                                $tablas[$nombre_tabla]['primary_key'] = [
                                    'columnas' => [],
                                    'generacion' => 'ninguna',
                                ];
                            }
                            unset($tablas[$nombre_tabla]['indices'][$i]);
                        }
                    }
                    $tablas[$nombre_tabla]['foreign_keys'] = array_values($tablas[$nombre_tabla]['foreign_keys']);
                    $tablas[$nombre_tabla]['indices'] = array_values($tablas[$nombre_tabla]['indices']);
                    if ($tablas[$nombre_tabla]['primary_key']['columnas'] !== []) {
                        $tablas[$nombre_tabla]['primary_key']['columnas'] = array_values(array_diff(
                            $tablas[$nombre_tabla]['primary_key']['columnas'],
                            [$eliminada]
                        ));
                    }
                }
            } elseif ($tipo_accion === 'rename_column') {
                $desde = $res['de'];
                $hacia = $res['a'];
                if (isset($tablas[$nombre_tabla]['columnas'][$desde])) {
                    $columna = $tablas[$nombre_tabla]['columnas'][$desde];
                    $columna['nombre'] = $hacia;
                    $columna['es_imagen'] = _es_campo_imagen($hacia, $patrones_imagen);
                    $columna['source'] = $fuente;
                    $columna['source_history'][] = $fuente;
                    unset($tablas[$nombre_tabla]['columnas'][$desde]);
                    $tablas[$nombre_tabla]['columnas'][$hacia] = $columna;
                    foreach ($tablas[$nombre_tabla]['foreign_keys'] as &$fk) {
                        foreach ($fk['columnas'] as &$nombre_fk) {
                            if ($nombre_fk === $desde) $nombre_fk = $hacia;
                        }
                        unset($nombre_fk);
                    }
                    unset($fk);
                    foreach ($tablas[$nombre_tabla]['indices'] as &$indice) {
                        foreach ($indice['columnas'] as &$nombre_indice) {
                            if ($nombre_indice === $desde) $nombre_indice = $hacia;
                        }
                        unset($nombre_indice);
                    }
                    unset($indice);
                    foreach ($tablas[$nombre_tabla]['primary_key']['columnas'] as &$nombre_pk) {
                        if ($nombre_pk === $desde) $nombre_pk = $hacia;
                    }
                    unset($nombre_pk);
                } else {
                    $advertencias[] = "[$archivo:~L{$op['linea']}] No se puede renombrar '$nombre_tabla.$desde': columna no encontrada.";
                }
            } elseif ($tipo_accion === 'drop_index') {
                $eliminado = false;
                foreach ($tablas[$nombre_tabla]['indices'] as $i => $indice) {
                    $coincide_nombre = $res['nombre'] !== null
                        && strtolower((string)$indice['nombre']) === strtolower((string)$res['nombre']);
                    $coincide_columnas = $res['columnas'] !== null && $indice['columnas'] === $res['columnas'];
                    $es_eliminacion_pk = $res['tipo_indice'] === 'primary'
                        && $res['nombre'] === null && $res['columnas'] === null
                        && $indice['tipo'] === 'primary';
                    if ($indice['tipo'] === $res['tipo_indice'] && ($es_eliminacion_pk || $coincide_nombre || $coincide_columnas)) {
                        unset($tablas[$nombre_tabla]['indices'][$i]);
                        $eliminado = true;
                    }
                }
                if (!$eliminado) {
                    $advertencias[] = "[$archivo:~L{$op['linea']}] No se encontró la restricción {$res['tipo_indice']} solicitada en '$nombre_tabla'.";
                }
                $tablas[$nombre_tabla]['indices'] = array_values($tablas[$nombre_tabla]['indices']);
                if ($res['tipo_indice'] === 'primary') {
                    $pk_restante = null;
                    foreach ($tablas[$nombre_tabla]['indices'] as $indice) {
                        if ($indice['tipo'] === 'primary') {
                            $pk_restante = $indice['columnas'];
                            break;
                        }
                    }
                    $tablas[$nombre_tabla]['primary_key'] = [
                        'columnas' => $pk_restante ?? [],
                        'generacion' => $pk_restante === null ? 'ninguna' : 'asignada',
                    ];
                }
            } elseif ($tipo_accion === 'drop_foreign_key') {
                $eliminado = false;
                foreach ($tablas[$nombre_tabla]['foreign_keys'] as $i => $fk) {
                    $coincide_nombre = $res['nombre'] !== null
                        && strtolower((string)($fk['nombre'] ?? '')) === strtolower((string)$res['nombre']);
                    $coincide_columnas = $res['columnas'] !== null && $fk['columnas'] === $res['columnas'];
                    if ($coincide_nombre || $coincide_columnas) {
                        unset($tablas[$nombre_tabla]['foreign_keys'][$i]);
                        $eliminado = true;
                    }
                }
                if (!$eliminado) {
                    $advertencias[] = "[$archivo:~L{$op['linea']}] No se encontró la FK solicitada en '$nombre_tabla'.";
                }
                $tablas[$nombre_tabla]['foreign_keys'] = array_values($tablas[$nombre_tabla]['foreign_keys']);
            } elseif ($tipo_accion === 'unknown_column') {
                $tablas[$nombre_tabla]['columnas_desconocidas'][] = [
                    'nombre' => $res['nombre'],
                    'tipo_laravel' => $res['tipo_laravel'],
                    'expresion' => $res['expresion'],
                    'source' => $fuente,
                ];
            } elseif ($tipo_accion === 'unknown_operation') {
                $tablas[$nombre_tabla]['operaciones_no_reconocidas'][] = [
                    'expression' => $res['expresion'],
                    'source' => $fuente,
                ];
            }

            if (count($advertencias) > $inicio_advertencias) {
                $tablas[$nombre_tabla]['analysis']['status'] = 'partial';
                foreach (array_slice($advertencias, $inicio_advertencias) as $mensaje) {
                    $tablas[$nombre_tabla]['analysis']['diagnostics'][] = _diagnostico_migracion($mensaje, $nombre_tabla);
                }
            }
        }
    }

    foreach ($tablas as $nombre_tabla => &$tabla) {
        foreach ($tabla['foreign_keys'] as &$fk) {
            if ($fk['tabla_referenciada'] === null && $fk['resolucion'] === 'convencion_pendiente'
                && count($fk['columnas']) === 1) {
                $coincidencias = array_values(array_intersect(
                    _candidatos_tabla_constrained($fk['columnas'][0]),
                    array_keys($tablas)
                ));
                if (count($coincidencias) === 1) {
                    $fk['tabla_referenciada'] = $coincidencias[0];
                    $fk['resolucion'] = 'convencion_laravel_verificada_en_esquema';
                    $fk['columnas_referenciadas'] = ['id'];
                } else {
                    $fuente_fk = $fk['source'] ?? [];
                    $archivo_fk = $fuente_fk['file'] ?? 'migraciones';
                    $linea_fk = isset($fuente_fk['line']) ? ":~L" . $fuente_fk['line'] : '';
                    $mensaje = "[$archivo_fk$linea_fk] No se pudo resolver de forma única el destino de constrained() para '$nombre_tabla." . $fk['columnas'][0] . "'.";
                    $advertencias[] = $mensaje;
                    $tabla['analysis']['status'] = 'partial';
                    $tabla['analysis']['diagnostics'][] = _diagnostico_migracion($mensaje, $nombre_tabla);
                }
            }
        }
        unset($fk);

        $tabla['fks'] = [];
        foreach ($tabla['columnas'] as &$columna) {
            $columna['fk'] = null;
            $columna['unique'] = false;
            $columna['es_pk'] = false;
        }
        unset($columna);
        foreach ($tabla['foreign_keys'] as $fk) {
            if (count($fk['columnas']) === 1 && count($fk['columnas_referenciadas']) === 1
                && $fk['tabla_referenciada'] !== null) {
                $tabla['fks'][] = [
                    'columna' => $fk['columnas'][0],
                    'referencia_tabla' => $fk['tabla_referenciada'],
                    'referencia_columna' => $fk['columnas_referenciadas'][0],
                    'on_delete' => $fk['on_delete'],
                ];
                if (isset($tabla['columnas'][$fk['columnas'][0]])) {
                    $tabla['columnas'][$fk['columnas'][0]]['fk'] = [
                        'referencia_tabla' => $fk['tabla_referenciada'],
                        'referencia_columna' => $fk['columnas_referenciadas'][0],
                        'on_delete' => $fk['on_delete'],
                        'on_update' => $fk['on_update'],
                    ];
                }
            }

        }

        foreach ($tabla['indices'] as $indice) {
            if ($indice['tipo'] === 'unique' && count($indice['columnas']) === 1
                && isset($tabla['columnas'][$indice['columnas'][0]])) {
                $tabla['columnas'][$indice['columnas'][0]]['unique'] = true;
            }
            if ($indice['tipo'] === 'primary') {
                foreach ($indice['columnas'] as $columna) {
                    if (isset($tabla['columnas'][$columna])) {
                        $tabla['columnas'][$columna]['es_pk'] = true;
                    }
                }
            }
        }

        $pk_columnas = $tabla['primary_key']['columnas'];
        $auto_incremental = count($pk_columnas) === 1
            && !empty($tabla['columnas'][$pk_columnas[0]]['autoincrement']);
        if (!empty($pk_columnas)) {
            $tabla['primary_key']['generacion'] = $auto_incremental ? 'auto_increment' : 'asignada';
        } else {
            $tabla['primary_key']['generacion'] = 'ninguna';
        }
        $fks_pivote = array_values(array_filter(
            $tabla['foreign_keys'],
            static fn($fk) => $fk['tabla_referenciada'] !== null
                && count($fk['columnas']) === 1
                && count($fk['columnas_referenciadas']) === 1
        ));
        $columnas_fk = array_values(array_unique(array_map(static fn($fk) => $fk['columnas'][0], $fks_pivote)));
        $referencias_fk = array_values(array_unique(array_map(static fn($fk) => $fk['tabla_referenciada'], $fks_pivote)));
        $pk_es_par_fk = !empty($pk_columnas)
            && count($pk_columnas) === 2
            && count($columnas_fk) === 2
            && count(array_diff($pk_columnas, $columnas_fk)) === 0;
        $tiene_pk_propia = !empty($pk_columnas) && !$pk_es_par_fk;
        $columnas_negocio = array_values(array_diff(
            array_keys($tabla['columnas']),
            array_merge($columnas_fk, ['created_at', 'updated_at'])
        ));
        $columnas_fk_ordenadas = $columnas_fk;
        sort($columnas_fk_ordenadas, SORT_STRING);
        $nombres_modelos_fk = array_map(static function ($columna) {
            return preg_replace('/_id$/', '', $columna);
        }, $columnas_fk_ordenadas);
        $nombre_pivote_convencional = implode('_', $nombres_modelos_fk);
        $nombre_compatible = strtolower($tabla['nombre']) === strtolower($nombre_pivote_convencional);
        $tiene_indice_compuesto_fk = false;
        foreach ($tabla['indices'] as $indice) {
            $columnas_indice = $indice['columnas'];
            sort($columnas_indice, SORT_STRING);
            if (in_array($indice['tipo'], ['primary', 'unique'], true)
                && $columnas_indice === $columnas_fk_ordenadas) {
                $tiene_indice_compuesto_fk = true;
                break;
            }
        }
        $pivote_confirmado = count($fks_pivote) === 2
            && count($tabla['foreign_keys']) === 2
            && count($columnas_fk) === 2
            && count($referencias_fk) === 2
            && !$tiene_pk_propia
            && empty($columnas_negocio)
            && ($nombre_compatible || $tiene_indice_compuesto_fk);
        $tabla['es_pivote'] = $pivote_confirmado;
        if ($tiene_pk_propia && count($fks_pivote) === 2) {
            $tabla['pivot_assessment'] = [
                'estado' => 'entidad_asociativa',
                'simple_pivot' => false,
                'evidencia' => ['dos_foreign_keys', 'primary_key_propia'],
            ];
        } elseif ($pivote_confirmado) {
            $tabla['pivot_assessment'] = [
                'estado' => 'pivote_simple_confirmado',
                'simple_pivot' => true,
                'evidencia' => [
                    'dos_foreign_keys_resueltas',
                    empty($columnas_negocio) ? 'sin_columnas_negocio_adicionales' : 'columnas_conocidas',
                    $nombre_compatible ? 'nombre_convencional' : 'indice_compuesto_foreign_keys',
                ],
            ];
        } elseif (count($tabla['foreign_keys']) === 2) {
            $tabla['pivot_assessment'] = [
                'estado' => 'candidato_no_confirmado',
                'simple_pivot' => null,
                'evidencia' => ['dos_foreign_keys'],
            ];
        } else {
            $tabla['pivot_assessment'] = [
                'estado' => 'sin_evidencia_suficiente',
                'simple_pivot' => null,
                'evidencia' => [],
            ];
        }
        $tabla['classification'] = _clasificar_tabla_analizada($tabla);
    }
    unset($tabla);

    $relationship_analysis = _analizar_relaciones_estructurales($tablas);
    $relations = $relationship_analysis['relations'];
    unset($relationship_analysis['relations']);
    $diagnosticos = [];
    foreach ($advertencias as $mensaje) {
        $diagnosticos[] = _diagnostico_migracion($mensaje);
    }
    foreach ($diagnosticos as $diagnostico) {
        $archivo_diagnostico = $diagnostico['source']['file'] ?? null;
        foreach ($migraciones as &$migracion) {
            if ($archivo_diagnostico === null || $migracion['archivo'] === $archivo_diagnostico) {
                $migracion['status'] = 'partial';
            }
        }
        unset($migracion);
    }
    $estado = empty($advertencias) ? 'complete' : 'partial';
    foreach ($tablas as $tabla) {
        if ($tabla['analysis']['status'] === 'partial') {
            $estado = 'partial';
            break;
        }
    }

    $generation_safe = true;
    foreach ($diagnosticos as $diagnostico) {
        if (($diagnostico['severity'] ?? null) === 'error'
            || in_array($diagnostico['impact'] ?? null, ['failed', 'critical', 'structural'], true)) {
            $generation_safe = false;
            break;
        }
    }

    return [
        'contract_version' => 1,
        'analysis' => [
            'status' => $estado,
            'generation_safe' => $generation_safe,
            'migrations' => $migraciones,
            'diagnostics' => $diagnosticos,
        ],
        'tablas' => $tablas,
        'tables' => $tablas,
        'relationship_analysis' => $relationship_analysis,
        'relations' => $relations,
        'advertencias' => $advertencias,
        'diagnostics' => $diagnosticos,
    ];
}
