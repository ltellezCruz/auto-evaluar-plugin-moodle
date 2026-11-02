@echo off
rem Arranca el servicio FastAPI. La configuracion se lee de .env (ver .env.example).
rem Uso: run.bat            (escucha solo en este equipo, puerto 8000)
rem      run.bat 0.0.0.0    (escucha en la red; ver advertencia en el README)
cd /d "%~dp0"

if not exist ".env" (
    echo Falta el archivo .env. Copia .env.example como .env y completa los valores.
    exit /b 1
)

set HOST=%1
if "%HOST%"=="" set HOST=127.0.0.1

if exist ".venv\Scripts\python.exe" (
    set PY=.venv\Scripts\python.exe
) else (
    set PY=python
)

%PY% -m uvicorn app.main:app --host %HOST% --port 8000
