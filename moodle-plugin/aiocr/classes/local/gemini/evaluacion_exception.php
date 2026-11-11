<?php
// This file is part of Moodle - https://moodle.org/
//
// Error controlado de una evaluación. Las clases de gemini/ no dependen de
// Moodle: en lugar de un texto llevan la clave del mensaje (lang:
// error:<clave>) y sus datos, y gemini_adapter lo traduce con get_string().

namespace assignfeedback_aiocr\local\gemini;

defined('MOODLE_INTERNAL') || die();

/**
 * Error de evaluación con clave de idioma.
 */
class evaluacion_exception extends \Exception {

    /** Claves posibles (todas existen como error:<clave> en lang/). */
    public const CLAVES = [
        'pdfinvalid', 'pdfencrypted', 'pdfnopages', 'pdfopen', 'filetoobig', 'toomanypages',
        'nocontent', 'transcription', 'gemini', 'busy', 'nogeminikey', 'nocontext',
    ];

    /** @var string clave del mensaje */
    public string $clave;

    /** @var mixed datos para el mensaje ({$a}) */
    public $a;

    /**
     * @param string $clave una de CLAVES
     * @param mixed $a datos del mensaje
     */
    public function __construct(string $clave, $a = null) {
        $this->clave = $clave;
        $this->a = $a;
        $detalle = is_scalar($a) ? (string) $a : json_encode($a, JSON_UNESCAPED_UNICODE);
        parent::__construct($clave . ($a === null ? '' : ': ' . $detalle));
    }
}
