<?php
// Ejecuta lo mismo que el clic en "Auto Evaluar" y muestra lo guardado.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
\core\session\manager::set_user(get_admin());
[$assignmentid, $userid] = [(int) $argv[1], (int) $argv[2]];
$t = microtime(true);
$r = \assignfeedback_aiocr\external\process_submission::execute($assignmentid, $userid);
printf("success=%s en %.1f s; error=%s\n", var_export($r['success'], true), microtime(true) - $t, $r['error'] ?? '-');
$grade = $DB->get_record('assign_grades', ['assignment' => $assignmentid, 'userid' => $userid], '*', IGNORE_MULTIPLE);
$c = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id]);
echo "calificacion=", $grade->grade, " | comentario guardado: ", $c ? strlen($c->commenttext) . " caracteres" : "NO", "\n";
echo $c ? substr(strip_tags(str_replace(['</p>', '<br>', '</h4>', '</li>'], "\n", $c->commenttext)), 0, 900) : '', "\n";
