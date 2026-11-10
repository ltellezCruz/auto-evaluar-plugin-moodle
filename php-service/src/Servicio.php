<?php
// Servicio.php
// Puente entre Moodle (el botón "Auto Evaluar" del plugin
// assignfeedback_aiocr) y Gemini.
//
// Pipeline de /ia/generar-retroalimentacion:
//     1. Comprueba la clave del servicio (cabecera X-Aiocr-Key) — en index.php.
//     2. Descarga TODOS los archivos de la entrega, en orden (solo desde
//        ALLOWED_FILE_HOSTS, solo /webservice/pluginfile.php, hasta
//        MAX_FILE_MB cada uno). Los que no son PDF ni imagen se omiten.
//     3. Cuenta las páginas (hasta MAX_PAGES en total). Con PDF_MODO=imagen
//        convierte cada página de los PDF en una imagen (Imagick); con
//        PDF_MODO=directo el PDF se envía entero. El texto en línea de la
//        entrega se agrega tal cual.
//     4. PASO 1 de Gemini: transcribe (prompts/extraer.txt) cada imagen, o
//        cada PDF entero en una sola llamada (prompts/extraer_pdf.txt +
//        extraer.txt), separando la respuesta por páginas.
//     5. Si alguna página falló la transcripción, se detiene aquí y se
//        informa el error (no se genera una evaluación incompleta).
//     6. PASO 2 de Gemini: evalúa la transcripción completa (prompts/evaluar.txt).
//     7. Guarda transcripción y metadatos (ver Transcripciones.php) y devuelve
//        el texto de retroalimentación al plugin.
//
// Los prompts viven solo en el servidor: quien llama al servicio envía
// datos (qué archivo evaluar), nunca instrucciones para Gemini.

namespace AutoEvaluar\Servicio;

final class Servicio
{
    public readonly string $promptExtraer;
    public readonly string $promptEvaluarBase;
    /** Encabezado del prompt cuando se envía el PDF entero ({paginas}). */
    public readonly string $promptExtraerPdf;
    public readonly Cupos $cupos;
    private readonly ConversorPdf $pdf;
    private readonly MoodleClient $moodle;
    private readonly GeminiClient $gemini;

    public function __construct(
        public readonly Config $config,
        ?ConversorPdf $pdf = null,
        ?Cupos $cupos = null,
    ) {
        $this->promptExtraer = $config->leerPrompt('extraer.txt');
        $this->promptEvaluarBase = $config->leerPrompt('evaluar.txt');
        $this->promptExtraerPdf = $config->leerPrompt('extraer_pdf.txt');
        $this->pdf = $pdf ?? new PdfImagick();
        $this->cupos = $cupos ?? Cupos::desde($config);
        $this->moodle = new MoodleClient($config);
        $this->gemini = new GeminiClient($config);
    }

    /** Comprueba el token y la conexión con Moodle (/moodle/info). */
    public function infoMoodle(): array
    {
        $datos = $this->moodle->llamar('core_webservice_get_site_info');
        $datos = is_array($datos) ? $datos : [];
        $info = [];
        foreach (['sitename', 'siteurl', 'username', 'release'] as $clave) {
            $info[$clave] = $datos[$clave] ?? null;
        }
        return $info;
    }

    // --- Validación de la solicitud (lo que hacía Pydantic) ---

