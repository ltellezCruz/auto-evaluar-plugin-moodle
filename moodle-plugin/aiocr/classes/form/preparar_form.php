<?php
// This file is part of Moodle - https://moodle.org/

namespace assignfeedback_aiocr\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Formulario de prepare.php: el contexto preparado, editable por el profesor.
 */
class preparar_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('textarea', 'prepared', get_string('preparedcontext', 'assignfeedback_aiocr'),
            ['rows' => 24, 'cols' => 100, 'style' => 'font-family: monospace; width: 100%;']);
        $mform->setType('prepared', PARAM_TEXT);
        $mform->addHelpButton('prepared', 'preparedcontext', 'assignfeedback_aiocr');

        $botones = [
            $mform->createElement('submit', 'generate', get_string('prepare:generate', 'assignfeedback_aiocr')),
            $mform->createElement('submit', 'save', get_string('prepare:save', 'assignfeedback_aiocr')),
            $mform->createElement('submit', 'clear', get_string('prepare:clear', 'assignfeedback_aiocr')),
            $mform->createElement('cancel'),
        ];
        $mform->addGroup($botones, 'botones', '', ' ', false);
    }
}
