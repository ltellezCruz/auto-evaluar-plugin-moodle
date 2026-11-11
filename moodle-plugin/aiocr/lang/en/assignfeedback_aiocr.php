<?php
// English strings for assignfeedback_aiocr ("Auto Evaluar").
// Every key here must also exist in lang/es/assignfeedback_aiocr.php.

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Auto Evaluar';
$string['enabled'] = 'Auto Evaluar';
$string['enabled_help'] = 'If enabled, the teacher can request AI-generated feedback for a student submission from the grading screen. The submission is sent to Google Gemini (or to the configured AI provider) and the result is saved in the feedback comments, without changing the grade.';
$string['default'] = 'Enabled by default';

// Capability.
$string['aiocr:generate'] = 'Generate AI feedback with Auto Evaluar';

// Site administration settings.
$string['settings:provider'] = 'AI provider';
$string['settings:provider_help'] = 'Which adapter handles the request. "Gemini (direct)" does everything inside Moodle and needs only a Gemini API key. "External service" uses the separate php-service from earlier versions. "Test (no AI)" returns a fixed text. New providers are classes in classes/local/ that implement ai_provider_interface.';
$string['settings:fastapi_url'] = 'External service URL';
$string['settings:fastapi_url_help'] = 'Base URL of the external service, without a trailing slash (e.g. http://127.0.0.1:8000).';
$string['settings:fastapi_apikey'] = 'External service key';
$string['settings:fastapi_apikey_help'] = 'Shared secret sent to the external service in the X-Aiocr-Key header. It must be identical to SERVICE_API_KEY in the service .env file.';
$string['settings:fastapi_timeout'] = 'External service timeout (seconds)';
$string['provider:fastapi_adapter'] = 'External service (php-service)';
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
$string['error:nourl'] = 'The external service URL is not configured (Site administration > Plugins > Assignment > Feedback plugins > Auto Evaluar).';
$string['error:connect'] = 'Could not connect to the external service ({$a->url}): {$a->error}';
$string['error:keyrejected'] = 'The external service rejected the key. Check that "External service key" in the plugin settings is identical to SERVICE_API_KEY in the service .env file.';
$string['error:service'] = 'External service ({$a->code}): {$a->detail}';
$string['error:status'] = 'The external service responded with status {$a->code}. URL: {$a->url}. Response: {$a->body}';
$string['error:empty'] = 'The AI service returned no feedback.';

// Test provider.
$string['mock:feedback'] = 'Test feedback generated without AI (provider "Test"). Files received: {$a->files}. Online text: {$a->onlinetext} characters.';

$string['settings:heading_gemini'] = 'Gemini (direct)';
$string['settings:heading_gemini_desc'] = 'Used by the "Gemini (direct)" provider. Moodle reads the submission files itself and calls the Gemini API over HTTPS (generativelanguage.googleapis.com, through the site proxy if one is configured). No external service, web service token or extra port is needed.';
$string['settings:gemini_apikey'] = 'Gemini API key';
$string['settings:gemini_apikey_help'] = 'Key from Google AI Studio (https://aistudio.google.com/apikey). It is stored in the Moodle database and visible to site administrators.';
$string['settings:gemini_model'] = 'Gemini model';
$string['settings:gemini_model_help'] = 'Model name used for transcription and evaluation, for example gemini-3.5-flash-lite.';
$string['settings:gemini_timeout'] = 'Timeout per Gemini call (seconds)';
$string['settings:gemini_timeout_help'] = 'One evaluation makes one call per file plus one for the evaluation. The web server (PHP, Apache, Nginx) must allow the whole evaluation to finish.';
$string['settings:max_file_mb'] = 'Maximum file size (MB)';
$string['settings:max_file_mb_help'] = 'Larger files are rejected before calling Gemini.';
$string['settings:max_pages'] = 'Maximum pages per submission';
$string['settings:max_pages_help'] = 'Sum of the pages of all PDF files and images. Larger submissions are rejected before calling Gemini.';
$string['settings:max_concurrent'] = 'Simultaneous evaluations';
$string['settings:max_concurrent_help'] = 'How many evaluations can run at the same time on this site. Further requests receive a "busy" message.';
$string['settings:save_transcription'] = 'Save transcriptions';
$string['settings:save_transcription_help'] = 'Saves the transcription of each evaluated submission in the plugin table, together with the request metadata (tokens, duration, model).';
$string['settings:heading_service'] = 'External service (optional)';
$string['settings:heading_service_desc'] = 'Only for the "External service" provider: the separate php-service from versions up to 0.4.0.';
$string['provider:gemini_adapter'] = 'Gemini (direct, no external service)';
$string['error:nogeminikey'] = 'The Gemini API key is not configured (Site administration > Plugins > Assignment > Feedback plugins > Auto Evaluar).';
$string['error:busy'] = '{$a} evaluations are already running. Try again in a moment.';
$string['error:filetoobig'] = 'The file "{$a->name}" is {$a->size} MB; the maximum is {$a->max} MB.';
$string['error:toomanypages'] = 'The submission has {$a->pages} pages in total; the maximum is {$a->max}.';
$string['error:nocontent'] = 'The submission has no PDF, PNG or JPG files and no online text. {$a}';
$string['error:pdfopen'] = 'Could not open the PDF "{$a->name}": {$a->error}';
$string['error:pdfinvalid'] = 'The file is not a PDF.';
$string['error:pdfencrypted'] = 'The PDF is password-protected or encrypted.';
$string['error:pdfnopages'] = 'Could not count the pages of the PDF (damaged file?).';
$string['error:transcription'] = 'Could not transcribe the submission: {$a}';
$string['error:gemini'] = 'Gemini: {$a}';
$string['skipped:type'] = 'file type not supported: {$a} (PDF, PNG or JPG only)';
$string['skipped:notfound'] = 'file not found';
// Privacy.
$string['privacy:metadata'] = 'The Auto Evaluar plugin stores the transcription, raw response and status of AI-generated feedback requests, linked to a specific submission and grader, for auditability.';
$string['privacy:metadata:assignfeedback_aiocr'] = 'Information about each Auto Evaluar feedback request.';
$string['privacy:metadata:assignfeedback_aiocr:grade'] = 'The grade record this AI feedback is linked to.';
$string['privacy:metadata:assignfeedback_aiocr:provider'] = 'Which AI provider generated this feedback.';
$string['privacy:metadata:assignfeedback_aiocr:rawresponse'] = 'The raw response returned by the AI provider.';
$string['privacy:metadata:assignfeedback_aiocr:usermodified'] = 'The teacher who triggered the AI feedback request.';
$string['privacy:metadata:assignfeedback_aiocr:timecreated'] = 'When the AI feedback request was made.';
$string['privacy:metadata:assignfeedback_aiocr:transcription'] = 'The transcription of the submission that was evaluated.';
$string['privacy:metadata:gemini'] = 'With the "Gemini (direct)" provider, the submission is sent to the Google Gemini API to be transcribed and evaluated.';
$string['privacy:metadata:gemini:submission'] = 'The submitted files (PDF or images) and the online text.';
$string['privacy:metadata:service'] = 'With the "External service" provider, the submission details are sent to the configured external service, which downloads the files and sends them to Google Gemini.';
$string['privacy:metadata:service:submission'] = 'The assignment and user ids, the username, the links to the submitted files and the online text.';
