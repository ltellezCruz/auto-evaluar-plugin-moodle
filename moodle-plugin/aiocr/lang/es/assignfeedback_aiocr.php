<?php
// Textos en español de assignfeedback_aiocr («Auto Evaluar»).
// Debe tener las mismas claves que lang/en/assignfeedback_aiocr.php.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Auto Evaluar';
$string['enabled'] = 'Auto Evaluar';
$string['enabled_help'] = 'Si está activado, el profesor puede pedir una retroalimentación generada con IA para la entrega de un estudiante desde la pantalla de calificación. La entrega se envía a Google Gemini (o al proveedor de IA configurado) y el resultado se guarda en los comentarios de retroalimentación, sin cambiar la calificación.';
$string['default'] = 'Activado por defecto';

// Capacidad.
$string['aiocr:generate'] = 'Generar retroalimentación con IA (Auto Evaluar)';

// Configuración en Administración del sitio.
$string['settings:provider'] = 'Proveedor de IA';
$string['settings:provider_help'] = 'Qué adaptador atiende la solicitud. «Gemini (directo)» hace todo dentro de Moodle y solo necesita una clave de Gemini. «Servicio externo» usa el php-service aparte de versiones anteriores. «Prueba (sin IA)» devuelve un texto fijo. Los proveedores nuevos son clases en classes/local/ que implementan ai_provider_interface.';
$string['settings:fastapi_url'] = 'URL del servicio externo';
$string['settings:fastapi_url_help'] = 'Dirección base del servicio externo, sin barra final (por ejemplo http://127.0.0.1:8000).';
$string['settings:fastapi_apikey'] = 'Clave del servicio externo';
$string['settings:fastapi_apikey_help'] = 'Clave compartida que se envía al servicio externo en la cabecera X-Aiocr-Key. Debe ser idéntica a SERVICE_API_KEY en el archivo .env del servicio.';
$string['settings:fastapi_timeout'] = 'Tiempo de espera del servicio externo (segundos)';
$string['provider:fastapi_adapter'] = 'Servicio externo (php-service)';
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
$string['error:nourl'] = 'No se ha configurado la URL del servicio externo (Administración del sitio > Extensiones > Tarea > Extensiones de retroalimentación > Auto Evaluar).';
$string['error:connect'] = 'No se pudo conectar con el servicio externo ({$a->url}): {$a->error}';
$string['error:keyrejected'] = 'El servicio externo rechazó la clave. Revisa que «Clave del servicio externo» en la configuración del plugin sea igual a SERVICE_API_KEY en el .env del servicio.';
$string['error:service'] = 'Servicio externo ({$a->code}): {$a->detail}';
$string['error:status'] = 'El servicio externo respondió con estado {$a->code}. URL: {$a->url}. Respuesta: {$a->body}';
$string['error:empty'] = 'El servicio de IA no devolvió retroalimentación.';

// Proveedor de prueba.
$string['mock:feedback'] = 'Retroalimentación de prueba generada sin IA (proveedor «Prueba»). Archivos recibidos: {$a->files}. Texto en línea: {$a->onlinetext} caracteres.';

