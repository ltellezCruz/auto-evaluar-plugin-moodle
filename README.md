# Auto Evaluar

Plugin de retroalimentación para la actividad Tarea de Moodle 4.5 (`assignfeedback_aiocr`). Transcribe con Gemini el trabajo de un estudiante (PDF, imágenes o texto en línea), lo evalúa y guarda el resultado como comentario de retroalimentación.

Trabajo de grado. Estado: prototipo (madurez ALPHA). Licencia: GPLv3 (ver `LICENSE`), como exige Moodle para sus plugins.

> **Desde la 0.7.0 se puede usar un modelo propio, como Gemma 4 31B con Ollama, sin claves** (para pruebas): [guía](docs/modelo-local-gemma.md).
>
> **Desde la 0.6.0 la evaluación sigue la rúbrica del profesor:** la de Moodle, un contexto escrito o archivos (rúbrica, solucionario), preparados una vez con «Preparar rúbrica con IA» ([guía, paso 6](docs/instalacion-autonoma.md#6-opcional-rúbrica-y-contexto-del-profesor)).
>
> **Desde la 0.5.0 el plugin es autónomo:** con el proveedor «Gemini (directo)» hace todo dentro de Moodle y basta con instalar el zip y poner la clave de Gemini ([guía](docs/instalacion-autonoma.md)). El servicio `php-service/` queda como proveedor opcional.
>
> **Desde la 0.4.0 todo el proyecto está en PHP.** El servicio FastAPI (Python) se reemplazó por `php-service/`, y los scripts, las pruebas y el monitor también pasaron a PHP. El plugin no cambió: el servicio nuevo responde igual que el anterior. La última versión en Python es el tag `v0.3.0`.

## Instalación

| Modalidad | Para quién | Guía |
|---|---|---|
| **Rápida: solo el plugin** | Cualquier Moodle 4.5+: el plugin hace todo (Gemini directo), sin servicio | [docs/instalacion-autonoma.md](docs/instalacion-autonoma.md) |
| 1. Moodle local en Windows | Probar todo en un equipo con el paquete oficial de Moodle | [docs/instalacion-local-windows.md](docs/instalacion-local-windows.md) |
| 2. Docker | Levantar Moodle, base de datos y el servicio en contenedores | [docs/instalacion-docker.md](docs/instalacion-docker.md) |
| 3. Moodle existente | Instalar el plugin y el servicio externo en un Moodle propio (Windows, Linux o macOS) | [docs/instalacion-plugin.md](docs/instalacion-plugin.md) |

**¿Ya tienes una versión instalada?** Mira el [CHANGELOG](CHANGELOG.md) y la guía de [actualización](docs/actualizacion.md). El plugin y el servicio comparten número de versión y se actualizan juntos.

## Estructura

```
auto-evaluar/
├── moodle-plugin/aiocr/   código fuente del plugin (única fuente de verdad)
├── php-service/           servicio externo opcional (proveedor «Servicio externo»), con sus pruebas
├── docker/                entorno completo en contenedores y prueba de punta a punta
├── scripts/               despliegue y empaquetado del plugin
├── tests/                 pruebas de los scripts, las versiones, el plugin y el monitor
├── tools/monitor/         medición de recursos para los experimentos
├── docs/                  guías de instalación y manual
├── composer.json          solo para las pruebas (PHPUnit)
└── data/                  transcripciones y mediciones locales (no se versiona)
```

Todo se ejecuta con PHP 8.1 o superior. En Windows sirve el PHP que trae el paquete de Moodle: `<CARPETA_MOODLE>\server\php\php.exe`. En los comandos de abajo, `php` es esa ruta si `php` no está en el PATH.

La carpeta `<moodle>/mod/assign/feedback/aiocr/` es solo el destino del despliegue: el código se edita aquí y se copia allí con el script de `scripts/`.

## Desplegar el plugin en Moodle

`scripts/deploy_plugin.php` (sin dependencias) borra la copia instalada, guarda un respaldo en `dist/backups/` y copia `moodle-plugin/aiocr/` a Moodle. No toca nada si el destino no es el plugin `assignfeedback_aiocr`.

`<moodle>` es la carpeta de código de Moodle, la que contiene `version.php` y `lib/`. En el paquete de Windows es `<CARPETA_MOODLE>\server\moodle`.

| Escenario | Comando |
|---|---|
| Windows o Linux, carpeta local | `php scripts/deploy_plugin.php --moodle-dir "<moodle>"` |
| Lo mismo y actualizar Moodle | `php scripts/deploy_plugin.php --moodle-dir "<moodle>" --upgrade` |
| Linux con Apache | `sudo php scripts/deploy_plugin.php --moodle-dir /var/www/moodle --owner www-data --upgrade` |
| Docker, Moodle dentro de la imagen | `php scripts/deploy_plugin.php --container <contenedor> --owner www-data --upgrade` |
| Docker con volumen montado (moodle-docker) | `php scripts/deploy_plugin.php --moodle-dir <carpeta montada> --container <contenedor> --upgrade` |

Con el paquete de Windows, por ejemplo:

```
"<CARPETA_MOODLE>\server\php\php.exe" scripts\deploy_plugin.php --moodle-dir "<CARPETA_MOODLE>\server\moodle" --upgrade --purge-caches
```

Otras opciones:
- `--purge-caches`: purga las cachés después de copiar (hace falta si cambiaste plantillas o textos).
- `--php`: PHP para `upgrade.php` y `purge_caches.php`. Por defecto, el mismo que ejecuta el script.
- `--container-path`: ruta de Moodle dentro del contenedor (por defecto `/var/www/html`).
- `--dry-run`: muestra los pasos sin ejecutarlos.
- La variable de entorno `MOODLE_DIR` reemplaza a `--moodle-dir`.

Sin `--upgrade`, después de cambiar `db/` o `version.php` hay que entrar a **Administración del sitio → Notificaciones**.

## Empaquetar el plugin

```bash
php scripts/package_plugin.php
```

Genera `dist/assignfeedback_aiocr_<release>_<version>.zip`, con la carpeta `aiocr/` en la raíz (usa la extensión `zip` de PHP). Ese zip se instala desde **Administración del sitio → Extensiones → Instalar complementos**, o se descomprime en `<moodle>/mod/assign/feedback/`. Antes de repartir una versión nueva, sube `$plugin->version` (y `release`) en `version.php`.

## Servicio PHP

```
php-service/
├── public/index.php       rutas HTTP (/, /moodle/info, /ia/generar-retroalimentacion)
├── src/
│   ├── Servicio.php       pipeline (descarga → imágenes → Gemini en 2 pasos) y validación
│   ├── Config.php         lee la configuración de .env / variables de entorno
│   ├── MoodleClient.php   API REST de Moodle y descarga con token (curl)
│   ├── GeminiClient.php   llamadas a Gemini y registro de tokens consumidos (curl)
│   ├── PdfImagick.php     PDF → una imagen por página (Imagick + Ghostscript)
│   ├── PdfPaginas.php     cuenta las páginas sin Imagick (PDF_MODO=directo)
│   ├── Transcripciones.php  registro en disco y retención
│   └── Cupos.php          límite de evaluaciones simultáneas (candados de archivo)
├── bin/comprobar.php      revisa la configuración antes de encender
├── prompts/
│   ├── extraer.txt        paso 1: transcripción
│   └── evaluar.txt        paso 2: evaluación (la transcripción se pega al final)
├── .env.example
├── Dockerfile
└── run.bat / run.sh
```

Equivalencias con la versión en Python:

| Python (FastAPI) | PHP |
|---|---|
| FastAPI + uvicorn | `public/index.php` con el servidor de PHP (`php -S`) o Apache |
| Pydantic | `Servicio::validarSolicitud` (mismos límites; errores 422 con el mismo formato) |
| requests | extensión `curl` |
| PyMuPDF | el PDF entero a Gemini (`PDF_MODO=directo`, sin extensiones) o la extensión `imagick` + Ghostscript (`PDF_MODO=imagen`) |
| `threading.BoundedSemaphore` | `Cupos` (candados `flock`) |
| unittest | PHPUnit |
| psutil (monitor) | contadores de Windows por PowerShell / `/proc` en Linux |

### Requisitos

- PHP 8.1 o superior con las extensiones `curl`, `json` y `mbstring` (el PHP del paquete de Moodle ya las trae).
- Para los PDF hay dos modos (`PDF_MODO` en el `.env`):
  - **`directo`:** el PDF se envía entero a Gemini, una llamada por archivo, y la transcripción se separa por páginas. **No necesita nada más.** Es el que se usa si falta Imagick (`PDF_MODO=auto`, el valor por defecto).
  - **`imagen`:** una imagen por página, como hacía el servicio en Python. Necesita la extensión **`imagick`** y **Ghostscript** (abajo). Los tokens por página solo son comparables con las mediciones anteriores en este modo.
  - `bin/comprobar.php` dice al arrancar qué modo quedó activo.
- Solo para `PDF_MODO=imagen`, Imagick y Ghostscript:
  - **Windows:** instala Ghostscript de 64 bits ([ghostscript.com](https://ghostscript.com/releases/gsdnld.html)). Descarga `php_imagick` 3.7.0 para PHP 8.2, *Thread Safe*, x64 desde [pecl.php.net/package/imagick](https://pecl.php.net/package/imagick); copia `php_imagick.dll` en `<CARPETA_MOODLE>\server\php\ext` y los `CORE_RL_*.dll` en `<CARPETA_MOODLE>\server\php`. No hace falta tocar el `php.ini` (lo comparte el Apache de Moodle): `run.bat` carga la extensión solo para el servicio. Detalles en [docs/instalacion-local-windows.md](docs/instalacion-local-windows.md#81-imagick-y-ghostscript-para-los-pdf).
  - **Linux (Debian/Ubuntu):** `sudo apt install php-imagick ghostscript`. Si ImageMagick no deja leer PDF, cambia `rights="none"` por `rights="read"` en la línea `pattern="PDF"` de `/etc/ImageMagick-6/policy.xml`.
  - **Docker:** la imagen ya trae todo.

### Arranque

1. Copiar `.env.example` como `.env` y completar al menos `MOODLE_TOKEN`, `GEMINI_API_KEY` y `SERVICE_API_KEY` (clave que también va en «Clave del servicio FastAPI» del plugin; el nombre del ajuste no cambió). Si `php` no está en el PATH, poner la ruta en `PHP_BIN`. Si la dirección oficial de Moodle (`wwwroot`) es distinta de `MOODLE_URL` (ngrok, Docker), definir `MOODLE_PUBLIC_URL`. El servicio solo envía el token a los hosts permitidos (`ALLOWED_FILE_HOSTS`).

2. Arrancar con `run.bat` (Windows) o `./run.sh` (Linux). Primero revisa la configuración (`bin/comprobar.php`) y después escucha en `http://127.0.0.1:8000`; `http://127.0.0.1:8000/` debe responder «Servidor PHP corriendo correctamente».

Se procesan todos los archivos de la entrega (PDF, PNG o JPG, en orden) y el texto en línea; los demás tipos se omiten y quedan anotados. Cada evaluación queda en `data/transcripciones/tarea_<id>/usuario_<id>_<fecha>.txt` más un `.json` con páginas, tokens por llamada, duración y resultado (fuera de git). Se borran a los `TRANSCRIPTIONS_DAYS` días. Los prompts se pueden editar sin tocar el código, y no hace falta reiniciar: PHP los lee en cada solicitud (igual que el `.env`).

Seguridad y límites (todo en `.env`):
- Solo el plugin puede usar el servicio: cada solicitud lleva `SERVICE_API_KEY` en la cabecera `X-Aiocr-Key` (si no, 401).
- El token de Moodle solo se envía a los hosts permitidos y a la ruta `/webservice/pluginfile.php/`.
- `MAX_FILE_MB`, `MAX_PAGES` y `MAX_CONCURRENT` limitan el tamaño del archivo, las páginas por entrega y las evaluaciones simultáneas.
- `/docs` está apagado salvo con `ENABLE_DOCS=true`.

> Aunque pida clave, el servicio no usa HTTPS: déjalo en `127.0.0.1` (valor por defecto de `run.bat`) o detrás de un firewall.

> **Windows y evaluaciones simultáneas:** el servidor de PHP (`php -S`) atiende **una solicitud a la vez** en Windows. Si dos profesores evalúan a la vez, el segundo espera a que termine el primero (no recibe «ocupado»). En Linux, `run.sh` enciende varios procesos (`PHP_CLI_SERVER_WORKERS`) y en Docker lo atiende Apache, así que ahí sí se aplica `MAX_CONCURRENT`.

## Pruebas

Sin internet ni gasto de Gemini (Moodle y Gemini simulados con `php -S`). Necesitan [Composer](https://getcomposer.org/) para instalar PHPUnit:

```bash
composer install
vendor/bin/phpunit                          # todas
vendor/bin/phpunit --testsuite scripts      # despliegue, empaquetado, versiones, plugin y monitor
vendor/bin/phpunit --testsuite servicio     # servicio PHP
```

Las pruebas de la conversión real de PDF (`PdfImagickTest`) se saltan si falta la extensión `imagick`.

De punta a punta en el entorno Docker encendido (crea un curso y una entrega, y llama a Gemini de verdad):

```bash
bash docker/pruebas/prueba_e2e.sh
```

## Mediciones de recursos

`tools/monitor/monitor.php` registra cada segundo la CPU, la RAM, el disco y la red del equipo y de los procesos de Moodle y del servicio PHP mientras se hacen evaluaciones.

```bash
php tools/monitor/monitor.php --etiqueta E1_flash_lite --notas "10 PDF de 2 páginas"
```

Se detiene con Ctrl+C. Guarda `data/mediciones/<fecha>_<hora>_<etiqueta>.csv` y un `.json` con los datos del experimento (etiqueta, notas, inicio, fin, número de filas y equipo). Nunca sobrescribe una medición anterior.

Funciona en Windows (lee los contadores del sistema por PowerShell) y en Linux (lee `/proc`); macOS no está soportado. El servicio se reconoce porque su comando incluye `php-service`, como lo arrancan `run.bat` y `run.sh`. Con Docker Desktop, los contenedores corren en una máquina virtual: solo valen las columnas del equipo completo.

> Las columnas `python_cpu_pct`, `python_ram_mb` y `python_procesos` de las mediciones anteriores se llaman ahora `servicio_cpu_pct`, `servicio_ram_mb` y `servicio_procesos`. Las demás columnas no cambian.
