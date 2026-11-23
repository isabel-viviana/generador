<?php
// Generador Frontend: Controladores Web Blade, Vistas CRUD, Consumo HTTP API y Rutas.

if (!defined('CRUD_DIR')) {
    define('CRUD_DIR', dirname(__DIR__));
}

require_once CRUD_DIR . '/src/util.php';
require_once CRUD_DIR . '/src/migraciones.php';

// Determina si una tabla es elegible para Frontend (misma regla de seguridad que Backend).
function es_tabla_elegible_front($nombre_tabla, $tabla_info, $config)
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

    $clase = nombre_clase($nombre_tabla, $config);
    if (!preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $clase)) {
        return false;
    }

    $palabras_reservadas = [
        'class', 'interface', 'trait', 'enum', 'function', 'fn', 'namespace',
        'self', 'static', 'parent', 'readonly', 'void', 'never', 'null', 'true', 'false'
    ];
    if (in_array(strtolower($clase), $palabras_reservadas, true)) {
        return false;
    }

    return true;
}

// ------------------------------------------------------------
// Generador de Código de Controlador Web
// ------------------------------------------------------------

function generar_codigo_controlador_web($nombre_tabla, $tabla_info, $todas_tablas, $config)
{
    $tpl_path = CRUD_DIR . '/templates/front/controller.tpl';
    if (!file_exists($tpl_path)) {
        throw new RuntimeException("Plantilla controller.tpl de Frontend no encontrada.");
    }
    $tpl = file_get_contents($tpl_path);

    $clase_nombre = nombre_clase($nombre_tabla, $config);
    $recurso = strtolower($nombre_tabla);
    $singular_nombre = studly(singular($nombre_tabla));

    // Identificar campos de imagen
    $campos_imagen = [];
    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if (!empty($c_def['es_imagen'])) {
            $campos_imagen[] = $c_nom;
        }
    }

    $except_images_str = !empty($campos_imagen)
        ? "'" . implode("', '", $campos_imagen) . "'"
        : '';

    // Manejo de archivos en store y update
    $attach_store_lines = [];
    $attach_update_lines = [];

    foreach ($campos_imagen as $c_img) {
        $attach_store_lines[] = "            if (\$request->hasFile('$c_img')) {\n                \$file = \$request->file('$c_img');\n                \$http = \$http->attach('$c_img', file_get_contents(\$file->getRealPath()), \$file->getClientOriginalName());\n            }";
        $attach_update_lines[] = "            if (\$request->hasFile('$c_img')) {\n                \$tieneArchivo = true;\n                \$file = \$request->file('$c_img');\n                \$http = \$http->attach('$c_img', file_get_contents(\$file->getRealPath()), \$file->getClientOriginalName());\n            }";
    }

    $attach_store_str = !empty($attach_store_lines) ? implode("\n", $attach_store_lines) . "\n" : "";
    $attach_update_str = !empty($attach_update_lines) ? implode("\n", $attach_update_lines) . "\n" : "";

    // Carga de opciones para claves foráneas (FK)
    $load_rel_create = [];
    $load_rel_edit = [];
    $compact_vars_create = [];
    $compact_vars_edit = ["'item'"];

    if (!empty($tabla_info['fks'])) {
        foreach ($tabla_info['fks'] as $fk) {
            $ref_tabla = strtolower($fk['referencia_tabla']);
            $var_name = lcfirst(studly($ref_tabla));
            $compact_vars_create[] = "'$var_name'";
            $compact_vars_edit[] = "'$var_name'";

            $load_code = <<<PHP
        \${$var_name} = [];
        try {
            \$res = Http::timeout(6)->get("{\$this->apiUrl}/{$ref_tabla}");
            if (\$res->successful()) {
                \${$var_name} = \$res->json('data') ?? [];
            }
        } catch (Throwable \$e) {}
PHP;
            $load_rel_create[] = $load_code;
            $load_rel_edit[] = $load_code;
        }
    }

    $load_create_str = !empty($load_rel_create) ? implode("\n", $load_rel_create) . "\n" : "";
    $load_edit_str = !empty($load_rel_edit) ? implode("\n", $load_rel_edit) . "\n" : "";

    $compact_create_str = !empty($compact_vars_create)
        ? "compact(" . implode(', ', $compact_vars_create) . ")"
        : "[]";

    $compact_edit_str = "compact(" . implode(', ', $compact_vars_edit) . ")";

    return str_replace(
        [
            '{{CLASS_NAME}}',
            '{{RECURSO}}',
            '{{SINGULAR_NAME}}',
            '{{EXCEPT_IMAGE_FIELDS}}',
            '{{ATTACH_FILES_STORE}}',
            '{{ATTACH_FILES_UPDATE}}',
            '{{LOAD_RELATIONS_CREATE}}',
            '{{LOAD_RELATIONS_EDIT}}',
            '{{COMPACT_RELATIONS_CREATE}}',
            '{{COMPACT_RELATIONS_EDIT}}',
        ],
        [
            $clase_nombre,
            $recurso,
            $singular_nombre,
            $except_images_str,
            $attach_store_str,
            $attach_update_str,
            $load_create_str,
            $load_edit_str,
            $compact_create_str,
            $compact_edit_str,
        ],
        $tpl
    );
}