    /** Revisa el cuerpo JSON y lo devuelve normalizado. Errores: 422 con la
     *  lista de problemas, en el mismo formato que FastAPI. */
    public static function validarSolicitud(mixed $datos): array
    {
        $errores = [];
        $error = static function (array $ubicacion, string $tipo, string $mensaje) use (&$errores): void {
            $errores[] = ['type' => $tipo, 'loc' => array_merge(['body'], $ubicacion), 'msg' => $mensaje];
        };
        if (!is_array($datos) || (array_is_list($datos) && $datos !== [])) {
            throw new ErrorHttp(422, [['type' => 'model_attributes_type', 'loc' => ['body'],
                'msg' => 'El cuerpo debe ser un objeto JSON']]);
        }

        $entero = static function (string $campo) use ($datos, $error): ?int {
            if (!array_key_exists($campo, $datos)) {
                $error([$campo], 'missing', 'Campo obligatorio');
                return null;
            }
            $v = $datos[$campo];
            if (is_string($v) && preg_match('/^\s*-?\d+\s*$/', $v)) {
                $v = (int) $v;
            } elseif (is_float($v) && floor($v) === $v) {
                $v = (int) $v;
            }
            if (!is_int($v)) {
                $error([$campo], 'int_parsing', 'Debe ser un número entero');
                return null;
            }
            if ($v <= 0) {
                $error([$campo], 'greater_than', 'Debe ser mayor que 0');
                return null;
            }
            return $v;
        };
        $texto = static function (array $ubicacion, mixed $v, int $maximo, bool $opcional) use ($error): ?string {
            if ($v === null && $opcional) {
                return null;
            }
            if (!is_string($v)) {
                $error($ubicacion, 'string_type', 'Debe ser un texto');
                return null;
            }
            if (self::largo($v) > $maximo) {
                $error($ubicacion, 'string_too_long', "Debe tener como mucho $maximo caracteres");
                return null;
            }
            return $v;
        };

        $solicitud = [
            'assignmentid' => $entero('assignmentid'),
            'userid' => $entero('userid'),
            'username' => $texto(['username'], $datos['username'] ?? null, 100, true),
            'files' => [],
            'onlinetext' => $texto(['onlinetext'], $datos['onlinetext'] ?? null, 100000, true),
        ];

        $archivos = $datos['files'] ?? [];
        if (!is_array($archivos) || !array_is_list($archivos)) {
            $error(['files'], 'list_type', 'Debe ser una lista');
        } elseif (count($archivos) > 20) {
            $error(['files'], 'too_long', 'Como mucho 20 archivos');
        } else {
            foreach ($archivos as $i => $archivo) {
                if (!is_array($archivo) || array_is_list($archivo) && $archivo !== []) {
                    $error(['files', $i], 'model_type', 'Debe ser un objeto');
                    continue;
                }
                foreach (['filename', 'fileurl'] as $campo) {
                    if (!array_key_exists($campo, $archivo)) {
                        $error(['files', $i, $campo], 'missing', 'Campo obligatorio');
                    }
                }
                $solicitud['files'][] = [
                    'filename' => $texto(['files', $i, 'filename'], $archivo['filename'] ?? null, 255, false),
                    'fileurl' => $texto(['files', $i, 'fileurl'], $archivo['fileurl'] ?? null, 2048, false),
                    'mimetype' => $texto(['files', $i, 'mimetype'], $archivo['mimetype'] ?? '', 255, false),
                ];
            }
        }

        if ($errores) {
            throw new ErrorHttp(422, $errores);
        }
        return $solicitud;
    }

    private static function largo(string $texto): int
    {
        return function_exists('mb_strlen') ? mb_strlen($texto, 'UTF-8') : (int) preg_match_all('/./su', $texto);
    }

    // --- Pipeline: descarga + varias páginas + Gemini (2 pasos) ---

    /** Lo que se ejecuta cuando el profesor le da clic a "Auto Evaluar"
     *  sobre la entrega de UN estudiante específico.
     *  $solicitud: el resultado de validarSolicitud(). */
    public function generarRetroalimentacion(array $solicitud, ?string $versionPlugin = null): array
    {
        $solicitud += ['files' => [], 'onlinetext' => null];
        if (!$solicitud['files'] && trim((string) $solicitud['onlinetext']) === '') {
            return [
                'feedback_text' => '',
                'success' => false,
                'error' => 'La entrega no tiene archivos ni texto en línea para procesar.',
            ];
        }

        if (!$this->cupos->tomar()) {
            throw new ErrorHttp(503, "El servicio ya está procesando {$this->config->maxConcurrent} evaluaciones. "
                . 'Intenta de nuevo en un momento.');
        }
        try {
            return $this->evaluar($solicitud, $versionPlugin);
        } finally {
            $this->cupos->liberar();
        }
    }

