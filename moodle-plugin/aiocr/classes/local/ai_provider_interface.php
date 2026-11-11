<?php
namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

/**
 * ai_provider_interface
 *
 * Este es el contrato ÚNICO que le importa al resto del plugin.
 * El backend (process_submission.php) y el frontend nunca hablan
 * directamente con FastAPI, con Gemini, ni con ningún modelo — solo
 * conocen esta interfaz. Así, cambiar de modelo de IA en el futuro
 * significa escribir UNA clase nueva que la implemente y seleccionarla
 * en Administración del sitio > Plugins > assignfeedback_aiocr,
 * sin tocar ni una línea del resto del plugin.
 *
 * Ejemplo de implementaciones posibles, todas intercambiables:
 *   - gemini_adapter.php    -> API de Gemini directamente, sin servicio
 *                              intermedio (por defecto desde la 0.5.0)
 *   - fastapi_adapter.php   -> servicio externo php-service/ (antes FastAPI)
 *   - mock_adapter.php      -> respuesta falsa fija, útil para pruebas
 *   - openai_adapter.php    -> (posible) API de OpenAI directamente
 */
interface ai_provider_interface {

    /**
     * Procesa una entrega y devuelve retroalimentación generada por IA.
     *
     * @param array $context Datos de la entrega a evaluar. Estructura esperada:
     *   [
     *     'assignmentid' => int,
     *     'userid'       => int,
     *     'username'     => string|null,   // usuario del estudiante (nombra la transcripción)
     *     'files'        => [ ['filename' => string, 'fileurl' => string, 'mimetype' => string,
     *                          'fileid' => int], ... ],   // fileid: id en la File API de Moodle
     *     'onlinetext'   => string|null,   // si la entrega es texto en línea en vez de archivo
     *   ]
     *
     * @return array Resultado normalizado, SIEMPRE con esta forma sin
     *   importar qué modelo de IA se use por debajo:
     *   [
     *     'success'      => bool,
     *     'feedbacktext' => string,        // listo para mostrar/guardar
     *     'raw'          => array|null,     // respuesta cruda del proveedor, para auditoría
     *     'error'        => string|null,    // mensaje de error si success=false
     *     'transcription'=> string|null,    // opcional: transcripción de la entrega
     *     'tokens'       => int,            // opcional: tokens de IA consumidos
     *   ]
     */
    public function generate_feedback(array $context): array;

    /**
     * Nombre corto identificador del proveedor (se guarda en la tabla
     * assignfeedback_aiocr.provider para trazabilidad).
     */
    public function get_provider_name(): string;
}
