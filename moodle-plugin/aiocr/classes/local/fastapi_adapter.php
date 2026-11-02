<?php
namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

/**
 * fastapi_adapter
 *
 * Implementación CONCRETA de ai_provider_interface que habla con el
 * servicio FastAPI (carpeta fastapi-service/ del repositorio), que
 * corre por fuera de Moodle.
 *
 * Este es solo UN adaptador posible entre varios — el resto del plugin
 * (process_submission.php, locallib.php) no sabe ni le importa
 * que esta clase existe; solo conoce ai_provider_interface.
 */
class fastapi_adapter implements ai_provider_interface {

    public function get_provider_name(): string {
        return 'fastapi_adapter';
    }

    public function generate_feedback(array $context): array {
        $baseurl = rtrim(get_config('assignfeedback_aiocr', 'fastapi_url'), '/');
        $timeout = (int) (get_config('assignfeedback_aiocr', 'fastapi_timeout') ?: 30);

        if (empty($baseurl)) {
            return [
                'success'      => false,
                'feedbacktext' => '',
                'raw'          => null,
                'error'        => 'No se ha configurado la URL del servicio FastAPI en Administración del sitio.',
            ];
        }

        // Payload que le enviamos a tu servicio FastAPI. Ajusta las
        // claves aquí si tu endpoint FastAPI espera un formato distinto
        // — este archivo es el ÚNICO lugar donde vive ese acoplamiento.
        $payload = [
            'assignmentid' => $context['assignmentid'],
            'userid'       => $context['userid'],
            'username'     => $context['username'] ?? null,
            'files'        => $context['files'],
            'onlinetext'   => $context['onlinetext'] ?? null,
        ];

        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => 10,
        ]);

        $endpoint = $baseurl . '/ia/generar-retroalimentacion';

        $respuesta = $curl->post($endpoint, json_encode($payload), [
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
        ]);

        $errno = $curl->get_errno();
        if ($errno) {
            return [
                'success'      => false,
                'feedbacktext' => '',
                'raw'          => null,
                'error'        => "No se pudo conectar con FastAPI ({$endpoint}): " . $curl->error,
            ];
        }

        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;

        $decodificado = json_decode($respuesta, true);

        if ($httpcode !== 200 || !is_array($decodificado)) {
            return [
                'success'      => false,
                'feedbacktext' => '',
                'raw'          => ['http_code' => $httpcode, 'body' => $respuesta],
                'error'        => "FastAPI respondió con estado {$httpcode}. "
                    . "URL intentada: {$endpoint}. "
                    . "curl errno: {$errno} | curl error: {$curl->error}. "
                    . "Cuerpo de la respuesta: " . substr((string) $respuesta, 0, 300),
            ];
        }

        // Se espera que tu endpoint FastAPI devuelva algo como:
        // {"feedback_text": "...", "score": 85, ...cualquier otro dato...}
        $feedbacktext = $decodificado['feedback_text'] ?? '';

        return [
            'success'      => true,
            'feedbacktext' => $feedbacktext,
            'raw'          => $decodificado,
            'error'        => null,
        ];
    }
}
