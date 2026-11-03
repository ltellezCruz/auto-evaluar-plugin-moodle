# Auto Evaluar

Plugin de retroalimentación para la actividad Tarea de Moodle 4.5 (`assignfeedback_aiocr`). Envía la entrega de un estudiante a un servicio FastAPI, que transcribe el trabajo manuscrito y lo evalúa con Gemini. El resultado se guarda como comentario de retroalimentación.

Trabajo de grado. Estado: prototipo (madurez ALPHA). Licencia: GPLv3 (ver `LICENSE`), como exige Moodle para sus plugins.

## Instalación

| Modalidad | Para quién | Guía |
|---|---|---|
| 1. Moodle local en Windows | Probar todo en un equipo con el paquete oficial de Moodle | [docs/instalacion-local-windows.md](docs/instalacion-local-windows.md) |
| 2. Docker | Levantar Moodle, base de datos y FastAPI en contenedores | [docs/instalacion-docker.md](docs/instalacion-docker.md) |
| 3. Moodle existente | Instalar el plugin en un Moodle propio (Windows, Linux o macOS) | [docs/instalacion-plugin.md](docs/instalacion-plugin.md) |

## Estructura

```
auto-evaluar/
├── moodle-plugin/aiocr/   código fuente del plugin (única fuente de verdad)
├── fastapi-service/       servicio que habla con Moodle y Gemini (con sus pruebas en tests/)
├── docker/                entorno completo en contenedores y prueba de punta a punta
├── scripts/               despliegue y empaquetado del plugin
├── tests/                 pruebas de los scripts
├── tools/monitor/         medición de recursos para los experimentos
├── docs/                  guías de instalación y manual
└── data/                  transcripciones y mediciones locales (no se versiona)
```

La carpeta `<moodle>/mod/assign/feedback/aiocr/` es solo el destino del despliegue: el código se edita aquí y se copia allí con el script de `scripts/`.

## Desplegar el plugin en Moodle

`scripts/deploy_plugin.py` (Python 3.8+, sin dependencias) borra la copia instalada, guarda un respaldo en `dist/backups/` y copia `moodle-plugin/aiocr/` a Moodle. No toca nada si el destino no es el plugin `assignfeedback_aiocr`.

`<moodle>` es la carpeta de código de Moodle, la que contiene `version.php` y `lib/`. En el paquete de Windows es `<CARPETA_MOODLE>\server\moodle`.

| Escenario | Comando |
|---|---|
| Windows o Linux, carpeta local | `python scripts/deploy_plugin.py --moodle-dir "<moodle>"` |
| Lo mismo y actualizar Moodle | `python scripts/deploy_plugin.py --moodle-dir "<moodle>" --php "<CARPETA_MOODLE>\server\php\php.exe" --upgrade` |
| Linux con Apache | `sudo python3 scripts/deploy_plugin.py --moodle-dir /var/www/moodle --owner www-data --upgrade` |
| Docker, Moodle dentro de la imagen | `python scripts/deploy_plugin.py --container <contenedor> --owner www-data --upgrade` |
| Docker con volumen montado (moodle-docker) | `python scripts/deploy_plugin.py --moodle-dir <carpeta montada> --container <contenedor> --upgrade` |

Otras opciones:
- `--purge-caches`: purga las cachés después de copiar (hace falta si cambiaste plantillas o textos).
- `--container-path`: ruta de Moodle dentro del contenedor (por defecto `/var/www/html`).
- `--dry-run`: muestra los pasos sin ejecutarlos.
- La variable de entorno `MOODLE_DIR` reemplaza a `--moodle-dir`.

Sin `--upgrade`, después de cambiar `db/` o `version.php` hay que entrar a **Administración del sitio → Notificaciones**.

## Empaquetar el plugin

```bash
python scripts/package_plugin.py
```

Genera `dist/assignfeedback_aiocr_<release>_<version>.zip`, con la carpeta `aiocr/` en la raíz. Ese zip se instala desde **Administración del sitio → Extensiones → Instalar complementos**, o se descomprime en `<moodle>/mod/assign/feedback/`. Antes de repartir una versión nueva, sube `$plugin->version` (y `release`) en `version.php`.

