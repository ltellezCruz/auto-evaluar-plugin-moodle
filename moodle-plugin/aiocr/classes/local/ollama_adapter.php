<?php
// This file is part of Moodle - https://moodle.org/

namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

use assignfeedback_aiocr\local\gemini\cliente_ollama;
use assignfeedback_aiocr\local\gemini\modelo_ia;

/**
 * ollama_adapter
 *
 * Proveedor «Modelo local (Ollama)»: todo dentro de Moodle (ver
 * adaptador_modelo) con un modelo propio, p. ej. Gemma 4 31B, servido con
 * Ollama en este servidor o en otro de la red. Sin claves ni tokens: los
 * datos no salen hacia Google.
 *
 * Diferencias con Gemini:
 *   - el modelo no lee PDF: cada página se convierte en imagen (rasterizador:
 *     Ghostscript de Moodle o Imagick) y se transcribe aparte, como el
 *     algoritmo original;
 *   - suele ser mucho más lento: el tiempo de espera es otro ajuste;
 *   - Moodle bloquea por defecto las direcciones locales: hay que permitir el
 *     host y el puerto de Ollama en «Seguridad HTTP».
 */
class ollama_adapter extends adaptador_modelo {

    /** Valores por defecto de los ajustes local_*. */
    public const URL_POR_DEFECTO = 'http://127.0.0.1:11434';
    public const MODELO_POR_DEFECTO = 'gemma4:31b';

    public function get_provider_name(): string {
        return 'ollama_adapter';
    }

    public function crear_modelo(): modelo_ia {
        $config = get_config('assignfeedback_aiocr');
        return new cliente_ollama(
            trim((string) ($config->local_url ?? '')) ?: self::URL_POR_DEFECTO,
            trim((string) ($config->local_model ?? '')) ?: self::MODELO_POR_DEFECTO,
            max(10, (int) ($config->local_timeout ?? 600) ?: 600),
            max(2048, (int) ($config->local_num_ctx ?? 16384) ?: 16384),
            [adaptador_modelo::class, 'transporte']
        );
    }
}
