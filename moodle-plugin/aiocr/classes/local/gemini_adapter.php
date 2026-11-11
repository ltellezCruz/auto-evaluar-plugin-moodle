<?php
// This file is part of Moodle - https://moodle.org/

namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

use assignfeedback_aiocr\local\gemini\cliente_gemini;
use assignfeedback_aiocr\local\gemini\evaluacion_exception;
use assignfeedback_aiocr\local\gemini\evaluador;

/**
 * gemini_adapter
 *
 * Proveedor AUTÓNOMO: el plugin hace todo el proceso dentro de Moodle, sin
 * servicio intermedio (ni FastAPI ni php-service):
 *   - lee los archivos de la entrega con la File API de Moodle (sin token
 *     de servicios web ni descargas por HTTP);
 *   - llama a la API de Gemini con la clase \curl de Moodle (respeta el
 *     proxy del sitio);
 *   - devuelve la transcripción y los metadatos para que process_submission
 *     los guarde en la tabla assignfeedback_aiocr.
 *
 * La lógica de transcribir y evaluar está en classes/local/gemini/ (no
 * depende de Moodle y tiene pruebas propias); esta clase solo pone lo que
 * es de Moodle: ajustes, archivos, candados, límites de tiempo y textos.
 */
class gemini_adapter implements ai_provider_interface {

    /** Modelo por defecto (ajuste gemini_model). */
    public const MODELO_POR_DEFECTO = 'gemini-3.5-flash-lite';

    public function get_provider_name(): string {
        return 'gemini_adapter';
    }

    public function generate_feedback(array $context): array {
        $config = get_config('assignfeedback_aiocr');
        $apikey = trim((string) ($config->gemini_apikey ?? ''));
        $modelo = trim((string) ($config->gemini_model ?? '')) ?: self::MODELO_POR_DEFECTO;
        $timeout = max(10, (int) ($config->gemini_timeout ?? 120) ?: 120);
        $maxmb = max(1, (int) ($config->max_file_mb ?? 20) ?: 20);
        $maxpaginas = max(1, (int) ($config->max_pages ?? 20) ?: 20);
        $maxsimultaneas = max(1, (int) ($config->max_concurrent ?? 3) ?: 3);
        $guardartranscripcion = !isset($config->save_transcription) || !empty($config->save_transcription);

        $registro = [
            'fecha' => date('c'),
            'version_plugin' => $config->version ?? null,
            'assignmentid' => $context['assignmentid'] ?? null,
            'userid' => $context['userid'] ?? null,
        ];

        try {
            if ($apikey === '') {
                throw new evaluacion_exception('nogeminikey');
            }
            $documentos = self::leer_archivos($context['files'] ?? [], $maxmb);

            // Límite de evaluaciones simultáneas (cada una ocupa memoria y
            // cuota de Gemini): un candado por "cupo".
            $candado = self::tomar_cupo($maxsimultaneas);
            if (!$candado) {
                throw new evaluacion_exception('busy', $maxsimultaneas);
            }
            try {
                // Varias llamadas a Gemini: más que el max_execution_time
                // habitual. El límite real lo pone el servidor web.
                \core_php_time_limit::raise();
                raise_memory_limit(MEMORY_EXTRA);

                $evaluador = new evaluador(
                    new cliente_gemini($apikey, $modelo, $timeout, [self::class, 'transporte']),
                    $maxpaginas
                );
                // Rúbrica / contexto del profesor (ver contexto_profesor).
                [$contextoprofesor, $origencontexto] = $context['teachercontext'] ?? [null, 'ninguno'];
                try {
                    $retro = $evaluador->evaluar($documentos, $context['onlinetext'] ?? null,
                        $contextoprofesor, $origencontexto);
                } finally {
                    $registro += $evaluador->registro;
                    $transcripcion = $evaluador->transcripcion;
                }
            } finally {
                $candado->release();
            }

            return [
                'success' => true,
                'feedbacktext' => $retro,
                'raw' => $registro,
                'transcription' => $guardartranscripcion ? $transcripcion : null,
                'tokens' => (int) ($registro['tokens_total'] ?? 0),
                'error' => null,
            ];
        } catch (evaluacion_exception $e) {
            $mensaje = self::mensaje($e);
            $registro['error'] = $registro['error'] ?? $mensaje;
            return [
                'success' => false,
                'feedbacktext' => '',
                'raw' => $registro,
                'transcription' => $guardartranscripcion && !empty($transcripcion) ? $transcripcion : null,
                'tokens' => (int) ($registro['tokens_total'] ?? 0),
                'error' => $mensaje,
            ];
        }
    }

