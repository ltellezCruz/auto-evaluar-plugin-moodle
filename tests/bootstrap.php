<?php
// Arranque común de las pruebas (ver phpunit.xml).

$raiz = dirname(__DIR__);
if (is_file("$raiz/vendor/autoload.php")) {
    require "$raiz/vendor/autoload.php";
}
require_once "$raiz/php-service/src/autoload.php";
require_once "$raiz/scripts/deploy_plugin.php";
require_once "$raiz/scripts/package_plugin.php";
require_once "$raiz/tools/monitor/monitor.php";
require_once "$raiz/php-service/tests/apoyo/ServidorLocal.php";
require_once "$raiz/php-service/tests/apoyo/PdfFalso.php";
require_once __DIR__ . '/apoyo.php';

// Los mensajes del servicio no ensucian la salida de las pruebas.
\AutoEvaluar\Servicio\Log::$activo = false;
