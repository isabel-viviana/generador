<?php
// Utilidades básicas del generador CRUD:
// mensajes en consola, escritura segura de archivos y conversión de nombres.

// ------------------------------------------------------------
// Mensajes en consola
// ------------------------------------------------------------

function mensaje($texto)
{
    echo $texto . PHP_EOL;
}

function exito($texto)
{
    mensaje('[OK]    ' . $texto);
}

function aviso($texto)
{
    mensaje('[AVISO] ' . $texto);
}

function mostrar_error($texto)
{
    mensaje('[ERROR] ' . $texto);
}

// ------------------------------------------------------------
// Directorios y rutas
// ------------------------------------------------------------

function crud_raiz_generador()
{
    if (defined('GENERADOR_ROOT')) {
        return GENERADOR_ROOT;
    }
    if (defined('CRUD_DIR')) {
        return dirname(CRUD_DIR, 2);
    }
    return dirname(__DIR__, 3);
}

function crear_directorio($ruta)
{
    if (!is_dir($ruta)) {
        mkdir($ruta, 0777, true);
    }
}

// Normaliza una ruta (barras, "." y "..") aunque el archivo todavía no exista.
function normalizar_ruta($ruta)
{
    $ruta = str_replace('/', '\\', $ruta);
    $partes = [];
    foreach (explode('\\', $ruta) as $parte) {
        if ($parte === '' || $parte === '.') {
            continue;
        }
        if ($parte === '..') {
            array_pop($partes);
            continue;
        }
        $partes[] = $parte;
    }
    return strtolower(implode('\\', $partes));
}

// Define las carpetas donde el generador puede escribir.
// Si se llama sin argumentos, devuelve la lista actual.
function carpetas_permitidas($carpetas = null)
{
    static $permitidas = [];
    if ($carpetas !== null) {
        $permitidas = array_map('normalizar_ruta', $carpetas);
    }
    return $permitidas;
}

function ruta_permitida($ruta)
{
    $ruta = normalizar_ruta($ruta);
    foreach (carpetas_permitidas() as $carpeta) {
        if (strpos($ruta, $carpeta . '\\') === 0) {
            return true;
        }
    }
    return false;
}

// ------------------------------------------------------------
// Escritura segura de archivos
// ------------------------------------------------------------
// Cada archivo generado lleva una marca:  @crud-generator hash:<sha1>
// El hash se calcula sobre el contenido SIN la línea de la marca.
// Así se sabe si alguien modificó el archivo después de generarlo.

// Inserta la línea de marca según el tipo de archivo.
function agregar_marca($ruta, $contenido)
{
    $hash = sha1(str_replace("\r\n", "\n", $contenido));
    $marca = '@crud-generator hash:' . $hash;

    if (substr($ruta, -10) === '.blade.php') {
        return '{{-- ' . $marca . ' --}}' . "\n" . $contenido;
    }
    if (substr($ruta, -4) === '.php') {
        // La marca va justo después de "<?php" (debe ser la primera línea).
        $lineas = explode("\n", $contenido, 2);
        return $lineas[0] . "\n" . '// ' . $marca . "\n" . ($lineas[1] ?? '');
    }
    if (substr($ruta, -4) === '.css') {
        return '/* ' . $marca . ' */' . "\n" . $contenido;
    }
    return null; // Tipo de archivo no soportado.
}

// Devuelve el estado de un archivo existente: 'generado', 'modificado' o 'manual'.
function estado_archivo($ruta)
{
    $texto = str_replace("\r\n", "\n", file_get_contents($ruta));

    if (!preg_match('/@crud-generator hash:([0-9a-f]{40})/', $texto, $m)) {
        return 'manual';
    }

    // Quitar la línea de la marca y recalcular el hash.
    $lineas = explode("\n", $texto);
    foreach ($lineas as $i => $linea) {
        if (strpos($linea, '@crud-generator hash:') !== false) {
            unset($lineas[$i]);
            break;
        }
    }
    $original = implode("\n", $lineas);

    return sha1($original) === $m[1] ? 'generado' : 'modificado';
}