    /**
     * Cliente de Gemini con los ajustes del sitio (lo usa también la
     * preparación de la rúbrica).
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
            [self::class, 'transporte']
        );
    }

    /**
     * Mensaje de una evaluacion_exception en el idioma del usuario.
     */
    public static function mensaje(evaluacion_exception $e): string {
        $a = $e->a;
        if ($e->clave === 'pdfopen') {
            // El motivo es otra clave (pdfinvalid, pdfencrypted, pdfnopages).
            $a = (object) ['name' => $a->name, 'error' => get_string('error:' . $a->error, 'assignfeedback_aiocr')];
        }
        return get_string('error:' . $e->clave, 'assignfeedback_aiocr', $a);
    }

    /**
     * Lee los archivos de la entrega desde la File API, en orden.
     *
     * @param array $archivos entradas de process_submission (con fileid)
     * @param int $maxmb tamaño máximo por archivo
     * @return array documentos para evaluador::evaluar()
     * @throws evaluacion_exception filetoobig
     */
    private static function leer_archivos(array $archivos, int $maxmb): array {
        $fs = get_file_storage();
        $documentos = [];
        foreach ($archivos as $archivo) {
            $nombre = (string) ($archivo['filename'] ?? '?');
            $file = !empty($archivo['fileid']) ? $fs->get_file_by_id((int) $archivo['fileid']) : false;
            if (!$file) {
                $documentos[] = ['nombre' => $nombre, 'omitido' => get_string('skipped:notfound', 'assignfeedback_aiocr')];
                continue;
            }
            $tipo = evaluador::tipo_admitido((string) $file->get_mimetype(), $file->get_filename());
            if ($tipo === null) {
                $documentos[] = ['nombre' => $nombre,
                    'omitido' => get_string('skipped:type', 'assignfeedback_aiocr', $file->get_mimetype())];
                continue;
            }
            if ($file->get_filesize() > $maxmb * 1024 * 1024) {
                throw new evaluacion_exception('filetoobig', (object) [
                    'name' => $nombre,
                    'size' => round($file->get_filesize() / 1048576, 1),
                    'max' => $maxmb,
                ]);
            }
            $documentos[] = ['nombre' => $nombre, 'mimetype' => $tipo, 'contenido' => $file->get_content()];
        }
        return $documentos;
    }

    /**
     * Toma uno de los $maximo cupos sin esperar.
     *
     * @return \core\lock\lock|false
     */
    private static function tomar_cupo(int $maximo) {
        $fabrica = \core\lock\lock_config::get_lock_factory('assignfeedback_aiocr');
        for ($i = 1; $i <= $maximo; $i++) {
            // Vida máxima de 1 hora: si un proceso muere, el cupo se libera solo.
            $candado = $fabrica->get_lock('evaluacion_' . $i, 0, HOURSECS);
            if ($candado) {
                return $candado;
            }
        }
        return false;
    }

    /**
     * Transporte HTTP de cliente_gemini con la clase \curl de Moodle.
     *
     * @return array [código HTTP, cuerpo, error de conexión]
     */
    public static function transporte(string $url, string $cuerpo, array $cabeceras, int $timeout): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(30, $timeout),
        ]);
        $curl->setHeader($cabeceras);
        $respuesta = $curl->post($url, $cuerpo);
        $codigo = (int) ($curl->get_info()['http_code'] ?? 0);
        // Si Moodle bloquea la URL (Seguridad HTTP), no hay errno pero sí error.
        $error = $curl->get_errno() || ($codigo === 0 && !empty($curl->error)) ? (string) $curl->error : '';
        return [$codigo, (string) $respuesta, $error];
    }
}
