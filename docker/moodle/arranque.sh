#!/bin/bash
# Arranque del contenedor de Moodle:
#   1. espera a la base de datos
#   2. instala Moodle la primera vez (o actualiza si cambió el plugin)
#   3. configura servicios web, Seguridad HTTP, plugin y token
#   4. enciende Apache
set -euo pipefail
cd /var/www/html

moodle_cli() {
    runuser -u www-data -- php "$@"
}

chown -R www-data:www-data /var/www/moodledata
if [ -n "${AIOCR_TOKEN_FILE:-}" ]; then
    mkdir -p "$(dirname "$AIOCR_TOKEN_FILE")"
    chown www-data:www-data "$(dirname "$AIOCR_TOKEN_FILE")"
fi

echo "Esperando a la base de datos (${MOODLE_DB_HOST})..."
until php -r 'exit(@mysqli_connect(getenv("MOODLE_DB_HOST"), getenv("MOODLE_DB_USER"), getenv("MOODLE_DB_PASSWORD"), getenv("MOODLE_DB_NAME")) ? 0 : 1);'; do
    sleep 2
done

instalado=$(php -r '
    $c = mysqli_connect(getenv("MOODLE_DB_HOST"), getenv("MOODLE_DB_USER"), getenv("MOODLE_DB_PASSWORD"), getenv("MOODLE_DB_NAME"));
    echo mysqli_num_rows(mysqli_query($c, "SHOW TABLES LIKE \"mdl_config\"")) ? "si" : "no";')

if [ "$instalado" = "no" ]; then
    echo "Instalando Moodle (tarda varios minutos la primera vez)..."
    moodle_cli admin/cli/install_database.php \
        --agree-license \
        --adminuser="${MOODLE_ADMIN_USER}" \
        --adminpass="${MOODLE_ADMIN_PASSWORD}" \
        --adminemail="${MOODLE_ADMIN_EMAIL}" \
        --fullname="${MOODLE_SITE_NAME}" \
        --shortname="${MOODLE_SITE_SHORTNAME}"
else
    echo "Moodle ya está instalado; aplicando actualizaciones pendientes..."
    moodle_cli admin/cli/upgrade.php --non-interactive
fi

moodle_cli /usr/local/share/aiocr/configurar.php

echo "Moodle disponible en ${MOODLE_WWWROOT}"
exec apache2-foreground
