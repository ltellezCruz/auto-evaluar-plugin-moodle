<?php
// index.php
// Punto de entrada HTTP del servicio (reemplaza a la app FastAPI). Todas
// las rutas pasan por aquí:
//
//     GET  /                               estado y versión (sin clave)
//     GET  /moodle/info                    prueba el token de Moodle (con clave)
//     POST /ia/generar-retroalimentacion   el botón "Auto Evaluar" (con clave)
//     GET  /docs                           descripción de la API (solo con ENABLE_DOCS=true)
//
// Ejecución (desde php-service/):
//     php -S 127.0.0.1:8000 public/index.php      (lo hacen run.bat y run.sh)
// En Docker lo sirve Apache (ver Dockerfile).

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use AutoEvaluar\Servicio\Config;
use AutoEvaluar\Servicio\ErrorHttp;
use AutoEvaluar\Servicio\Log;
use AutoEvaluar\Servicio\Servicio;
use AutoEvaluar\Servicio\Version;

// Una evaluación puede tardar varios minutos (una llamada a Gemini por
// página) y debe terminar aunque el plugin deje de esperar: así queda el
// registro en disco.
@set_time_limit(600);
ignore_user_abort(true);

function responder(int $estado, array $datos): void
{
    http_response_code($estado);
    header('Content-Type: application/json');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Solo el plugin (que conoce SERVICE_API_KEY) puede usar el servicio. */
function verificarClave(Config $config): void
{
    $clave = $_SERVER['HTTP_X_AIOCR_KEY'] ?? '';
    if (!is_string($clave) || $clave === '' || !hash_equals($config->serviceApiKey, $clave)) {
        throw new ErrorHttp(401, 'Clave del servicio ausente o incorrecta (X-Aiocr-Key).');
    }
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = rawurldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
if ($ruta !== '/') {
    $ruta = rtrim($ruta, '/');
}

$rutas = [
    '/' => ['GET'],
    '/moodle/info' => ['GET'],
    '/ia/generar-retroalimentacion' => ['POST'],
    '/docs' => ['GET'],
    '/openapi.json' => ['GET'],
];

try {
    if (!isset($rutas[$ruta])) {
        throw new ErrorHttp(404, 'Not Found');
    }
    if (!in_array($metodo, $rutas[$ruta], true)) {
        header('Allow: ' . implode(', ', $rutas[$ruta]));
        throw new ErrorHttp(405, 'Method Not Allowed');
    }

    // --- Endpoints de diagnóstico ---
    if ($ruta === '/') {
        responder(200, ['mensaje' => 'Servidor PHP corriendo correctamente', 'version' => Version::VERSION]);
        return;
    }

    try {
        $config = Config::cargar();
    } catch (\RuntimeException $e) {
        Log::error($e->getMessage());
        throw new ErrorHttp(500, 'El servicio no está bien configurado: ' . $e->getMessage());
    }
    $config->aplicarZonaHoraria();

    // /docs y /openapi.json describen la API: solo si ENABLE_DOCS=true.
    if ($ruta === '/docs' || $ruta === '/openapi.json') {
        if (!$config->enableDocs) {
            throw new ErrorHttp(404, 'Not Found');
        }
        responder(200, [
            'titulo' => 'Auto Evaluar - servicio PHP',
            'version' => Version::VERSION,
            'rutas' => [
                'GET /' => 'Estado y versión. Sin clave.',
                'GET /moodle/info' => 'Comprueba el token de Moodle. Cabecera X-Aiocr-Key.',
                'POST /ia/generar-retroalimentacion' => [
                    'cabeceras' => ['X-Aiocr-Key' => 'obligatoria', 'X-Aiocr-Plugin-Version' => 'opcional, hasta 20 caracteres'],
                    'cuerpo' => [
                        'assignmentid' => 'entero > 0',
                        'userid' => 'entero > 0',
                        'username' => 'texto opcional (hasta 100)',
                        'files' => 'lista (hasta 20) de {filename, fileurl, mimetype}',
                        'onlinetext' => 'texto opcional (hasta 100000)',
                    ],
                    'respuesta' => '{"feedback_text": "...", "success": true|false, "error": "..."}',
                ],
            ],
        ]);
        return;
    }

    verificarClave($config);

    if ($ruta === '/moodle/info') {
        $servicio = new Servicio($config);
        responder(200, $servicio->infoMoodle());
        return;
    }

    // POST /ia/generar-retroalimentacion
    $versionPlugin = $_SERVER['HTTP_X_AIOCR_PLUGIN_VERSION'] ?? null;
    if (is_string($versionPlugin) && strlen($versionPlugin) > 20) {
        throw new ErrorHttp(422, [['type' => 'string_too_long', 'loc' => ['header', 'x-aiocr-plugin-version'],
            'msg' => 'Debe tener como mucho 20 caracteres']]);
    }
    $cuerpo = json_decode((string) file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new ErrorHttp(422, [['type' => 'json_invalid', 'loc' => ['body'], 'msg' => 'JSON no válido']]);
    }
    $solicitud = Servicio::validarSolicitud($cuerpo);
    $servicio = new Servicio($config);
    responder(200, $servicio->generarRetroalimentacion($solicitud, $versionPlugin));

} catch (ErrorHttp $e) {
    if ($e->estado >= 500) {
        Log::error("$metodo $ruta -> {$e->estado}: " . $e->getMessage());
    }
    responder($e->estado, ['detail' => $e->detalle]);
} catch (\Throwable $e) {
    Log::error("$metodo $ruta -> 500: " . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    responder(500, ['detail' => 'Internal Server Error']);
}
