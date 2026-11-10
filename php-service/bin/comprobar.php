<?php
// comprobar.php
// Se ejecuta antes de encender el servicio (run.bat, run.sh y Docker):
// revisa la configuración y las extensiones de PHP, muestra un resumen y
// hace la limpieza de transcripciones antiguas. Si falta algo obligatorio,
// termina con código 1 y el servicio no arranca (como hacía FastAPI).
//
//     php bin/comprobar.php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use AutoEvaluar\Servicio\Config;
use AutoEvaluar\Servicio\Log;
use AutoEvaluar\Servicio\PdfImagick;
use AutoEvaluar\Servicio\PdfSimple;
use AutoEvaluar\Servicio\Servicio;
use AutoEvaluar\Servicio\Transcripciones;
use AutoEvaluar\Servicio\Version;

try {
    $config = Config::cargar();
    $config->aplicarZonaHoraria();
    $config->leerPrompt('extraer.txt');
    $config->leerPrompt('evaluar.txt');
} catch (\RuntimeException $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

$faltan = array_filter(['curl', 'json'], static fn ($e) => !extension_loaded($e));
if ($faltan) {
    fwrite(STDERR, 'ERROR: faltan extensiones de PHP: ' . implode(', ', $faltan) . " (actívalas en php.ini).\n");
    exit(1);
}

Log::info('Auto Evaluar ' . Version::VERSION . ' (PHP ' . PHP_VERSION . ')');
Log::info(sprintf(
    'Moodle: %s | modelo: %s | hosts permitidos: %s | transcripciones: %s',
    $config->moodleUrl, $config->geminiModel, implode(', ', $config->allowedFileHosts),
    $config->saveTranscriptions ? $config->outputDir : 'no se guardan'
));
Log::info(sprintf(
    'Límites: %d MB por archivo, %d páginas, %d evaluaciones simultáneas, retención %s días',
    $config->maxFileMb, $config->maxPages, $config->maxConcurrent,
    $config->transcriptionsDays ?: 'sin límite'
));

if ($config->pdfModo === 'directo') {
    Log::info('PDF: se envían enteros a Gemini (PDF_MODO=directo' . (extension_loaded('imagick') ? '' : ', sin Imagick') . ').');
} elseif (!extension_loaded('imagick')) {
    Log::advertencia('PDF_MODO=imagen pero falta la extensión imagick: los PDF no se podrán convertir. '
        . 'Usa PDF_MODO=auto o directo para enviarlos enteros a Gemini.');
} else {
    Log::info("PDF: una imagen por página a {$config->pdfDpi} DPI (PDF_MODO=imagen).");
    // Prueba real: convertir un PDF de una página (usa Ghostscript).
    $carpeta = sys_get_temp_dir() . '/aiocr_comprobar_' . bin2hex(random_bytes(4));
    try {
        mkdir($carpeta);
        file_put_contents("$carpeta/prueba.pdf", PdfSimple::crear(['Auto Evaluar']));
        (new PdfImagick())->convertir("$carpeta/prueba.pdf", 72, 1);
        Log::info('Imagick + Ghostscript: conversión de PDF a imagen OK');
    } catch (\Throwable $e) {
        Log::advertencia('ImageMagick no pudo convertir un PDF de prueba (¿falta Ghostscript, o la política '
            . 'de ImageMagick bloquea PDF?): ' . $e->getMessage());
    } finally {
        Servicio::borrarCarpeta($carpeta);
    }
}
if (PHP_OS_FAMILY === 'Windows' && $config->maxConcurrent > 1 && PHP_SAPI === 'cli') {
    Log::info('En Windows, php -S atiende una evaluación a la vez: las demás esperan su turno.');
}

Transcripciones::limpiarAntiguas($config, true);
exit(0);
