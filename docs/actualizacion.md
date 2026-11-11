# Actualizar Auto Evaluar

Esta guía es para quien ya tiene instalada una versión y quiere pasar a una nueva. Qué cambia en cada versión, si los componentes son compatibles entre sí y los pasos particulares de cada salto están en el [CHANGELOG](../CHANGELOG.md): **léelo antes de empezar**.

## Cómo se versiona

- El **plugin** y el **servicio** (`php-service/`; antes, el servicio FastAPI) comparten número de versión (por ejemplo `0.2.0`) y se publican juntos. **Actualiza siempre los dos.**
- El plugin tiene además un número interno que Moodle usa para saber si debe actualizarse: `$plugin->version`, por ejemplo `2026110301`.
- Cada evaluación guarda en su `.json` con qué versión del servicio (`version_servicio`) y del plugin (`version_plugin`) se hizo. Así se pueden comparar resultados entre versiones.

### Qué versión tengo instalada

| Componente | Dónde verlo |
|---|---|
| Plugin | Administración del sitio → Extensiones → Vista general de extensiones → buscar «aiocr». O por terminal: `php admin/cli/cfg.php --component=assignfeedback_aiocr --name=version` |
| Servicio | Abrir `http://127.0.0.1:8000/`: responde con `"version"`. Si no aparece ese campo, es la 0.1.0 (`RESTmain.py`) |

## Qué se conserva al actualizar

| Dato | ¿Se conserva? |
|---|---|
| Ajustes del plugin (URL, timeout, clave) | Sí. Solo los cambia `db/upgrade.php` cuando el CHANGELOG lo indica |
| Comentarios de retroalimentación ya guardados en las entregas | Sí |
| Historial de solicitudes (tabla `assignfeedback_aiocr`) | Sí |
| `.env` del servicio y `docker/.env` | Sí: nunca se sobrescriben. Las variables nuevas hay que agregarlas a mano |
| Transcripciones en `data/transcripciones/` | Sí, hasta que cumplen `TRANSCRIPTIONS_DAYS` |

## Antes de actualizar

1. **Lee el CHANGELOG** de cada versión entre la tuya y la nueva. Fíjate en la tabla de compatibilidad y en «Al actualizar».
2. **Respalda la base de datos de Moodle.** Es lo único que permite volver atrás (ver «Volver a la versión anterior»).

   | Modalidad | Comando |
   |---|---|
   | Paquete local de Windows | `<CARPETA_MOODLE>\server\mysql\bin\mysqldump.exe -u root -p moodle > respaldo_moodle.sql` (con contraseña vacía, solo pulsa Enter) |
   | Docker (desde `docker/`) | `docker compose exec db sh -c 'mariadb-dump -u root -p"$MARIADB_ROOT_PASSWORD" moodle' > respaldo_moodle.sql` |
   | Moodle institucional | El procedimiento de respaldo del área de TI |

3. Copia tu `.env` (y `docker/.env`) a un lugar seguro.

El script de despliegue respalda solo el **código** del plugin en `dist/backups/`. La base de datos no la respalda.

## Actualizar

### Modalidad 1: Moodle local en Windows

1. Trae la versión nueva: `git pull`, o descarga el repositorio.
2. Instala el plugin y actualiza Moodle:

   ```bat
   "<CARPETA_MOODLE>\server\php\php.exe" scripts\deploy_plugin.php --moodle-dir "<CARPETA_MOODLE>\server\moodle" --upgrade --purge-caches
   ```

   El script muestra `Versión instalada X -> nueva Y`. Si la versión del repositorio es **anterior** a la instalada, se detiene sin tocar nada.
3. Si hay ajustes nuevos, Moodle los registra. Revísalos en Administración del sitio → Extensiones → Módulos de actividades → Tarea → Extensiones de retroalimentación → Auto Evaluar.
4. Compara `php-service\.env` con `.env.example` y agrega las variables nuevas que indique el CHANGELOG. Si falta una obligatoria, `run.bat` no enciende el servicio y te dice cuál es.
5. Reinicia el servicio (Ctrl+C y `run.bat`) para que vuelva a revisar la configuración. El servicio PHP no tiene dependencias que instalar.

