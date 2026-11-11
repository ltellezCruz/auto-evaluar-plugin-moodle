# Modalidad 3: instalar el plugin en un Moodle existente

> **Desde la 0.5.0 hay una forma más simple:** el plugin puede hacer todo solo, sin el servicio PHP, sin token y sin tocar «Seguridad HTTP». Ver [instalacion-autonoma.md](instalacion-autonoma.md). Esta guía es para usar el **servicio externo** (proveedor «Servicio externo (php-service)»).

Esta guía es para quien ya tiene un Moodle funcionando (en Windows, Linux o macOS) y quiere agregarle el botón **Auto Evaluar**. Hay que instalar dos piezas:

1. **El plugin**, dentro de Moodle.
2. **El servicio PHP** (`php-service/`), en el mismo servidor o en otro. Es el que descarga el PDF y habla con Gemini.

```
 Moodle (con el plugin) ──── POST /ia/generar-retroalimentacion ───▶ servicio PHP ──▶ Gemini
        ▲                                                                  │
        └──────────── descarga del PDF con un token de Moodle ─────────────┘
```

**Qué está probado:** Moodle 4.5.13+ y 4.5.15+. El zip se instaló con el mismo mecanismo de «Instalar complementos» y pasó el validador de Moodle. El flujo completo funcionó con la versión en Python del servicio (0.3.0) en el mismo equipo (Windows) y en Docker. El servicio PHP (0.4.0) pasa las mismas pruebas automáticas y se probó en Windows contra el Moodle local y Gemini reales (`PDF_MODO=directo`).

**Qué no está probado:** la conversión de PDF con Imagick en Windows, macOS, servidores Linux de producción, Moodle 5.x y un token de un usuario que no sea administrador.

## 0. Requisitos

