<?php
// This file is part of Moodle - https://moodle.org/
//
// Lo que el evaluador necesita de un modelo de IA. Lo implementan
// cliente_gemini (API de Google) y cliente_ollama (modelo local, p. ej.
// Gemma, servido con Ollama). No depende de Moodle.

namespace assignfeedback_aiocr\local\gemini;

defined('MOODLE_INTERNAL') || die();

/**
 * Modelo de IA multimodal.
 */
interface modelo_ia {

    /** Nombre del modelo (se guarda en el registro de cada evaluación). */
    public function nombre(): string;

    /**
     * Si acepta un PDF entero. Si no (modelos locales), el evaluador
     * convierte cada página en imagen y la transcribe por separado.
     */
    public function acepta_pdf(): bool;

    /** Clave de idioma de sus errores (error:<clave>): 'gemini' o 'localmodel'. */
    public function clave_error(): string;

    /**
     * @param string|null $sistema instrucciones fijas del plugin
     * @param string $texto contenido del mensaje
     * @param array $adjuntos lista de ['mimetype' => string, 'contenido' => string]
     * @return array [texto de la respuesta, ['entrada', 'salida', 'razonamiento', 'total']]
     * @throws evaluacion_exception con la clave de clave_error()
     */
    public function consultar_con(?string $sistema, string $texto, array $adjuntos = []): array;
}
