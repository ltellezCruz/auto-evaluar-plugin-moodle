<?php
// This file is part of Moodle - https://moodle.org/
//
// Modelo local servido con Ollama (por ejemplo Gemma). Sin claves: el
// modelo corre en un equipo propio y los datos no salen de la red.
// API: POST <url>/api/chat (https://github.com/ollama/ollama/blob/main/docs/api.md).
// No depende de Moodle: la conexión la hace el transporte que recibe.

namespace assignfeedback_aiocr\local\gemini;

defined('MOODLE_INTERNAL') || die();

/**
 * Cliente mínimo de /api/chat de Ollama.
 */
final class cliente_ollama implements modelo_ia {

    /** @var callable */
    private $transporte;

    /**
     * @param string $url dirección de Ollama, p. ej. http://127.0.0.1:11434
     * @param string $modelo etiqueta del modelo en Ollama, p. ej. gemma4:31b
     * @param int $timeout segundos por llamada
     * @param int $contexto ventana de contexto en tokens (num_ctx). La de
     *        Ollama por defecto es corta para estos prompts.
     * @param callable $transporte fn(string $url, string $cuerpo, array $cabeceras, int $timeout): array
     *        que devuelve [código HTTP, cuerpo, error de conexión ('' si no hubo)]
     */
    public function __construct(
        private readonly string $url,
        private readonly string $modelo,
        private readonly int $timeout,
        private readonly int $contexto,
        callable $transporte,
    ) {
        if (!preg_match('/^[a-zA-Z0-9._:\/-]+$/', $modelo)) {
            throw new evaluacion_exception('localmodel', "nombre de modelo no válido: $modelo");
        }
        if (!preg_match('#^https?://#i', $url)) {
            throw new evaluacion_exception('localmodel', "dirección no válida: $url");
        }
        $this->transporte = $transporte;
    }

    public function nombre(): string {
        return $this->modelo;
    }

    public function acepta_pdf(): bool {
        return false;
    }

    public function clave_error(): string {
        return 'localmodel';
    }

    public function consultar_con(?string $sistema, string $texto, array $adjuntos = []): array {
        $mensajes = [];
        if ($sistema !== null) {
            $mensajes[] = ['role' => 'system', 'content' => $sistema];
        }
        $usuario = ['role' => 'user', 'content' => $texto];
        foreach ($adjuntos as $adjunto) {
            if (!str_starts_with((string) $adjunto['mimetype'], 'image/')) {
                throw new evaluacion_exception('localmodel', "el modelo local solo recibe imágenes (llegó {$adjunto['mimetype']})");
            }
            $usuario['images'][] = base64_encode($adjunto['contenido']);
        }
        $mensajes[] = $usuario;
        $cuerpo = json_encode([
            'model' => $this->modelo,
            'messages' => $mensajes,
            'stream' => false,
            'options' => ['num_ctx' => $this->contexto],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        unset($mensajes, $usuario);

        [$estado, $respuesta, $errorconexion] = ($this->transporte)(
            rtrim($this->url, '/') . '/api/chat',
            $cuerpo,
            ['Content-Type: application/json'],
            $this->timeout
        );
        unset($cuerpo);

        if ($errorconexion !== '') {
            throw new evaluacion_exception('localmodel', "no se pudo conectar con {$this->url}: $errorconexion");
        }
        $datos = json_decode((string) $respuesta, true);
        if ((int) $estado !== 200) {
            $detalle = is_array($datos) && is_string($datos['error'] ?? null) ? $datos['error'] : substr((string) $respuesta, 0, 300);
            throw new evaluacion_exception('localmodel', "error $estado: $detalle");
        }
        $contenido = is_array($datos) ? ($datos['message']['content'] ?? null) : null;
        if (!is_string($contenido)) {
            throw new evaluacion_exception('localmodel', 'respuesta sin texto');
        }
        $entrada = (int) ($datos['prompt_eval_count'] ?? 0);
        $salida = (int) ($datos['eval_count'] ?? 0);
        return [trim($contenido), ['entrada' => $entrada, 'salida' => $salida, 'razonamiento' => 0, 'total' => $entrada + $salida]];
    }
}
