<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Feedback';
$string['enabled'] = 'feedback';
$string['enabled_help'] = 'If enabled, teachers can request AI-generated feedback for a student submission from the grading screen. The submission is sent to an external AI/OCR service (configurable) and the resulting comment is stored as feedback.';
$string['default'] = 'Enabled by default';

// Capacidad
$string['aiocr:generate'] = 'Generate AI/OCR feedback';

// Configuración de administrador
$string['settings:provider'] = 'AI provider';
$string['settings:provider_help'] = 'Which adapter class handles the AI/OCR request. Add new providers by creating a class in classes/local/ that implements ai_provider_interface, then select it here — no core code changes needed.';
$string['settings:fastapi_url'] = 'FastAPI service URL';
$string['settings:fastapi_url_help'] = 'Base URL of your FastAPI service (e.g. http://127.0.0.1:8000). Used only by the "FastAPI local" provider.';
$string['settings:fastapi_timeout'] = 'Request timeout (seconds)';
$string['provider:fastapi_adapter'] = 'FastAPI (servicio local/personalizado)';

// Interfaz (JS / Mustache)
$string['requestfeedback'] = 'Auto Evaluar';
$string['processing'] = 'Procesando con IA...';
$string['feedbackgenerated'] = 'Retroalimentación generada';
$string['erroraiservice'] = 'Error al conectar con el servicio de IA: {$a}';
$string['nosubmission'] = 'Este estudiante no tiene una entrega para procesar.';

// Privacidad
$string['privacy:metadata'] = 'The AI/OCR Feedback plugin stores the raw response and status of AI-generated feedback requests, linked to a specific submission and grader, for auditability.';
$string['privacy:metadata:assignfeedback_aiocr'] = 'Information about each AI/OCR feedback request.';
$string['privacy:metadata:assignfeedback_aiocr:grade'] = 'The grade record this AI feedback is linked to.';
$string['privacy:metadata:assignfeedback_aiocr:provider'] = 'Which AI provider generated this feedback.';
$string['privacy:metadata:assignfeedback_aiocr:rawresponse'] = 'The raw response returned by the AI provider.';
$string['privacy:metadata:assignfeedback_aiocr:usermodified'] = 'The teacher who triggered the AI feedback request.';
$string['privacy:metadata:assignfeedback_aiocr:timecreated'] = 'When the AI feedback request was made.';