    private function evaluar(array $solicitud, ?string $versionPlugin): array
    {
        $inicio = hrtime(true);
        $textoEnLinea = trim((string) $solicitud['onlinetext']);
        $registro = [
            'fecha' => date('Y-m-d\TH:i:s'),
            // Con qué versiones se produjo esta evaluación (útil al comparar
            // resultados entre versiones del plugin, del servicio o de los prompts).
            'version_servicio' => Version::VERSION,
            'version_plugin' => $versionPlugin,
            'assignmentid' => $solicitud['assignmentid'],
            'userid' => $solicitud['userid'],
            'archivos' => array_map(static fn ($a) => ['nombre' => $a['filename'], 'estado' => 'pendiente'], $solicitud['files']),
            'texto_en_linea_caracteres' => self::largo($textoEnLinea),
            'modelo' => $this->config->geminiModel,
            'modo_pdf' => $this->config->pdfModo,
            'dpi' => $this->config->pdfModo === 'imagen' ? $this->config->pdfDpi : null,
            'paginas' => 0,
            'llamadas' => [],
            'resultado' => 'error',
            'error' => null,
        ];
        $transcripcionCompleta = '';

        try {
            // Los archivos y sus imágenes se borran al terminar la solicitud.
            $carpetaTemporal = self::crearCarpetaTemporal();
            try {
                // --- Descargar todos los archivos de la entrega ---
                $descargados = []; // [nombre original, ruta local, índice en el registro]
                foreach ($solicitud['files'] as $i => $archivo) {
                    try {
                        $ruta = $this->moodle->descargarArchivo($archivo['fileurl'], $carpetaTemporal, 'archivo_' . ($i + 1));
                    } catch (TipoNoAdmitido $e) {
                        $registro['archivos'][$i]['estado'] = 'omitido: ' . $e->getMessage();
                        continue;
                    } catch (\Throwable $e) {
                        throw new ErrorHttp(502, "No se pudo descargar «{$archivo['filename']}» desde Moodle: " . $e->getMessage());
                    }
                    $descargados[] = [$archivo['filename'], $ruta, $i];
                }

                if (!$descargados && $textoEnLinea === '') {
                    $omitidos = implode('; ', array_map(static fn ($a) => "{$a['nombre']} ({$a['estado']})", $registro['archivos']));
                    throw new ErrorHttp(422, "La entrega no tiene archivos PDF, PNG o JPG ni texto en línea. $omitidos");
                }

                // --- Contar páginas antes de convertir: el límite es por entrega ---
                $directo = $this->config->pdfModo === 'directo';
                try {
                    $paginasPorArchivo = array_map(
                        fn ($d) => !str_ends_with($d[1], '.pdf') ? 1 : ($directo
                            ? PdfPaginas::contar($d[1])
                            : $this->pdf->contarPaginas($d[1])),
                        $descargados
                    );
                } catch (\Throwable $e) {
                    throw new ErrorHttp(422, 'No se pudo abrir un PDF de la entrega: ' . $e->getMessage());
                }
                $total = array_sum($paginasPorArchivo);
                if ($total > $this->config->maxPages) {
                    throw new ErrorHttp(422, "La entrega tiene $total páginas en total; el máximo es {$this->config->maxPages} (MAX_PAGES).");
                }
                $registro['paginas'] = $total;

                // --- PASO 1: transcribir cada página de cada archivo con Gemini ---
                $transcripciones = [];
                foreach ($descargados as $n => [$nombre, $ruta, $i]) {
                    if ($directo && str_ends_with($ruta, '.pdf')) {
                        $registro['archivos'][$i]['estado'] = 'procesado';
                        $registro['archivos'][$i]['paginas'] = $paginasPorArchivo[$n];
                        array_push($transcripciones, ...$this->transcribirPdfEntero($nombre, $ruta, $paginasPorArchivo[$n], $registro));
                        continue;
                    }
                    try {
                        $imagenes = str_ends_with($ruta, '.pdf')
                            ? $this->pdf->convertir($ruta, $this->config->pdfDpi, $paginasPorArchivo[$n])
                            : [$ruta];
                    } catch (\Throwable $e) {
                        throw new ErrorHttp(500, "Error al convertir «{$nombre}» a imagen: " . $e->getMessage());
                    }
                    $registro['archivos'][$i]['estado'] = 'procesado';
                    $registro['archivos'][$i]['paginas'] = count($imagenes);

                    foreach ($imagenes as $k => $imagen) {
                        $pagina = ['archivo' => $nombre, 'pagina' => $k + 1, 'texto' => '', 'error' => null];
                        try {
                            [$texto, $consumo] = $this->gemini->consultar($this->promptExtraer, $imagen);
                            $registro['llamadas'][] = ['paso' => 'transcripcion', 'archivo' => $nombre, 'pagina' => $k + 1] + $consumo;
                            if (trim($texto) === '') {
                                throw new \RuntimeException('La transcripción está vacía.');
                            }
                            $pagina['texto'] = $texto;
                        } catch (\Throwable $e) {
                            $pagina['error'] = $e->getMessage();
                        }
                        $transcripciones[] = $pagina;
                    }
                }
            } finally {
                self::borrarCarpeta($carpetaTemporal);
            }

            $bloques = [];
            foreach ($transcripciones as $t) {
                if ($t['texto'] !== '') {
                    $bloques[] = "Archivo: {$t['archivo']} | Página: {$t['pagina']}\n{$t['texto']}";
                }
            }
            if ($textoEnLinea !== '') {
                // Ya es texto: no pasa por la transcripción. Se marca como tal para
                // que la evaluación sepa que no trae el enunciado del profesor.
                $bloques[] = 'Archivo: Texto en línea de la entrega (escrito por el estudiante en Moodle; '
                    . "no incluye el enunciado del profesor) | Página: 1\n$textoEnLinea";
            }
            $transcripcionCompleta = implode("\n\n", $bloques);

            // Si alguna página falló, no seguimos: no se publica una evaluación
            // incompleta.
            $pendientes = array_filter($transcripciones, static fn ($t) => $t['error'] !== null || trim($t['texto']) === '');
            if ($pendientes) {
                $detalle = implode('; ', array_map(
                    static fn ($t) => "{$t['archivo']} pág.{$t['pagina']}: " . ($t['error'] ?? 'None'), $pendientes
                ));
                $registro['error'] = "No se pudo transcribir alguna página: $detalle";
                return ['feedback_text' => '', 'success' => false, 'error' => $registro['error']];
            }

            // --- PASO 2: evaluar la transcripción completa ---
            try {
                [$retroalimentacion, $consumo] = $this->gemini->consultar($this->promptEvaluarBase . $transcripcionCompleta);
                $registro['llamadas'][] = ['paso' => 'evaluacion'] + $consumo;
            } catch (\Throwable $e) {
                throw new ErrorHttp(502, 'Error al generar la retroalimentación con Gemini: ' . $e->getMessage());
            }

            $registro['resultado'] = 'ok';
            return ['feedback_text' => $retroalimentacion, 'success' => true];

        } catch (ErrorHttp $e) {
            $registro['error'] = $e->detalle;
            throw $e;
        } finally {
            $registro['duracion_s'] = round((hrtime(true) - $inicio) / 1e9, 2);
            $registro['tokens_total'] = array_sum(array_map(static fn ($ll) => $ll['total'] ?? 0, $registro['llamadas']));
            Transcripciones::guardarEvaluacion($this->config, $registro, $transcripcionCompleta);
        }
    }