// Guarda un archivo de forma atómica y segura para evitar archivos truncados en fallos de I/O.
function guardar_archivo_atomico($ruta, $contenido)
{
    $dir = dirname($ruta);
    crear_directorio($dir);

    $tmp = $dir . DIRECTORY_SEPARATOR . '.' . basename($ruta) . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $contenido, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    // En Windows, rename() puede fallar si el destino ya existe. Usamos backup temporal seguro.
    if (file_exists($ruta)) {
        $bak = $dir . DIRECTORY_SEPARATOR . '.' . basename($ruta) . '.bak.' . bin2hex(random_bytes(4));
        if (!@rename($ruta, $bak)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $ruta)) {
            @rename($bak, $ruta); // Restaurar original si falla
            @unlink($tmp);
            return false;
        }
        @unlink($bak);
        return true;
    }

    if (!@rename($tmp, $ruta)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

// Escribe un archivo generado respetando el código existente.
// Devuelve: 'creado', 'actualizado', 'sin_cambios', 'modificado', 'manual' o 'error'.
function escribir($ruta, $contenido)
{
    $nombre = $ruta;

    if (!ruta_permitida($ruta)) {
        mostrar_error("Escritura no permitida fuera de las carpetas autorizadas: $nombre");
        return 'error';
    }

    if (substr($ruta, -4) === '.php' && strpos($contenido, '<?php') !== 0 && substr($ruta, -10) !== '.blade.php') {
        mostrar_error("El contenido PHP debe comenzar con <?php: $nombre");
        return 'error';
    }

    $final = agregar_marca($ruta, $contenido);
    if ($final === null) {
        mostrar_error("Tipo de archivo no soportado: $nombre");
        return 'error';
    }

    $existe = file_exists($ruta);

    if ($existe) {
        $estado = estado_archivo($ruta);
        if ($estado === 'manual') {
            aviso("Omitido (archivo manual, no se sobrescribe): $nombre");
            return 'manual';
        }
        if ($estado === 'modificado') {
            aviso("Omitido (archivo generado pero modificado a mano): $nombre");
            return 'modificado';
        }
        if (str_replace("\r\n", "\n", file_get_contents($ruta)) === $final) {
            mensaje("[=]     Sin cambios: $nombre");
            return 'sin_cambios';
        }
    }

    if (!guardar_archivo_atomico($ruta, $final)) {
        mostrar_error("No se pudo escribir: $nombre");
        return 'error';
    }

    if ($existe) {
        exito("Actualizado: $nombre");
        return 'actualizado';
    }
    exito("Creado: $nombre");
    return 'creado';
}

// Escribe un archivo dentro de las carpetas autorizadas sin agregar la marca @crud-generator.
// Se utiliza para archivos de configuración compartidos (routes/api.php, bootstrap/app.php, config/services.php, .env).
function escribir_archivo_sin_marca($ruta, $contenido)
{
    $nombre = $ruta;

    if (!ruta_permitida($ruta)) {
        mostrar_error("Escritura no permitida fuera de las carpetas autorizadas: $nombre");
        return false;
    }

    if (file_exists($ruta) && file_get_contents($ruta) === $contenido) {
        return true;
    }

    if (!guardar_archivo_atomico($ruta, $contenido)) {
        mostrar_error("No se pudo escribir: $nombre");
        return false;
    }

    return true;
}

// ------------------------------------------------------------
// Nombres
// ------------------------------------------------------------

function quitar_tildes($texto)
{
    return strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ]);
}

// Convierte una palabra en plural a singular (reglas combinadas español e inglés).
// En nombres compuestos (detalle_pedidos, module_teachers) solo se cambia la última palabra.
// Si el resultado no es correcto, defina el nombre en config.php ('clases').
function singular($texto)
{
    $partes = explode('_', $texto);
    $p = array_pop($partes);

    $irregulares = [
        'modules' => 'module',
        'schedules' => 'schedule',
        'rules' => 'rule',
        'roles' => 'role',
        'files' => 'file',
        'profiles' => 'profile',
        'tables' => 'table',
        'articles' => 'article',
        'samples' => 'sample',
        'examples' => 'example',
        'variables' => 'variable',
        'vehicles' => 'vehicle',
        'cycles' => 'cycle',
        'titles' => 'title',
        'matches' => 'match',
        'branches' => 'branch',
        'batches' => 'batch',
        'classes' => 'class',
        'addresses' => 'address',
        'processes' => 'process',
        'boxes' => 'box',
        'taxes' => 'tax',
        'quizzes' => 'quiz',
        'people' => 'person',
        'children' => 'child',
    ];

    $p_lower = strtolower($p);
    if (isset($irregulares[$p_lower])) {
        $p = $irregulares[$p_lower];
    } elseif (preg_match('/ies$/i', $p)) {
        $p = substr($p, 0, -3) . 'y';                       // categories -> category
    } elseif (preg_match('/[aeiou]ces$/i', $p)) {
        $p = substr($p, 0, -3) . 'z';                       // luces -> luz
    } elseif (preg_match('/(ch|sh|ss|x|z)es$/i', $p, $m)) {
        $p = substr($p, 0, -2);                             // matches -> match, boxes -> box
    } elseif (preg_match('/([bcdfghjklmnpqrstvwxyz]le|ule|ole|ile|ale|ele|ope|ode|ome|ite|ive|ise|use|ute|ure)s$/i', $p)) {
        $p = substr($p, 0, -1);                             // modules -> module, roles -> role, codes -> code
    } elseif (preg_match('/(or|ur|er|ar|ir)es$/i', $p)) {
        $p = substr($p, 0, -2);                             // profesores -> profesor
    } elseif (preg_match('/(ion|on)es$/i', $p)) {
        $p = substr($p, 0, -2);                             // lecciones -> leccion
    } elseif (preg_match('/[aeiou][lrndjy]es$/i', $p)) {
        $p = substr($p, 0, -2);                             // animales -> animal, ordenes -> orden
    } elseif (preg_match('/[^s]s$/i', $p)) {
        $p = substr($p, 0, -1);                             // productos -> producto, students -> student
    }

    $partes[] = $p;
    return implode('_', $partes);
}

