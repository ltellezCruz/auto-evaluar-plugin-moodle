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
        global $OUTPUT, $PAGE;

        // La retroalimentación generada ya se ve (y se edita) en
        // "Comentarios de retroalimentación"; aquí solo se indica cuándo
        // se generó por última vez.
        $ultima = $grade ? $this->ultima_generada($grade) : null;

        // Estado de la rúbrica / contexto del profesor que guiará la evaluación.
        $plugincontexto = \assignfeedback_aiocr\local\contexto_profesor::class;
        if (trim((string) $this->get_config('prepared')) === '') {
            $estado = 'contextstatus:sources';
            $aviso = false;
        } else {
            $aviso = $plugincontexto::desactualizado($this->assignment);
            $estado = $aviso ? 'contextstatus:outdated' : 'contextstatus:prepared';
        }
        $cm = $this->assignment->get_course_module();
        $puedepreparar = has_capability('moodle/course:manageactivities', $this->assignment->get_context());

        $datos = [
            'assignmentid' => $this->assignment->get_instance()->id,
            'userid'       => $userid,
            'ultima'       => $ultima ? get_string('generatedon', 'assignfeedback_aiocr', userdate($ultima->timecreated)) : null,
            'contexto'     => get_string($estado, 'assignfeedback_aiocr'),
            'contextoaviso' => $aviso,
            'prepararurl'  => $puedepreparar
                ? (new moodle_url('/mod/assign/feedback/aiocr/prepare.php', ['id' => $cm->id]))->out(false) : null,
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

        // Textos en el idioma del usuario (lang/), que el JavaScript recibe ya traducidos.
        $textos = json_encode([
            'generada'    => get_string('feedbackgenerated', 'assignfeedback_aiocr'),
            'desconocido' => get_string('unknownerror', 'assignfeedback_aiocr'),
            'red'         => get_string('networkerror', 'assignfeedback_aiocr'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Todo lo que llega del servidor (errores, detalles) se inserta como
        // texto (textContent), nunca como HTML: así no se puede inyectar código.
        $js = 'var AIOCR_TEXTOS = ' . $textos . ";\n" . <<<'JS'
function aiocrAviso(resultado, tipo, texto, detalle) {
    resultado.textContent = "";
    var caja = document.createElement("div");
    caja.className = "alert alert-" + tipo;
    caja.textContent = texto;
    if (detalle) {
        var pequeno = document.createElement("small");
        pequeno.textContent = detalle;
        caja.appendChild(document.createElement("br"));
        caja.appendChild(pequeno);
    }
    resultado.appendChild(caja);
}

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
    resultado.textContent = "";

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
            aiocrAviso(resultado, "danger", item.exception.message, item.exception.debuginfo);
            return;
        }

        if (item.data.success) {
            aiocrAviso(resultado, "success", AIOCR_TEXTOS.generada);
            setTimeout(function() { window.location.reload(); }, 1200);
        } else {
            aiocrAviso(resultado, "danger", item.data.error || AIOCR_TEXTOS.desconocido);
        }
    })
    .catch(function(error) {
        spinner.style.display = "none";
        boton.disabled = false;
        aiocrAviso(resultado, "danger", AIOCR_TEXTOS.red + " " + error);
    });
});
JS;

        $PAGE->requires->js_init_code($js);
    }

    /**
     * Último registro generado con éxito para esta calificación, o null.
     */
    private function ultima_generada(stdClass $grade): ?stdClass {
        global $DB;
        if (empty($grade->id)) {
            return null;
        }
        $registros = $DB->get_records('assignfeedback_aiocr', ['grade' => $grade->id, 'status' => 'done'],
            'timecreated DESC', '*', 0, 1);
        return $registros ? reset($registros) : null;
    }

    /**
     * Vista completa de solo lectura. El texto viene de un modelo de IA (y
     * en parte de lo que escribió el estudiante): se limpia con
     * format_text antes de mostrarlo.
     *
     * @param stdClass $grade El registro de mdl_assign_grades del estudiante actual.
     * @return string HTML a insertar en la página.
     */
    public function view(stdClass $grade) {
        $ultima = $this->ultima_generada($grade);
        if (!$ultima) {
            return '';
        }
        return format_text($ultima->feedbacktext, FORMAT_HTML, ['context' => $this->assignment->get_context()]);
    }

    /**
     * Resumen corto (tabla "Ver todas las entregas" y estado de la entrega
     * del estudiante): solo cuándo se generó, como texto escapado. La
     * retroalimentación en sí se ve en "Comentarios de retroalimentación".
     */
    public function view_summary(stdClass $grade, &$showviewlink) {
        $showviewlink = false;
        $ultima = $this->ultima_generada($grade);
        if (!$ultima) {
            return '-';
        }
        return s(get_string('generatedon', 'assignfeedback_aiocr', userdate($ultima->timecreated)));
    }

    /**
     * Hay algo que mostrar solo si alguna generación terminó bien (los
     * intentos con error quedan en la tabla para auditoría, pero no se
     * muestran).
     */
    public function is_empty(stdClass $grade) {
        return $this->ultima_generada($grade) === null;
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

        // Rúbrica / contexto del profesor (ver classes/local/contexto_profesor.php).
        $mform->addElement('textarea', 'assignfeedback_aiocr_context',
            get_string('teachercontext', 'assignfeedback_aiocr'), ['rows' => 8, 'cols' => 70]);
        $mform->setType('assignfeedback_aiocr_context', PARAM_TEXT);
        $mform->addHelpButton('assignfeedback_aiocr_context', 'teachercontext', 'assignfeedback_aiocr');
        $mform->hideIf('assignfeedback_aiocr_context', 'assignfeedback_aiocr_enabled', 'notchecked');

        $mform->addElement('filemanager', 'assignfeedback_aiocr_files',
            get_string('teacherfiles', 'assignfeedback_aiocr'), null, self::opciones_archivos());
        $mform->addHelpButton('assignfeedback_aiocr_files', 'teacherfiles', 'assignfeedback_aiocr');
        $mform->hideIf('assignfeedback_aiocr_files', 'assignfeedback_aiocr_enabled', 'notchecked');

        // La preparación se hace después de guardar (necesita la tarea creada).
        if ($this->assignment->has_instance()) {
            $url = new moodle_url('/mod/assign/feedback/aiocr/prepare.php', ['id' => $this->assignment->get_course_module()->id]);
            $mform->addElement('static', 'assignfeedback_aiocr_preparelink', '',
                html_writer::link($url, get_string('preparecontext', 'assignfeedback_aiocr')));
            $mform->hideIf('assignfeedback_aiocr_preparelink', 'assignfeedback_aiocr_enabled', 'notchecked');
        }
    }

    /** Opciones del selector de archivos del profesor. */
    private static function opciones_archivos(): array {
        $maxmb = max(1, (int) get_config('assignfeedback_aiocr', 'max_file_mb') ?: 20);
        return [
            'subdirs' => 0,
            'maxfiles' => 5,
            'maxbytes' => $maxmb * 1024 * 1024,
            'accepted_types' => \assignfeedback_aiocr\local\contexto_profesor::TIPOS_ARCHIVO,
        ];
    }

    /** Valores actuales del texto y de los archivos del profesor en el formulario. */
    public function data_preprocessing(&$defaultvalues) {
        $contextid = $this->assignment->has_instance() ? $this->assignment->get_context()->id : null;
        $borrador = file_get_submitted_draft_itemid('assignfeedback_aiocr_files');
        file_prepare_draft_area($borrador, $contextid, 'assignfeedback_aiocr',
            \assignfeedback_aiocr\local\contexto_profesor::AREA_ARCHIVOS, 0, self::opciones_archivos());
        $defaultvalues['assignfeedback_aiocr_files'] = $borrador;
        $defaultvalues['assignfeedback_aiocr_context'] = $this->assignment->has_instance()
            ? (string) $this->get_config('context') : '';
    }

    /** Guarda el texto y los archivos del profesor de esta tarea. */
    public function save_settings(stdClass $data) {
        $this->set_config('context', trim((string) ($data->assignfeedback_aiocr_context ?? '')));
        if (isset($data->assignfeedback_aiocr_files)) {
            file_save_draft_area_files($data->assignfeedback_aiocr_files, $this->assignment->get_context()->id,
                'assignfeedback_aiocr', \assignfeedback_aiocr\local\contexto_profesor::AREA_ARCHIVOS, 0,
                self::opciones_archivos());
        }
        return true;
    }
}