$string['settings:heading_gemini'] = 'Gemini (directo)';
$string['settings:heading_gemini_desc'] = 'Los usa el proveedor «Gemini (directo)». Moodle lee los archivos de la entrega y llama a la API de Gemini por HTTPS (generativelanguage.googleapis.com, a través del proxy del sitio si hay uno). No hace falta un servicio externo, ni un token de servicios web, ni abrir puertos.';
$string['settings:gemini_apikey'] = 'Clave de la API de Gemini';
$string['settings:gemini_apikey_help'] = 'Clave de Google AI Studio (https://aistudio.google.com/apikey). Se guarda en la base de datos de Moodle y la pueden ver los administradores del sitio.';
$string['settings:gemini_model'] = 'Modelo de Gemini';
$string['settings:gemini_model_help'] = 'Nombre del modelo para transcribir y evaluar, por ejemplo gemini-3.5-flash-lite.';
$string['settings:gemini_timeout'] = 'Tiempo de espera por llamada a Gemini (segundos)';
$string['settings:gemini_timeout_help'] = 'Una evaluación hace una llamada por archivo y una más para evaluar. El servidor web (PHP, Apache, Nginx) debe dejar terminar la evaluación completa.';
$string['settings:max_file_mb'] = 'Tamaño máximo por archivo (MB)';
$string['settings:max_file_mb_help'] = 'Los archivos más grandes se rechazan antes de llamar a Gemini.';
$string['settings:max_pages'] = 'Páginas máximas por entrega';
$string['settings:max_pages_help'] = 'Suma de las páginas de todos los PDF e imágenes. Las entregas más grandes se rechazan antes de llamar a Gemini.';
$string['settings:max_concurrent'] = 'Evaluaciones simultáneas';
$string['settings:max_concurrent_help'] = 'Cuántas evaluaciones pueden correr a la vez en este sitio. Las demás reciben un aviso de «ocupado».';
$string['settings:save_transcription'] = 'Guardar transcripciones';
$string['settings:save_transcription_help'] = 'Guarda la transcripción de cada entrega evaluada en la tabla del plugin, junto con los datos de la solicitud (tokens, duración, modelo).';
$string['settings:heading_service'] = 'Servicio externo (opcional)';
$string['settings:heading_service_desc'] = 'Solo para el proveedor «Servicio externo»: el php-service aparte de las versiones hasta la 0.4.0.';
$string['provider:gemini_adapter'] = 'Gemini (directo, sin servicio externo)';
$string['error:nogeminikey'] = 'No se ha configurado la clave de la API de Gemini (Administración del sitio > Extensiones > Tarea > Extensiones de retroalimentación > Auto Evaluar).';
$string['error:busy'] = 'Ya hay {$a} evaluaciones en curso. Intenta de nuevo en un momento.';
$string['error:filetoobig'] = 'El archivo «{$a->name}» pesa {$a->size} MB; el máximo es {$a->max} MB.';
$string['error:toomanypages'] = 'La entrega tiene {$a->pages} páginas en total; el máximo es {$a->max}.';
$string['error:nocontent'] = 'La entrega no tiene archivos PDF, PNG o JPG ni texto en línea. {$a}';
$string['error:pdfopen'] = 'No se pudo abrir el PDF «{$a->name}»: {$a->error}';
$string['error:pdfinvalid'] = 'El archivo no es un PDF.';
$string['error:pdfencrypted'] = 'El PDF está protegido con contraseña o cifrado.';
$string['error:pdfnopages'] = 'No se pudo contar las páginas del PDF (¿archivo dañado?).';
$string['error:transcription'] = 'No se pudo transcribir la entrega: {$a}';
$string['error:gemini'] = 'Gemini: {$a}';
$string['skipped:type'] = 'tipo de archivo no admitido: {$a} (solo PDF, PNG o JPG)';
$string['skipped:notfound'] = 'archivo no encontrado';
$string['teachercontext'] = 'Contexto para la IA (rúbrica, respuestas esperadas)';
$string['teachercontext_help'] = 'Opcional. Todo lo que debe guiar a la IA al retroalimentar esta tarea: preguntas, respuestas esperadas, criterios, tolerancias, qué ignorar. Si la tarea usa una rúbrica o guía de evaluación (Calificación avanzada), se incluye sola. Los estudiantes nunca ven este texto.';
$string['teacherfiles'] = 'Archivos del profesor para la IA';
$string['teacherfiles_help'] = 'Opcional. Rúbrica, enunciado o solucionario en PDF, PNG o JPG. Se usan al preparar el contexto (ver «Preparar rúbrica con IA»); no se envían en cada evaluación. Los estudiantes no los ven.';
$string['preparecontext'] = 'Preparar rúbrica con IA';
$string['preparedcontext'] = 'Contexto preparado';
$string['preparedcontext_help'] = 'Es lo que la IA usará para evaluar a cada estudiante de esta tarea. Genéralo desde tu material y luego revísalo y corrígelo: respuestas esperadas, criterios y lo que aparezca en «Vacíos o ambigüedades». Si está vacío, se usa el material tal cual (sin los archivos).';
$string['prepare:generate'] = 'Generar con IA (reemplaza el texto)';
$string['prepare:save'] = 'Guardar cambios';
$string['prepare:clear'] = 'Borrar contexto preparado';
$string['prepare:done'] = 'Contexto preparado ({$a} tokens). Revísalo y guarda las correcciones.';
$string['prepare:cleared'] = 'Contexto preparado borrado. Las evaluaciones usarán el material tal cual.';
$string['prepare:saved'] = 'Contexto preparado guardado.';
$string['prepare:intro'] = 'Gemini lee una sola vez tu material de esta tarea (descripción, rúbrica, contexto y archivos) y lo ordena en preguntas, respuestas esperadas y criterios. Aquí lo revisas y corriges, y desde entonces guía la evaluación de cada estudiante. La IA solo sugiere niveles de la rúbrica; la nota la decides tú.';
$string['prepare:disabled'] = 'Auto Evaluar no está activado en esta tarea (Configuración > Tipos de retroalimentación).';
$string['prepare:otherprovider'] = 'El proveedor de IA del sitio no es «Gemini (directo)»: el contexto preparado solo lo usa ese proveedor.';
$string['prepare:sources'] = 'Material del profesor encontrado';
$string['prepare:source:rubric'] = 'Rúbrica o guía de evaluación en Moodle: {$a}';
$string['prepare:source:context'] = 'Contexto escrito en los ajustes de la tarea: {$a}';
$string['prepare:source:files'] = 'Archivos (del plugin y archivos adicionales de la tarea): {$a}';
$string['prepare:source:skipped'] = 'No se usan (tipo o tamaño): {$a}';
$string['prepare:method:rubric'] = 'Rúbrica';
$string['prepare:method:guide'] = 'Guía de evaluación';
$string['prepare:none'] = 'Todavía no hay contexto preparado: las evaluaciones usan el material tal cual (sin los archivos).';
$string['prepare:outdated'] = 'El material cambió después de preparar el contexto (rúbrica, contexto, descripción o archivos). Vuelve a generarlo o revísalo y guárdalo.';
$string['prepare:current'] = 'Contexto preparado al día (último cambio: {$a}).';
$string['contextstatus:sources'] = 'Contexto del profesor: material de la tarea tal cual (sin preparar)';
$string['contextstatus:prepared'] = 'Contexto del profesor: preparado y revisado';
$string['contextstatus:outdated'] = 'Contexto del profesor: preparado, pero el material cambió después';
$string['error:nocontext'] = 'No hay material del profesor para preparar.';
// Privacidad.
$string['privacy:metadata'] = 'El plugin Auto Evaluar guarda la transcripción, la respuesta y el estado de cada solicitud de retroalimentación generada con IA, asociados a una entrega y al profesor que la pidió, para fines de auditoría.';
$string['privacy:metadata:assignfeedback_aiocr'] = 'Información de cada solicitud de retroalimentación de Auto Evaluar.';
$string['privacy:metadata:assignfeedback_aiocr:grade'] = 'El registro de calificación al que se asocia la retroalimentación.';
$string['privacy:metadata:assignfeedback_aiocr:provider'] = 'El proveedor de IA que generó la retroalimentación.';
$string['privacy:metadata:assignfeedback_aiocr:rawresponse'] = 'La respuesta completa devuelta por el proveedor de IA.';
$string['privacy:metadata:assignfeedback_aiocr:usermodified'] = 'El profesor que pidió la retroalimentación.';
$string['privacy:metadata:assignfeedback_aiocr:timecreated'] = 'Cuándo se pidió la retroalimentación.';
$string['privacy:metadata:assignfeedback_aiocr:transcription'] = 'La transcripción de la entrega que se evaluó.';
$string['privacy:metadata:gemini'] = 'Con el proveedor «Gemini (directo)», la entrega se envía a la API de Google Gemini para transcribirla y evaluarla.';
$string['privacy:metadata:gemini:submission'] = 'Los archivos entregados (PDF o imágenes) y el texto en línea.';
$string['privacy:metadata:service'] = 'Con el proveedor «Servicio externo», los datos de la entrega se envían al servicio configurado, que descarga los archivos y los envía a Google Gemini.';
$string['privacy:metadata:service:submission'] = 'Los id de la tarea y del usuario, el nombre de usuario, los enlaces a los archivos entregados y el texto en línea.';