    /** PDF_MODO=directo: una sola llamada a Gemini con el PDF entero. La
     *  respuesta se separa por las líneas «=== PAGINA N ===» que pide
     *  prompts/extraer_pdf.txt, para que la evaluación cite archivo y página
     *  igual que en el modo imagen. Si Gemini no las pone, queda un solo
     *  bloque con el rango de páginas.
     *  @return array<int, array{archivo: string, pagina: int|string, texto: string, error: ?string}> */
    private function transcribirPdfEntero(string $nombre, string $ruta, int $paginas, array &$registro): array
    {
        $rango = $paginas > 1 ? "1-$paginas" : 1;
        $prompt = str_replace('{paginas}', (string) $paginas, $this->promptExtraerPdf) . $this->promptExtraer;
        try {
            [$texto, $consumo] = $this->gemini->consultar($prompt, $ruta);
            $registro['llamadas'][] = ['paso' => 'transcripcion', 'archivo' => $nombre, 'pagina' => $rango] + $consumo;
            if (trim($texto) === '') {
                throw new \RuntimeException('La transcripción está vacía.');
            }
        } catch (\Throwable $e) {
            return [['archivo' => $nombre, 'pagina' => $rango, 'texto' => '', 'error' => $e->getMessage()]];
        }
        return self::separarPaginas($nombre, $texto, $rango);
    }

    /** Parte la transcripción de un PDF entero en un bloque por página. */
    public static function separarPaginas(string $nombre, string $texto, int|string $rango): array
    {
        $partes = preg_split('/^[ \t*#]*={2,}\s*P[ÁA]GINA\s+(\d+)\s*={2,}[ \t*]*$/mu', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($partes) < 3) {
            return [['archivo' => $nombre, 'pagina' => $rango, 'texto' => trim($texto), 'error' => null]];
        }
        $resultado = [];
        $previo = trim($partes[0]); // texto antes de la primera marca (si lo hay)
        for ($k = 1; $k + 1 < count($partes); $k += 2) {
            $contenido = trim(($previo !== '' ? "$previo\n\n" : '') . trim($partes[$k + 1]));
            $previo = '';
            $resultado[] = [
                'archivo' => $nombre,
                'pagina' => (int) $partes[$k],
                // Una página en blanco no es un error de transcripción.
                'texto' => $contenido !== '' ? $contenido : 'Página sin contenido visible',
                'error' => null,
            ];
        }
        return $resultado;
    }

    private static function crearCarpetaTemporal(): string
    {
        for ($intento = 0; $intento < 10; $intento++) {
            $ruta = Config::normalizar(sys_get_temp_dir()) . '/aiocr_' . bin2hex(random_bytes(6));
            if (@mkdir($ruta, 0700)) {
                return $ruta;
            }
        }
        throw new ErrorHttp(500, 'No se pudo crear una carpeta temporal.');
    }

    public static function borrarCarpeta(string $ruta): void
    {
        if (!is_dir($ruta)) {
            return;
        }
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($ruta, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterador as $elemento) {
            $elemento->isDir() && !$elemento->isLink() ? @rmdir($elemento->getPathname()) : @unlink($elemento->getPathname());
        }
        @rmdir($ruta);
    }
}
