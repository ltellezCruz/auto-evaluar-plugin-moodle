<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/feedbackplugin.php');

/**
 * assign_feedback_aiocr
 *
 * Esta es la clase que el núcleo de mod_assign reconoce y carga
 * automáticamente por convención de nombre y ubicación de carpeta
 * (mod/assign/feedback/aiocr/locallib.php) — así es como Moodle permite
 * ampliar mod_assign SIN tocar su código: el "core" de assign busca
 * subcarpetas en feedback/*, y por cada una espera encontrar una clase
 * assign_feedback_<nombrecarpeta> que extienda assign_feedback_plugin.
 *
 * Aquí se implementan los "hooks" (puntos de extensión) que Moodle llama
 * en los momentos oportunos del ciclo de vida de una entrega.
 */
class assign_feedback_aiocr extends assign_feedback_plugin {

    /** @return string Nombre visible del plugin (usa las cadenas de idioma). */
    public function get_name() {
        return get_string('pluginname', 'assignfeedback_aiocr');
    }

    /**
     * ESTE es el hook correcto para insertar UI dentro del formulario
     * ACTIVO de calificación (donde está "Comentarios de retroalimentación"
     * con el botón "Guardar cambios"). view() sólo sirve para pantallas
     * de solo lectura (resumen de todas las entregas), por eso el botón
     * no aparecía antes.
     *
     * Debe devolver true si agregó algo al formulario.
     */
    public function get_form_elements_for_user($grade, MoodleQuickForm $mform, stdClass $data, $userid) {
        global $OUTPUT, $DB, $PAGE;

        $anterior = null;
        if ($grade && !empty($grade->id)) {
            $anterior = $DB->get_record('assignfeedback_aiocr', [
                'grade'  => $grade->id,
                'status' => 'done',
            ], '*', IGNORE_MULTIPLE);
        }

        $datos = [
            'assignmentid'     => $this->assignment->get_instance()->id,
            'userid'           => $userid,
            'existingfeedback' => $anterior ? $anterior->feedbacktext : null,
        ];

        $html = $OUTPUT->render_from_template('assignfeedback_aiocr/feedback_button', $datos);

        $mform->addElement('static', 'assignfeedback_aiocr_button', $this->get_name(), $html);

        self::asegurar_js_enganchado($PAGE);

        return true;
    }

    /**
     * Agrega el JavaScript UNA sola vez por página, usando delegación de
     * eventos sobre document. Esto es necesario porque la pantalla de
     * calificación de Moodle 4.x carga este panel por AJAX
     * (core_get_fragment) — cualquier <script> insertado junto con ese
     * HTML nunca se ejecuta (regla de seguridad de los navegadores para
     * contenido insertado dinámicamente). $PAGE->requires->js_init_code
     * sí garantiza la ejecución, sin importar cómo se cargó el HTML del
     * botón, porque delega el clic desde 'document' en vez de engancharse
     * directamente al botón.
     */
    private static function asegurar_js_enganchado($PAGE) {
        static $ya_agregado = false;
        if ($ya_agregado) {
            return;
        }
        $ya_agregado = true;

        $js = <<<'JS'
document.addEventListener('click', function(e) {
    var boton = e.target.closest('[data-action="aiocr-generate"]');
    if (!boton) {
        return;
    }

    var contenedor = boton.closest('[data-region="aiocr-feedback-region"]');
    var spinner = contenedor.querySelector('[data-region="aiocr-spinner"]');
    var resultado = contenedor.querySelector('[data-region="aiocr-result"]');

    boton.disabled = true;
    spinner.style.display = "";
    resultado.innerHTML = "";

    var url = M.cfg.wwwroot + "/lib/ajax/service.php?sesskey=" + M.cfg.sesskey
        + "&info=assignfeedback_aiocr_generate_feedback";

    fetch(url, {
        method: "POST",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify([{
            index: 0,
            methodname: "assignfeedback_aiocr_generate_feedback",
            args: {
                assignmentid: parseInt(boton.dataset.assignmentid, 10),
                userid: parseInt(boton.dataset.userid, 10)
            }
        }])
    })
    .then(function(respuesta) { return respuesta.json(); })
    .then(function(datos) {
        var item = datos[0];
        spinner.style.display = "none";
        boton.disabled = false;

        if (item.error) {
            var detalle = item.exception.message;
            if (item.exception.debuginfo) {
                detalle += '<br><br><small>' + item.exception.debuginfo + '</small>';
            }
            resultado.innerHTML = '<div class="alert alert-danger">' + detalle + '</div>';
            return;
        }

        if (item.data.success) {
            resultado.innerHTML = '<div class="alert alert-success">Retroalimentación generada. Recargando...</div>';
            setTimeout(function() { window.location.reload(); }, 1200);
        } else {
            resultado.innerHTML = '<div class="alert alert-danger">' + (item.data.error || "Error desconocido") + '</div>';
        }
    })
    .catch(function(error) {
        spinner.style.display = "none";
        boton.disabled = false;
        resultado.innerHTML = '<div class="alert alert-danger">Error de red: ' + error + '</div>';
    });
});
JS;

        $PAGE->requires->js_init_code($js);
    }

    /**
     * Renderiza el botón + resultado en pantallas de SOLO LECTURA
     * (por ejemplo, el resumen de "Ver todas las entregas").
     *
     * @param stdClass $grade El registro de mdl_assign_grades del estudiante actual.
     * @return string HTML a insertar en la página.
     */
    public function view(stdClass $grade) {
        global $OUTPUT, $DB;

        // Buscamos si ya existe una retroalimentación generada antes,
        // para mostrarla de una vez (vía DB API: $DB->get_record).
        $anterior = $DB->get_record('assignfeedback_aiocr', [
            'grade'  => $grade->id,
            'status' => 'done',
        ], '*', IGNORE_MULTIPLE);

        $datos = [
            'assignmentid'      => $this->assignment->get_instance()->id,
            'userid'            => $grade->userid,
            'existingfeedback'  => $anterior ? $anterior->feedbacktext : null,
        ];

        return $OUTPUT->render_from_template('assignfeedback_aiocr/feedback_button', $datos);
    }

    /**
     * Resumen corto que Moodle muestra en la lista de calificaciones
     * (tabla "Ver todas las entregas").
     */
    public function view_summary(stdClass $grade, &$showviewlink) {
        global $DB;
        $showviewlink = false;

        $registro = $DB->get_record('assignfeedback_aiocr', [
            'grade'  => $grade->id,
            'status' => 'done',
        ], '*', IGNORE_MULTIPLE);

        if (!$registro) {
            return '-';
        }

        return shorten_text($registro->feedbacktext, 100);
    }

    /**
     * Declara si este plugin tiene datos guardados para una entrega,
     * usado por Moodle internamente (por ejemplo, para saber si hay
     * algo que mostrar/exportar).
     */
    public function is_empty(stdClass $grade) {
        global $DB;
        return !$DB->record_exists('assignfeedback_aiocr', ['grade' => $grade->id]);
    }

    /**
     * Configuración a nivel de la actividad "Tarea" (aparece cuando el
     * profesor edita los ajustes de la tarea, sección "Retroalimentación").
     * La configuración GLOBAL del proveedor de IA (URL de FastAPI, etc.)
     * vive en Administración del sitio, definida en settings.php.
     */
    public function get_settings(MoodleQuickForm $mform) {
        $mform->addElement(
            'static',
            'assignfeedback_aiocr_info',
            '',
            get_string('enabled_help', 'assignfeedback_aiocr')
        );
    }
}