// ------------------------------------------------------------
// Generador de Vistas Blade (Index, Create, Edit, Show)
// ------------------------------------------------------------

function _formatear_etiqueta($col_nom)
{
    if (preg_match('/^(.*)_id$/', $col_nom, $m)) {
        return ucwords(str_replace('_', ' ', $m[1]));
    }
    return ucwords(str_replace('_', ' ', $col_nom));
}

// Vista Index
function generar_codigo_vista_index($nombre_tabla, $tabla_info, $todas_tablas, $config)
{
    $tpl = file_get_contents(CRUD_DIR . '/templates/front/index.tpl');

    $singular_nombre = studly(singular($nombre_tabla));
    $plural_nombre = studly($nombre_tabla);
    $recurso = strtolower($nombre_tabla);

    $th_lines = [];
    $td_lines = [];

    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if ($c_def['es_pk'] || in_array($c_nom, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }

        $label = _formatear_etiqueta($c_nom);
        $th_lines[] = "                            <th>$label</th>";

        $tipo = $c_def['tipo'] ?? 'string';
        $es_img = !empty($c_def['es_imagen']);

        if ($es_img) {
            $td_lines[] = "                                <td>\n                                    @if(!empty(\$item['$c_nom']))\n                                        <img src=\"{{ \$item['$c_nom'] }}\" class=\"crud-thumb\" alt=\"$label\">\n                                    @else\n                                        <span class=\"badge badge-secondary\">Sin imagen</span>\n                                    @endif\n                                </td>";
        } elseif ($tipo === 'boolean') {
            $td_lines[] = "                                <td>\n                                    @if(!empty(\$item['$c_nom']))\n                                        <span class=\"badge badge-success\">Sí</span>\n                                    @else\n                                        <span class=\"badge badge-secondary\">No</span>\n                                    @endif\n                                </td>";
        } elseif (!empty($c_def['fk'])) {
            $rel_prop = lcfirst(studly(preg_replace('/_id$/', '', $c_nom)));
            $td_lines[] = "                                <td>{{ \$item['$rel_prop']['nombre'] ?? \$item['$rel_prop']['titulo'] ?? \$item['$rel_prop']['name'] ?? \$item['$c_nom'] ?? '-' }}</td>";
        } else {
            $td_lines[] = "                                <td>{{ \$item['$c_nom'] ?? '-' }}</td>";
        }
    }

    return str_replace(
        [
            '{{PLURAL_NAME}}',
            '{{SINGULAR_NAME}}',
            '{{PLURAL_LOWER}}',
            '{{RECURSO}}',
            '{{TABLE_HEADERS}}',
            '{{TABLE_CELLS}}',
        ],
        [
            $plural_nombre,
            $singular_nombre,
            $recurso,
            $recurso,
            implode("\n", $th_lines),
            implode("\n", $td_lines),
        ],
        $tpl
    );
}

