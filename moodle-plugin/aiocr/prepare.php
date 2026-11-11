<?php
// This file is part of Moodle - https://moodle.org/
//
// «Preparar rúbrica»: el profesor pide a Gemini que ordene su material
// (descripción de la tarea, rúbrica de Moodle, contexto escrito y archivos)
// en un contexto de evaluación, lo revisa, lo corrige y lo guarda. Después,
// cada evaluación de un estudiante usa esa versión (ver
// classes/local/contexto_profesor.php).

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use assignfeedback_aiocr\local\contexto_profesor;
use assignfeedback_aiocr\local\gemini\evaluacion_exception;
use assignfeedback_aiocr\local\gemini_adapter;

$cmid = required_param('id', PARAM_INT);
[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'assign');
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('moodle/course:manageactivities', $context);

$assign = new assign($context, $cm, $course);
$url = new moodle_url('/mod/assign/feedback/aiocr/prepare.php', ['id' => $cm->id]);
$volver = new moodle_url('/mod/assign/view.php', ['id' => $cm->id]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('preparecontext', 'assignfeedback_aiocr'));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

$plugin = contexto_profesor::plugin($assign);
$form = new \assignfeedback_aiocr\form\preparar_form($url, ['cmid' => $cm->id]);

if ($form->is_cancelled()) {
    redirect($volver);
}
if ($datos = $form->get_data()) {
    if (!empty($datos->generate)) {
        try {
            [, $consumo] = contexto_profesor::preparar($assign);
            redirect($url, get_string('prepare:done', 'assignfeedback_aiocr', $consumo['total']),
                null, \core\output\notification::NOTIFY_SUCCESS);
        } catch (evaluacion_exception $e) {
            redirect($url, gemini_adapter::mensaje($e), null, \core\output\notification::NOTIFY_ERROR);
        }
    } else if (!empty($datos->clear)) {
        contexto_profesor::borrar($assign);
        redirect($url, get_string('prepare:cleared', 'assignfeedback_aiocr'), null, \core\output\notification::NOTIFY_INFO);
    } else {
        $texto = trim((string) $datos->prepared);
        if ($texto === '') {
            contexto_profesor::borrar($assign);
        } else {
            contexto_profesor::guardar($assign, $texto);
        }
        redirect($url, get_string('prepare:saved', 'assignfeedback_aiocr'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$fuentes = contexto_profesor::fuentes($assign);
$form->set_data(['id' => $cm->id, 'prepared' => (string) $plugin->get_config('prepared')]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('preparecontext', 'assignfeedback_aiocr') . ': ' . format_string($assign->get_instance()->name));
echo html_writer::tag('p', get_string('prepare:intro', 'assignfeedback_aiocr'));

if (!$plugin->is_enabled()) {
    echo $OUTPUT->notification(get_string('prepare:disabled', 'assignfeedback_aiocr'), 'warning');
}
if (get_config('assignfeedback_aiocr', 'provider') !== 'gemini_adapter' && get_config('assignfeedback_aiocr', 'provider')) {
    echo $OUTPUT->notification(get_string('prepare:otherprovider', 'assignfeedback_aiocr'), 'warning');
}

// Qué material del profesor hay.
$lista = [
    get_string('prepare:source:rubric', 'assignfeedback_aiocr', $fuentes['metodo']
        ? get_string('prepare:method:' . $fuentes['metodo'], 'assignfeedback_aiocr') . " ({$fuentes['criterios']})"
        : get_string('no')),
    get_string('prepare:source:context', 'assignfeedback_aiocr', $fuentes['contexto'] ? get_string('yes') : get_string('no')),
    get_string('prepare:source:files', 'assignfeedback_aiocr', $fuentes['archivos']
        ? implode(', ', array_map(static fn ($a) => s($a->get_filename()), $fuentes['archivos'])) : get_string('no')),
];
if ($fuentes['omitidos']) {
    $lista[] = get_string('prepare:source:skipped', 'assignfeedback_aiocr', s(implode(', ', $fuentes['omitidos'])));
}
echo $OUTPUT->box(html_writer::tag('strong', get_string('prepare:sources', 'assignfeedback_aiocr'))
    . html_writer::alist($lista), 'generalbox');

$preparado = trim((string) $plugin->get_config('prepared'));
if ($preparado === '') {
    echo $OUTPUT->notification(get_string('prepare:none', 'assignfeedback_aiocr'), 'info');
} else if (contexto_profesor::desactualizado($assign)) {
    echo $OUTPUT->notification(get_string('prepare:outdated', 'assignfeedback_aiocr'), 'warning');
} else {
    echo $OUTPUT->notification(get_string('prepare:current', 'assignfeedback_aiocr',
        userdate((int) $plugin->get_config('preparedtime'))), 'success');
}

$form->display();
echo $OUTPUT->footer();
