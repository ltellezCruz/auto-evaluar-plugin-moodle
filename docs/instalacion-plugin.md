# Modalidad 3: instalar el plugin en un Moodle existente

Esta guía es para quien ya tiene un Moodle funcionando (en Windows, Linux o macOS) y quiere agregarle el botón **Auto Evaluar**. Hay que instalar dos piezas:

1. **El plugin**, dentro de Moodle.
2. **El servicio FastAPI**, en el mismo servidor o en otro. Es el que descarga el PDF y habla con Gemini.

```
 Moodle (con el plugin) ──── POST /ia/generar-retroalimentacion ───▶ FastAPI ──▶ Gemini
        ▲                                                              │
        └──────────── descarga del PDF con un token de Moodle ─────────┘
```

**Qué está probado:** Moodle 4.5.13+ y 4.5.15+. El zip se instaló con el mismo mecanismo de «Instalar complementos» y pasó el validador de Moodle. El flujo completo funciona con FastAPI en el mismo equipo (Windows) y en Docker.

**Qué no está probado:** macOS, servidores Linux de producción, Moodle 5.x y un token de un usuario que no sea administrador.

## 0. Requisitos

| Pieza | Requisito |
|---|---|
| Moodle | 4.5 o superior (`version.php` exige 2024100700). Acceso de administrador del sitio. |
| FastAPI | Python 3.10 o superior, o Docker. Salida a internet hacia `generativelanguage.googleapis.com`. |
| Red | Moodle debe poder llamar a FastAPI (puerto 8000), y FastAPI debe poder descargar de Moodle. |
| Gemini | Una clave de [Google AI Studio](https://aistudio.google.com/apikey). |

## 1. Decide dónde corre FastAPI

Esta decisión define los valores de los pasos siguientes:

| Escenario | URL de FastAPI en el plugin (paso 6) | `.env` de FastAPI (paso 7) | Seguridad HTTP de Moodle (paso 5) |
|---|---|---|---|
| **A. Mismo servidor que Moodle** (recomendado) | `http://127.0.0.1:8000` | `MOODLE_URL=<wwwroot>` | Quitar `127.0.0.0/8` y `localhost` de bloqueados |
| **B. Otro servidor de la red interna** | `http://<ip-de-fastapi>:8000` | `MOODLE_URL=<wwwroot>` | Quitar el rango privado que contiene esa IP (ej. `10.0.0.0/8`) |
| **C. FastAPI en Docker, en el mismo servidor** | `http://127.0.0.1:8000` | `MOODLE_URL=<wwwroot>` | Igual que A |

En todos los casos hay que agregar el puerto `8000` a los puertos permitidos.

`<wwwroot>` es la dirección oficial de tu Moodle, el valor de `$CFG->wwwroot` en su `config.php` (por ejemplo `https://moodle.miuniversidad.edu`). FastAPI descarga las entregas por esa misma dirección, así que el servidor de FastAPI debe poder abrirla.

**Excepción:** si `wwwroot` apunta a `localhost`, desde un contenedor Docker esa dirección es el propio contenedor. En ese caso usa una dirección interna y declara la pública:

```ini
MOODLE_URL=http://host.docker.internal
MOODLE_PUBLIC_URL=http://localhost
```

FastAPI cambia la dirección de los enlaces y se presenta ante Moodle con el host público. Esto solo funciona si ambas usan el mismo esquema (`http` o `https`), salvo que Moodle tenga `$CFG->sslproxy = true`.

## 2. Obtén el zip del plugin

Desde una copia del repositorio:

```bash
python scripts/package_plugin.py
```

Genera `dist/assignfeedback_aiocr_<release>_<version>.zip`. Adentro está la carpeta `aiocr/` con `version.php` en su raíz.

## 3. Instala el plugin

Elige **una** de estas tres opciones.

### Opción A: desde la web («Instalar complementos»)

1. Administración del sitio → Extensiones → **Instalar complementos**.
2. Sube el zip y marca que confirmas el tipo de plugin, si te lo pregunta.
3. Moodle valida el paquete. El aviso «Declared maturity level [MATURITY_ALPHA]» es esperado: el plugin es un prototipo. Continúa.
4. «Actualizar base de datos Moodle ahora».

Esta opción solo funciona si el servidor web puede escribir en la carpeta de código de Moodle. Si aparece un error de permisos, o si la opción está desactivada (`$CFG->disableupdateautodeploy`), usa la opción B.

### Opción B: copiar la carpeta a mano

Descomprime el zip dentro de `<moodle>/mod/assign/feedback/`, de modo que quede `<moodle>/mod/assign/feedback/aiocr/version.php`.

| Sistema | Carpeta habitual de Moodle (`<moodle>`) | Después de copiar |
|---|---|---|
| Windows (paquete oficial o XAMPP) | `<CARPETA_MOODLE>\server\moodle` o `C:\xampp\htdocs\moodle` | Nada más |
| Linux (Apache o Nginx) | `/var/www/moodle` o `/var/www/html/moodle` | `sudo chown -R www-data:www-data <moodle>/mod/assign/feedback/aiocr` (en RHEL o CentOS el usuario es `apache`) |
| macOS (MAMP) | `/Applications/MAMP/htdocs/moodle` | Nada más, normalmente |

En Linux, por ejemplo:

```bash
sudo unzip dist/assignfeedback_aiocr_*.zip -d /var/www/moodle/mod/assign/feedback/
sudo chown -R www-data:www-data /var/www/moodle/mod/assign/feedback/aiocr
```

Luego entra a Administración del sitio → **Notificaciones** → «Actualizar base de datos Moodle ahora». También se puede hacer por terminal:

```bash
sudo -u www-data php /var/www/moodle/admin/cli/upgrade.php --non-interactive
```

### Opción C: con el script de despliegue

Si tienes el repositorio en el mismo servidor, el script hace la copia, el respaldo, la actualización y la purga de cachés:

```bash
sudo python3 scripts/deploy_plugin.py --moodle-dir /var/www/moodle --owner www-data --upgrade --purge-caches
```

## 4. Servicios web y token

FastAPI necesita un token para descargar las entregas.

1. Administración del sitio → General → Características avanzadas → **Habilitar servicios web**.
2. Servidor → Servicios web → Vista general → Habilitar protocolos → **REST**.
3. Servidor → Servicios web → Servicios externos → comprueba que **Moodle mobile web service** esté habilitado.
4. Servidor → Servicios web → Gestionar tokens → **Crear ficha**: usuario administrador, servicio «Moodle mobile web service». Revisa la fecha «Válido hasta».
5. Guarda el token en privado. Va en el `.env` de FastAPI (paso 7).

> Lo probado es un token del administrador del sitio. Un usuario dedicado con menos permisos es más seguro, pero necesita al menos `webservice/rest:use` y poder ver las entregas de los cursos (rol de profesor). Esa configuración no está probada.

## 5. Seguridad HTTP

Por defecto, Moodle bloquea las llamadas a direcciones locales y privadas. Sin este paso, el botón muestra «Esta URL está bloqueada».

Administración del sitio → General → Seguridad → **Seguridad HTTP**:
- **Lista de direcciones cURL bloqueadas:** quita solo la entrada que cubre la IP de FastAPI, según la tabla del paso 1. Deja las demás.
- **Puertos cURL permitidos:** agrega `8000`.

En un servidor institucional, coordina este cambio con el área de TI.

## 6. Configura el plugin y actívalo

1. Administración del sitio → Extensiones → Módulos de actividades → Tarea → **Extensiones de retroalimentación** → sección del plugin:
   - **AI provider:** FastAPI.
   - **FastAPI service URL:** la del paso 1, sin barra final.
   - **FastAPI API key:** la misma clave que `SERVICE_API_KEY` del paso 7.
   - **Request timeout:** `240` segundos. Una página tarda unos 35 s. Ten en cuenta que el servidor web también tiene su propio límite: `max_execution_time` de PHP y los timeouts de Apache, Nginx o PHP-FPM. Ese límite debe ser mayor.
2. En cada tarea donde quieras el botón: Configuración → **Tipos de retroalimentación** → activa el plugin.
3. Por defecto pueden usar el botón los roles Profesor, Profesor sin permiso de edición y Gestor (capacidad `assignfeedback/aiocr:generate`).

## 7. Instala el servicio FastAPI

Copia la carpeta `fastapi-service/` del repositorio al servidor elegido en el paso 1.

### Configuración (todos los sistemas)

```bash
cd fastapi-service
cp .env.example .env        # en Windows: copy .env.example .env
```

En `.env`:

```ini
MOODLE_URL=<wwwroot>        # ver la excepción del paso 1
MOODLE_TOKEN=<token del paso 4>
GEMINI_API_KEY=<tu clave>
SERVICE_API_KEY=<clave compartida con el plugin>
```

Genera `SERVICE_API_KEY` con `python -c "import secrets; print(secrets.token_urlsafe(32))"` y ponla también en «FastAPI API key» del plugin (paso 6).

`.env.example` explica el resto de variables: modelo, DPI, carpeta de transcripciones y timeouts.

### Linux

```bash
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
./run.sh                    # prueba en primer plano; Ctrl+C para salir
```

Para dejarlo como servicio, crea `/etc/systemd/system/aiocr-fastapi.service` (ajusta rutas y usuario):

```ini
[Unit]
Description=Auto Evaluar - servicio FastAPI
After=network-online.target

[Service]
User=aiocr
WorkingDirectory=/opt/auto-evaluar/fastapi-service
ExecStart=/opt/auto-evaluar/fastapi-service/.venv/bin/python -m uvicorn app.main:app --host 127.0.0.1 --port 8000
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now aiocr-fastapi
```

En el escenario B, cambia `--host 127.0.0.1` por la IP de la red interna.

### Windows

```bat
python -m venv .venv
.venv\Scripts\pip install -r requirements.txt
run.bat
```

Para que arranque con el equipo, se puede crear una tarea en el Programador de tareas que ejecute `run.bat` al iniciar.

### macOS (no probado)

Igual que Linux, con `python3 -m venv .venv` y `./run.sh`.

### Docker (escenario C)

```bash
docker build -t aiocr-fastapi fastapi-service
docker run -d --name aiocr-fastapi --restart unless-stopped \
  --env-file fastapi-service/.env \
  -e OUTPUT_DIR=/data/transcripciones \
  -v "$(pwd)/data/transcripciones:/data/transcripciones" \
  --add-host=host.docker.internal:host-gateway \
  -p 127.0.0.1:8000:8000 aiocr-fastapi
```

`--add-host` solo hace falta en Linux y solo si usas la excepción del paso 1 (`host.docker.internal` es el equipo anfitrión visto desde el contenedor).

## 8. Comprueba

1. **Desde el servidor de FastAPI:** abre `http://127.0.0.1:8000/moodle/info`. Debe devolver un JSON con el nombre de tu sitio. Eso confirma el token y la conexión FastAPI → Moodle.
2. **En Moodle:** tarea → Ver todas las entregas → Calificar → **Auto Evaluar** sobre una entrega en PDF de prueba.

## 9. Seguridad y datos

Qué protege al servicio y qué depende de ti:

| Riesgo | Protección |
|---|---|
| Que otro programa use FastAPI y gaste tu cuota de Gemini | Cada solicitud debe traer `SERVICE_API_KEY` (cabecera `X-Aiocr-Key`); sin ella responde 401. **Además**, que escuche solo en `127.0.0.1` (escenarios A y C) o limita con un firewall el puerto 8000 a la IP de Moodle (escenario B) |
| Que el token de Moodle termine en otro servidor | Solo se envía a los hosts de `MOODLE_URL` / `ALLOWED_FILE_HOSTS` y solo a la ruta `/webservice/pluginfile.php/` |
| Que alguien cambie lo que se le pide a Gemini | Los prompts viven solo en el servidor; la solicitud solo dice qué archivo evaluar. Los prompts tratan el contenido de la entrega como datos, no como instrucciones |
| Que un estudiante use el botón | Solo los roles con `assignfeedback/aiocr:generate` (profesores y gestores) |
| Solicitudes que agotan memoria, disco o cuota | Límites `MAX_FILE_MB`, `MAX_PAGES` y `MAX_CONCURRENT`; los archivos temporales se borran al terminar cada evaluación |
| Que la carpeta de transcripciones crezca sin fin | Se borran las de más de `TRANSCRIPTIONS_DAYS` días; con `SAVE_TRANSCRIPTIONS=false` no se guarda nada |
| Que se conozca la API | `/docs` y `/openapi.json` están apagados (`ENABLE_DOCS=false`) |

- **La clave viaja en texto plano si FastAPI está en otro servidor (escenario B) sin HTTPS.** Dentro de una red interna de confianza suele bastar con el firewall; si no, pon FastAPI detrás de un proxy con HTTPS.
- **El token y la clave de Gemini viven solo en `.env`.** No los subas al repositorio ni los compartas. Dale al token una fecha de vencimiento.
- **El contenido de las entregas se envía a Google (Gemini).** Las transcripciones quedan guardadas en `data/transcripciones/tarea_<id>/` con los id numéricos de Moodle (no el nombre de usuario) durante `TRANSCRIPTIONS_DAYS` días. Antes de usarlo con estudiantes reales, acuerda con la institución el tratamiento de datos personales.

## 10. Actualizar y desinstalar

- **Actualizar el plugin:** genera un zip nuevo, repite el paso 3 (opción A o B, reemplazando la carpeta) y luego Notificaciones.
- **Actualizar FastAPI:** reemplaza `app/` y `prompts/` y reinicia el servicio.
- **Desinstalar:** Administración del sitio → Extensiones → Vista general de extensiones → el plugin → **Desinstalar**. Esto borra su tabla. Después borra la carpeta `aiocr`.

## 11. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| «Instalar complementos» falla por permisos | El servidor web no puede escribir en `<moodle>/mod` | Opción B del paso 3 |
| «Esta URL está bloqueada» | Seguridad HTTP | Paso 5 |
| «No se pudo conectar con FastAPI» | FastAPI apagado, firewall o URL incorrecta | `http://<fastapi>:8000/` desde el servidor de Moodle (`curl`) |
| «Operation timed out» | El timeout del plugin o del servidor web es muy bajo | Paso 6.1 |
| «Moodle redirigió la descarga» | `MOODLE_PUBLIC_URL` distinto de `$CFG->wwwroot` | Copia exactamente el `wwwroot` |
| «no está en ALLOWED_FILE_HOSTS» | `MOODLE_URL` no es el `wwwroot` | Pon el `wwwroot` exacto en `MOODLE_URL` (paso 1) |
| «Ficha (token) no válida» | Token borrado, vencido o mal copiado | Paso 4 |
| «El servicio FastAPI rechazó la clave» | «FastAPI API key» distinta de `SERVICE_API_KEY` | Pasos 6 y 7 |
| «El servicio ya está procesando N evaluaciones» | Se alcanzó `MAX_CONCURRENT` | Esperar, o subir `MAX_CONCURRENT` si el servidor lo soporta |
| El botón no aparece | El plugin no está activado en la tarea, o hay cachés viejas | Paso 6.2 y purgar cachés |
