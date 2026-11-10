@echo off
rem Arranca el servicio PHP. La configuracion se lee de .env (ver .env.example).
rem Uso: run.bat            (escucha solo en este equipo, puerto 8000)
rem      run.bat 0.0.0.0    (escucha en la red; ver advertencia en el README)
rem
rem PHP que se usa, en este orden: la variable PHP, PHP_BIN del .env, o "php"
rem del PATH. Con el paquete de Moodle para Windows, en el .env:
rem      PHP_BIN=<CARPETA_MOODLE>\server\php\php.exe
cd /d "%~dp0"
rem Consola en UTF-8: los mensajes del servicio llevan tildes.
chcp 65001 >nul

if not exist ".env" (
    echo Falta el archivo .env. Copia .env.example como .env y completa los valores.
    exit /b 1
)

if "%PHP%"=="" (
    for /f "usebackq eol=# tokens=1,* delims==" %%a in (".env") do (
        if /i "%%a"=="PHP_BIN" set "PHP=%%b"
    )
)
if "%PHP%"=="" set "PHP=php"
set "PHP=%PHP:"=%"

"%PHP%" -v >nul 2>&1
if errorlevel 1 (
    echo No se encontro PHP: "%PHP%". Define PHP_BIN en el .env con la ruta de php.exe.
    exit /b 1
)

set HOST=%1
if "%HOST%"=="" set HOST=127.0.0.1

rem Imagick se carga solo para el servicio (no en el php.ini que comparte con
rem el Apache de Moodle) si php_imagick.dll esta en la carpeta ext de ese PHP.
set "EXTRA="
"%PHP%" -m 2>nul | findstr /i /x "imagick" >nul
if errorlevel 1 (
    for %%i in ("%PHP%") do if exist "%%~dpiext\php_imagick.dll" set "EXTRA=-d extension=imagick"
)

rem Revisa la configuracion antes de encender (si falta algo, no arranca).
"%PHP%" %EXTRA% bin\comprobar.php
if errorlevel 1 exit /b 1

echo Servicio en http://%HOST%:8000/  (Ctrl+C para detenerlo)
rem La ruta completa de index.php identifica este proceso en tools\monitor.
"%PHP%" %EXTRA% -S %HOST%:8000 "%~dp0public\index.php"
