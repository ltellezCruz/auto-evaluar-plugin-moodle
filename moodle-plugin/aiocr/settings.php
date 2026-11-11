<?php
// mod/assign/feedback/aiocr/settings.php
//
// Moodle incluye automáticamente ESTE archivo (por convención de nombre
// y ubicación) al construir la página de administración de plugins de
// retroalimentación de tareas.
//
// Dos grupos de ajustes:
//   - Gemini (proveedor autónomo, por defecto): todo ocurre dentro de Moodle.
//   - Servicio externo (opcional): el servicio php-service/ de versiones
//     anteriores. Los nombres fastapi_* se conservan para no tocar la base
//     de datos de los sitios que ya lo usan.

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/mod/assign/feedback/aiocr/classes/local/provider_factory.php');

    $settings->add(new admin_setting_configselect(
        'assignfeedback_aiocr/provider',
        get_string('settings:provider', 'assignfeedback_aiocr'),
        get_string('settings:provider_help', 'assignfeedback_aiocr'),
        'gemini_adapter',
        \assignfeedback_aiocr\local\provider_factory::get_available_providers()
    ));

    // --- Gemini directo (proveedor autónomo) ---
    $settings->add(new admin_setting_heading(
        'assignfeedback_aiocr/heading_gemini',
        get_string('settings:heading_gemini', 'assignfeedback_aiocr'),
        get_string('settings:heading_gemini_desc', 'assignfeedback_aiocr')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'assignfeedback_aiocr/gemini_apikey',
        get_string('settings:gemini_apikey', 'assignfeedback_aiocr'),
        get_string('settings:gemini_apikey_help', 'assignfeedback_aiocr'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/gemini_model',
        get_string('settings:gemini_model', 'assignfeedback_aiocr'),
        get_string('settings:gemini_model_help', 'assignfeedback_aiocr'),
        \assignfeedback_aiocr\local\gemini_adapter::MODELO_POR_DEFECTO,
        '/^[a-zA-Z0-9._-]+$/'
    ));

    // Por llamada. Una evaluación hace (archivos + 1) llamadas.
    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/gemini_timeout',
        get_string('settings:gemini_timeout', 'assignfeedback_aiocr'),
        get_string('settings:gemini_timeout_help', 'assignfeedback_aiocr'),
        120,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/max_file_mb',
        get_string('settings:max_file_mb', 'assignfeedback_aiocr'),
        get_string('settings:max_file_mb_help', 'assignfeedback_aiocr'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/max_pages',
        get_string('settings:max_pages', 'assignfeedback_aiocr'),
        get_string('settings:max_pages_help', 'assignfeedback_aiocr'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/max_concurrent',
        get_string('settings:max_concurrent', 'assignfeedback_aiocr'),
        get_string('settings:max_concurrent_help', 'assignfeedback_aiocr'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'assignfeedback_aiocr/save_transcription',
        get_string('settings:save_transcription', 'assignfeedback_aiocr'),
        get_string('settings:save_transcription_help', 'assignfeedback_aiocr'),
        1
    ));

    // --- Modelo local (Ollama), p. ej. Gemma ---
    $settings->add(new admin_setting_heading(
        'assignfeedback_aiocr/heading_local',
        get_string('settings:heading_local', 'assignfeedback_aiocr'),
        get_string('settings:heading_local_desc', 'assignfeedback_aiocr',
            get_string(\assignfeedback_aiocr\local\rasterizador::disponible() !== null
                ? 'settings:rasterizer_ok' : 'settings:rasterizer_missing', 'assignfeedback_aiocr',
                \assignfeedback_aiocr\local\rasterizador::disponible()))
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/local_url',
        get_string('settings:local_url', 'assignfeedback_aiocr'),
        get_string('settings:local_url_help', 'assignfeedback_aiocr'),
        \assignfeedback_aiocr\local\ollama_adapter::URL_POR_DEFECTO,
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/local_model',
        get_string('settings:local_model', 'assignfeedback_aiocr'),
        get_string('settings:local_model_help', 'assignfeedback_aiocr'),
        \assignfeedback_aiocr\local\ollama_adapter::MODELO_POR_DEFECTO,
        '/^[a-zA-Z0-9._:\/-]+$/'
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/local_timeout',
        get_string('settings:local_timeout', 'assignfeedback_aiocr'),
        get_string('settings:local_timeout_help', 'assignfeedback_aiocr'),
        600,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/local_num_ctx',
        get_string('settings:local_num_ctx', 'assignfeedback_aiocr'),
        get_string('settings:local_num_ctx_help', 'assignfeedback_aiocr'),
        16384,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/local_dpi',
        get_string('settings:local_dpi', 'assignfeedback_aiocr'),
        get_string('settings:local_dpi_help', 'assignfeedback_aiocr'),
        150,
        PARAM_INT
    ));

    // --- Servicio externo (opcional) ---
    $settings->add(new admin_setting_heading(
        'assignfeedback_aiocr/heading_service',
        get_string('settings:heading_service', 'assignfeedback_aiocr'),
        get_string('settings:heading_service_desc', 'assignfeedback_aiocr')
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/fastapi_url',
        get_string('settings:fastapi_url', 'assignfeedback_aiocr'),
        get_string('settings:fastapi_url_help', 'assignfeedback_aiocr'),
        'http://127.0.0.1:8000',
        PARAM_URL
    ));

    // Misma clave que SERVICE_API_KEY en el .env del servicio.
    $settings->add(new admin_setting_configpasswordunmask(
        'assignfeedback_aiocr/fastapi_apikey',
        get_string('settings:fastapi_apikey', 'assignfeedback_aiocr'),
        get_string('settings:fastapi_apikey_help', 'assignfeedback_aiocr'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'assignfeedback_aiocr/fastapi_timeout',
        get_string('settings:fastapi_timeout', 'assignfeedback_aiocr'),
        '',
        240,
        PARAM_INT
    ));
}
