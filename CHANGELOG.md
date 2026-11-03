# Registro de cambios

El plugin (`$plugin->release` en `moodle-plugin/aiocr/version.php`) y el servicio FastAPI (`__version__` en `fastapi-service/app/__init__.py`) **comparten número de versión** y se actualizan juntos. Las pruebas verifican que coincidan.

Cada versión indica:
- **Compatibilidad:** con qué versión del otro componente funciona.
- **Al actualizar:** qué tiene que hacer quien ya tenía instalada la versión anterior.

Cómo actualizar paso a paso: [docs/actualizacion.md](docs/actualizacion.md).

## 0.2.0 (plugin `2026110301`)

### Cambios

- **Seguridad:**
  - FastAPI exige una clave compartida (`SERVICE_API_KEY`, cabecera `X-Aiocr-Key`); el plugin tiene el ajuste nuevo «FastAPI API key».
  - El token de Moodle solo se envía a `/webservice/pluginfile.php/` de los hosts permitidos.
  - Solo se aceptan PDF, PNG y JPG.
  - `/docs` está apagado; se eliminó `/moodle/cursos`.
- **Límites:** `MAX_FILE_MB`, `MAX_PAGES` y `MAX_CONCURRENT`.
- **Transcripciones:** se guardan en `tarea_<id>/usuario_<id>_<fecha>.txt` más un `.json` con las versiones, páginas, tokens, duración y resultado. Se borran a los `TRANSCRIPTIONS_DAYS` días.
- **Descargas por la red interna:** `MOODLE_PUBLIC_URL` (Docker y ngrok).
- **Timeout:** el del plugin pasa de 30 s a 240 s.
- **Nombre:** el plugin se muestra como «Auto Evaluar».
- **Versiones visibles:** el servicio informa su versión en `/` y registra la del plugin en cada evaluación.

### Compatibilidad

| Plugin | Servicio | Resultado |
|---|---|---|
| 0.2.0 | 0.2.0 | ✅ |
| 0.1.0 | 0.2.0 | ❌ El servicio responde 401: el plugin viejo no envía la clave |
| 0.2.0 | 0.1.0 (`RESTmain.py`) | ⚠️ Funciona, pero sin ninguna de las protecciones nuevas |

**Actualiza los dos.** El orden recomendado es primero el plugin y después el servicio, así no hay un momento en que el servicio pida una clave que el plugin aún no envía.

### Al actualizar desde 0.1.0

1. **Plugin:**
   - Al pasar por Notificaciones, Moodle ejecuta `db/upgrade.php`. Ese paso sube el timeout a 240 s si seguía en 30 (o vacío) y respeta cualquier otro valor que hayas elegido.
   - Moodle muestra el ajuste nuevo **«FastAPI API key»**. Ponle la misma clave que en el paso 2.
   - Si la URL de FastAPI era la IP de ejemplo `http://192.168.1.144:8000`, revísala: el valor por defecto ahora es `http://127.0.0.1:8000`, pero un valor ya guardado no se cambia.
2. **Servicio:**
   - Ya no es `RESTmain.py`: es la carpeta `fastapi-service/` y se arranca con `run.bat` o `run.sh`.
   - Las claves pasan del código a `.env`. Variables **obligatorias**: `MOODLE_TOKEN`, `GEMINI_API_KEY` y `SERVICE_API_KEY`. Las demás tienen valores por defecto (ver `.env.example`).
   - Si Moodle se abre por ngrok, agrega `MOODLE_PUBLIC_URL`.
3. **Transcripciones antiguas:** los archivos `<usuario>.txt` de la versión anterior no se mueven. Si los copias a `data/transcripciones/`, la limpieza automática los borra a los `TRANSCRIPTIONS_DAYS` días de su última modificación.
4. **Base de datos:** no hay cambios de tablas. El historial de la tabla `assignfeedback_aiocr` se conserva.

## 0.1.0 (plugin `2026091700`)

Primera versión funcional, previa a la reestructuración del repositorio. Botón «Auto Evaluar» en la pantalla de calificación, servicio `RESTmain.py` con Gemini en dos pasos (transcripción y evaluación) y comentario guardado sin cambiar la nota.
