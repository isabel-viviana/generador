# Generador CRUD Laravel

Generador CLI PHP que analiza migraciones existentes y adapta CRUD a partir de las fuentes de Backend y Frontend configuradas. No ejecuta migraciones ni inventa reglas de negocio, relaciones, etiquetas o pantallas. Los archivos destino existentes se conservan cuando hay conflictos.

## Requisitos

- PHP CLI 8.0 o superior en `PATH`.
- `backend/` y `frontend/` creados como proyectos Laravel en el directorio padre de la carpeta del generador. La generación temporal se comprobó con las fuentes actuales (Laravel `^13.17`, lock `13.43.0`); no se validó el runtime con dependencias instaladas.
- Composer para instalar las dependencias de Laravel. El generador no ejecuta `composer install` por su cuenta.
- MySQL configurado si las migraciones del ejercicio utilizan MySQL.
- Node.js/npm solamente si `../frontend/package.json` declara scripts de assets.
- Fuentes originales verificadas para adaptar. Por defecto, `config.php` espera `pracExaLav/backend` y `pracExaLav/frontend` dentro de la carpeta del generador. Las rutas destino se configuran como `backend` y `frontend` en el directorio padre. El generador determina ambas ubicaciones a partir de sus propios archivos, por lo que su carpeta puede tener cualquier nombre.

## Preparar un ejercicio desde cero

Ejecuta estos comandos desde la carpeta que será raíz del ejercicio. Si ya creaste los proyectos, omite los dos primeros comandos.

```sh
composer create-project laravel/laravel backend
composer create-project laravel/laravel frontend
```

Instala las dependencias de ambos proyectos si no fueron instaladas al crearlos:

```sh
cd backend && composer install
cd ../frontend && composer install
cd ..
```

Copia el generador a una carpeta propia del ejercicio. El nombre de esa carpeta es libre; conserva dentro de ella `.tools/.crud` y las fuentes `pracExaLav`:

```text
Ejercicio/
├── backend/
├── frontend/
└── herramientas/               # puede llamarse g, crud, generador u otro nombre
    ├── pracExaLav/
    │   ├── backend/
    │   └── frontend/
    └── .tools/
        └── .crud/
            ├── generate.php
            ├── config.php
            └── src/
```

La fuente es solo lectura y nunca debe apuntar al Backend o Frontend destino. Si las fuentes están en otra ubicación, configura sus rutas en `.tools/.crud/config.php` desde la carpeta del generador. Revisa también `directorio_backend`, `directorio_frontend`, `adaptaciones_backend` y `adaptaciones_frontend`: una tabla nueva solo se adapta cuando existe un mapeo fuente explícito y compatible.

Crea las migraciones en `../backend`. Ejemplo de comandos de Laravel desde la carpeta del generador:

```sh
cd ../backend
php artisan make:migration create_products_table
php artisan make:migration create_categories_table
cd -
```

Edita esas migraciones con el esquema real del ejercicio, incluidas las claves foráneas que realmente existan. El analizador lee PHP estático; no ejecuta migraciones ni evalúa bucles o lógica dinámica.

## Generar todo

Desde la carpeta del generador:

```sh
php .tools/.crud/generate.php all
```

`all` ejecuta, en orden:

1. Verifica PHP, las carpetas `../backend` y `../frontend` y la estructura Laravel.
2. Detecta Composer y, si el Frontend declara scripts npm, Node.js/npm y assets faltantes.
3. Analiza migraciones, tablas, columnas, claves foráneas y relaciones. Un análisis inseguro detiene el flujo.
4. Comprueba las fuentes de adaptación antes de escribir en los proyectos.
5. Si falta `.env` y existe `.env.example`, crea `.env` sin reemplazar archivos existentes. Genera `APP_KEY` solo cuando está ausente o vacío. Al crear el `.env` del Frontend, alinea `API_BASE_URL` con la configuración; en el Backend deja vacías las variables Cloudinary copiadas desde el ejemplo para no reutilizar credenciales de referencia.
6. Ejecuta el generador Backend y después el Frontend. Si falla Backend, no inicia la etapa Frontend.
7. Configura `API_BASE_URL` en un `.env` recién creado desde `.env.example`. Si el Frontend ya tenía `.env`, preserva un valor no vacío y lo reporta para revisión si no coincide con la URL configurada.
8. Detecta campos de imagen y revisa SDK, `config/services.php` y variables Cloudinary cuando corresponde.
9. Informa URLs configuradas, conflictos y pasos manuales.

El comando retorna un código distinto de cero ante errores, fuentes ausentes o adaptaciones incompletas. También se pueden ejecutar las etapas existentes por separado:

```sh
php .tools/.crud/generate.php back
php .tools/.crud/generate.php front
```

Ejecuta los comandos desde la carpeta del generador (o indica la ruta de su archivo `generate.php`).

## Configuración manual necesaria

El generador puede crear `.env` y una `APP_KEY` vacía de forma segura, pero no conoce tus credenciales:

