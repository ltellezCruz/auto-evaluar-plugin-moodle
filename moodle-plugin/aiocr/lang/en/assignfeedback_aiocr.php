<?php
// English strings for assignfeedback_aiocr ("Auto Evaluar").
// Every key here must also exist in lang/es/assignfeedback_aiocr.php.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Auto Evaluar';
$string['enabled'] = 'Auto Evaluar';
$string['enabled_help'] = 'If enabled, teachers can request AI-generated feedback for a student submission from the grading screen. The submission is sent to an external AI service (configurable) and the resulting comment is stored as feedback comments, without changing the grade.';
$string['default'] = 'Enabled by default';

// Capability.
$string['aiocr:generate'] = 'Generate AI feedback with Auto Evaluar';

// Site administration settings.
$string['settings:provider'] = 'AI provider';
$string['settings:provider_help'] = 'Which adapter class handles the request. Add new providers by creating a class in classes/local/ that implements ai_provider_interface; no core code changes needed. "Test (no AI)" returns a fixed text and is meant for trying the plugin without the service.';
$string['settings:fastapi_url'] = 'FastAPI service URL';
$string['settings:fastapi_url_help'] = 'Base URL of the FastAPI service, without a trailing slash (e.g. http://127.0.0.1:8000).';
$string['settings:fastapi_apikey'] = 'FastAPI API key';
$string['settings:fastapi_apikey_help'] = 'Shared secret sent to the FastAPI service in the X-Aiocr-Key header. It must be identical to SERVICE_API_KEY in the service .env file. Without it the service rejects every request.';
$string['settings:fastapi_timeout'] = 'Request timeout (seconds)';
$string['provider:fastapi_adapter'] = 'FastAPI (Gemini)';
$string['provider:mock_adapter'] = 'Test (no AI)';

// Grading screen (template and JavaScript).
$string['requestfeedback'] = 'Auto Evaluar';
$string['processing'] = 'Processing with AI...';
$string['feedbackgenerated'] = 'Feedback generated. Reloading...';
$string['generatedon'] = 'AI feedback generated on {$a}';
$string['unknownerror'] = 'Unknown error.';
$string['networkerror'] = 'Network error:';

// Errors.
$string['nosubmission'] = 'This student has no submission to process.';
$string['erroraiservice'] = 'Error connecting to the AI service: {$a}';
$string['error:nourl'] = 'The FastAPI service URL is not configured (Site administration > Plugins > Assignment > Feedback plugins > Auto Evaluar).';
$string['error:connect'] = 'Could not connect to FastAPI ({$a->url}): {$a->error}';
$string['error:keyrejected'] = 'The FastAPI service rejected the key. Check that "FastAPI API key" in the plugin settings is identical to SERVICE_API_KEY in the FastAPI .env file.';
$string['error:service'] = 'FastAPI ({$a->code}): {$a->detail}';
$string['error:status'] = 'FastAPI responded with status {$a->code}. URL: {$a->url}. Response: {$a->body}';
$string['error:empty'] = 'The AI service returned no feedback.';

// Test provider.
$string['mock:feedback'] = 'Test feedback generated without AI (provider "Test"). Files received: {$a->files}. Online text: {$a->onlinetext} characters.';

// Privacy.
$string['privacy:metadata'] = 'The Auto Evaluar plugin stores the raw response and status of AI-generated feedback requests, linked to a specific submission and grader, for auditability.';
$string['privacy:metadata:assignfeedback_aiocr'] = 'Information about each Auto Evaluar feedback request.';
$string['privacy:metadata:assignfeedback_aiocr:grade'] = 'The grade record this AI feedback is linked to.';
$string['privacy:metadata:assignfeedback_aiocr:provider'] = 'Which AI provider generated this feedback.';
$string['privacy:metadata:assignfeedback_aiocr:rawresponse'] = 'The raw response returned by the AI provider.';
$string['privacy:metadata:assignfeedback_aiocr:usermodified'] = 'The teacher who triggered the AI feedback request.';
$string['privacy:metadata:assignfeedback_aiocr:timecreated'] = 'When the AI feedback request was made.';
