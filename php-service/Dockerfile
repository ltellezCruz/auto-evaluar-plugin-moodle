# Imagen del servicio PHP (Apache + PHP 8.2 + Imagick + Ghostscript). La
# configuración llega por variables de entorno (ver .env.example); el .env
# no se copia a la imagen.
FROM php:8.2-apache

# Ghostscript lee los PDF; Imagick (extensión de PHP) los convierte a PNG.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ghostscript libmagickwand-dev \
    && pecl install imagick-3.7.0 \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    # Debian prohíbe leer PDF en la política de ImageMagick: se permite solo leerlos.
    && for politica in /etc/ImageMagick-*/policy.xml; do \
         sed -i 's#<policy domain="coder" rights="none" pattern="PDF" />#<policy domain="coder" rights="read" pattern="PDF" />#' "$politica"; \
       done

# Una evaluación puede durar varios minutos (una llamada a Gemini por página).
RUN { \
      echo 'max_execution_time = 600'; \
      echo 'memory_limit = 512M'; \
      echo 'post_max_size = 8M'; \
      echo 'expose_php = Off'; \
    } > /usr/local/etc/php/conf.d/aiocr.ini

# Apache escucha en el 8000 y manda todas las rutas a public/index.php.
RUN sed -i 's/^Listen 80$/Listen 8000/' /etc/apache2/ports.conf \
    && { \
      echo '<VirtualHost *:8000>'; \
      echo '    DocumentRoot /app/public'; \
      echo '    <Directory /app/public>'; \
      echo '        AllowOverride None'; \
      echo '        Require all granted'; \
      echo '        FallbackResource /index.php'; \
      echo '    </Directory>'; \
      echo '    ErrorLog /proc/self/fd/2'; \
      echo '    CustomLog /proc/self/fd/1 combined'; \
      echo '</VirtualHost>'; \
    } > /etc/apache2/sites-available/000-default.conf \
    && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security.conf

WORKDIR /app
COPY src/ src/
COPY public/ public/
COPY bin/ bin/
COPY prompts/ prompts/

# Usuario sin privilegios (uid 1000, el mismo de la versión anterior: la
# carpeta data/transcripciones montada ya le pertenece).
RUN useradd --create-home --uid 1000 aiocr \
    && mkdir -p /data/transcripciones && chown aiocr /data/transcripciones
ENV APACHE_RUN_USER=aiocr \
    APACHE_RUN_GROUP=aiocr \
    OUTPUT_DIR=/data/transcripciones \
    TIMEZONE=America/Bogota

EXPOSE 8000
# Revisa la configuración (y limpia transcripciones viejas) antes de encender Apache.
CMD ["sh", "-c", "runuser -u aiocr -- php /app/bin/comprobar.php && exec apache2-foreground"]
