<?php
// This file is part of Moodle - https://moodle.org/
//
// Pipeline de una evaluación, dentro de Moodle. Port de
// php-service/src/Servicio.php (modo PDF_MODO=directo):
//     1. Cuenta las páginas de toda la entrega (máximo max_pages).
//     2. PASO 1 de Gemini: transcribe cada PDF entero en una llamada
//        (prompts EXTRAER_PDF + EXTRAER, separado por «=== PAGINA N ===»)
//        y cada imagen en otra (EXTRAER). El texto en línea va tal cual.
//     3. Si algo no se pudo transcribir, se detiene: no se publica una
//        evaluación incompleta.
//     4. PASO 2 de Gemini: evalúa la transcripción completa. Las reglas
//        (EVALUAR + REGLAS_EVALUACION) van como instrucciones de sistema; el
//        contexto del profesor (rúbrica) y la entrega van en el mensaje, en
//        bloques separados con marcas aleatorias: lo que venga de la entrega
//        se trata como datos, nunca como instrucciones.
//
// No depende de Moodle: recibe los archivos ya leídos y el cliente de
// Gemini. gemini_adapter pone lo que es de Moodle (archivos, ajustes,
// candados, base de datos).

namespace assignfeedback_aiocr\local\gemini;

defined('MOODLE_INTERNAL') || die();

/**
 * Transcribe y evalúa una entrega.
 */
final class evaluador {

    /** @var array metadatos de la última evaluación (se guardan aunque falle) */
    public array $registro = [];

    /** @var string transcripción de la última evaluación */
    public string $transcripcion = '';

    /**
     * @param cliente_gemini $gemini cliente ya configurado
     * @param int $maxpaginas páginas máximas de toda la entrega
     */
    public function __construct(
        private readonly cliente_gemini $gemini,
        private readonly int $maxpaginas,
    ) {
    }

