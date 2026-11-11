# Registro de cambios

El plugin (`$plugin->release` en `moodle-plugin/aiocr/version.php`) y el servicio (`Version::VERSION` en `php-service/src/Version.php`; hasta la 0.3.0, `__version__` en `fastapi-service/app/__init__.py`) **comparten número de versión** y se actualizan juntos. Las pruebas verifican que coincidan.

Cada versión indica:
- **Compatibilidad:** con qué versión del otro componente funciona.
- **Al actualizar:** qué tiene que hacer quien ya tenía instalada la versión anterior.

Cómo actualizar paso a paso: [docs/actualizacion.md](docs/actualizacion.md).

## 0.5.0 (plugin `2026111000`): plugin autónomo, sin servicio intermedio

### Cambios

- **Nuevo proveedor «Gemini (directo, sin servicio externo)»** (`gemini_adapter`). El plugin hace todo dentro de Moodle:
  - lee los archivos de la entrega con la File API (sin token de servicios web ni descargas por HTTP);
  - envía cada PDF entero a Gemini (como `PDF_MODO=directo` del servicio) y cada imagen aparte, separa la transcripción por páginas y luego evalúa;
  - aplica los mismos límites: tamaño por archivo, páginas por entrega y evaluaciones simultáneas (candados de Moodle);
  - llama a Gemini con la clase `\curl` de Moodle, que respeta el proxy del sitio.
- La lógica viene del servicio PHP (`Servicio`, `GeminiClient`, `PdfPaginas` y los prompts) y vive en `classes/local/gemini/`. No depende de Moodle y tiene pruebas propias (`tests/PluginGeminiTest.php`). Los prompts están en `classes/local/gemini/prompts.php` (en PHP para que el servidor web no los sirva como archivos) y las pruebas verifican que sean iguales a los de `php-service/prompts/`.
- **Base de datos:** la tabla `assignfeedback_aiocr` gana los campos `transcription` y `tokens`. Con el proveedor autónomo se guardan ahí la transcripción y, en `rawresponse`, los metadatos (archivos, páginas, llamadas, tokens, duración, modelo, versión), en lugar de los archivos `data/transcripciones/` del servicio.
- **Ajustes nuevos:** clave y modelo de Gemini, tiempo de espera por llamada, tamaño máximo, páginas máximas, evaluaciones simultáneas y «Guardar transcripciones». Los del servicio quedan en la sección «Servicio externo (opcional)», con nombres visibles sin «FastAPI».
- **Privacidad:** se declaran los datos que salen de Moodle (Gemini o el servicio externo) y el campo de transcripción.
- **El servicio `php-service/` sigue disponible** como proveedor «Servicio externo (php-service)» y Docker lo sigue usando. Su versión sube a 0.5.0 sin cambios de código.
- Guía nueva: [docs/instalacion-autonoma.md](docs/instalacion-autonoma.md).

### Compatibilidad

| Plugin | Servicio | Resultado |
|---|---|---|
| 0.5.0 (Gemini directo) | ninguno | ✅ Probado en el Moodle local con Gemini real (texto en línea, PDF de 1 y 2 páginas, entrega mixta, PDF escaneado real, límites de páginas, tamaño y tipo) con el servicio apagado |
| 0.5.0 (Servicio externo) | 0.4.0 o 0.5.0 | ✅ Mismo contrato (el plugin envía además `fileid`, que el servicio ignora) |
| 0.4.0 | 0.5.0 | ✅ Mismo contrato |

### Al actualizar desde 0.4.0

1. **Plugin:** despliégalo y ejecuta la actualización de Moodle. Agrega los campos `transcription` y `tokens` (el historial se conserva) y los ajustes nuevos.
2. **El proveedor no cambia solo:** si usabas el servicio, sigues con él. Para pasar al modo autónomo: Proveedor de IA → «Gemini (directo, sin servicio externo)» y pon la clave de Gemini. Después puedes apagar el servicio; el token de servicios web y los cambios de «Seguridad HTTP» ya no hacen falta (puedes revertirlos).
3. **Instalaciones nuevas:** el proveedor por defecto es el autónomo.

