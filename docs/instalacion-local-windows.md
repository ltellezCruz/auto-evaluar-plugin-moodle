# Modalidad 1: Moodle local en Windows con el paquete oficial

Esta guía arma todo el entorno en un solo equipo Windows: Moodle 4.5 (paquete oficial con Apache, MariaDB y PHP), el plugin Auto Evaluar y el servicio FastAPI.

Al terminar tendrás:

```
 Navegador ──▶ http://localhost  (Moodle + plugin, puerto 80)
                     │  clic en «Auto Evaluar»
                     ▼
              http://127.0.0.1:8000  (FastAPI) ──▶ Gemini (internet)
```

Se probó con Windows 11, Moodle 4.5.13+ y Python 3.14.

## 0. Requisitos

| Requisito | Detalle |
|---|---|
| Windows 10 u 11 | Con permisos de administrador. El puerto 80 libre (sin IIS, Skype ni otro servidor web). |
| Visual C++ Redistributable 2019 o posterior | Lo necesita el PHP del paquete. [Descarga](https://learn.microsoft.com/en-us/cpp/windows/latest-supported-vc-redist). |
| Python 3.10 o superior | [python.org](https://www.python.org/downloads/). Marca «Add python.exe to PATH» al instalar. |
| Git (opcional) | Para clonar el repositorio. También se puede descargar como zip. |
| Clave de Gemini | [Google AI Studio](https://aistudio.google.com/apikey). |

En esta guía:
- `<REPO>` es la carpeta del repositorio `auto-evaluar`.
- `<CARPETA_MOODLE>` es la carpeta donde extraes el paquete de Moodle.

Ambas pueden tener espacios en la ruta.

## 1. Descargar el repositorio y Moodle

1. Clona o descarga el repositorio en `<REPO>`.
2. Descarga el paquete oficial: <https://download.moodle.org/download.php/windows/MoodleWindowsInstaller-latest-405.zip> (unos 230 MB).
3. Extráelo **fuera** del repositorio, por ejemplo en `C:\Moodle45`. Dentro deben quedar `server\`, `Start Moodle.exe` y `Stop Moodle.exe`.

> No renombres la carpeta `server`: los ejecutables de arranque dependen de ese nombre.

## 2. Arrancar Moodle e instalarlo

1. Clic derecho en `Start Moodle.exe` → **Ejecutar como administrador**. Se abre una ventana negra que termina con `APACHE and MYSQL are running...`. No la cierres: mientras esté abierta, Moodle está encendido.

   `Start Moodle.exe` ajusta automáticamente las rutas de Apache, PHP y MariaDB a la carpeta donde está el paquete. Si más adelante mueves la carpeta, basta con volver a ejecutarlo.

2. Abre <http://localhost/> y sigue el instalador web:
   - Idioma: Español.
   - Base de datos: servidor `localhost`, base de datos `moodle`, usuario `root`, **contraseña vacía**, prefijo `mdl_`, puerto vacío.
   - El aviso naranja «site not https» es normal en local: Continuar.
   - «Actualizar base de datos Moodle ahora» (unos minutos, sin cerrar la pestaña).
   - Crea la cuenta de administrador. La contraseña debe tener al menos 8 caracteres, 1 número, 1 minúscula, 1 mayúscula y 1 carácter especial.

3. Al terminar debes ver **Administración del sitio** en el menú superior.

### 2.1 Hacer que el paquete se pueda mover (recomendado)

El instalador web escribe en `<CARPETA_MOODLE>\server\moodle\config.php` la ruta absoluta de los datos. Si mueves la carpeta, Moodle deja de funcionar. Para evitarlo, abre ese archivo con el Bloc de notas y reemplaza la línea de `dataroot`:

```php
$CFG->dataroot  = realpath(__DIR__ . '/../moodledata');
```

## 3. Preparar un curso de prueba

1. **Cursos:** crea un curso, o restaura una copia `.mbz` en Administración del sitio → Cursos → Restaurar curso.

   La restauración queda en cola. Para ejecutarla, abre una terminal y corre el cron:

   ```bat
   cd /d "<CARPETA_MOODLE>\server\moodle"
   "<CARPETA_MOODLE>\server\php\php.exe" admin\cli\cron.php
   ```

2. **Tarea:** agrega una actividad **Tarea** con el tipo de entrega «Archivos enviados».
3. **Usuarios:** crea un estudiante de prueba y matricúlalo en el curso con rol Estudiante. Matricula también a tu usuario administrador con rol **Profesor**: algunas funciones de servicios web fallan si el dueño del token no está matriculado.
4. **Entrega:** entra como el estudiante (Participantes → ⋮ → Iniciar sesión como) y sube un PDF de prueba. Usa trabajos ficticios, no de estudiantes reales.

## 4. Servicios web y token

FastAPI usa un token de Moodle para descargar el PDF de la entrega.

1. Administración del sitio → General → **Características avanzadas** → marca **Habilitar servicios web** → Guardar.
2. Servidor → Servicios web → **Vista general** → Habilitar protocolos → marca **REST** → Guardar.
3. Servidor → Servicios web → **Servicios externos**: comprueba que **Moodle mobile web service** esté habilitado.
4. Servidor → Servicios web → **Administrar fichas (tokens)** → **Crear ficha**:
   - Usuario: tu administrador, que también es profesor del curso.
   - Servicio: Moodle mobile web service.
   - **Válido hasta:** viene con una fecha cercana. Desmárcalo o pon una fecha lejana; si no, el token deja de funcionar sin aviso.
5. Copia el token (32 caracteres) y guárdalo en privado.
6. Pruébalo en el navegador, cambiando `TU_TOKEN`:

   ```
   http://localhost/webservice/rest/server.php?wstoken=TU_TOKEN&wsfunction=core_webservice_get_site_info&moodlewsrestformat=json
   ```

   Si funciona, aparece un JSON con `"sitename"`. Si aparece `"exception"`, revisa los pasos anteriores.

## 5. Permitir que Moodle llame a FastAPI (Seguridad HTTP)

Moodle bloquea por defecto las llamadas a direcciones locales. Sin este paso, el botón muestra «Esta URL está bloqueada».

Administración del sitio → General → Seguridad → **Seguridad HTTP**:
- En **Lista de direcciones cURL bloqueadas**, borra solo las líneas `127.0.0.0/8` y `localhost`. Deja las demás.
- En **Puertos cURL permitidos**, agrega una línea con `8000`.
- Guarda.

## 6. Instalar el plugin

Desde una terminal en `<REPO>`:

```bat
python scripts\deploy_plugin.py --moodle-dir "<CARPETA_MOODLE>\server\moodle" --php "<CARPETA_MOODLE>\server\php\php.exe" --upgrade --purge-caches
```

El script copia `moodle-plugin\aiocr` a `<CARPETA_MOODLE>\server\moodle\mod\assign\feedback\aiocr`, registra el plugin en Moodle (crea su tabla) y purga cachés.

**Cada vez que cambies el código del plugin, vuelve a ejecutar este mismo comando.** No edites los archivos dentro de la carpeta de Moodle: el siguiente despliegue los reemplaza. Antes de reemplazarlos, el script guarda un respaldo en `<REPO>\dist\backups\`.

Si prefieres no usar la terminal: ejecuta el comando sin `--php --upgrade --purge-caches` y luego entra a Administración del sitio → **Notificaciones** → «Actualizar base de datos Moodle ahora».

## 7. Configurar y activar el plugin

1. **Configuración global:** Administración del sitio → Extensiones → Módulos de actividades → Tarea → **Extensiones de retroalimentación** → sección del plugin:

   | Campo | Valor |
   |---|---|
   | AI provider | FastAPI (servicio local/personalizado) |
   | FastAPI service URL | `http://127.0.0.1:8000` (sin barra final) |
   | Clave del servicio FastAPI (en inglés: *FastAPI API key*) | La misma clave que pondrás en `SERVICE_API_KEY` en el paso 8 |
   | Request timeout (seconds) | `240` (valor por defecto). Una página ya tarda unos 35 s; no lo subas por encima de 300, que es el límite de PHP y Apache del paquete |

2. **En cada tarea:** abre la tarea → Configuración → **Tipos de retroalimentación** → marca el plugin junto a «Comentarios de retroalimentación» → Guardar.

## 8. Servicio FastAPI

Desde una terminal en `<REPO>\fastapi-service`:

```bat
python -m venv .venv
.venv\Scripts\pip install -r requirements.txt
copy .env.example .env
notepad .env
```

En `.env` completa al menos:

```ini
MOODLE_URL=http://localhost
MOODLE_TOKEN=<el token del paso 4>
GEMINI_API_KEY=<tu clave de Gemini>
SERVICE_API_KEY=<clave del servicio>
```

`SERVICE_API_KEY` es una clave que inventas tú y que comparten el plugin y FastAPI: sin ella, el servicio rechaza cualquier solicitud. Genera una larga y aleatoria con:

```bat
python -c "import secrets; print(secrets.token_urlsafe(32))"
```

Pon la misma en «Clave del servicio FastAPI» del plugin (paso 7.1).

`.env` no se sube al repositorio. No pegues estas claves en chats, correos ni documentos.

Arranca el servicio:

```bat
run.bat
```

Cuando veas `Application startup complete`, abre <http://127.0.0.1:8000/>: debe responder «Servidor FastAPI corriendo correctamente». No cierres esa ventana mientras evalúas.

## 9. Prueba de punta a punta

> Para probar el botón, los permisos y el guardado **sin FastAPI ni Gemini**, elige «Prueba (sin IA)» en **Proveedor de IA** (paso 7.1). El botón guarda un texto fijo que lista los archivos recibidos. Vuelve a «FastAPI (Gemini)» para evaluar de verdad.

1. En Moodle: curso → tarea → **Ver todas las entregas** → **Calificar** en la fila del estudiante.
2. Debajo de «Calificación» aparece el botón **Auto Evaluar**. Dale clic una sola vez.
3. Aparece «Procesando con IA...». Al terminar, sale «Retroalimentación generada. Recargando...».
4. El texto queda en **Comentarios de retroalimentación** y la calificación numérica no cambia.
5. Si la entrega tiene varios archivos, se procesan todos en orden, junto con el texto en línea si lo hay. Los archivos que no son PDF ni imagen se omiten.
6. La transcripción queda guardada en `<REPO>\data\transcripciones\tarea_<id>\usuario_<id>_<fecha>.txt`, junto a un `.json` con las páginas, los tokens consumidos y la duración.

## 10. Uso diario

| Para… | Haz… |
|---|---|
| Encender | `Start Moodle.exe` (como administrador) y `fastapi-service\run.bat` |
| Apagar | `Stop Moodle.exe` y Ctrl+C en la ventana de FastAPI |
| Aplicar cambios del plugin | El comando del paso 6 |
| Aplicar cambios de los prompts o del `.env` | Reiniciar FastAPI (Ctrl+C y `run.bat`) |

## 11. Opcional: abrir Moodle desde otros equipos con ngrok

ngrok publica tu Moodle local con una dirección `https://….ngrok-free.dev`. Es útil para entrar desde otro computador, pero mientras está encendido tu Moodle queda visible en internet.

1. Crea una cuenta gratuita en <https://dashboard.ngrok.com/signup>, descarga ngrok y conéctalo con tu cuenta:

   ```bat
   ngrok config add-authtoken TU_AUTHTOKEN
   ```

2. Con Moodle encendido, ejecuta `ngrok http 80` y anota la dirección `https://TU_DOMINIO.ngrok-free.dev`.
3. En `config.php` cambia la dirección del sitio. Moodle solo acepta una dirección a la vez, así que también en tu equipo deberás entrar por la de ngrok:

   ```php
   $CFG->wwwroot  = 'https://TU_DOMINIO.ngrok-free.dev';
   $CFG->sslproxy = true;
   ```

4. En `fastapi-service\.env` agrega la dirección pública y reinicia FastAPI:

   ```ini
   MOODLE_URL=http://localhost
   MOODLE_PUBLIC_URL=https://TU_DOMINIO.ngrok-free.dev
   ```

   Los enlaces de los archivos que el plugin envía a FastAPI usan la dirección de ngrok. Con `MOODLE_PUBLIC_URL`, FastAPI los cambia por `http://localhost` y descarga el PDF dentro del mismo equipo, sin dar la vuelta por internet ni gastar transferencia de ngrok.

5. La URL de FastAPI en el plugin sigue siendo `http://127.0.0.1:8000`, porque esa llamada no pasa por ngrok.

Para volver al modo local: cierra ngrok, vuelve a poner `$CFG->wwwroot = 'http://localhost';` y comenta `sslproxy`. Luego purga cachés.

## 12. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `Start Moodle.exe` se cierra solo | Puerto 80 ocupado o falta Visual C++ | Cierra el otro servidor web; instala el Redistributable |
| Moodle no abre después de mover la carpeta | `dataroot` con ruta absoluta | Ejecuta `Start Moodle.exe` y aplica el paso 2.1 |
| «Esta URL está bloqueada» al dar clic | Seguridad HTTP | Paso 5 |
| «No se pudo conectar con FastAPI» | FastAPI apagado o URL incorrecta en el plugin | `run.bat`; revisa la URL del paso 7 |
| «Falta la variable MOODLE_TOKEN» al arrancar FastAPI | `.env` incompleto | Paso 8 |
| «no está en ALLOWED_FILE_HOSTS» | La dirección del sitio no coincide con `MOODLE_URL` | Con ngrok, define `MOODLE_PUBLIC_URL` (paso 11) |
| «Moodle redirigió la descarga» | `MOODLE_PUBLIC_URL` no coincide con `$CFG->wwwroot` | Usa exactamente la misma dirección |
| «Moodle no entregó el archivo: Ficha (token) no válida» | Token borrado o mal copiado | Paso 4 |
| «El servicio FastAPI rechazó la clave» | «Clave del servicio FastAPI» distinta de `SERVICE_API_KEY` | Pasos 7.1 y 8 |
| «El servicio ya está procesando N evaluaciones» | Se alcanzó `MAX_CONCURRENT` | Esperar, o subir `MAX_CONCURRENT` en `.env` |
| «El PDF tiene N páginas; el máximo es…» o «El archivo supera el máximo…» | Límites `MAX_PAGES` / `MAX_FILE_MB` | Subirlos en `.env` si es necesario |
| La retroalimentación habla de «una página de inicio de sesión» | Token inválido o vencido: se descargó el login en vez del PDF | Crea otro token (paso 4) |
| El botón no aparece | Plugin no activado en la tarea o cachés viejas | Paso 7.2 y purgar cachés |
| El plugin corta antes de que termine | Timeout del plugin muy bajo | Súbelo en el paso 7.1 |
