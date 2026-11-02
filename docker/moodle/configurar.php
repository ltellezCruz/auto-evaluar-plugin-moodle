<?php
// Deja Moodle listo para el plugin (se ejecuta en cada arranque; es idempotente):
//   - servicios web + REST + servicio móvil (el que usa el token)
//   - Seguridad HTTP: permite llamar a FastAPI dentro de la red de Docker
//   - configuración del plugin (URL de FastAPI y timeout)
//   - token del administrador, escrito en AIOCR_TOKEN_FILE para FastAPI
//
// Equivale a los pasos 4, 5 y 7.1 de la guía de instalación local.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/adminlib.php');

// Las páginas de administración solo se cargan para quien tiene permisos.
\core\session\manager::set_user(get_admin());

/**
 * Guarda un ajuste usando su clase de administración, para que se apliquen
 * los efectos secundarios (p. ej. el servicio móvil habilita REST y el
 * permiso webservice/rest:use).
 */
function aiocr_guardar_ajuste(string $nombre, $valor): void {
    $encontrados = admin_find_write_settings(admin_get_root(), ["s__$nombre" => $valor]);
    $ajuste = reset($encontrados);
    if (!$ajuste) {
        throw new coding_exception("No existe el ajuste $nombre");
    }
    $error = $ajuste->write_setting($valor);
    if ($error !== '') {
        throw new coding_exception("No se pudo guardar $nombre: $error");
    }
    echo "  $nombre = " . str_replace("\n", ', ', (string) $valor) . "\n";
}

echo "Configurando Moodle para Auto Evaluar...\n";

aiocr_guardar_ajuste('enablewebservices', 1);
aiocr_guardar_ajuste('enablemobilewebservice', 1);
set_config('webserviceprotocols', 'rest');

// La red de docker-compose usa 172.28.0.0/16 (dentro de 172.16.0.0/12), así
// que ese rango sale de la lista bloqueada. El resto de la lista por defecto
// se conserva.
aiocr_guardar_ajuste('curlsecurityblockedhosts', implode("\n", [
    '127.0.0.0/8', '192.168.0.0/16', '10.0.0.0/8', '0.0.0.0',
    'localhost', '169.254.169.254', '0000::1',
]));
aiocr_guardar_ajuste('curlsecurityallowedport', implode("\n", ['443', '80', '8000']));

set_config('provider', 'fastapi_adapter', 'assignfeedback_aiocr');
set_config('fastapi_url', getenv('AIOCR_FASTAPI_URL') ?: 'http://fastapi:8000', 'assignfeedback_aiocr');
set_config('fastapi_timeout', getenv('AIOCR_FASTAPI_TIMEOUT') ?: 240, 'assignfeedback_aiocr');
echo "  plugin: fastapi_url = " . get_config('assignfeedback_aiocr', 'fastapi_url') . "\n";

// Token permanente del administrador para el servicio móvil.
$archivo = getenv('AIOCR_TOKEN_FILE');
if ($archivo) {
    $admin = get_admin();
    $servicio = $DB->get_record('external_services', ['shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE], '*', MUST_EXIST);
    $token = $DB->get_field('external_tokens', 'token', [
        'userid' => $admin->id,
        'externalserviceid' => $servicio->id,
        'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        'name' => 'aiocr-docker',
    ], IGNORE_MULTIPLE);
    if (!$token) {
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT, $servicio, $admin->id, context_system::instance(), 0, '', 'aiocr-docker'
        );
        echo "  token creado para {$admin->username}\n";
    }
    file_put_contents($archivo, $token);
    chmod($archivo, 0644);
    echo "  token escrito en $archivo\n";
}

purge_all_caches();
echo "Listo.\n";
