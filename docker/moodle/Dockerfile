# Moodle 4.5 + plugin assignfeedback_aiocr.
# Base: imagen oficial de desarrollo de Moodle HQ (PHP + Apache con las
# extensiones que Moodle necesita). El código de Moodle se descarga aquí.
# Contexto de construcción: la raíz del repositorio (ver docker-compose.yml).
FROM moodlehq/moodle-php-apache:8.2

ARG MOODLE_TGZ=https://download.moodle.org/download.php/direct/stable405/moodle-latest-405.tgz

RUN curl -fsSL "$MOODLE_TGZ" | tar -xz --strip-components=1 -C /var/www/html \
    && chown -R www-data:www-data /var/www/html

COPY docker/moodle/config.php /var/www/html/config.php
COPY --chown=www-data:www-data moodle-plugin/aiocr /var/www/html/mod/assign/feedback/aiocr
COPY docker/moodle/configurar.php /usr/local/share/aiocr/configurar.php
COPY docker/moodle/arranque.sh /usr/local/bin/aiocr-arranque
RUN sed -i 's/\r$//' /usr/local/bin/aiocr-arranque && chmod +x /usr/local/bin/aiocr-arranque

CMD ["aiocr-arranque"]