| Pieza | Requisito |
|---|---|
| Moodle | 4.5 o superior (`version.php` exige 2024100700). Acceso de administrador del sitio. |
| Servicio | PHP 8.1 o superior con `curl` y `mbstring`, (opcional: la extensión `imagick` y Ghostscript, para `PDF_MODO=imagen`); o Docker. Salida a internet hacia `generativelanguage.googleapis.com`. |
| Red | Moodle debe poder llamar al servicio (puerto 8000), y el servicio debe poder descargar de Moodle. |
| Gemini | Una clave de [Google AI Studio](https://aistudio.google.com/apikey). |

## 1. Decide dónde corre el servicio

Esta decisión define los valores de los pasos siguientes:

| Escenario | URL del servicio en el plugin (paso 6) | `.env` del servicio (paso 7) | Seguridad HTTP de Moodle (paso 5) |
|---|---|---|---|
| **A. Mismo servidor que Moodle** (recomendado) | `http://127.0.0.1:8000` | `MOODLE_URL=<wwwroot>` | Quitar `127.0.0.0/8` y `localhost` de bloqueados |
| **B. Otro servidor de la red interna** | `http://<ip-del-servicio>:8000` | `MOODLE_URL=<wwwroot>` | Quitar el rango privado que contiene esa IP (ej. `10.0.0.0/8`) |
| **C. Servicio en Docker, en el mismo servidor** | `http://127.0.0.1:8000` | `MOODLE_URL=<wwwroot>` | Igual que A |

En todos los casos hay que agregar el puerto `8000` a los puertos permitidos.

`<wwwroot>` es la dirección oficial de tu Moodle, el valor de `$CFG->wwwroot` en su `config.php` (por ejemplo `https://moodle.miuniversidad.edu`). El servicio descarga las entregas por esa misma dirección, así que el servidor donde corre debe poder abrirla.

**Excepción:** si `wwwroot` apunta a `localhost`, desde un contenedor Docker esa dirección es el propio contenedor. En ese caso usa una dirección interna y declara la pública:

```ini
MOODLE_URL=http://host.docker.internal
MOODLE_PUBLIC_URL=http://localhost
```

El servicio cambia la dirección de los enlaces y se presenta ante Moodle con el host público. Esto solo funciona si ambas usan el mismo esquema (`http` o `https`), salvo que Moodle tenga `$CFG->sslproxy = true`.

## 2. Obtén el zip del plugin

Desde una copia del repositorio:

```bash
php scripts/package_plugin.php
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
sudo php scripts/deploy_plugin.php --moodle-dir /var/www/moodle --owner www-data --upgrade --purge-caches
```

## 4. Servicios web y token

El servicio necesita un token para descargar las entregas.

1. Administración del sitio → General → Características avanzadas → **Habilitar servicios web**.
2. Servidor → Servicios web → Vista general → Habilitar protocolos → **REST**.
3. Servidor → Servicios web → Servicios externos → comprueba que **Moodle mobile web service** esté habilitado.
4. Servidor → Servicios web → Administrar fichas (tokens) → **Crear ficha**: usuario administrador, servicio «Moodle mobile web service». Revisa la fecha «Válido hasta».
5. Guarda el token en privado. Va en el `.env` del servicio (paso 7).

> Lo probado es un token del administrador del sitio. Un usuario dedicado con menos permisos es más seguro, pero necesita al menos `webservice/rest:use` y poder ver las entregas de los cursos (rol de profesor). Esa configuración no está probada.

## 5. Seguridad HTTP

Por defecto, Moodle bloquea las llamadas a direcciones locales y privadas. Sin este paso, el botón muestra «Esta URL está bloqueada».

Administración del sitio → General → Seguridad → **Seguridad HTTP**:
- **Lista de direcciones cURL bloqueadas:** quita solo la entrada que cubre la IP del servicio, según la tabla del paso 1. Deja las demás.
- **Puertos cURL permitidos:** agrega `8000`.

En un servidor institucional, coordina este cambio con el área de TI.

## 6. Configura el plugin y actívalo

1. Administración del sitio → Extensiones → Módulos de actividades → Tarea → **Extensiones de retroalimentación** → sección del plugin:
   - **AI provider:** FastAPI (el nombre del ajuste se conservó; ahora es el servicio PHP).
   - **FastAPI service URL:** la del paso 1, sin barra final.
   - **Clave del servicio FastAPI** (en inglés: *FastAPI API key*): la misma clave que `SERVICE_API_KEY` del paso 7.
   - **Proveedor de IA:** «FastAPI (Gemini)». «Prueba (sin IA)» sirve para probar el plugin sin el servicio.
   - **Request timeout:** `240` segundos. Una página tarda unos 35 s. Ten en cuenta que el servidor web también tiene su propio límite: `max_execution_time` de PHP y los timeouts de Apache, Nginx o PHP-FPM. Ese límite debe ser mayor.
2. En cada tarea donde quieras el botón: Configuración → **Tipos de retroalimentación** → activa el plugin.
3. Por defecto pueden usar el botón los roles Profesor, Profesor sin permiso de edición y Gestor (capacidad `assignfeedback/aiocr:generate`).

## 7. Instala el servicio PHP

Copia la carpeta `php-service/` del repositorio al servidor elegido en el paso 1. No necesita Composer: solo PHP y sus extensiones.

### Configuración (todos los sistemas)

```bash
cd php-service
cp .env.example .env        # en Windows: copy .env.example .env
```

En `.env`:

```ini
MOODLE_URL=<wwwroot>        # ver la excepción del paso 1
MOODLE_TOKEN=<token del paso 4>
GEMINI_API_KEY=<tu clave>
SERVICE_API_KEY=<clave compartida con el plugin>
```

Genera `SERVICE_API_KEY` con `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` y ponla también en «Clave del servicio FastAPI» del plugin (paso 6).

`.env.example` explica el resto de variables: ruta de PHP (`PHP_BIN`), zona horaria, modelo, DPI, carpeta de transcripciones y timeouts.

Antes de encender, `php bin/comprobar.php` revisa la configuración y las extensiones, y avisa si Imagick o Ghostscript no pueden convertir PDF (`run.bat` y `run.sh` lo ejecutan solos).

### Linux

```bash
sudo apt install php-cli php-curl php-mbstring php-imagick ghostscript    # Debian/Ubuntu
./run.sh                    # prueba en primer plano; Ctrl+C para salir
```

Si `comprobar.php` dice que ImageMagick no puede convertir PDF, permite la lectura de PDF en `/etc/ImageMagick-6/policy.xml`: en la línea con `pattern="PDF"`, cambia `rights="none"` por `rights="read"`.

Para producción, lo recomendable es servir `php-service/public/` con Apache o Nginx + PHP-FPM en el puerto 8000, con todas las rutas hacia `index.php` (es lo que hace la imagen de Docker; ver `php-service/Dockerfile`) y `max_execution_time` de al menos 300. Para algo más simple, `run.sh` como servicio de systemd: crea `/etc/systemd/system/aiocr-servicio.service` (ajusta rutas y usuario):

```ini
[Unit]
Description=Auto Evaluar - servicio PHP
After=network-online.target

[Service]
User=aiocr
WorkingDirectory=/opt/auto-evaluar/php-service
ExecStart=/opt/auto-evaluar/php-service/run.sh 127.0.0.1
Restart=on-failure

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now aiocr-servicio
```

En el escenario B, cambia `127.0.0.1` por la IP de la red interna.

### Windows

Sigue el paso 8 de la [guía local](instalacion-local-windows.md#8-servicio-php) (Imagick, Ghostscript, `PHP_BIN` y `run.bat`). Sirve el PHP del paquete de Moodle o cualquier PHP 8.1+ *Thread Safe* x64.

Para que arranque con el equipo, se puede crear una tarea en el Programador de tareas que ejecute `run.bat` al iniciar.

### macOS (no probado)

Igual que Linux, con PHP, Imagick y Ghostscript de Homebrew (`brew install php imagemagick ghostscript` y `pecl install imagick`) y `./run.sh`.

### Docker (escenario C)

```bash
docker build -t aiocr-servicio php-service
docker run -d --name aiocr-servicio --restart unless-stopped \
  --env-file php-service/.env \
  -e OUTPUT_DIR=/data/transcripciones \
  -v "$(pwd)/data/transcripciones:/data/transcripciones" \
  --add-host=host.docker.internal:host-gateway \
  -p 127.0.0.1:8000:8000 aiocr-servicio
```

`--add-host` solo hace falta en Linux y solo si usas la excepción del paso 1 (`host.docker.internal` es el equipo anfitrión visto desde el contenedor).

## 8. Comprueba

1. **Desde el servidor del servicio:** pide `http://127.0.0.1:8000/moodle/info` con la cabecera de la clave, por ejemplo `curl -H "X-Aiocr-Key: <SERVICE_API_KEY>" http://127.0.0.1:8000/moodle/info`. Debe devolver un JSON con el nombre de tu sitio. Eso confirma el token y la conexión servicio → Moodle.
2. **En Moodle:** tarea → Ver todas las entregas → Calificar → **Auto Evaluar** sobre una entrega en PDF de prueba.

## 9. Seguridad y datos

Qué protege al servicio y qué depende de ti:

| Riesgo | Protección |
|---|---|
| Que otro programa use el servicio y gaste tu cuota de Gemini | Cada solicitud debe traer `SERVICE_API_KEY` (cabecera `X-Aiocr-Key`); sin ella responde 401. **Además**, que escuche solo en `127.0.0.1` (escenarios A y C) o limita con un firewall el puerto 8000 a la IP de Moodle (escenario B) |
| Que el token de Moodle termine en otro servidor | Solo se envía a los hosts de `MOODLE_URL` / `ALLOWED_FILE_HOSTS` y solo a la ruta `/webservice/pluginfile.php/` |
| Que alguien cambie lo que se le pide a Gemini | Los prompts viven solo en el servidor; la solicitud solo dice qué archivo evaluar. Los prompts tratan el contenido de la entrega como datos, no como instrucciones |
| Que un estudiante use el botón | Solo los roles con `assignfeedback/aiocr:generate` (profesores y gestores) |
| Solicitudes que agotan memoria, disco o cuota | Límites `MAX_FILE_MB`, `MAX_PAGES` y `MAX_CONCURRENT`; los archivos temporales se borran al terminar cada evaluación |
| Que la carpeta de transcripciones crezca sin fin | Se borran las de más de `TRANSCRIPTIONS_DAYS` días; con `SAVE_TRANSCRIPTIONS=false` no se guarda nada |
| Que se conozca la API | `/docs` y `/openapi.json` están apagados (`ENABLE_DOCS=false`) |

- **La clave viaja en texto plano si el servicio está en otro servidor (escenario B) sin HTTPS.** Dentro de una red interna de confianza suele bastar con el firewall; si no, pon el servicio detrás de un proxy con HTTPS.
- **El token y la clave de Gemini viven solo en `.env`.** No los subas al repositorio ni los compartas. Dale al token una fecha de vencimiento.
- **El contenido de las entregas se envía a Google (Gemini).** Las transcripciones quedan guardadas en `data/transcripciones/tarea_<id>/` con los id numéricos de Moodle (no el nombre de usuario) durante `TRANSCRIPTIONS_DAYS` días. Antes de usarlo con estudiantes reales, acuerda con la institución el tratamiento de datos personales.

## 10. Actualizar y desinstalar

- **Actualizar el plugin:** genera un zip nuevo, repite el paso 3 (opción A o B, reemplazando la carpeta) y luego Notificaciones.
- **Actualizar el servicio:** reemplaza `src/`, `public/`, `bin/` y `prompts/`. Con `php -S` o Apache no hace falta reiniciar; con PHP-FPM y OPcache, recarga PHP-FPM.
- **Desinstalar:** Administración del sitio → Extensiones → Vista general de extensiones → el plugin → **Desinstalar**. Esto borra su tabla. Después borra la carpeta `aiocr`.

## 11. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| «Instalar complementos» falla por permisos | El servidor web no puede escribir en `<moodle>/mod` | Opción B del paso 3 |
| «Esta URL está bloqueada» | Seguridad HTTP | Paso 5 |
| «No se pudo conectar con FastAPI» | Servicio apagado, firewall o URL incorrecta | `http://<servicio>:8000/` desde el servidor de Moodle (`curl`) |
| «Operation timed out» | El timeout del plugin o del servidor web es muy bajo | Paso 6.1 |
| «Moodle redirigió la descarga» | `MOODLE_PUBLIC_URL` distinto de `$CFG->wwwroot` | Copia exactamente el `wwwroot` |
| «no está en ALLOWED_FILE_HOSTS» | `MOODLE_URL` no es el `wwwroot` | Pon el `wwwroot` exacto en `MOODLE_URL` (paso 1) |
| «Ficha (token) no válida» | Token borrado, vencido o mal copiado | Paso 4 |
| «El servicio FastAPI rechazó la clave» | «Clave del servicio FastAPI» distinta de `SERVICE_API_KEY` | Pasos 6 y 7 |
| «El servicio ya está procesando N evaluaciones» | Se alcanzó `MAX_CONCURRENT` | Esperar, o subir `MAX_CONCURRENT` si el servidor lo soporta |
| «Error al convertir … a imagen» o «Falta la extensión imagick» | `PDF_MODO=imagen` sin Imagick o Ghostscript, o la política de ImageMagick bloquea PDF (o usa `PDF_MODO=directo`) | Paso 7 (`php bin/comprobar.php` dice cuál) |
| El botón no aparece | El plugin no está activado en la tarea, o hay cachés viejas | Paso 6.2 y purgar cachés |
