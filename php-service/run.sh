#!/bin/sh
# Arranca el servicio PHP. La configuración se lee de .env (ver .env.example).
# Uso: ./run.sh            (escucha solo en este equipo, puerto 8000)
#      ./run.sh 0.0.0.0    (escucha en la red; ver advertencia en el README)
#
# PHP que se usa: la variable PHP, PHP_BIN del .env, o "php" del PATH.
cd "$(dirname "$0")" || exit 1

if [ ! -f .env ]; then
    echo "Falta el archivo .env. Copia .env.example como .env y completa los valores."
    exit 1
fi

if [ -z "${PHP:-}" ]; then
    PHP="$(sed -n 's/^[[:space:]]*PHP_BIN[[:space:]]*=[[:space:]]*//p' .env | tail -n 1 | tr -d '"'"'"'\r')"
fi
PHP="${PHP:-php}"

if ! "$PHP" -v > /dev/null 2>&1; then
    echo "No se encontró PHP: \"$PHP\". Instálalo o define PHP_BIN en el .env."
    exit 1
fi

HOST="${1:-127.0.0.1}"

# Revisa la configuración antes de encender (si falta algo, no arranca).
"$PHP" bin/comprobar.php || exit 1

# En Linux y macOS, php -S puede atender varias solicitudes a la vez: un
# proceso más que MAX_CONCURRENT, para que el que sobra responda "ocupado" (503).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-4}"

echo "Servicio en http://$HOST:8000/  (Ctrl+C para detenerlo)"
# La ruta completa de index.php identifica este proceso en tools/monitor.
exec "$PHP" -S "$HOST:8000" "$(pwd)/public/index.php"
