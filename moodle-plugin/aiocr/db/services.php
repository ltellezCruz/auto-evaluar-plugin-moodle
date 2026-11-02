<?php
// db/services.php — registra la función externa como parte de la
// "External API" de Moodle, disponible tanto por AJAX interno (desde la
// pantalla de calificación) como por REST/token si se necesitara desde
// un sistema externo.

defined('MOODLE_INTERNAL') || die();

$functions = [
    'assignfeedback_aiocr_generate_feedback' => [
        'classname'   => 'assignfeedback_aiocr\external\process_submission',
        'methodname'  => 'execute',
        'description' => 'Envía una entrega al proveedor de IA/OCR configurado y guarda el resultado como retroalimentación.',
        'type'        => 'write',
        'ajax'        => true,   // habilita la llamada AJAX desde el JavaScript de locallib.php
        'capabilities' => 'assignfeedback/aiocr:generate',
    ],
];

// Servicio agrupador (opcional): útil si quieres exponer esta función
// también vía token REST externo, además del uso interno por AJAX.
$services = [
    'AI OCR Feedback service' => [
        'functions'       => ['assignfeedback_aiocr_generate_feedback'],
        'restrictedusers' => 0,
        'enabled'         => 1,
        'shortname'       => 'assignfeedback_aiocr_service',
    ],
];
