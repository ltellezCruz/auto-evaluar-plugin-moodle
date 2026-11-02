<?php
namespace assignfeedback_aiocr\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;

/**
 * provider
 *
 * Todo plugin de Moodle DEBE declarar qué datos personales almacena
 * (GDPR / Ley de protección de datos). Como assignfeedback_aiocr SÍ
 * guarda datos ligados a usuarios (el texto de retroalimentación y
 * quién lo generó), implementamos metadata_provider en vez de un
 * null_provider — así se documenta correctamente y Moodle puede
 * incluir estos datos en una exportación o borrado de datos del usuario.
 *
 * Para un prototipo de tesis esto es suficiente; una versión de
 * producción real debería completar también export_user_data() y
 * delete_data_for_user() siguiendo la documentación de Moodle sobre
 * la API de privacidad.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'assignfeedback_aiocr',
            [
                'grade'        => 'privacy:metadata:assignfeedback_aiocr:grade',
                'provider'     => 'privacy:metadata:assignfeedback_aiocr:provider',
                'rawresponse'  => 'privacy:metadata:assignfeedback_aiocr:rawresponse',
                'usermodified' => 'privacy:metadata:assignfeedback_aiocr:usermodified',
                'timecreated'  => 'privacy:metadata:assignfeedback_aiocr:timecreated',
            ],
            'privacy:metadata:assignfeedback_aiocr'
        );

        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT ctx.id
                  FROM {assignfeedback_aiocr} afa
                  JOIN {assign} a ON a.id = afa.assignment
                  JOIN {course_modules} cm ON cm.instance = a.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
                 WHERE afa.userid = :userid OR afa.usermodified = :usermodified2";
        $contextlist->add_from_sql($sql, [
            'contextlevel'  => CONTEXT_MODULE,
            'userid'        => $userid,
            'usermodified2' => $userid,
        ]);
        return $contextlist;
    }

    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        // Implementación mínima: se puede ampliar según necesidad real del proyecto.
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        // TODO (fuera del alcance del prototipo): exportar registros del
        // usuario en formato legible siguiendo \core_privacy\local\request\writer.
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('assign', $context->instanceid);
        if ($cm) {
            $DB->delete_records('assignfeedback_aiocr', ['assignment' => $cm->instance]);
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('assignfeedback_aiocr', ['userid' => $userid]);
    }

    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        // Implementación mínima: se puede ampliar según necesidad real del proyecto.
    }
}
