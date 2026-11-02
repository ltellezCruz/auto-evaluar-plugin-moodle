# Auto Evaluar

Plugin de retroalimentación para la actividad Tarea de Moodle 4.5 (`assignfeedback_aiocr`). Envía la entrega de un estudiante a un servicio FastAPI, que transcribe el trabajo manuscrito y lo evalúa con Gemini. El resultado se guarda como comentario de retroalimentación.

Trabajo de grado. Estado: prototipo (en reestructuración).

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
