<?php
// db/upgrade.php — pasos que Moodle ejecuta al actualizar el plugin en un
// sitio donde ya estaba instalado (Administración del sitio → Notificaciones
// o admin/cli/upgrade.php). Moodle llama a esta función con la versión
// que había en la base de datos y ejecuta solo los pasos posteriores.
//
// Reglas para agregar un paso (ver CHANGELOG.md y docs/actualizacion.md):
//   1. Subir $plugin->version en version.php.
//   2. Agregar un bloque "if ($oldversion < NUEVA_VERSION)" al final, con
//      upgrade_plugin_savepoint(true, NUEVA_VERSION, ...) al cerrarlo.
//   3. Nunca modificar pasos anteriores: ya se ejecutaron en otros sitios.
//   4. Si el paso cambia tablas, usar el XMLDB editor y actualizar también
//      db/install.xml (las instalaciones nuevas no pasan por aquí).

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion Versión del plugin registrada antes de actualizar.
 * @return bool
 */
function xmldb_assignfeedback_aiocr_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026110301) {
        // 0.1.0 → 0.2.0. El timeout por defecto era 30 s y una evaluación
        // de una página ya tarda ~35 s: se sube a 240 s solo si el sitio
        // conserva el valor antiguo o no tiene ninguno. Un valor elegido
        // por el administrador (distinto de 30) se respeta.
        $timeout = (int) get_config('assignfeedback_aiocr', 'fastapi_timeout');
        if ($timeout <= 30) {
            set_config('fastapi_timeout', 240, 'assignfeedback_aiocr');
        }

        // La clave del servicio (fastapi_apikey) es nueva y no se puede
        // generar aquí: debe ser igual a SERVICE_API_KEY de FastAPI. Moodle
        // la muestra como ajuste nuevo al terminar la actualización.

        upgrade_plugin_savepoint(true, 2026110301, 'assignfeedback', 'aiocr');
    }

    if ($oldversion < 2026111000) {
        // 0.4.0 → 0.5.0. Proveedor autónomo (gemini_adapter): la
        // transcripción y los tokens de cada solicitud se guardan en la
        // tabla del plugin en lugar de archivos del servicio externo.
        $dbman = $DB->get_manager();
        $tabla = new xmldb_table('assignfeedback_aiocr');
        $campos = [
            new xmldb_field('transcription', XMLDB_TYPE_TEXT, null, null, null, null, null, 'errormessage'),
            new xmldb_field('tokens', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'transcription'),
        ];
        foreach ($campos as $campo) {
            if (!$dbman->field_exists($tabla, $campo)) {
                $dbman->add_field($tabla, $campo);
            }
        }

        // Los sitios que ya usaban el servicio externo lo conservan: el
        // proveedor solo cambia si el administrador lo elige. Los ajustes
        // nuevos (clave de Gemini, límites) aparecen al terminar.

        upgrade_plugin_savepoint(true, 2026111000, 'assignfeedback', 'aiocr');
    }

    return true;
}
