<?php
// GeminiClient.php
// Llamadas a la API de Gemini (texto o imagen + texto) con la extensión curl.

namespace AutoEvaluar\Servicio;

final class GeminiClient
{
    private const TIPOS = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'pdf' => 'application/pdf',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    public static function obtenerConsumoTokens(array $respuesta): array
    {
        $uso = is_array($respuesta['usageMetadata'] ?? null) ? $respuesta['usageMetadata'] : [];
        return [
            'entrada' => (int) ($uso['promptTokenCount'] ?? 0),
            'salida' => (int) ($uso['candidatesTokenCount'] ?? 0),
            'razonamiento' => (int) ($uso['thoughtsTokenCount'] ?? 0),
            'total' => (int) ($uso['totalTokenCount'] ?? 0),
        ];
    }

    /** @return array{0: string, 1: array} (texto de la respuesta, consumo de tokens) */
    public function consultar(string $pregunta, ?string $rutaImagen = null): array
    {
        $partes = [];

        if ($rutaImagen !== null) {
            $extension = strtolower(pathinfo($rutaImagen, PATHINFO_EXTENSION));
            $contenido = file_get_contents($rutaImagen);
            if ($contenido === false) {
                throw new \RuntimeException("No se pudo leer $rutaImagen.");
            }
            $partes[] = [
                'inline_data' => [
                    'mime_type' => self::TIPOS[$extension] ?? 'application/octet-stream',
                    'data' => base64_encode($contenido),
                ],
            ];
            unset($contenido);
        }

        $partes[] = ['text' => $pregunta];

        $cuerpo = json_encode(
            ['contents' => [['role' => 'user', 'parts' => $partes]]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        $ch = curl_init($this->config->geminiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cuerpo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->config->geminiApiKey,
            ],
            CURLOPT_TIMEOUT => $this->config->geminiTimeout,
            CURLOPT_CONNECTTIMEOUT => min(30, $this->config->geminiTimeout),
        ]);
        $respuesta = curl_exec($ch);
        $errorCurl = curl_error($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($respuesta === false) {
            throw new \RuntimeException("No se pudo conectar con Gemini: $errorCurl");
        }
        if ($estado !== 200) {
            throw new \RuntimeException("Gemini respondió con error $estado: $respuesta");
        }

        $resultado = json_decode((string) $respuesta, true);
        $partesRespuesta = $resultado['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($resultado) || !is_array($partesRespuesta)) {
            $motivo = $resultado['candidates'][0]['finishReason'] ?? ($resultado['promptFeedback']['blockReason'] ?? 'sin contenido');
            throw new \RuntimeException("Gemini no devolvió texto ($motivo).");
        }

        $consumo = self::obtenerConsumoTokens($resultado);
        Log::info(sprintf(
            'Tokens Gemini (%s): entrada=%d salida=%d razonamiento=%d total=%d',
            $this->config->geminiModel, $consumo['entrada'], $consumo['salida'],
            $consumo['razonamiento'], $consumo['total']
        ), 'aiocr.gemini');

        $texto = '';
        foreach ($partesRespuesta as $parte) {
            $texto .= is_array($parte) ? (string) ($parte['text'] ?? '') : '';
        }
        return [trim($texto), $consumo];
    }
}
