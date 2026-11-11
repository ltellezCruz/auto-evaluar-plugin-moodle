<?php
// This file is part of Moodle - http://moodle.org/
//
// Metadatos de versión del subplugin de retroalimentación assignfeedback_aiocr.
//
// Este subplugin cuelga de mod_assign (mod/assign/feedback/aiocr) y añade
// un botón "Auto Evaluar" a la pantalla de calificación
// de tareas, sin modificar ni un solo archivo del núcleo de Moodle.

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026111100;        // YYYYMMDDXX (ver db/upgrade.php y CHANGELOG.md)
$plugin->requires  = 2024100700;        // Versión mínima de Moodle (4.5)
$plugin->component = 'assignfeedback_aiocr';
$plugin->maturity  = MATURITY_ALPHA;    // Prototipo de tesis, no producción
$plugin->release   = '0.6.0';

// Este subplugin depende de que mod_assign esté instalado.
$plugin->dependencies = [
    'mod_assign' => 2024100700,
];