## 0.4.0 (plugin `2026110900`): todo el proyecto en PHP

El código del plugin no cambia: solo sube su número de versión para ir a la par del servicio. La última versión con el servicio en Python es la 0.3.0 (tag `v0.3.0`).

### Cambios

- **El servicio FastAPI (Python) se reemplazó por `php-service/`.** Hace lo mismo y responde igual: las mismas rutas, la cabecera `X-Aiocr-Key`, el cuerpo y las respuestas (`detail` en los errores), las variables del `.env`, los prompts, los límites y el registro en `data/transcripciones/`.
  - HTTP: `public/index.php`, servido con `php -S` (`run.bat` y `run.sh`) o con Apache (Docker).
  - Descargas y Gemini: extensión `curl`.
  - **PDF: nuevo `PDF_MODO`.** Con `directo`, cada PDF se envía entero a Gemini en una sola llamada (sin convertirlo a imágenes) y la transcripción se separa por páginas con las marcas `=== PAGINA N ===` que pide `prompts/extraer_pdf.txt`; las páginas se cuentan en PHP (`PdfPaginas`) para aplicar `MAX_PAGES`. Con `imagen`, una imagen por página como antes, con la extensión `imagick` + Ghostscript (antes, PyMuPDF). `auto` (por defecto) usa `imagen` si está Imagick y si no, `directo`.
  - El registro `.json` anota `modo_pdf`; en modo directo `dpi` es `null` y la llamada de transcripción lleva el rango de páginas (`"1-2"`).
  - `MAX_CONCURRENT` usa candados de archivo. **En Windows, `php -S` atiende una solicitud a la vez**: las evaluaciones simultáneas esperan su turno en vez de recibir 503.
  - `bin/comprobar.php` revisa la configuración antes de encender y dice qué modo de PDF quedó activo (y avisa si `PDF_MODO=imagen` no puede convertir).
  - El `.env` y los prompts se leen en cada solicitud: cambiarlos ya no exige reiniciar.
  - `/` responde «Servidor PHP corriendo correctamente». `/docs` (con `ENABLE_DOCS=true`) es una descripción en JSON, no Swagger.
  - Si Moodle responde con un código de error al descargar, el mensaje ya no incluye la URL (antes llevaba el token).
- **Scripts:** `scripts/deploy_plugin.php` y `scripts/package_plugin.php` reemplazan a los `.py`, con las mismas opciones. `--php` toma por defecto el PHP que ejecuta el script.
- **Pruebas:** PHPUnit (`composer install` y `vendor/bin/phpunit`) en lugar de unittest. Las de Moodle y Gemini simulados usan `php -S`.
- **Monitor:** `tools/monitor/monitor.php` reemplaza a `monitor.py` (sin psutil: contadores de Windows por PowerShell y `/proc` en Linux; macOS no). Las columnas `python_*` pasan a `servicio_*`.
- **Docker:** el contenedor `fastapi` pasa a llamarse `servicio` (imagen `php:8.2-apache` con Imagick y Ghostscript). La variable del puerto es `SERVICIO_PORT` (sigue sirviendo `FASTAPI_PORT`).
- **El plugin no cambia.** Sus ajustes conservan los nombres «FastAPI» (`fastapi_url`, `fastapi_apikey`, `fastapi_timeout`) para no tocar la base de datos.

### Nuevas variables del `.env` (opcionales)

| Variable | Para qué |
|---|---|
| `PHP_BIN` | Ruta de `php.exe` que usa `run.bat` / `run.sh` si `php` no está en el PATH |
| `TIMEZONE` | Zona horaria de las fechas del registro (por defecto `America/Bogota`) |
| `PDF_MODO` | `auto` (por defecto), `directo` o `imagen`: cómo se envían los PDF a Gemini |

### Compatibilidad

