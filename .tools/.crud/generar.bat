@echo off
rem ============================================================
rem  Generador CRUD Laravel - lanzador para Windows
rem  Uso:  generar back
rem        generar front
rem ============================================================
setlocal

rem Carpeta donde esta este .bat (termina en \), sin importar desde donde se ejecute.
set "CRUD_DIR=%~dp0"
set "PHP_EXE="

rem 1) Buscar PHP en el PATH.
where php >nul 2>nul
if %ERRORLEVEL%==0 set "PHP_EXE=php"

rem 2) Si no esta en el PATH, buscar en la ubicacion habitual de Laragon.
if not defined PHP_EXE (
    for /d %%D in ("C:\laragon\bin\php\php-*") do (
        if exist "%%D\php.exe" set "PHP_EXE=%%D\php.exe"
    )
)

if not defined PHP_EXE (
    echo [ERROR] No se encontro PHP.
    echo         Agregue php.exe al PATH de Windows o instale Laragon en C:\laragon.
    exit /b 2
)

"%PHP_EXE%" "%CRUD_DIR%generate.php" %*
exit /b %ERRORLEVEL%
