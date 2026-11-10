<?php
// Moodle y Gemini simulados para las pruebas (enrutador de "php -S"):
//     GET  = Moodle (descarga de archivos)
//     POST = Gemini
// Cada petición se anota, una por línea en JSON, en el archivo de la
// variable AIOCR_FALSO_REGISTRO, para que la prueba revise qué se pidió.

require __DIR__ . '/../../src/PdfSimple.php';

use AutoEvaluar\Servicio\PdfSimple;

const TOKEN = 'token_de_prueba';

function anotar(array $peticion): void
{
    $archivo = getenv('AIOCR_FALSO_REGISTRO');
    if ($archivo) {
        file_put_contents($archivo, json_encode($peticion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}

function responder(int $codigo, string $tipo, string $cuerpo): void
{
    http_response_code($codigo);
    header('Content-Type: ' . $tipo);
    header('Content-Length: ' . strlen($cuerpo));
    echo $cuerpo;
}

function crearPdf(int $paginas): string
{
    $textos = [];
    for ($i = 1; $i <= $paginas; $i++) {
        $textos[] = "Ejercicio pagina $i: 2+2=5";
    }
    return PdfSimple::crear($textos);
}

$ruta = $_SERVER['REQUEST_URI'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    anotar(['GET', $ruta, $_SERVER['HTTP_HOST'] ?? null]);
    if (!str_contains($ruta, 'token=' . TOKEN)) {
        responder(200, 'application/json', json_encode(['error' => 'Ficha (token) no válida - ficha no encontrada']));
    } elseif (str_contains($ruta, '/texto')) {
        responder(200, 'text/plain', 'no es un pdf');
    } elseif (str_contains($ruta, '/una_pagina')) {
        responder(200, 'application/pdf', crearPdf(1));
    } else {
        responder(200, 'application/pdf', crearPdf(2));
    }
    return;
}

$cuerpo = json_decode((string) file_get_contents('php://input'), true);
anotar(['POST', $ruta, $cuerpo, $_SERVER['HTTP_X_GOOG_API_KEY'] ?? null]);
$texto = '<div>RETRO FALSA</div>';
foreach ($cuerpo['contents'][0]['parts'] ?? [] as $parte) {
    if (($parte['inline_data']['mime_type'] ?? '') === 'application/pdf') {
        // PDF entero (PDF_MODO=directo): una transcripción por página, con la marca que pide el prompt.
        $paginas = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', base64_decode($parte['inline_data']['data']));
        $texto = '';
        for ($i = 1; $i <= $paginas; $i++) {
            $texto .= "=== PAGINA $i ===\nTRANSCRIPCION FALSA\n";
        }
    } elseif (isset($parte['inline_data'])) {
        $texto = 'TRANSCRIPCION FALSA';
    }
}
responder(200, 'application/json', json_encode([
    'candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
]));
