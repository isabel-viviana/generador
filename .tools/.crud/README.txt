================================================================================
                    GENERADOR CRUD LARAVEL (PORTABLE)
================================================================================

A. QUÉ ES
---------
Es una herramienta CLI portable y ligera desarrollada en PHP nativo para proyectos
Laravel (versiones 10, 11 y 12).
Analiza las migraciones del Backend sin ejecutarlas y adapta automáticamente:
  1. Backend API: Modelos Eloquent, Controladores REST y Rutas API copiados de
     las fuentes reales configuradas en pracExaLav/backend.
  2. Frontend Blade: Controladores Web, vistas Blade (listado, creación, edición,
     detalle), estilos CSS y consumo de la API REST mediante Illuminate\Support\Facades\Http.
No requiere dependencias externas, librerías adicionales ni Composer para funcionar.


B. REQUISITOS
-------------
  - Sistema operativo: Windows 10 u 11 (compatible con rutas con espacios).
  - PHP: Versión 8.0, 8.1, 8.2, 8.3 o superior disponible en el PATH o en C:\laragon\bin\php.
  - Extensiones PHP recomendadas: curl, openssl, mbstring, json, pdo, fileinfo.
  - Laravel: Proyectos preexistentes en Backend/ y Frontend/ (Laravel 10, 11 o 12).
  - Conexión a Internet: ÚNICAMENTE necesaria si se suben imágenes reales a Cloudinary.
    El análisis y la generación de código funcionan 100% offline.


C. INSTALACIÓN
--------------
Para usar el generador en cualquier proyecto Laravel:
  1. Copia la carpeta ".tools/.crud" dentro de la raíz de tu proyecto:
     TuProyecto/
     ├── Backend/
     ├── Frontend/
     └── .tools/
         └── .crud/
  2. Abre una consola de comandos (CMD o PowerShell) en la raíz de TuProyecto.
  3. ¡Listo! No requiere ejecutar "composer install" ni configuraciones adicionales.


D. COMANDOS
-----------
El generador provee únicamente dos comandos de generación y un script de arranque:

  1. Generar el Backend API:
     .tools\.crud\generar back
     (o entrando a .tools\.crud y ejecutando: generar back)

  2. Generar el Frontend Web:
     .tools\.crud\generar front
     (o entrando a .tools\.crud y ejecutando: generar front)

  3. Iniciar ambos proyectos en desarrollo:
     .tools\.crud\iniciar.bat
     Abre dos ventanas independientes ejecutando php artisan serve en sus respectivos puertos.


E. QUÉ GENERA
-------------
Al ejecutar "generar back":
  - Los Modelos Eloquent y Controladores API se copian de fuentes originales
    verificadas y solo se adaptan mediante reemplazos literales delimitados.
  - Los modelos conservan traits, imports, métodos y relaciones de la fuente;
    los campos fillable y claves Eloquent se ajustan a las migraciones.
  - Las validaciones y respuestas API se conservan desde el controlador fuente.
    Una entidad sin mapeo seguro o sin reglas explícitas queda pendiente; no se
    sustituye por un CRUD genérico.
  - Las rutas API se adaptan desde routes/api.php de la referencia. Las rutas
    manuales existentes se conservan y los conflictos se reportan.
  - routes/web.php solo se copia desde la referencia cuando no existe. No hay
    controladores Web CRUD en pracExaLav/backend; el Frontend queda fuera de esta fase.
  - Rutas API (Backend/routes/api.php):
    Registra rutas Route::apiResource() delimitadas con marcadores de protección.
    En Laravel 11/12, si routes/api.php no existía, registra automáticamente el archivo en bootstrap/app.php.
  - Configuración .env:
    Inserta claves vacías para CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY y CLOUDINARY_API_SECRET si aplican.
  - Configuración de recursos:
    Si un controlador fuente usa Cloudinary, adapta la entrada cloudinary de
    pracExaLav/backend/config/services.php solo cuando el destino aún no la define.

