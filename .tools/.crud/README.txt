================================================================================
                    GENERADOR CRUD LARAVEL (PORTABLE)
================================================================================

A. QUÉ ES
---------
Es una herramienta CLI portable y ligera desarrollada en PHP nativo para proyectos
Laravel (versiones 10, 11 y 12).
Analiza las migraciones del Backend sin ejecutarlas y genera automáticamente:
  1. Backend API: Modelos Eloquent, Controladores REST, Rutas API y soporte
     para subida de imágenes a Cloudinary.
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
  - Modelos Eloquent (Backend/app/Models/<Modelo>.php):
    Con $table, $fillable, $casts y métodos de relación belongsTo/hasMany detectados.
  - Controladores API (Backend/app/Http/Controllers/<Modelo>Controller.php):
    Implementan index, store, show, update y destroy con validación de datos,
    eager loading de relaciones y respuestas JSON estándar.
  - Servicio Cloudinary (Backend/app/Support/Cloudinary.php):
    Generado automáticamente si se detectan campos de imagen en las tablas.
  - Rutas API (Backend/routes/api.php):
    Registra rutas Route::apiResource() delimitadas con marcadores de protección.
    En Laravel 11/12, si routes/api.php no existía, registra automáticamente el archivo en bootstrap/app.php.
  - Configuración .env:
    Inserta claves vacías para CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY y CLOUDINARY_API_SECRET si aplican.

Al ejecutar "generar front":
  - Controladores Web (Frontend/app/Http/Controllers/<Modelo>Controller.php):
    Gestionan el flujo HTTP consumiendo la API REST con Http::timeout(10)->...
    Manejan subida de imágenes multipart y envían _method=PUT para actualizaciones.
  - Vistas Blade (Frontend/resources/views/crud/<recurso>/):
    index.blade.php   -> Tabla de registros, badge de datos, botones ver/editar/eliminar.
    create.blade.php  -> Formulario con validación visual, select para foreign keys y file inputs.
    edit.blade.php    -> Formulario de edición con valores actuales e imagen actual.
    show.blade.php    -> Ficha de detalle con relaciones e imagen renderizada.
  - Layout y estilos:
    Frontend/resources/views/layouts/app.blade.php -> Plantilla base limpia y responsiva.
    Frontend/public/css/crud.css                  -> Estilos CSS nativos sin dependencias.
  - Rutas Web (Frontend/routes/web.php):
    Registra Route::resource() web protegido con marcadores.
  - Configuración .env:
    Configura BACKEND_API_URL=http://127.0.0.1:8001/api y APP_URL en Frontend/.env.


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

Variables de entorno (.env):
  - Frontend/.env:
    API_URL=http://127.0.0.1:8001/api     (URL base del Backend API)
    BACKEND_URL=http://127.0.0.1:8001     (URL raíz del Backend)
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


J. LIMITACIONES
---------------
  - Migraciones dinámicas: El analizador inspecciona código PHP estático mediante expresiones
    regulares y AST básico; construcciones dinámicas como bucles for/foreach para crear columnas
    no son detectadas.
  - Relaciones complejas: Reconoce belongsTo y hasMany mediante llaves foráneas estándar (foreignId
    o constrained). Relaciones many-to-many (BelongsToMany / tablas pivote) y polimórficas
    deben ser configuradas manualmente.
  - Paginación: El método index devuelve el listado completo ($model::with(...)->get());
    si la tabla tiene miles de registros, se recomienda agregar ->paginate() manualmente.
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
