<?php
namespace assignfeedback_aiocr\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use assignfeedback_aiocr\local\provider_factory;

/**
 * process_submission
 *
 * Este es el Moodle External API Endpoint: el punto de entrada que
 * llama el JavaScript de locallib.php vía AJAX. Su única responsabilidad es orquestar:
 *   1. Validar contexto/capacidad/parámetros (obligatorio en toda
 *      external function de Moodle).
 *   2. Reunir los datos de la entrega del estudiante.
 *   3. Pedirle al provider_factory el proveedor de IA configurado
 *      (sin saber cuál es).
 *   4. Guardar el resultado en la tabla assignfeedback_aiocr (DB API).
 *   5. Devolver una respuesta estructurada al frontend.
 */
class process_submission extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'ID de la actividad Tarea (mdl_assign.id)'),
            'userid'       => new external_value(PARAM_INT, 'ID del estudiante cuya entrega se procesa'),
        ]);
    }

    public static function execute(int $assignmentid, int $userid): array {
        global $DB, $USER;

        // --- 1. Validación estándar de Moodle ---
        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'userid'       => $userid,
        ]);

        [$course, $cm] = get_course_and_cm_from_instance($params['assignmentid'], 'assign');
        $context = \context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('assignfeedback/aiocr:generate', $context);

        // --- 2. Reunir los datos de la entrega (archivos, texto) ---
        $assign = new \assign($context, $cm, $course);
        $submission = $assign->get_user_submission($params['userid'], false);

        if (!$submission) {
            return [
                'success'      => false,
                'feedbacktext' => '',
                'error'        => get_string('nosubmission', 'assignfeedback_aiocr'),
            ];
        }

        $archivos = self::extraer_archivos_entrega($assign, $submission);

        // El username solo lo usa el servicio externo (fastapi_adapter).
        $estudiante = \core_user::get_user($params['userid']);

        $contextodatos = [
            'assignmentid' => $params['assignmentid'],
            'userid'       => $params['userid'],
            'username'     => $estudiante->username,
            'files'        => $archivos,
            'onlinetext'   => self::extraer_texto_en_linea($assign, $submission),
        ];

        // --- 3. Delegar al proveedor de IA configurado (genérico) ---
        $provider = provider_factory::get_provider();
        $resultado = $provider->generate_feedback($contextodatos);

        // --- 4a. Si Gemini respondió bien, escribirlo DIRECTO en el
        // campo real de "Comentarios de retroalimentación" de Moodle,
        // usando la API interna de calificación (mismo mecanismo que usa
        // el formulario de calificación manual, sin rodeos por REST). ---
        if ($resultado['success'] && !empty($resultado['feedbacktext'])) {
            $datosform = new \stdClass();
            $datosform->grade = -1; // no tocar la calificación numérica
            $datosform->attemptnumber = -1; // -1 = el intento más reciente
            $datosform->addattempt = 0;
            $datosform->workflowstate = '';
            $datosform->applytoall = 0;
            $datosform->assignfeedbackcomments_editor = [
                'text'   => $resultado['feedbacktext'],
                'format' => FORMAT_HTML,
            ];
            $assign->save_grade($params['userid'], $datosform);
        }

        // --- 4b. Persistir también en nuestra tabla propia (DB API),
        // para trazabilidad/auditoría de qué generó la IA y cuándo. ---
        $gradeobj = $assign->get_user_grade($params['userid'], true); // crea el registro de grade si no existe

        $registro = new \stdClass();
        $registro->assignment    = $params['assignmentid'];
        $registro->grade         = $gradeobj->id;
        $registro->userid        = $params['userid'];
        $registro->usermodified  = $USER->id;
        $registro->provider      = $provider->get_provider_name();
        $registro->status        = $resultado['success'] ? 'done' : 'error';
        $registro->feedbacktext  = $resultado['feedbacktext'] ?? '';
        $registro->rawresponse   = json_encode($resultado['raw'] ?? null);
        $registro->errormessage  = $resultado['error'] ?? null;
        $registro->transcription = $resultado['transcription'] ?? null;
        $registro->tokens        = (int) ($resultado['tokens'] ?? 0);
        $registro->timecreated   = time();
        $registro->timemodified  = time();

        $DB->insert_record('assignfeedback_aiocr', $registro);

        // --- 5. Respuesta al frontend ---
        return [
            'success'      => (bool) $resultado['success'],
            'feedbacktext' => (string) ($resultado['feedbacktext'] ?? ''),
            'error'        => $resultado['error'] ?? null,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'      => new external_value(PARAM_BOOL, 'Si la generación fue exitosa'),
            'feedbacktext' => new external_value(PARAM_RAW, 'Texto de retroalimentación generado'),
            'error'        => new external_value(PARAM_RAW, 'Mensaje de error, si lo hubo', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Extrae los archivos adjuntos de una entrega usando el File API de Moodle.
     */
    private static function extraer_archivos_entrega(\assign $assign, \stdClass $submission): array {
        $archivos = [];
        $fs = get_file_storage();
        $context = $assign->get_context();

        $files = $fs->get_area_files(
            $context->id,
            'assignsubmission_file',
            'submission_files',
            $submission->id,
            'filename',
            false
        );

        foreach ($files as $file) {
            $url = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                'assignsubmission_file',
                'submission_files',
                $submission->id,
                $file->get_filepath(),
                $file->get_filename()
            );

            // Convertimos a la variante "webservice/pluginfile.php", que
            // acepta el token como credencial en la URL. La URL normal
            // (pluginfile.php) exige una sesión de navegador iniciada, y
            // sin eso Moodle devuelve la página de login en vez del
            // archivo real — que es justo lo que estaba pasando.
            $urlconweservice = str_replace('/pluginfile.php/', '/webservice/pluginfile.php/', $url->out(false));

            $archivos[] = [
                'filename' => $file->get_filename(),
                'fileurl'  => $urlconweservice,
                'mimetype' => $file->get_mimetype(),
                // Los proveedores que corren dentro de Moodle (gemini_adapter)
                // leen el archivo directamente, sin URL ni token.
                'fileid'   => (int) $file->get_id(),
            ];
        }

        return $archivos;
    }

    /**
     * Extrae el texto en línea de la entrega, si el estudiante usó ese
     * tipo de entrega en vez de (o además de) subir un archivo.
     */
    private static function extraer_texto_en_linea(\assign $assign, \stdClass $submission): ?string {
        global $DB;
        $registro = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
        // html_to_text conserva párrafos y saltos de línea (strip_tags los perdía).
        return $registro ? trim(html_to_text($registro->onlinetext, 0, false)) : null;
    }
}
