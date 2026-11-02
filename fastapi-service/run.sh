#!/bin/sh
# Arranca el servicio FastAPI. La configuración se lee de .env (ver .env.example).
# Uso: ./run.sh            (escucha solo en este equipo, puerto 8000)
#      ./run.sh 0.0.0.0    (escucha en la red; ver advertencia en el README)
cd "$(dirname "$0")" || exit 1

if [ ! -f .env ]; then
    echo "Falta el archivo .env. Copia .env.example como .env y completa los valores."
    exit 1
fi

HOST="${1:-127.0.0.1}"

if [ -x .venv/bin/python ]; then
    PY=.venv/bin/python
else
    PY=python3
fi

exec "$PY" -m uvicorn app.main:app --host "$HOST" --port 8000
