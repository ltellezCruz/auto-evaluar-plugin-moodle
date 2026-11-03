<?php
namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

/**
 * mock_adapter
 *
 * Proveedor de prueba: devuelve una retroalimentación fija sin llamar a
 * FastAPI ni a Gemini. Sirve para probar el plugin (botón, permisos,
 * guardado del comentario, registro en la tabla) sin el servicio y sin
 * gastar cuota. Se elige en la configuración del plugin ("Prueba (sin IA)").
 *
 * También es el ejemplo más simple de cómo implementar ai_provider_interface.
 */
class mock_adapter implements ai_provider_interface {

    public function get_provider_name(): string {
        return 'mock_adapter';
    }

    public function generate_feedback(array $context): array {
        $nombres = array_map(fn($archivo) => $archivo['filename'], $context['files'] ?? []);
        $texto = get_string('mock:feedback', 'assignfeedback_aiocr', (object) [
            'files'      => $nombres ? implode(', ', $nombres) : '-',
            'onlinetext' => \core_text::strlen((string) ($context['onlinetext'] ?? '')),
        ]);

        return [
            'success'      => true,
            'feedbacktext' => \html_writer::tag('p', s($texto)),
            'raw'          => ['mock' => true, 'files' => $nombres],
            'error'        => null,
        ];
    }
}