| Plugin | Servicio | Resultado |
|---|---|---|
| 0.4.0 | 0.4.0 (PHP) | ✅ Pruebas automáticas, y contra el Moodle local y Gemini reales en modo `directo`: texto en línea, PDF de 1 y 2 páginas, entrega mixta, límites de páginas y de tamaño) |
| 0.3.0 | 0.4.0 (PHP) | ✅ Mismo contrato que el servicio 0.3.0 en Python |
| 0.4.0 | 0.3.0 (Python) | ✅ Mismo contrato |
| 0.2.0 | 0.4.0 (PHP) | ✅ Igual que con el servicio 0.3.0 |

### Al actualizar desde 0.3.0 (Python)

1. **Plugin:** despliega la 0.4.0 y ejecuta la actualización de Moodle (no cambia ajustes ni tablas; solo el número de versión).
2. **Servicio:** copia `fastapi-service/.env` a `php-service/.env` y agrega `PHP_BIN` si hace falta. Imagick y Ghostscript son opcionales ([guía local, paso 8](docs/instalacion-local-windows.md#8-servicio-php)): sin ellos, los PDF van enteros a Gemini. Arranca con `php-service/run.bat` (o `run.sh`) en lugar del de `fastapi-service/`.
3. **Docker:** `docker compose up -d --build --remove-orphans` (quita el contenedor `fastapi`).
4. **Mediciones:** si comparas CSV viejos con nuevos, renombra las columnas `python_*` como `servicio_*`. Los tokens por página solo son comparables con los de Python en `PDF_MODO=imagen`.

## 0.3.0 (plugin `2026110400`)

### Cambios

- **Entregas completas:** el servicio procesa **todos los archivos** de la entrega, en orden, y el **texto en línea**.
  - Los archivos que no son PDF ni imagen (por ejemplo un `.docx`) se omiten y quedan anotados en el `.json`, sin cancelar la evaluación de los demás.
  - `MAX_PAGES` cuenta las páginas de toda la entrega.
  - Una entrega con solo texto en línea también se evalúa.
- **Seguridad (XSS):**
  - El texto generado por la IA ya no se muestra sin limpiar. Antes, el resumen de la tabla de calificaciones y del estado de la entrega del estudiante insertaba el HTML tal cual, y un estudiante podía inducir a la IA a devolver código.
  - Ahora el resumen dice solo «Retroalimentación con IA generada el …» como texto, y la vista completa pasa por `format_text`.
  - Los mensajes del botón se insertan como texto (`textContent`).
- **Corrección:** una respuesta del servicio con `success: false` (por ejemplo, una página que no se pudo transcribir) se mostraba como «Retroalimentación generada» sin texto. Ahora se muestra como error.
- **Idiomas:** todos los textos están en `lang/` (incluidos los del JavaScript y los errores); `lang/en` queda en inglés y hay un `lang/es` nuevo, en español.
- **Proveedor «Prueba (sin IA)»** (`mock_adapter`): responde con un texto fijo sin llamar al servicio, para probar el plugin sin gastar cuota.
- **Pantalla de calificación:** ya no se repite la retroalimentación junto al botón (está en «Comentarios de retroalimentación»); solo se indica cuándo se generó.
- **Texto en línea:** se convierte con `html_to_text`, que conserva los párrafos.

### Compatibilidad

| Plugin | Servicio | Resultado |
|---|---|---|
| 0.3.0 | 0.3.0 | ✅ |
| 0.3.0 | 0.2.0 | ✅ Funciona, pero el servicio evalúa solo el primer archivo |
| 0.2.0 | 0.3.0 | ✅ Funciona; el plugin 0.2.0 ya envía todos los archivos y el texto. Sin las correcciones de seguridad del plugin |

### Al actualizar desde 0.2.0

1. **Plugin:** Notificaciones (o `--upgrade`). No hay pasos de base de datos ni ajustes nuevos. Purga las cachés para que se carguen los idiomas y la plantilla.
2. **Servicio:** reemplaza `app/` y reinicia. No hay variables nuevas.
3. **Recomendado:** actualiza el plugin cuanto antes por la corrección de seguridad.

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
