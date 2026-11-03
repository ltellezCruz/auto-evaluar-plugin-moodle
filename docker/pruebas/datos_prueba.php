<?php
// Crea un curso con una tarea (con Auto Evaluar activado), un estudiante y
// una entrega con /tmp/entrega_prueba.pdf. Cada ejecución crea datos nuevos
// (nombres con sufijo de fecha), así que se puede repetir.
// Imprime "assignmentid userid" para boton.php.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

\core\session\manager::set_user(get_admin());
$sufijo = date('Ymd_His');
$gen = new testing_data_generator();

$course = $gen->create_course(['fullname' => "Curso de prueba $sufijo", 'shortname' => "prueba_$sufijo"]);
$student = $gen->create_user([
    'username' => "estudiante_$sufijo", 'firstname' => 'Estudiante', 'lastname' => "Prueba $sufijo",
]);
$gen->enrol_user($student->id, $course->id, 'student');
$gen->enrol_user(get_admin()->id, $course->id, 'editingteacher');

$mod = $gen->create_module('assign', [
    'course' => $course->id, 'name' => 'Tarea',
    'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 1,
    'assignsubmission_file_maxsizebytes' => 0,
    'assignfeedback_comments_enabled' => 1, 'assignfeedback_aiocr_enabled' => 1,
]);

$cm = get_coursemodule_from_instance('assign', $mod->id);
$context = context_module::instance($cm->id);
$assign = new assign($context, $cm, $course);
$submission = $assign->get_user_submission($student->id, true);

get_file_storage()->create_file_from_pathname([
    'contextid' => $context->id, 'component' => 'assignsubmission_file', 'filearea' => 'submission_files',
    'itemid' => $submission->id, 'filepath' => '/', 'filename' => 'entrega_prueba.pdf',
], '/tmp/entrega_prueba.pdf');
$DB->insert_record('assignsubmission_file', (object) [
    'assignment' => $mod->id, 'submission' => $submission->id, 'numfiles' => 1,
]);
$submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
$DB->update_record('assign_submission', $submission);

echo "{$mod->id} {$student->id}\n";