// 'detalle_pedido' -> 'DetallePedido'
function studly($texto)
{
    $texto = quitar_tildes($texto);
    $texto = preg_replace('/[^A-Za-z0-9]+/', ' ', $texto);
    return str_replace(' ', '', ucwords(strtolower(trim($texto))));
}

// 'ModuleTeacher' -> 'module_teacher'
function snake($texto)
{
    $texto = preg_replace('/\s+/u', '', ucwords($texto));
    return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $texto));
}

// Convierte una palabra a plural (español e inglés)
function plural($texto)
{
    $partes = explode('_', $texto);
    $p = array_pop($partes);

    $irregulares_plural = [
        'module' => 'modules',
        'teacher' => 'teachers',
        'student' => 'students',
        'user' => 'users',
        'member' => 'members',
        'player' => 'players',
        'customer' => 'customers',
        'product' => 'products',
        'match' => 'matches',
        'box' => 'boxes',
        'class' => 'classes',
        'person' => 'people',
        'child' => 'children',
    ];

    $p_lower = strtolower($p);
    if (isset($irregulares_plural[$p_lower])) {
        $p = $irregulares_plural[$p_lower];
    } elseif (preg_match('/[aeiou]y$/i', $p)) {
        $p .= 's';
    } elseif (preg_match('/[^aeiou]y$/i', $p)) {
        $p = substr($p, 0, -1) . 'ies'; // category -> categories
    } elseif (preg_match('/z$/i', $p)) {
        $p = substr($p, 0, -1) . 'ces'; // luz -> luces
    } elseif (preg_match('/(ch|sh|ss|x|z)$/i', $p)) {
        $p .= 'es'; // match -> matches, box -> boxes
    } elseif (preg_match('/er$/i', $p)) {
        $p .= 's'; // teacher -> teachers, user -> users, player -> players
    } elseif (preg_match('/[aeiou][lndj]$/i', $p)) {
        $p .= 'es'; // animal -> animales, orden -> ordenes
    } elseif (preg_match('/(or|ur|ar|ir)$/i', $p)) {
        $p .= 'es'; // profesor -> profesores, autor -> autores
    } elseif (!preg_match('/s$/i', $p)) {
        $p .= 's'; // student -> students, module -> modules, producto -> productos
    }

    $partes[] = $p;
    return implode('_', $partes);
}

// 'Mi Inventario 2' -> 'mi-inventario-2'
function slug($texto)
{
    $texto = strtolower(quitar_tildes($texto));
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    return trim($texto, '-');
}

// Nombre de clase para una tabla: usa config.php si está definido, si no lo calcula.
function nombre_clase($tabla, $config)
{
    if (!empty($config['clases'][$tabla])) {
        return $config['clases'][$tabla];
    }
    return studly(singular($tabla));
}

// Nombre del proyecto en formato slug (para los dominios locales).
function nombre_proyecto($config)
{
    if (!empty($config['proyecto'])) {
        return slug($config['proyecto']);
    }
    if (defined('RAIZ')) {
        return slug(basename(RAIZ));
    }
    if (defined('CRUD_DIR')) {
        return slug(basename(crud_raiz_generador()));
    }
    return slug(basename(getcwd()));
}
