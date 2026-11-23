@echo off
rem ============================================================
rem  Lanzador de Desarrollo: Inicia Backend (8001) y Frontend (8002)
rem ============================================================
setlocal

set "CRUD_DIR=%~dp0"
set "PHP_EXE="

rem 1) Buscar PHP en el PATH
where php >nul 2>nul
if %ERRORLEVEL%==0 set "PHP_EXE=php"

rem 2) Buscar en Laragon si no esta en PATH
if not defined PHP_EXE (
    for /d %%D in ("C:\laragon\bin\php\php-*") do (
        if exist "%%D\php.exe" set "PHP_EXE=%%D\php.exe"
    )
)

if not defined PHP_EXE (
    echo [ERROR] No se encontro PHP en el PATH ni en C:\laragon.
    pause
    exit /b 2
)

rem Obtener la carpeta raiz del proyecto (dos niveles arriba de .crud)
pushd "%CRUD_DIR%..\.."
set "PROYECTO_RAIZ=%CD%"
popd

if not exist "%PROYECTO_RAIZ%\Backend" (
    echo [ERROR] No se encontro la carpeta Backend en: "%PROYECTO_RAIZ%\Backend"
    pause
    exit /b 1
)

if not exist "%PROYECTO_RAIZ%\Frontend" (
    echo [ERROR] No se encontro la carpeta Frontend en: "%PROYECTO_RAIZ%\Frontend"
    pause
    exit /b 1
)

echo ============================================================
echo   INICIANDO SERVICIOS DE DESARROLLO
echo ============================================================
echo   Backend  (API REST)   : http://127.0.0.1:8001
echo   Frontend (Web Blade)  : http://127.0.0.1:8002
echo ============================================================
echo.

rem Iniciar Backend en ventana separada
start "Backend Laravel API (Puerto 8001)" cmd /k "title Backend API :8001 && cd /d "%PROYECTO_RAIZ%\Backend" && "%PHP_EXE%" artisan serve --port=8001"

rem Iniciar Frontend en ventana separada
start "Frontend Laravel Blade (Puerto 8002)" cmd /k "title Frontend Web :8002 && cd /d "%PROYECTO_RAIZ%\Frontend" && "%PHP_EXE%" artisan serve --port=8002"

echo Ambos servicios fueron iniciados en ventanas independientes.
echo Para detenerlos, cierre las ventanas correspondientes.
echo.
