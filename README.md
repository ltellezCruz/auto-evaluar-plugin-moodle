# Auto Evaluar

Plugin de retroalimentación para la actividad Tarea de Moodle 4.5 (`assignfeedback_aiocr`). Envía la entrega de un estudiante a un servicio FastAPI, que transcribe el trabajo manuscrito y lo evalúa con Gemini. El resultado se guarda como comentario de retroalimentación.

Trabajo de grado. Estado: prototipo (en reestructuración).

## Instalación

| Modalidad | Para quién | Guía |
|---|---|---|
| 1. Moodle local en Windows | Probar todo en un equipo con el paquete oficial de Moodle | [docs/instalacion-local-windows.md](docs/instalacion-local-windows.md) |
| 2. Docker | Levantar Moodle, base de datos y FastAPI en contenedores | [docs/instalacion-docker.md](docs/instalacion-docker.md) (sin probar aún) |
| 3. Moodle existente | Instalar el plugin en un Moodle propio (Windows, Linux o macOS) | En preparación |

## Estructura

```
auto-evaluar/
├── moodle-plugin/aiocr/   código fuente del plugin (única fuente de verdad)
├── fastapi-service/       servicio que habla con Moodle y Gemini
├── tools/                 herramientas de medición
├── scripts/               despliegue y empaquetado del plugin
├── docs/                  manual y documentación
└── data/                  resultados locales (no se versiona)
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

2. Copiar `.env.example` como `.env` y completar al menos `MOODLE_TOKEN` y `GEMINI_API_KEY`. Si Moodle se abre por ngrok, agregar ese dominio en `ALLOWED_FILE_HOSTS`: el servicio solo envía el token a los hosts de esa lista.

3. Arrancar con `run.bat` (Windows) o `./run.sh` (Linux). Escucha en `http://127.0.0.1:8000`; `http://127.0.0.1:8000/` debe responder «Servidor FastAPI corriendo correctamente».

Las transcripciones se guardan en `data/transcripciones/` (fuera de git). Los prompts se pueden editar sin tocar el código; basta con reiniciar el servicio.

> El servicio no pide autenticación. Arrancarlo con `run.bat 0.0.0.0` lo expone a la red y cualquiera que lo alcance puede gastar la cuota de Gemini.
