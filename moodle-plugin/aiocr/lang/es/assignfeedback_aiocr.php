<?php
// Textos en español de assignfeedback_aiocr («Auto Evaluar»).
// Debe tener las mismas claves que lang/en/assignfeedback_aiocr.php.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Auto Evaluar';
$string['enabled'] = 'Auto Evaluar';
$string['enabled_help'] = 'Si está activado, el profesor puede pedir una retroalimentación generada con IA para la entrega de un estudiante desde la pantalla de calificación. La entrega se envía a un servicio de IA externo (configurable) y el resultado se guarda en los comentarios de retroalimentación, sin cambiar la calificación.';
$string['default'] = 'Activado por defecto';

// Capacidad.
$string['aiocr:generate'] = 'Generar retroalimentación con IA (Auto Evaluar)';

// Configuración en Administración del sitio.
$string['settings:provider'] = 'Proveedor de IA';
$string['settings:provider_help'] = 'Clase adaptadora que atiende la solicitud. Para agregar proveedores se crea una clase en classes/local/ que implemente ai_provider_interface, sin tocar el núcleo. «Prueba (sin IA)» devuelve un texto fijo y sirve para probar el plugin sin el servicio.';
$string['settings:fastapi_url'] = 'URL del servicio FastAPI';
$string['settings:fastapi_url_help'] = 'Dirección base del servicio FastAPI, sin barra final (por ejemplo http://127.0.0.1:8000).';
$string['settings:fastapi_apikey'] = 'Clave del servicio FastAPI';
$string['settings:fastapi_apikey_help'] = 'Clave compartida que se envía al servicio FastAPI en la cabecera X-Aiocr-Key. Debe ser idéntica a SERVICE_API_KEY en el archivo .env del servicio. Sin ella, el servicio rechaza todas las solicitudes.';
$string['settings:fastapi_timeout'] = 'Tiempo de espera (segundos)';
$string['provider:fastapi_adapter'] = 'FastAPI (Gemini)';
$string['provider:mock_adapter'] = 'Prueba (sin IA)';

// Pantalla de calificación (plantilla y JavaScript).
$string['requestfeedback'] = 'Auto Evaluar';
$string['processing'] = 'Procesando con IA...';
$string['feedbackgenerated'] = 'Retroalimentación generada. Recargando...';
$string['generatedon'] = 'Retroalimentación con IA generada el {$a}';
$string['unknownerror'] = 'Error desconocido.';
$string['networkerror'] = 'Error de red:';

// Errores.
$string['nosubmission'] = 'Este estudiante no tiene una entrega para procesar.';
$string['erroraiservice'] = 'Error al conectar con el servicio de IA: {$a}';
$string['error:nourl'] = 'No se ha configurado la URL del servicio FastAPI (Administración del sitio > Extensiones > Tarea > Extensiones de retroalimentación > Auto Evaluar).';
$string['error:connect'] = 'No se pudo conectar con FastAPI ({$a->url}): {$a->error}';
$string['error:keyrejected'] = 'El servicio FastAPI rechazó la clave. Revisa que «Clave del servicio FastAPI» en la configuración del plugin sea igual a SERVICE_API_KEY en el .env de FastAPI.';
$string['error:service'] = 'FastAPI ({$a->code}): {$a->detail}';
$string['error:status'] = 'FastAPI respondió con estado {$a->code}. URL: {$a->url}. Respuesta: {$a->body}';
$string['error:empty'] = 'El servicio de IA no devolvió retroalimentación.';

// Proveedor de prueba.
$string['mock:feedback'] = 'Retroalimentación de prueba generada sin IA (proveedor «Prueba»). Archivos recibidos: {$a->files}. Texto en línea: {$a->onlinetext} caracteres.';

// Privacidad.
$string['privacy:metadata'] = 'El plugin Auto Evaluar guarda la respuesta y el estado de cada solicitud de retroalimentación generada con IA, asociados a una entrega y al profesor que la pidió, para fines de auditoría.';
$string['privacy:metadata:assignfeedback_aiocr'] = 'Información de cada solicitud de retroalimentación de Auto Evaluar.';
$string['privacy:metadata:assignfeedback_aiocr:grade'] = 'El registro de calificación al que se asocia la retroalimentación.';
$string['privacy:metadata:assignfeedback_aiocr:provider'] = 'El proveedor de IA que generó la retroalimentación.';
$string['privacy:metadata:assignfeedback_aiocr:rawresponse'] = 'La respuesta completa devuelta por el proveedor de IA.';
$string['privacy:metadata:assignfeedback_aiocr:usermodified'] = 'El profesor que pidió la retroalimentación.';
$string['privacy:metadata:assignfeedback_aiocr:timecreated'] = 'Cuándo se pidió la retroalimentación.';