// Generador de inputs de formulario para Create y Edit
function _generar_campo_formulario($c_nom, $c_def, $es_edit = false)
{
    $label = _formatear_etiqueta($c_nom);
    $tipo = $c_def['tipo'] ?? 'string';
    $nullable = !empty($c_def['nullable']);
    $req_attr = $nullable ? '' : ' required';
    $es_img = !empty($c_def['es_imagen']);

    $valor_val = $es_edit
        ? "old('$c_nom', \$item['$c_nom'] ?? '')"
        : "old('$c_nom')";

    // Campo de imagen
    if ($es_img) {
        $html_img_preview = '';
        if ($es_edit) {
            $html_img_preview = <<<HTML

                    @if(!empty(\$item['$c_nom']))
                        <div class="image-preview-container">
                            <img src="{{ \$item['$c_nom'] }}" class="image-preview-lg" alt="$label actual">
                            <span class="field-hint">Imagen actual. Seleccione un nuevo archivo solo si desea reemplazarla.</span>
                        </div>
                    @endif
HTML;
        }

        $req_img = ($es_edit || $nullable) ? '' : ' required';
        return <<<HTML
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="$c_nom" class="form-label">$label</label>
                    <input type="file" name="$c_nom" id="$c_nom" class="form-control" accept="image/*"$req_img>$html_img_preview
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Clave Foránea (FK)
    if (!empty($c_def['fk'])) {
        $ref_tabla = strtolower($c_def['fk']['referencia_tabla']);
        $var_name = lcfirst(studly($ref_tabla));

        return <<<HTML
                <div class="form-group">
                    <label for="$c_nom" class="form-label">$label</label>
                    <select name="$c_nom" id="$c_nom" class="form-control"$req_attr>
                        <option value="">-- Seleccionar $label --</option>
                        @foreach(\${$var_name} ?? [] as \$rel)
                            <option value="{{ \$rel['id'] }}" {{ $valor_val == \$rel['id'] ? 'selected' : '' }}>
                                {{ \$rel['nombre'] ?? \$rel['titulo'] ?? \$rel['name'] ?? \$rel['descripcion'] ?? ('ID #' . \$rel['id']) }}
                            </option>
                        @endforeach
                    </select>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Enum
    if ($tipo === 'enum' && !empty($c_def['opciones']['valores'])) {
        $options_html = [];
        $options_html[] = '                        <option value="">-- Seleccionar --</option>';
        foreach ($c_def['opciones']['valores'] as $val) {
            $val_safe = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $val_label = ucfirst($val_safe);
            $options_html[] = "                        <option value=\"$val_safe\" {{ $valor_val == '$val_safe' ? 'selected' : '' }}>$val_label</option>";
        }
        $options_str = implode("\n", $options_html);

        return <<<HTML
                <div class="form-group">
                    <label for="$c_nom" class="form-label">$label</label>
                    <select name="$c_nom" id="$c_nom" class="form-control"$req_attr>
$options_str
                    </select>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Booleano
    if ($tipo === 'boolean') {
        $checked_code = $es_edit
            ? "(old('$c_nom', \$item['$c_nom'] ?? false) ? 'checked' : '')"
            : "(old('$c_nom') ? 'checked' : '')";

        return <<<HTML
                <div class="form-group" style="justify-content: center; padding-top: 1rem;">
                    <input type="hidden" name="$c_nom" value="0">
                    <label class="checkbox-label">
                        <input type="checkbox" name="$c_nom" id="$c_nom" value="1" {{ $checked_code }}>
                        $label
                    </label>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Texto largo
    if (in_array($tipo, ['text', 'longText', 'mediumText'], true)) {
        return <<<HTML
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="$c_nom" class="form-label">$label</label>
                    <textarea name="$c_nom" id="$c_nom" class="form-control"$req_attr>{{ $valor_val }}</textarea>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Números enteros
    if (in_array($tipo, ['integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger'], true)) {
        return <<<HTML
                <div class="form-group">
                    <label for="$c_nom" class="form-label">$label</label>
                    <input type="number" name="$c_nom" id="$c_nom" class="form-control" value="{{ $valor_val }}"$req_attr>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Números decimales
    if (in_array($tipo, ['decimal', 'float', 'double'], true)) {
        return <<<HTML
                <div class="form-group">
                    <label for="$c_nom" class="form-label">$label</label>
                    <input type="number" step="0.01" name="$c_nom" id="$c_nom" class="form-control" value="{{ $valor_val }}"$req_attr>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Fechas
    if ($tipo === 'date') {
        return <<<HTML
                <div class="form-group">
                    <label for="$c_nom" class="form-label">$label</label>
                    <input type="date" name="$c_nom" id="$c_nom" class="form-control" value="{{ $valor_val }}"$req_attr>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    if (in_array($tipo, ['dateTime', 'timestamp', 'dateTimeTz', 'timestampTz'], true)) {
        return <<<HTML
                <div class="form-group">
                    <label for="$c_nom" class="form-label">$label</label>
                    <input type="datetime-local" name="$c_nom" id="$c_nom" class="form-control" value="{{ $valor_val }}"$req_attr>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
    }

    // Por defecto: input text
    return <<<HTML
                <div class="form-group">
                    <label for="$c_nom" class="form-label">$label</label>
                    <input type="text" name="$c_nom" id="$c_nom" class="form-control" value="{{ $valor_val }}"$req_attr>
                    @error('$c_nom')<span class="field-error">{{ \$message }}</span>@enderror
                </div>
HTML;
}

// Vista Create
function generar_codigo_vista_create($nombre_tabla, $tabla_info, $todas_tablas, $config)
{
    $tpl = file_get_contents(CRUD_DIR . '/templates/front/create.tpl');

    $singular_nombre = studly(singular($nombre_tabla));
    $recurso = strtolower($nombre_tabla);

    $hay_imagen = false;
    $fields_html = [];

    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if ($c_def['es_pk'] || in_array($c_nom, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        if (!empty($c_def['es_imagen'])) {
            $hay_imagen = true;
        }
        $fields_html[] = _generar_campo_formulario($c_nom, $c_def, false);
    }

    $enctype = $hay_imagen ? ' enctype="multipart/form-data"' : '';

    return str_replace(
        [
            '{{SINGULAR_NAME}}',
            '{{RECURSO}}',
            '{{FORM_ENCTYPE}}',
            '{{FORM_FIELDS_CREATE}}',
        ],
        [
            $singular_nombre,
            $recurso,
            $enctype,
            implode("\n", $fields_html),
        ],
        $tpl
    );
}

// Vista Edit
function generar_codigo_vista_edit($nombre_tabla, $tabla_info, $todas_tablas, $config)
{
    $tpl = file_get_contents(CRUD_DIR . '/templates/front/edit.tpl');

    $singular_nombre = studly(singular($nombre_tabla));
    $recurso = strtolower($nombre_tabla);

    $hay_imagen = false;
    $fields_html = [];

    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if ($c_def['es_pk'] || in_array($c_nom, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }
        if (!empty($c_def['es_imagen'])) {
            $hay_imagen = true;
        }
        $fields_html[] = _generar_campo_formulario($c_nom, $c_def, true);
    }

    $enctype = $hay_imagen ? ' enctype="multipart/form-data"' : '';

    return str_replace(
        [
            '{{SINGULAR_NAME}}',
            '{{RECURSO}}',
            '{{FORM_ENCTYPE}}',
            '{{FORM_FIELDS_EDIT}}',
        ],
        [
            $singular_nombre,
            $recurso,
            $enctype,
            implode("\n", $fields_html),
        ],
        $tpl
    );
}

// Vista Show
function generar_codigo_vista_show($nombre_tabla, $tabla_info, $todas_tablas, $config)
{
    $tpl = file_get_contents(CRUD_DIR . '/templates/front/show.tpl');

    $singular_nombre = studly(singular($nombre_tabla));
    $recurso = strtolower($nombre_tabla);

    $details_html = [];

    foreach ($tabla_info['columnas'] as $c_nom => $c_def) {
        if ($c_def['es_pk'] || in_array($c_nom, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            continue;
        }

        $label = _formatear_etiqueta($c_nom);
        $tipo = $c_def['tipo'] ?? 'string';
        $es_img = !empty($c_def['es_imagen']);

        if ($es_img) {
            $details_html[] = <<<HTML
            <div class="detail-item" style="grid-column: 1 / -1;">
                <span class="detail-label">$label</span>
                <div class="detail-value" style="margin-top: 0.5rem;">
                    @if(!empty(\$item['$c_nom']))
                        <img src="{{ \$item['$c_nom'] }}" class="image-preview-lg" alt="$label">
                    @else
                        <span class="badge badge-secondary">Sin imagen registrada</span>
                    @endif
                </div>
            </div>
HTML;
        } elseif ($tipo === 'boolean') {
            $details_html[] = <<<HTML
            <div class="detail-item">
                <span class="detail-label">$label</span>
                <span class="detail-value">
                    @if(!empty(\$item['$c_nom']))
                        <span class="badge badge-success">Sí / Activo</span>
                    @else
                        <span class="badge badge-secondary">No / Inactivo</span>
                    @endif
                </span>
            </div>
HTML;
        } elseif (!empty($c_def['fk'])) {
            $rel_prop = lcfirst(studly(preg_replace('/_id$/', '', $c_nom)));
            $details_html[] = <<<HTML
            <div class="detail-item">
                <span class="detail-label">$label</span>
                <span class="detail-value">{{ \$item['$rel_prop']['nombre'] ?? \$item['$rel_prop']['titulo'] ?? \$item['$rel_prop']['name'] ?? \$item['$c_nom'] ?? '-' }}</span>
            </div>
HTML;
        } else {
            $details_html[] = <<<HTML
            <div class="detail-item">
                <span class="detail-label">$label</span>
                <span class="detail-value">{{ \$item['$c_nom'] ?? '-' }}</span>
            </div>
HTML;
        }
    }

    return str_replace(
        [
            '{{SINGULAR_NAME}}',
            '{{RECURSO}}',
            '{{SHOW_FIELDS}}',
        ],
        [
            $singular_nombre,
            $recurso,
            implode("\n", $details_html),
        ],
        $tpl
    );
}

// ------------------------------------------------------------
// Layout Base, CSS y Rutas Web
// ------------------------------------------------------------

function asegurar_layout_frontend($ruta_frontend, array $recursos_elegibles, $config)
{
    $tpl_path = CRUD_DIR . '/templates/front/layout.tpl';
    $tpl = file_get_contents($tpl_path);

    $app_name = !empty($config['proyecto']) ? $config['proyecto'] : 'Mi Aplicación';

    $nav_items = [];
    foreach ($recursos_elegibles as $rec) {
        $plural_nombre = studly($rec);
        $nav_items[] = "                    <a href=\"{{ route('$rec.index') }}\" class=\"sidebar-link {{ request()->routeIs('$rec.*') ? 'active' : '' }}\">📁 $plural_nombre</a>";
    }
    $nav_str = implode("\n", $nav_items);

    $codigo = str_replace(
        ['{{APP_NAME}}', '{{NAV_LINKS}}'],
        [$app_name, $nav_str],
        $tpl
    );

    $ruta_layout = $ruta_frontend . '/resources/views/layouts/app.blade.php';
    return escribir($ruta_layout, $codigo);
}

function asegurar_css_frontend($ruta_frontend)
{
    $css_path = CRUD_DIR . '/templates/front/crud.css';
    $css_contenido = file_get_contents($css_path);

    $ruta_css_dst = $ruta_frontend . '/public/css/crud.css';
    return escribir($ruta_css_dst, $css_contenido);
}

function registrar_rutas_web_frontend($ruta_frontend, array $controladores_generados)
{
    $ruta_web = $ruta_frontend . '/routes/web.php';
    $lineas_rutas = [];

    // Redirección de la raíz '/' al primer recurso generado
    if (!empty($controladores_generados)) {
        $primer_recurso = strtolower($controladores_generados[0]['tabla']);
        $lineas_rutas[] = "Route::get('/', function () {";
        $lineas_rutas[] = "    return redirect()->route('{$primer_recurso}.index');";
        $lineas_rutas[] = "});";
    }

    foreach ($controladores_generados as $item) {
        $recurso = strtolower($item['tabla']);
        $clase_ctrl = $item['clase_ctrl'];
        $lineas_rutas[] = "Route::resource('$recurso', \\App\\Http\\Controllers\\{$clase_ctrl}::class);";
    }

    $crlf = "\n";
    $marcador_inicio = '// <crud-generator>';
    $marcador_fin = '// </crud-generator>';
    $bloque_nuevo = $marcador_inicio . $crlf . implode($crlf, $lineas_rutas) . $crlf . $marcador_fin;

    if (!file_exists($ruta_web)) {
        $contenido_inicial = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n" . $bloque_nuevo . "\n";
        escribir_archivo_sin_marca($ruta_web, $contenido_inicial);
        return 'creado';
    }

    $contenido_actual = file_get_contents($ruta_web);
    $tiene_inicio = strpos($contenido_actual, $marcador_inicio) !== false;
    $tiene_fin = strpos($contenido_actual, $marcador_fin) !== false;

    if ($tiene_inicio && $tiene_fin) {
        $patron = '/' . preg_quote($marcador_inicio, '/') . '.*?' . preg_quote($marcador_fin, '/') . '/s';
        $nuevo_contenido = preg_replace($patron, $bloque_nuevo, $contenido_actual, 1);

        if ($nuevo_contenido === $contenido_actual) {
            return 'sin_cambios';
        }

        escribir_archivo_sin_marca($ruta_web, $nuevo_contenido);
        return 'actualizado';
    } elseif ($tiene_inicio || $tiene_fin) {
        $limpio = str_replace([$marcador_inicio, $marcador_fin], '', $contenido_actual);
        $nuevo_contenido = rtrim($limpio) . "\n\n" . $bloque_nuevo . "\n";
        escribir_archivo_sin_marca($ruta_web, $nuevo_contenido);
        return 'actualizado';
    } else {
        $nuevo_contenido = rtrim($contenido_actual) . "\n\n" . $bloque_nuevo . "\n";
        escribir_archivo_sin_marca($ruta_web, $nuevo_contenido);
        return 'actualizado';
    }
}

function configurar_env_frontend($ruta_frontend, $config)
{
    $env_path = $ruta_frontend . '/.env';
    if (!file_exists($env_path)) {
        return false;
    }

    $contenido = file_get_contents($env_path);
    $crlf = (strpos($contenido, "\r\n") !== false) ? "\r\n" : "\n";
    $cambio = false;

    $nom_proy = nombre_proyecto($config);
    $url_api = "http://{$nom_proy}-backend.test:8001/api";
    $url_front = "http://{$nom_proy}-frontend.test:8002";

    // 1. BACKEND_API_URL
    if (preg_match('/^BACKEND_API_URL=(.*)$/m', $contenido, $m)) {
        $val_act = trim($m[1], " '\"");
        if ($val_act === '') {
            $contenido = preg_replace('/^BACKEND_API_URL=.*$/m', "BACKEND_API_URL={$url_api}", $contenido);
            $cambio = true;
        }
    } else {
        $contenido = rtrim($contenido) . $crlf . $crlf . "# Backend REST API" . $crlf . "BACKEND_API_URL={$url_api}" . $crlf;
        $cambio = true;
    }

    // 2. APP_URL de Frontend
    if (preg_match('/^APP_URL=(.*)$/m', $contenido, $m)) {
        $val_act = trim($m[1], " '\"");
        if ($val_act === '' || $val_act === 'http://localhost') {
            $contenido = preg_replace('/^APP_URL=.*$/m', "APP_URL={$url_front}", $contenido);
            $cambio = true;
        }
    } else {
        $contenido = rtrim($contenido) . $crlf . "APP_URL={$url_front}" . $crlf;
        $cambio = true;
    }

    if ($cambio) {
        escribir_archivo_sin_marca($env_path, $contenido);
        return true;
    }

    return false;
}

// ------------------------------------------------------------
// Flujo Principal de Generación Frontend
// ------------------------------------------------------------

function generar_frontend_crud($ruta_frontend, $ruta_backend, $config)
{
    $resumen = [
        'controladores' => [],
        'vistas' => [],
        'layout' => null,
        'css' => null,
        'rutas_web' => null,
        'env_actualizado' => false,
        'omitidas' => [],
        'advertencias' => [],
        'errores' => [],
    ];

    // 1. Analizar migraciones del Backend para conocer tablas y tipos
    $analisis = analizar_migraciones($ruta_backend, $config);
    $resumen['advertencias'] = $analisis['advertencias'] ?? [];
    $guardia = guardia_generacion_migraciones($analisis);
    if ($guardia['bloqueada']) {
        $resumen['errores'][] = $guardia['mensaje'];
        return $resumen;
    }

    $tablas = $analisis['tablas'];

    if (empty($tablas)) {
        $resumen['errores'][] = 'No se detectaron tablas en las migraciones de Backend/database/migrations.';
        return $resumen;
    }

    // Autorizar carpetas de escritura en Frontend
    carpetas_permitidas([
        $ruta_frontend . '/app/Http/Controllers',
        $ruta_frontend . '/resources/views',
        $ruta_frontend . '/public/css',
        $ruta_frontend . '/routes',
        $ruta_frontend,
    ]);

    $controladores_generados = [];
    $recursos_elegibles = [];

    // 2. Generar Controlador y Vistas Blade por cada tabla elegible
    foreach ($tablas as $nom_tbl => $tbl_info) {
        if (!es_tabla_elegible_front($nom_tbl, $tbl_info, $config)) {
            $motivo = !empty($tbl_info['es_pivote']) ? 'tabla pivote' : 'tabla excluida o nombre no válido';
            $resumen['omitidas'][] = "$nom_tbl ($motivo)";
            continue;
        }

        $clase = nombre_clase($nom_tbl, $config);
        $recurso = strtolower($nom_tbl);
        $recursos_elegibles[] = $recurso;

        // A. Generar Controlador Web
        try {
            $codigo_ctrl = generar_codigo_controlador_web($nom_tbl, $tbl_info, $tablas, $config);
            $ruta_ctrl = $ruta_frontend . '/app/Http/Controllers/' . $clase . 'Controller.php';
            $st_ctrl = escribir($ruta_ctrl, $codigo_ctrl);
            $resumen['controladores'][] = [
                'clase' => $clase . 'Controller',
                'archivo' => "app/Http/Controllers/{$clase}Controller.php",
                'estado' => $st_ctrl,
            ];
            $controladores_generados[] = [
                'tabla' => $nom_tbl,
                'clase_ctrl' => $clase . 'Controller',
            ];
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error generando controlador web {$clase}Controller: " . $e->getMessage();
        }

        // B. Generar Vistas Blade (Index, Create, Edit, Show)
        $dir_vistas_recurso = $ruta_frontend . '/resources/views/crud/' . $recurso;
        crear_directorio($dir_vistas_recurso);

        $vistas = [
            'index' => 'generar_codigo_vista_index',
            'create' => 'generar_codigo_vista_create',
            'edit' => 'generar_codigo_vista_edit',
            'show' => 'generar_codigo_vista_show',
        ];

        foreach ($vistas as $nombre_vista => $fn_generador) {
            try {
                $codigo_vista = $fn_generador($nom_tbl, $tbl_info, $tablas, $config);
                $ruta_vista = $dir_vistas_recurso . '/' . $nombre_vista . '.blade.php';
                $st_vista = escribir($ruta_vista, $codigo_vista);
                $resumen['vistas'][] = [
                    'vista' => "crud.{$recurso}.{$nombre_vista}",
                    'archivo' => "resources/views/crud/{$recurso}/{$nombre_vista}.blade.php",
                    'estado' => $st_vista,
                ];
            } catch (Throwable $e) {
                $resumen['errores'][] = "Error generando vista {$recurso}/{$nombre_vista}: " . $e->getMessage();
            }
        }
    }

    // 3. Generar Layout Base app.blade.php
    try {
        $st_layout = asegurar_layout_frontend($ruta_frontend, $recursos_elegibles, $config);
        $resumen['layout'] = $st_layout;
    } catch (Throwable $e) {
        $resumen['errores'][] = "Error generando layout: " . $e->getMessage();
    }

    // 4. Generar CSS Responsivo
    try {
        $st_css = asegurar_css_frontend($ruta_frontend);
        $resumen['css'] = $st_css;
    } catch (Throwable $e) {
        $resumen['errores'][] = "Error generando crud.css: " . $e->getMessage();
    }

    // 5. Registrar Rutas Web en routes/web.php
    if (!empty($controladores_generados)) {
        try {
            $st_rutas = registrar_rutas_web_frontend($ruta_frontend, $controladores_generados);
            $resumen['rutas_web'] = $st_rutas;
        } catch (Throwable $e) {
            $resumen['errores'][] = "Error registrando rutas web: " . $e->getMessage();
        }
    }

    // 6. Configurar .env del Frontend
    try {
        $st_env = configurar_env_frontend($ruta_frontend, $config);
        $resumen['env_actualizado'] = $st_env;
    } catch (Throwable $e) {
        $resumen['advertencias'][] = "No se pudo actualizar .env de Frontend: " . $e->getMessage();
    }

    return $resumen;
}