Al ejecutar "generar front":
  - Copia los controladores, vistas index/create/edit/show, Home, layout, rutas, CSS y
    JavaScript existentes de pracExaLav/frontend mediante source_adaptation.php.
    No usa CRUD templates alternativos, no genera resources/views/crud ni crea crud.css.
  - Solo adapta los identificadores y textos que están declarados en adaptaciones_frontend.
    El HTML/Blade, clases, distribución, validaciones visuales, manejo de imágenes,
    formularios, navegación y lógica del controlador permanecen basados en la fuente real.
  - Los archivos fuente sin cambios (CSS, JavaScript y vistas de entidades originales,
    entre otros) se copian byte por byte. Conflictos con destinos diferentes se informan
    y no se sobrescriben.
  - Rutas Web: si routes/web.php ya existe, agrega únicamente las declaraciones faltantes
    en un bloque administrado, conservando el contenido manual. Una ruta raíz o resource
    manual incompatible se informa como conflicto y no se duplica.
    La ruta exacta welcome predeterminada del esqueleto Laravel se sustituye por la Home
    fuente; cualquier otra ruta raíz se conserva y se informa como conflicto.
    Backend/routes/api.php usa el marcador <crud-generator>; Frontend/routes/web.php
    usa el marcador distinto <crud-generator-frontend-routes>.
  - API_BASE_URL: el valor vacío existente se completa desde frontend_api_base_url. Si
    .env no existe, queda pendiente; un valor no vacío se conserva, y si no es una URL
    HTTP/HTTPS válida o termina en "/" se registra como pendiente.
  - La ejecución devuelve inventario de archivos copiados/adaptados/sin cambios, conflictos
    y mapeos pendientes. Un manifiesto incompleto no produce un CRUD genérico.


