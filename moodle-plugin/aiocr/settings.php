<?php
// mod/assign/feedback/aiocr/settings.php
//
// Moodle incluye automáticamente ESTE archivo (por convención de nombre
// y ubicación) al construir la página de administración de plugins de
// retroalimentación de tareas. Por eso la configuración global del
// plugin (URL de FastAPI, proveedor de IA) debe vivir aquí, y NO dentro
// de locallib.php como se había puesto antes.

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/mod/assign/feedback/aiocr/classes/local/provider_factory.php');

    $settings->add(new admin_setting_configselect(
        'assignfeedback_aiocr/provider',
        get_string('settings:provider', 'assignfeedback_aiocr'),
        get_string('settings:provider_help', 'assignfeedback_aiocr'),
        'fastapi_adapter',
        \assignfeedback_aiocr\local\provider_factory::get_available_providers()
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/fastapi_url',
        get_string('settings:fastapi_url', 'assignfeedback_aiocr'),
        get_string('settings:fastapi_url_help', 'assignfeedback_aiocr'),
        'http://127.0.0.1:8000',
        PARAM_URL
    ));

    // Una evaluación hace (páginas + 1) llamadas a Gemini: 1 página ya
    // tarda ~35 s. 240 s queda por debajo del límite habitual de PHP y
    // Apache (300 s en el paquete de Windows).
    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/fastapi_timeout',
        get_string('settings:fastapi_timeout', 'assignfeedback_aiocr'),
        '',
        240,
        PARAM_INT
    ));
}
