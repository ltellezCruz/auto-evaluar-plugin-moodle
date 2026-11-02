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
