<?php
// db/access.php — capacidad que controla quién puede disparar la
// generación de retroalimentación con IA (normalmente el profesor).

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'assignfeedback/aiocr:generate' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'teacher'        => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
];
