<?php
// This file is part of Moodle - https://moodle.org/
//
// Llamadas a la API de Gemini (texto, o archivo + texto). Port de
// php-service/src/GeminiClient.php. No depende de Moodle: la conexión HTTP
// la hace el "transporte" que recibe (en Moodle, la clase \curl, que respeta
// el proxy del sitio; en las pruebas, una función falsa).

namespace assignfeedback_aiocr\local\gemini;

defined('MOODLE_INTERNAL') || die();

/**
 * Cliente mínimo de generateContent.
 */
final class cliente_gemini implements modelo_ia {

    /** Dirección de la API (sin el modelo). */
    public const URL_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** @var callable */
    private $transporte;

    /**
     * @param string $apikey clave de Google AI Studio
     * @param string $modelo por ejemplo gemini-3.5-flash-lite
     * @param int $timeout segundos por llamada
     * @param callable $transporte fn(string $url, string $cuerpo, array $cabeceras, int $timeout): array
     *        que devuelve [código HTTP, cuerpo, error de conexión ('' si no hubo)]
     */
    public function __construct(
        private readonly string $apikey,
        public readonly string $modelo,
        private readonly int $timeout,
        callable $transporte,
    ) {
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $modelo)) {
            throw new evaluacion_exception('gemini', "nombre de modelo no válido: $modelo");
        }
        $this->transporte = $transporte;
    }

    public function nombre(): string {
        return $this->modelo;
    }

    public function acepta_pdf(): bool {
        return true;
    }

    public function clave_error(): string {
        return 'gemini';
    }

    /** Tokens de la respuesta (usageMetadata). */
    public static function consumo_tokens(array $respuesta): array {
        $uso = is_array($respuesta['usageMetadata'] ?? null) ? $respuesta['usageMetadata'] : [];
        return [
            'entrada' => (int) ($uso['promptTokenCount'] ?? 0),
            'salida' => (int) ($uso['candidatesTokenCount'] ?? 0),
            'razonamiento' => (int) ($uso['thoughtsTokenCount'] ?? 0),
            'total' => (int) ($uso['totalTokenCount'] ?? 0),
        ];
    }

    /**
     * Envía una pregunta, con un archivo opcional (PDF o imagen).
     *
     * @param string $pregunta texto del prompt
     * @param string|null $mimetype tipo del archivo adjunto
     * @param string|null $datos bytes del archivo adjunto
     * @return array [texto de la respuesta, consumo de tokens]
     * @throws evaluacion_exception gemini
     */
    public function consultar(string $pregunta, ?string $mimetype = null, ?string $datos = null): array {
        return $this->consultar_con(null, $pregunta, $datos === null ? [] : [['mimetype' => $mimetype, 'contenido' => $datos]]);
    }

    /**
     * Envía instrucciones de sistema (separadas de los datos), un texto y
     * varios archivos.
     *
     * Las instrucciones van en system_instruction: el modelo las distingue
     * del contenido del mensaje, que es donde van los datos (contexto del
     * profesor y entrega del estudiante).
     *
     * @param string|null $sistema instrucciones fijas del plugin
     * @param string $texto contenido del mensaje
     * @param array $adjuntos lista de ['mimetype' => string, 'contenido' => string]
     * @return array [texto de la respuesta, consumo de tokens]
     * @throws evaluacion_exception gemini
     */
    public function consultar_con(?string $sistema, string $texto, array $adjuntos = []): array {
        $partes = [];
        foreach ($adjuntos as $adjunto) {
            $partes[] = ['inline_data' => ['mime_type' => $adjunto['mimetype'], 'data' => base64_encode($adjunto['contenido'])]];
        }
        $partes[] = ['text' => $texto];
        $peticion = ['contents' => [['role' => 'user', 'parts' => $partes]]];
        if ($sistema !== null) {
            $peticion['system_instruction'] = ['parts' => [['text' => $sistema]]];
        }
        $cuerpo = json_encode($peticion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        unset($partes, $peticion);

        [$estado, $respuesta, $errorconexion] = ($this->transporte)(
            self::URL_BASE . $this->modelo . ':generateContent',
            $cuerpo,
            ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apikey],
            $this->timeout
        );
        unset($cuerpo);

        if ($errorconexion !== '') {
            throw new evaluacion_exception('gemini', "no se pudo conectar: $errorconexion");
        }
        if ((int) $estado !== 200) {
            throw new evaluacion_exception('gemini', "error $estado: " . self::resumen_error((string) $respuesta));
        }
        $resultado = json_decode((string) $respuesta, true);
        $partesrespuesta = $resultado['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($resultado) || !is_array($partesrespuesta)) {
            $motivo = $resultado['candidates'][0]['finishReason'] ?? ($resultado['promptFeedback']['blockReason'] ?? 'sin contenido');
            throw new evaluacion_exception('gemini', "no devolvió texto ($motivo)");
        }
        $texto = '';
        foreach ($partesrespuesta as $parte) {
            $texto .= is_array($parte) ? (string) ($parte['text'] ?? '') : '';
        }
        return [trim($texto), self::consumo_tokens($resultado)];
    }

    /** El mensaje de error de Gemini, sin el cuerpo completo. */
    private static function resumen_error(string $respuesta): string {
        $datos = json_decode($respuesta, true);
        $mensaje = is_array($datos) ? ($datos['error']['message'] ?? null) : null;
        return is_string($mensaje) ? $mensaje : substr($respuesta, 0, 300);
    }
}
