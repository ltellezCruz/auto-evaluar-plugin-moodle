# Modalidad 2: todo el entorno en Docker

> Con la versión en Python (0.3.0, tag `v0.3.0`) se probó en Windows 11 con Docker Desktop (Docker 29.9, Compose 5.6): Moodle 4.5.15+ se instaló y configuró solo, y una entrega en PDF se evaluó de punta a punta. **La imagen del servicio PHP (0.4.0) todavía no se ha construido ni probado en Docker.** No se ha probado en Linux ni en macOS.

Levanta cuatro contenedores con un solo comando. Moodle queda instalado y configurado para el plugin, sin pasos manuales en la administración.

```
 navegador ──▶ http://localhost:8080 ──▶ moodle (Moodle 4.5 + plugin)
                                           │   ▲
                         llama al botón    ▼   │ descarga el PDF por http://moodle
                                         servicio ──▶ Gemini (internet)
                                           │
                     db (MariaDB)  ◀── moodle, cron
```

| Contenedor | Qué hace |
|---|---|
| `db` | MariaDB 10.11 con la base de Moodle |
| `moodle` | Apache + PHP 8.2 (imagen `moodlehq/moodle-php-apache`), código de Moodle 4.5 y el plugin |
| `cron` | Ejecuta el cron de Moodle cada minuto (restauraciones, tareas programadas) |
| `servicio` | El servicio PHP de `php-service/`: Apache + PHP 8.2 + Imagick + Ghostscript |

> Docker levanta también el servicio externo (`servicio`) y configura el plugin para usarlo. Para probar el modo autónomo (0.5.0), en Moodle cambia el proveedor a «Gemini (directo)» y pon la clave de Gemini; el contenedor `servicio` deja de usarse.

## 1. Requisitos

- **Docker Desktop** (Windows o macOS) o Docker Engine con el plugin compose (Linux). En Windows necesita WSL 2.
- Unos 3 GB libres y conexión a internet. La primera construcción descarga Moodle (~90 MB) y las imágenes base.
- Una clave de Gemini.

## 2. Configurar

Desde la carpeta `docker/` del repositorio:

```bash
cp .env.example .env
```

Edita `docker/.env` y completa al menos:

| Variable | Valor |
|---|---|
| `MOODLE_ADMIN_PASSWORD` | Contraseña del administrador: 8 o más caracteres con número, minúscula, mayúscula y carácter especial |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Contraseñas de la base de datos. Solo se usan dentro de Docker |
| `AIOCR_SERVICE_KEY` | Clave compartida entre el plugin y el servicio. Se configura sola en los dos. Genérala con `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` (o cualquier texto largo y aleatorio) |
| `GEMINI_API_KEY` | Tu clave de Gemini |

El resto tiene valores por defecto. Moodle queda en `http://localhost:8080`.

## 3. Levantar

```bash
docker compose up -d --build
```

La **primera vez** tarda varios minutos. En el equipo de pruebas fueron unos 4 minutos para construir las imágenes y unos 5 para instalar Moodle. Durante la instalación, el comando parece detenido mientras espera a que Moodle esté listo. Para seguir el progreso desde otra terminal:

```bash
docker compose logs -f moodle
```

Moodle está listo cuando aparece `Moodle disponible en http://localhost:8080`. El contenedor `servicio` arranca después, cuando Moodle ya está sano.

Durante el arranque, `moodle` hace automáticamente lo que en la modalidad 1 son pasos manuales:

- instala Moodle (solo la primera vez);
- habilita servicios web, REST y el servicio móvil;
- ajusta la Seguridad HTTP para permitir a `servicio:8000`;
- configura el plugin (URL `http://servicio:8000`, timeout 240 s);
- crea un token para el administrador y lo comparte con `servicio` por un volumen interno. No hay que copiarlo a mano.

## 4. Usar