## Servicio FastAPI

```
fastapi-service/
├── app/
│   ├── main.py            endpoints y pipeline (descarga → imágenes → Gemini en 2 pasos)
│   ├── config.py          lee la configuración de .env / variables de entorno
│   ├── moodle_client.py   API REST de Moodle y descarga con token
│   ├── gemini_client.py   llamadas a Gemini y registro de tokens consumidos
│   └── pdf.py             PDF → una imagen por página
├── prompts/
│   ├── extraer.txt        paso 1: transcripción
│   └── evaluar.txt        paso 2: evaluación (la transcripción se pega al final)
├── requirements.txt
├── .env.example
└── run.bat / run.sh
```

Requiere Python 3.10 o superior.

1. Instalar dependencias (en un entorno virtual, recomendado):

   ```bash
   cd fastapi-service
   python -m venv .venv
   .venv\Scripts\pip install -r requirements.txt      # Windows
   .venv/bin/pip install -r requirements.txt          # Linux
   ```

2. Copiar `.env.example` como `.env` y completar al menos `MOODLE_TOKEN`, `GEMINI_API_KEY` y `SERVICE_API_KEY` (clave que también va en «FastAPI API key» del plugin). Si la dirección oficial de Moodle (`wwwroot`) es distinta de `MOODLE_URL` (ngrok, Docker), definir `MOODLE_PUBLIC_URL`. El servicio solo envía el token a los hosts permitidos (`ALLOWED_FILE_HOSTS`).

3. Arrancar con `run.bat` (Windows) o `./run.sh` (Linux). Escucha en `http://127.0.0.1:8000`; `http://127.0.0.1:8000/` debe responder «Servidor FastAPI corriendo correctamente».

Cada evaluación queda en `data/transcripciones/tarea_<id>/usuario_<id>_<fecha>.txt` más un `.json` con páginas, tokens por llamada, duración y resultado (fuera de git). Se borran a los `TRANSCRIPTIONS_DAYS` días. Los prompts se pueden editar sin tocar el código; basta con reiniciar el servicio.

Seguridad y límites (todo en `.env`):
- Solo el plugin puede usar el servicio: cada solicitud lleva `SERVICE_API_KEY` en la cabecera `X-Aiocr-Key` (si no, 401).
- El token de Moodle solo se envía a los hosts permitidos y a la ruta `/webservice/pluginfile.php/`.
- `MAX_FILE_MB`, `MAX_PAGES` y `MAX_CONCURRENT` limitan el tamaño del archivo, las páginas por PDF y las evaluaciones simultáneas.
- `/docs` está apagado salvo con `ENABLE_DOCS=true`.

> Aunque pida clave, el servicio no usa HTTPS: déjalo en `127.0.0.1` (valor por defecto de `run.bat`) o detrás de un firewall.

## Pruebas

Sin internet ni gasto de Gemini (Moodle y Gemini simulados):

```bash
python -m unittest discover -s tests -v                              # scripts de despliegue y empaquetado
cd fastapi-service && python -m unittest discover -s tests -v        # servicio FastAPI
```

De punta a punta en el entorno Docker encendido (crea un curso y una entrega, y llama a Gemini de verdad):

```bash
bash docker/pruebas/prueba_e2e.sh
```

## Mediciones de recursos

`tools/monitor/monitor.py` registra cada segundo la CPU, la RAM, el disco y la red del equipo y de los procesos de Moodle y FastAPI mientras se hacen evaluaciones.

```bash
pip install -r tools/monitor/requirements.txt
python tools/monitor/monitor.py --etiqueta E1_flash_lite --notas "10 PDF de 2 páginas"
```

Se detiene con Ctrl+C. Guarda `data/mediciones/<fecha>_<hora>_<etiqueta>.csv` y un `.json` con los datos del experimento (etiqueta, notas, inicio, fin, número de filas y equipo). Nunca sobrescribe una medición anterior.

Los procesos se reconocen en Windows (paquete de Moodle) y en Linux. Con Docker Desktop, los contenedores corren en una máquina virtual: solo valen las columnas del equipo completo.