1. Completa `../backend/.env` con `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y el `DB_PASSWORD` correspondiente a tu MySQL.
2. Si `../frontend/.env` ya existía antes de ejecutar el generador, verifica que `API_BASE_URL` apunte a la URL API indicada por el comando. Los valores existentes no se sobrescriben.
3. Solo después de comprobar la conexión y el contenido de las migraciones, ejecuta:

   ```sh
   cd ../backend
   php artisan migrate
   cd -
   ```

4. Si el Frontend usa Vite y todavía no hay `public/build/manifest.json`, instala/compila los assets:

   ```sh
   cd ../frontend
   npm install
   npm run build
   cd -
   ```

La instalación de paquetes no se ejecuta automáticamente, porque Composer/npm pueden necesitar red, modificar archivos de bloqueo o ejecutar scripts de terceros.

## Cloudinary e imágenes

Cloudinary solo se reporta cuando el analizador detecta columnas configuradas como imagen (`campos_imagen`). Para esos casos:

- El generador puede añadir `cloudinary/cloudinary_php` al `composer.json` y adaptar la entrada de `config/services.php` desde la fuente, si es seguro.
- El SDK debe instalarse en Backend. Si `composer.json` ya lo declara y falta `vendor/`, ejecuta `composer install`; si no está declarado, ejecuta el comando que indique el informe, normalmente:

  ```sh
  cd ../backend
  composer require cloudinary/cloudinary_php:^3.3
  cd -
  ```

- Completa en `../backend/.env` las credenciales auténticas `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY` y `CLOUDINARY_API_SECRET`.
- La subida real requiere credenciales válidas y conexión a Cloudinary. El generador no sube imágenes ni afirma que esa operación esté probada.

Si no hay campos de imagen, no se exige configurar Cloudinary.

## Iniciar Backend y Frontend

```sh
php .tools/.crud/generate.php start
```

Este comando comprueba proyectos, `vendor/`, assets requeridos por Vite y puertos configurados antes de iniciar `php artisan serve`. Si hay `package.json` con scripts de assets pero faltan tanto `public/build/manifest.json` como `public/hot`, prepara esos assets manualmente antes de volver a ejecutar `start`. Los valores por defecto son `host_local=127.0.0.1`, `puerto_backend=8001` y `puerto_frontend=8002`; se pueden ajustar en `.tools/.crud/config.php`. Si un puerto está ocupado, el comando falla claramente y no afirma que ambos servidores iniciaron. Al iniciar, informa URLs y permanece en primer plano; usa `Ctrl+C` para detener los procesos.

También hay lanzadores equivalentes:

```sh
bash .tools/.crud/iniciar.sh
```

En Windows, desde CMD:

```bat
.tools\.crud\iniciar.bat
```

El Backend expone sus rutas bajo `/api`; la URL concreta se informa usando la configuración del proyecto. Si falta `vendor/`, instala dependencias manualmente con `composer install` en cada aplicación y vuelve a iniciar.

## Probar los CRUD

1. Confirma la conexión de Backend a MySQL y ejecuta `php artisan migrate` en `../backend`.
2. Inicia los servicios con `php .tools/.crud/generate.php start`.
3. Abre la URL de Frontend que mostró el comando.
4. Prueba crear, listar, editar y eliminar cada entidad generada. Verifica los endpoints API del Backend si el Frontend muestra un error de conexión.
5. Para formularios relacionados, revisa que las migraciones tengan claves foráneas y que exista mapeo compatible en las fuentes. El generador no infiere una relación únicamente por un sufijo `_id`.
6. Prueba una carga de imagen solo después de configurar Cloudinary; las credenciales no se validan ni la subida se ejecuta durante la generación.

## Protección, conflictos y límites

- Los generadores conservan archivos manuales y archivos generados que fueron modificados. Los conflictos se reportan y no se sobrescriben.
- Las migraciones complejas/dinámicas pueden quedar parciales; en ese caso se detiene la generación insegura.
- Las tablas o campos sin correspondencia de fuente segura requieren una adaptación explícita. No se crea un CRUD genérico.
- Las migraciones se ejecutan manualmente para evitar modificar accidentalmente bases de datos.
- El generador no instala dependencias, no inventa credenciales y no comprueba disponibilidad real de MySQL o Cloudinary.
- Una ejecución de `all` puede completar generación y aun así dejar pasos manuales como MySQL, migraciones, dependencias o assets.

## Pruebas incluidas

Ejecuta cada script PHP desde la raíz:

```sh
php .tools/.crud/tests/automation_test.php
php .tools/.crud/tests/migraciones_test.php
php .tools/.crud/tests/source_adaptation_test.php
php .tools/.crud/tests/backend_source_adaptation_test.php
php .tools/.crud/tests/frontend_source_adaptation_test.php
```

Las pruebas de adaptación Backend/Frontend y el caso de generación integrada de `migraciones_test.php` necesitan las fuentes `pracExaLav` configuradas. Si no existen, fallarán explícitamente al localizarlas; el resto de aserciones de migraciones se ejecuta antes del caso dependiente.

## Errores frecuentes

- **No se encuentra Backend o Frontend:** crea `backend/` y `frontend/` en el directorio padre del generador, o ajusta `directorio_backend` y `directorio_frontend`.
- **Proyecto Laravel inválido:** comprueba `composer.json`, `artisan` y `app/`; luego instala dependencias con Composer.
- **Fuente original ausente:** ajusta `fuente_backend`/`fuente_frontend` a copias verificadas y legibles. No uses el destino como fuente.
- **Migración parcial/no segura:** revisa las advertencias. Sustituye la lógica dinámica por migraciones estáticas o resuelve el diagnóstico antes de regenerar.
- **Adaptación pendiente:** completa el mapeo explícito de columnas, relaciones, validaciones o etiquetas; no se generan alternativas inventadas.
- **Puerto ocupado:** libera el puerto o cambia `puerto_backend`/`puerto_frontend` y vuelve a ejecutar `start`.
- **Error MySQL:** revisa las variables `DB_*` y que MySQL esté iniciado. Las migraciones no se ejecutan automáticamente.
- **Cloudinary no disponible:** instala el SDK, completa sus tres credenciales y prueba la subida desde la aplicación con conexión activa.
- **Assets Vite faltantes:** ejecuta `npm install` y `npm run build` en Frontend cuando `package.json` lo requiera.