> **Si vienes de la versión en Python** (`fastapi-service\`): copia tu `fastapi-service\.env` a `php-service\.env`, y agrega `PHP_BIN` con la ruta de `php.exe` ([guía local, paso 8](instalacion-local-windows.md#8-servicio-php)). Imagick y Ghostscript son opcionales: sin ellos los PDF se envían enteros a Gemini. Desde ese momento se usa `php-service\run.bat`. El plugin no cambia.

### Modalidad 2: Docker

Desde `docker/`:

1. `git pull`.
2. Compara `docker/.env` con `docker/.env.example` y agrega lo nuevo. Si falta una variable obligatoria, `docker compose` se detiene con «Define … en docker/.env».
3. Reconstruye y reinicia:

   ```bash
   docker compose up -d --build --remove-orphans
   ```

   `--remove-orphans` quita el contenedor `fastapi` si vienes de la versión en Python. Al arrancar, el contenedor `moodle` ejecuta la actualización de Moodle (`db/upgrade.php`) y vuelve a aplicar la configuración del plugin, incluida la clave. No hay pasos manuales en Moodle.
4. Comprueba con `docker compose logs moodle` (busca `assignfeedback_aiocr`) y con `bash docker/pruebas/prueba_e2e.sh`.

Los cursos, los usuarios y las entregas viven en los volúmenes `dbdata` y `moodledata`, así que no se pierden al reconstruir. Solo `docker compose down -v` los borra.

### Modalidad 3: Moodle existente

1. **Plugin:** genera el zip nuevo (`php scripts/package_plugin.php`) y súbelo en **Instalar complementos**. Moodle detecta que es una actualización. Otra opción es reemplazar la carpeta `<moodle>/mod/assign/feedback/aiocr` (opción B de la [guía](instalacion-plugin.md)). Después, **Notificaciones** → «Actualizar base de datos Moodle ahora».
2. **Servicio:** reemplaza `src/`, `public/`, `bin/` y `prompts/` en el servidor. **No reemplaces `.env`.** Agrega las variables nuevas al `.env`, ejecuta `php bin/comprobar.php` y reinicia (`sudo systemctl restart aiocr-servicio`, o recarga PHP-FPM). Si vienes de la versión en Python, instala el servicio PHP como en el [paso 7 de la guía](instalacion-plugin.md#7-instala-el-servicio-php) reutilizando tu `.env`, y después apaga el servicio FastAPI.

### En qué orden

El CHANGELOG lo indica en cada versión. La regla general: **primero el componente que agrega algo que el otro va a necesitar.**

Mientras actualizas, el botón puede mostrar un error, por ejemplo «El servicio FastAPI rechazó la clave». Eso no daña nada: no se modifica ninguna entrega ni ninguna nota. Para evitar ese intervalo, actualiza fuera del horario de uso.

## Después de actualizar

1. Comprueba las dos versiones (ver «Qué versión tengo instalada»).
2. Evalúa una entrega de prueba.
3. Revisa que el `.json` de esa evaluación (en `data/transcripciones/tarea_<id>/`) tenga las versiones nuevas.

## Volver a la versión anterior

**Moodle no permite instalar una versión anterior de un plugin encima de una más nueva.** Si se intenta, bloquea **todo el sitio** con «Cannot downgrade assignfeedback_aiocr from X to Y» hasta que se repone el código nuevo. Por eso `deploy_plugin.php` se niega a copiar código más antiguo que el instalado.

Para volver de verdad:

1. Restaura la base de datos con el respaldo que hiciste antes de actualizar. Esto deshace también todo lo que pasó en Moodle después de esa hora.
2. Restaura el código del plugin, desde `dist/backups/aiocr_<fecha>` o desde la versión anterior del repositorio (`git checkout v0.1.0`):

   ```bat
   php scripts\deploy_plugin.php --moodle-dir "<moodle>" --permitir-retroceso --purge-caches
   ```

3. Vuelve a la versión anterior del servicio y a su `.env` respaldado, y reinícialo.

Si solo hay un problema con una versión nueva, casi siempre es más simple corregir hacia adelante (una versión nueva) que volver atrás.

## De 0.4.0 a 0.5.0: pasar al modo autónomo

Actualiza el plugin como siempre (arriba). El proveedor no cambia solo. Para dejar de usar el servicio: Administración del sitio → Extensiones → Tarea → Extensiones de retroalimentación → Auto Evaluar → Proveedor de IA «Gemini (directo, sin servicio externo)» y la clave de Gemini. Prueba una entrega y después apaga el servicio. Ver [instalacion-autonoma.md](instalacion-autonoma.md).

## Para quien publica una versión nueva

1. Sube `$plugin->version` (formato `AAAAMMDDXX`) y `$plugin->release` en `moodle-plugin/aiocr/version.php`, y `Version::VERSION` en `php-service/src/Version.php` al mismo `release`.
2. Si cambian ajustes guardados o tablas, agrega un paso al **final** de `db/upgrade.php` con su `upgrade_plugin_savepoint`. **No modifiques pasos anteriores.** Si cambian tablas, actualiza también `db/install.xml`.
3. Escribe la entrada en `CHANGELOG.md`: cambios, tabla de compatibilidad y pasos de «Al actualizar».
4. Si agregas variables al `.env`, ponlas en `.env.example` (y en `docker/.env.example` y `docker-compose.yml` si aplica) con un valor por defecto siempre que se pueda.
5. Corre las pruebas (`composer install` y `vendor/bin/phpunit`). Verifican, entre otras cosas, que las versiones coincidan, que el CHANGELOG mencione la versión y que `db/upgrade.php` no apunte a una versión mayor que la del plugin.
6. Prueba la actualización desde la versión anterior, no solo una instalación nueva.
7. Genera el zip (`php scripts/package_plugin.php`) y marca la versión en git: `git tag v0.2.0`.
