<?php
// This file is part of Moodle - https://moodle.org/

namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

use assignfeedback_aiocr\local\gemini\cliente_gemini;
use assignfeedback_aiocr\local\gemini\evaluacion_exception;
use assignfeedback_aiocr\local\gemini\modelo_ia;

/**
 * gemini_adapter
 *
 * Proveedor «Gemini (directo)»: todo dentro de Moodle (ver
 * adaptador_modelo) con la API de Gemini de Google. Necesita una clave de
 * Google AI Studio. Los PDF se envían enteros.
 */
class gemini_adapter extends adaptador_modelo {

    /** Modelo por defecto (ajuste gemini_model). */
    public const MODELO_POR_DEFECTO = 'gemini-3.5-flash-lite';

    public function get_provider_name(): string {
        return 'gemini_adapter';
    }

    public function crear_modelo(): modelo_ia {
        return self::crear_cliente();
    }

    /**
     * Cliente de Gemini con los ajustes del sitio.
     *
     * @throws evaluacion_exception nogeminikey
     */
    public static function crear_cliente(): cliente_gemini {
        $config = get_config('assignfeedback_aiocr');
        $apikey = trim((string) ($config->gemini_apikey ?? ''));
        if ($apikey === '') {
            throw new evaluacion_exception('nogeminikey');
        }
        return new cliente_gemini(
            $apikey,
            trim((string) ($config->gemini_model ?? '')) ?: self::MODELO_POR_DEFECTO,
            max(10, (int) ($config->gemini_timeout ?? 120) ?: 120),
            [adaptador_modelo::class, 'transporte']
        );
    }
}