1. Entra a <http://localhost:8080> con el usuario `admin` (o el que pusiste en `MOODLE_ADMIN_USER`). El sitio se instala en inglés. Para usarlo en español: Site administration → Language → Language packs → instala «Español - Internacional (es)» y elígelo como idioma por defecto en Language settings.
2. Crea un curso, una tarea con entrega de archivos y un estudiante de prueba con una entrega en PDF. Es la sección 3 de la [guía local](instalacion-local-windows.md#3-preparar-un-curso-de-prueba).
3. En la tarea → Configuración → Tipos de retroalimentación, activa el plugin.
4. Ver todas las entregas → Calificar → **Auto Evaluar**.

Las transcripciones quedan en `data/transcripciones/tarea_<id>/` del repositorio, cada una con un `.json` de metadatos (páginas, tokens, duración). Se borran solas a los `TRANSCRIPTIONS_DAYS` días (180 por defecto). Los logs de cada contenedor rotan a partir de 10 MB.

## 5. Operación

| Para… | Comando (desde `docker/`) |
|---|---|
| Ver el estado | `docker compose ps` |
| Apagar (conserva los datos) | `docker compose down` |
| Encender de nuevo | `docker compose up -d` |
| Aplicar cambios del plugin o del servicio | `docker compose up -d --build` |
| Cambiar los prompts | `docker compose up -d --build servicio` |
| Ver errores del servicio | `docker compose logs -f servicio` |
| Comprobar el servicio | Abrir <http://127.0.0.1:8000/> (puerto `SERVICIO_PORT`). Las demás rutas exigen la cabecera `X-Aiocr-Key` |
| Pasar de la versión en Python a la PHP (quita el contenedor viejo `fastapi`, que ocupa el puerto 8000) | `docker compose up -d --build --remove-orphans` |
| **Borrar todo**, incluidos cursos y usuarios | `docker compose down -v` |

### Prueba automática de punta a punta

Con los contenedores encendidos, desde la raíz del repositorio:

```bash
bash docker/pruebas/prueba_e2e.sh
```

Crea un curso, un estudiante y una entrega con `docker/pruebas/entrega_prueba.pdf`, ejecuta lo mismo que el botón y muestra el comentario guardado. Llama a Gemini de verdad (2 llamadas). En Windows se ejecuta desde Git Bash.

Para probar un cambio del plugin sin reconstruir, se puede copiar al contenedor en ejecución con el script de despliegue. Ese cambio se pierde cuando el contenedor se vuelve a crear.

```bash
php scripts/deploy_plugin.php --container auto-evaluar-moodle-1 --owner www-data --upgrade --purge-caches
```

### Acceso remoto con ngrok

```bash
ngrok http 8080
```

En `docker/.env`, pon `MOODLE_WWWROOT=https://tu-dominio.ngrok-free.dev` y `MOODLE_SSLPROXY=1`, y luego ejecuta `docker compose up -d`. El servicio sigue descargando los PDF por la red interna: `MOODLE_PUBLIC_URL` toma ese valor automáticamente.

## 6. Problemas frecuentes

| Síntoma | Qué revisar |
|---|---|
| `Define … en docker/.env` al ejecutar compose | Falta esa variable en `docker/.env` |
| `moodle` se reinicia en bucle | `docker compose logs moodle`. Si falló la instalación a mitad, `docker compose down -v` y volver a levantar |
| El puerto 8080 está ocupado | Cambia `MOODLE_PORT` **y** `MOODLE_WWWROOT` en `docker/.env` |
| «Esta URL está bloqueada» al usar el botón | La red no quedó en 172.28.0.0/16: revisa `docker network inspect auto-evaluar_default` |
| `servicio` no arranca: «Falta la variable MOODLE_TOKEN» | Moodle no escribió el token: revisa el final de `docker compose logs moodle` |
| «Moodle redirigió la descarga» | `MOODLE_WWWROOT` no coincide con la dirección con la que entras |
| En Linux, el servicio no puede escribir en `data/transcripciones` | El contenedor usa el uid 1000: `sudo chown 1000 data/transcripciones` |