    /**
     * Tipo de archivo que se puede evaluar.
     *
     * @return string|null 'application/pdf', 'image/png', 'image/jpeg' o null si no se admite
     */
    public static function tipo_admitido(string $mimetype, string $nombre): ?string {
        $mimetype = strtolower(trim(explode(';', $mimetype)[0]));
        if (in_array($mimetype, ['application/pdf', 'image/png', 'image/jpeg'], true)) {
            return $mimetype;
        }
        return [
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        ][strtolower(pathinfo($nombre, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * @param array $documentos en orden; cada uno ['nombre' => string, 'mimetype' => string,
     *        'contenido' => string] o, si no se procesa, ['nombre' => string, 'omitido' => motivo]
     * @param string|null $textoenlinea texto en línea de la entrega
     * @param string|null $contextoprofesor rúbrica / contexto de la tarea (de confianza)
     * @param string $origencontexto de dónde salió el contexto (se anota en el registro)
     * @return string retroalimentación (HTML que genera Gemini)
     * @throws evaluacion_exception
     */
    public function evaluar(array $documentos, ?string $textoenlinea, ?string $contextoprofesor = null,
            string $origencontexto = 'ninguno'): string {
        $contextoprofesor = trim((string) $contextoprofesor);
        $inicio = microtime(true);
        $textoenlinea = trim((string) $textoenlinea);
        $this->transcripcion = '';
        $this->registro = [
            'archivos' => array_map(static fn ($d) => [
                'nombre' => $d['nombre'],
                'estado' => isset($d['omitido']) ? 'omitido: ' . $d['omitido'] : 'pendiente',
            ], $documentos),
            'texto_en_linea_caracteres' => self::largo($textoenlinea),
            'modelo' => $this->gemini->modelo,
            'modo_pdf' => 'directo',
            'contexto_profesor' => $contextoprofesor === '' ? 'ninguno' : $origencontexto,
            'contexto_profesor_caracteres' => self::largo($contextoprofesor),
            'paginas' => 0,
            'llamadas' => [],
            'resultado' => 'error',
            'error' => null,
        ];
        try {
            $retro = $this->procesar($documentos, $textoenlinea, $contextoprofesor);
            $this->registro['resultado'] = 'ok';
            return $retro;
        } catch (evaluacion_exception $e) {
            $this->registro['error'] = $e->getMessage();
            throw $e;
        } finally {
            $this->registro['duracion_s'] = round(microtime(true) - $inicio, 2);
            $this->registro['tokens_total'] = array_sum(array_column($this->registro['llamadas'], 'total'));
        }
    }

    private function procesar(array $documentos, string $textoenlinea, string $contextoprofesor): string {
        $validos = array_filter($documentos, static fn ($d) => !isset($d['omitido']));
        if (!$validos && $textoenlinea === '') {
            $omitidos = implode('; ', array_map(static fn ($a) => "{$a['nombre']} ({$a['estado']})", $this->registro['archivos']));
            throw new evaluacion_exception('nocontent', $omitidos ?: '-');
        }

        // --- Contar páginas antes de llamar: el límite es por entrega ---
        $paginas = [];
        foreach ($validos as $i => $d) {
            if ($d['mimetype'] !== 'application/pdf') {
                $paginas[$i] = 1;
                continue;
            }
            try {
                $paginas[$i] = pdf_paginas::contar($d['contenido']);
            } catch (evaluacion_exception $e) {
                throw new evaluacion_exception('pdfopen', (object) ['name' => $d['nombre'], 'error' => $e->clave]);
            }
        }
        $total = array_sum($paginas);
        if ($total > $this->maxpaginas) {
            throw new evaluacion_exception('toomanypages', (object) ['pages' => $total, 'max' => $this->maxpaginas]);
        }
        $this->registro['paginas'] = $total;

        // --- PASO 1: transcribir ---
        $bloques = [];
        $fallos = [];
        foreach ($validos as $i => $d) {
            $espdf = $d['mimetype'] === 'application/pdf';
            $rango = $paginas[$i] > 1 ? "1-{$paginas[$i]}" : 1;
            $prompt = $espdf
                ? str_replace('{paginas}', (string) $paginas[$i], prompts::EXTRAER_PDF) . prompts::EXTRAER
                : prompts::EXTRAER;
            $this->registro['archivos'][$i]['estado'] = 'procesado';
            $this->registro['archivos'][$i]['paginas'] = $paginas[$i];
            try {
                [$texto, $consumo] = $this->gemini->consultar($prompt, $d['mimetype'], $d['contenido']);
                $this->registro['llamadas'][] = ['paso' => 'transcripcion', 'archivo' => $d['nombre'], 'pagina' => $rango] + $consumo;
                if ($texto === '') {
                    throw new evaluacion_exception('gemini', 'la transcripción está vacía');
                }
            } catch (evaluacion_exception $e) {
                $fallos[] = "{$d['nombre']}: " . (is_scalar($e->a) ? $e->a : $e->getMessage());
                continue;
            }
            $partes = $espdf ? self::separar_paginas($texto, $rango) : [[1, $texto]];
            foreach ($partes as [$pagina, $contenido]) {
                $bloques[] = "Archivo: {$d['nombre']} | Página: $pagina\n$contenido";
            }
        }
        if ($textoenlinea !== '') {
            // Ya es texto: no pasa por la transcripción. Se marca como tal para
            // que la evaluación sepa que no trae el enunciado del profesor.
            $bloques[] = 'Archivo: Texto en línea de la entrega (escrito por el estudiante en Moodle; '
                . "no incluye el enunciado del profesor) | Página: 1\n$textoenlinea";
        }
        $this->transcripcion = implode("\n\n", $bloques);
        if ($fallos) {
            throw new evaluacion_exception('transcription', implode('; ', $fallos));
        }

        // --- PASO 2: evaluar la transcripción completa ---
        [$sistema, $mensaje] = self::armar_evaluacion($this->transcripcion, $contextoprofesor);
        [$retro, $consumo] = $this->gemini->consultar_con($sistema, $mensaje);
        $this->registro['llamadas'][] = ['paso' => 'evaluacion'] + $consumo;
        if ($retro === '') {
            throw new evaluacion_exception('gemini', 'la evaluación está vacía');
        }
        return $retro;
    }

    /**
     * Instrucciones de sistema y mensaje del paso de evaluación.
     *
     * Cada solicitud usa marcas nuevas al azar: quien escribe la entrega no
     * puede adivinarlas para «cerrar» su bloque y abrir uno falso. Por si
     * acaso, cualquier «<<<» dentro de los datos se neutraliza.
     *
     * @return array [instrucciones de sistema, mensaje]
     */
    public static function armar_evaluacion(string $transcripcion, string $contextoprofesor): array {
        $azar = strtoupper(bin2hex(random_bytes(6)));
        $entrega = "ENTREGA_$azar";
        $profesor = "PROFESOR_$azar";
        // EVALUAR termina en «The extraction to evaluate follows:»: aquí la
        // entrega va en el mensaje, en su bloque.
        $reglas = preg_replace('/The extraction to evaluate follows:\s*$/', '', prompts::EVALUAR);
        $sistema = rtrim($reglas) . "
" . str_replace(['{entrega}', '{profesor}'], [$entrega, $profesor], prompts::REGLAS_EVALUACION);

        $neutralizar = static fn (string $t) => str_replace(['<<<', '>>>'], ['‹‹‹', '›››'], $t);
        $mensaje = '';
        if ($contextoprofesor !== '') {
            $mensaje .= "TEACHER CONTEXT
<<<$profesor>>>
" . $neutralizar($contextoprofesor) . "
<<<END_$profesor>>>

";
        }
        $mensaje .= "STUDENT SUBMISSION
<<<$entrega>>>
" . $neutralizar($transcripcion) . "
<<<END_$entrega>>>
";
        return [$sistema, $mensaje];
    }

    /** Largo en caracteres (UTF-8). */
    private static function largo(string $texto): int {
        return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : (int) preg_match_all('/./su', $texto);
    }

    /**
     * Parte la transcripción de un PDF entero en un bloque por página, por
     * las líneas «=== PAGINA N ===». Sin marcas, un solo bloque con el rango.
     *
     * @return array lista de [página, texto]
     */
    public static function separar_paginas(string $texto, int|string $rango): array {
        $partes = preg_split('/^[ \t*#]*={2,}\s*P[ÁA]GINA\s+(\d+)\s*={2,}[ \t*]*$/mu', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($partes) < 3) {
            return [[$rango, trim($texto)]];
        }
        $resultado = [];
        $previo = trim($partes[0]);
        for ($k = 1; $k + 1 < count($partes); $k += 2) {
            $contenido = trim(($previo !== '' ? "$previo\n\n" : '') . trim($partes[$k + 1]));
            $previo = '';
            $resultado[] = [(int) $partes[$k], $contenido !== '' ? $contenido : 'Página sin contenido visible'];
        }
        return $resultado;
    }
}

