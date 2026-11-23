@echo off
setlocal

set "CRUD_DIR=%~dp0"
set "PHP_EXE="

where php >nul 2>nul
if %ERRORLEVEL%==0 set "PHP_EXE=php"

if not defined PHP_EXE (
    for /d %%D in ("C:\laragon\bin\php\php-*") do (
        if exist "%%D\php.exe" set "PHP_EXE=%%D\php.exe"
    )
)

if not defined PHP_EXE (
    echo [ERROR] No se encontro PHP en PATH ni en C:\laragon.
    exit /b 2
)

"%PHP_EXE%" "%CRUD_DIR%generate.php" start
exit /b %ERRORLEVEL%