F. FUNCIONAMIENTO Y COMUNICACIÓN
--------------------------------
  - El Backend actúa como API REST independiente y se conecta a la Base de Datos.
  - El Frontend es una aplicación Laravel Blade independiente que NO se conecta directamente
    a la base de datos del Backend; consume los endpoints REST utilizando el cliente HTTP de Laravel.
  - Para relaciones (ej: producto pertenece a categoría), el Frontend solicita GET /api/categorias
    para poblar los <select> de los formularios de forma automática.
  - Para imágenes, el Frontend envía la imagen al Backend en multipart/form-data; el Backend
    la sube a Cloudinary y guarda la URL segura (https://...) devuelta por la nube.


G. CONFIGURACIÓN
----------------
Archivo .tools/.crud/config.php:
  - 'tablas_excluidas': Lista de tablas del sistema que no generan CRUD (migrations, failed_jobs, etc.).
  - 'campos_imagen': Patrones para detectar imágenes (foto, imagen, image, avatar, banner, logo, etc.).
  - 'clases': Nombres manuales o excepciones de singularización para tablas con nombres especiales.
  - 'fuente_backend': Ruta relativa al Backend de referencia (por defecto pracExaLav/backend).
    Si el generador se copia a otro proyecto, configure la ruta real de la fuente verificada.
  - 'fuente_frontend': Ruta relativa a la fuente original (por defecto pracExaLav/frontend).
    La carpeta de referencia nunca puede ser el destino de generación.
  - 'adaptaciones_frontend': Manifiesto por tabla. Declara fuente, nombres técnicos y de
    interfaz, clase del controlador, variables, ruta web, endpoint API, tarjeta Home,
    campos ordenados (campo fuente, etiqueta, tipo, control e imagen), relaciones y textos.
    Cada campo destino debe mapearse uno a uno y en orden con todos los campos de una
    entidad fuente compatible. No se infieren etiquetas ni semántica desde columnas.
    Si el esquema requiere otro número/orden de campos o un control/relación incompatible,
    el recurso se registra pendiente. Los tokens configurados deben aparecer en los archivos
    fuente; de lo contrario, se informa el mapeo pendiente en vez de escribir una salida parcial.
  - 'frontend_nombre_app_fuente'/'frontend_nombre_app' y
    'frontend_home_parrafo_fuente'/'frontend_home_parrafo': textos exactos y sus reemplazos
    para el título y la introducción de Home. 'frontend_api_base_url': URL base HTTP/HTTPS
    explícita, sin barra final (los controladores fuente concatenan rutas).
  - 'adaptaciones_backend': Mapeos por tabla para elegir archivos fuente, cambiar nombres de campos
    (campo destino => campo validado por la fuente) y declarar reglas explícitas para campos nuevos.

Variables de entorno (.env):
  - Frontend/.env:
    API_BASE_URL=http://127.0.0.1:8001/api (URL base usada por los controladores fuente)
  - Backend/.env (opcional, solo para subida real a Cloudinary):
    CLOUDINARY_CLOUD_NAME=tu_cloud_name
    CLOUDINARY_API_KEY=tu_api_key
    CLOUDINARY_API_SECRET=tu_api_secret


H. INICIO DEL PROYECTO
----------------------
Para levantar ambos servicios en modo desarrollo local:
  Opción 1: Ejecutar ".tools\.crud\iniciar.bat" desde la raíz del proyecto.
  Opción 2: Manualmente en dos consolas:
    Consola 1 (Backend):  cd Backend  && php artisan serve --port=8001
    Consola 2 (Frontend): cd Frontend && php artisan serve --port=8002

Puertos asignados por convención:
  - Backend API:  http://127.0.0.1:8001  (API en http://127.0.0.1:8001/api)
  - Frontend Web: http://127.0.0.1:8002


I. PROTECCIÓN DE ARCHIVOS E IDEMPOTENCIA
-----------------------------------------
El generador incluye un sistema estricto de protección contra pérdida de código:
  1. Firma digital SHA-256:
     Todo archivo generado incluye una cabecera con un hash:
     /* @crud-generator hash:<hash_sha256> */
  2. Archivos manuales:
     Si un archivo en la ruta destino fue creado por el usuario (no tiene la marca de agua),
     el generador NUNCA lo sobrescribe.
  3. Archivos modificados por el desarrollador:
     Si el desarrollador editó un archivo generado previamente, el hash ya no coincidirá
     con el contenido original. El generador detecta la personalización y NO lo sobrescribe.
  4. Idempotencia:
     Si vuelves a ejecutar "generar back" o "generar front" sin cambios en las migraciones,
     el generador detecta que los archivos son idénticos y omite su escritura de manera segura.
  5. Marcadores en rutas:
     Las rutas en routes/api.php y routes/web.php están delimitadas por:
     // === CRUD GENERATOR ROUTES START ===
     // === CRUD GENERATOR ROUTES END ===
     El generador solo actualiza el bloque interior, respetando rutas previas del usuario.

I.1. COPIA SEGURA Y ADAPTACIÓN DEL BACKEND DESDE FUENTES
------------------------------------
El núcleo .tools/.crud/src/source_adaptation.php ofrece copiar_archivo_fuente()
para copiar un archivo existente a una ruta relativa dentro de un directorio destino.
La copia conserva los bytes originales y crea los directorios que falten. Si el destino
ya existe con el mismo contenido, no lo vuelve a escribir; si tiene contenido distinto,
informa "conflicto" y lo conserva sin sobrescribir.

Ejemplo de uso:
  $resultado = copiar_archivo_fuente(
      $archivo_original,
      $directorio_destino,
      'app/Models/Modelo.php',
      ['{{MODEL_CLASS}}' => 'Student']
  );

Solo se sustituyen tokens literales declarados explícitamente en el cuarto argumento.
No se reemplazan nombres ordinarios ni coincidencias parciales. Los tokens ausentes
se informan en "pendientes"; el contenido de origen se mantiene. El resultado informa
el estado (copiado, adaptado, sin_cambios, conflicto o error), el motivo,
las adaptaciones aplicadas y las pendientes. La generación Backend utiliza este mecanismo
para los modelos, controladores API y rutas desde pracExaLav/backend. Si un cambio requiere
inventar relaciones o reglas de negocio, queda pendiente y no se escribe un template genérico.

I.2. ADAPTACIÓN FIEL DEL FRONTEND
---------------------------------
La generación Web usa los controladores y las vistas originales de pracExaLav/frontend como
fuente real. El manifiesto de config.php habilita una entidad solo con un mapeo completo
compatible con uno de esos recursos. Los cambios se aplican como sustituciones literales
autorizadas; no se reemplazan identificadores en masa ni se sintetiza estructura Blade.
Layout, Home, rutas, recursos CSS y JavaScript también parten de sus archivos originales.

El test .tools/.crud/tests/frontend_source_adaptation_test.php verifica byte a byte las
entidades Student/Teacher/Module, compara la estructura y assets, adapta una entidad
products con nombres/etiquetas distintos, ejercita rutas/API y comprueba idempotencia,
protección manual y que pracExaLav quede intacto. Los campos nuevos, reglas semánticas,
relaciones no equivalentes o cambios de diseño no declarados se dejan pendientes.

J. LIMITACIONES
---------------
  - Migraciones dinámicas: El analizador inspecciona código PHP estático mediante expresiones
    regulares y AST básico; construcciones dinámicas como bucles for/foreach para crear columnas
    no son detectadas.
  - Fidelidad de Backend: solo se adaptan relaciones cuya cardinalidad/método exista en un modelo
    fuente. Las entidades asociativas con métodos hasMany hacia la tabla intermedia se comparan
    considerando la relación belongsToMany confirmada por las migraciones; una relación sin
    equivalente seguro queda pendiente y no se inventa.
  - Validaciones de nuevas entidades: cada campo debe mapearse a uno validado por el controlador
    fuente o declarar reglas explícitas en config.php. Las migraciones no inventan reglas de negocio.
  - Controladores Web Backend: pracExaLav/backend no contiene controladores Web CRUD; esta ausencia
    se informa como advertencia y no bloquea la generación Backend, que no genera rutas Web CRUD.
  - Entidades asociativas: las relaciones belongsToMany se conservan en las entidades de origen.
    Una tabla intermedia como module_teachers solo recibe CRUD si la fuente tiene un controlador
    dedicado y una ruta apiResource explícita. Si no existen, se omite sin crear un modelo,
    controlador o ruta genéricos, y sin tratar la ausencia como una adaptación pendiente.
  - Frontend: los controladores fuente propagan respuestas API 404 como 404 Web; otros errores
    conservan el manejo original de la fuente.
  - El comportamiento de listado, búsqueda, paginación, errores, respuestas y archivos es el de
    la fuente original elegida; el generador no lo convierte a un CRUD genérico.
  - Frontend: solo se pueden adaptar esquemas compatibles uno a uno con los campos y controles
    de una vista original. Nuevas columnas, distinta distribución de campos o relaciones sin
    equivalente requieren una fuente/mapeo explícito y quedan pendientes; no se reconstruyen.
  - Frontend: una ruta raíz manual distinta de la Home fuente no se reemplaza. Debe resolverse
    manualmente; los destinos con contenido distinto tampoco se sobrescriben.
  - Cloudinary: Requiere credenciales activas y conexión a Internet al subir imágenes; si no están
    configuradas en el .env, el servicio lanza un error controlado sin romper el Backend.
  - Frontend dependiente del Backend: Si el Backend no está corriendo en el puerto 8001,
    el Frontend mostrará mensajes de error indicando fallo de conexión HTTP con la API.


K. SOLUCIÓN DE PROBLEMAS FRECUENTES
-----------------------------------
1. "No se reconoce PHP como un comando interno":
   Solución: Agrega la ruta de tu PHP (ej: C:\php o C:\laragon\bin\php\php-8.x) a las Variables
   de Entorno del Sistema (PATH), o instala Laragon en su ruta estándar.

2. "Fallo al conectar con la API en Frontend":
   Solución: Verifica que el Backend esté encendido en http://127.0.0.1:8001 ejecutando
   "php artisan serve --port=8001" dentro de la carpeta Backend/.

3. "Un controlador o modelo no se actualiza al re-ejecutar el generador":
   Causa: Modificaste manualmente el archivo y el sistema de protección evitó sobreescribir tus cambios.
   Solución: Si deseas regenerarlo desde cero, borra el archivo manualmente o cambia su nombre.

4. "Error al registrar rutas API en Laravel 11/12":
   Solución: Asegúrate de que el archivo Backend/bootstrap/app.php tenga permisos de escritura
   o agrega manualmente api: __DIR__.'/../routes/api.php' en withRouting().

5. "Imágenes no se suben a Cloudinary":
   Solución: Revisa que Backend/.env contenga tus credenciales CLOUDINARY_CLOUD_NAME,
   CLOUDINARY_API_KEY y CLOUDINARY_API_SECRET, y que cuentes con acceso a Internet.

================================================================================
FIN DEL MANUAL DE USUARIO
================================================================================
