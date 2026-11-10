<?php
// Pruebas del servicio con Moodle y Gemini simulados (servidor HTTP local).
// No usan internet ni gastan cuota de Gemini.
//
// Desde la raíz del repositorio:
//     vendor/bin/phpunit --testsuite servicio

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Servicio\Config;
use AutoEvaluar\Servicio\Cupos;
use AutoEvaluar\Servicio\ErrorHttp;
use AutoEvaluar\Servicio\PdfPaginas;
use AutoEvaluar\Servicio\PdfSimple;
use AutoEvaluar\Servicio\Servicio;
use AutoEvaluar\Servicio\Transcripciones;
use AutoEvaluar\Servicio\Version;
use PHPUnit\Framework\TestCase;

final class ServicioTest extends TestCase
{
    public const TOKEN = 'token_de_prueba';
    public const CLAVE = 'clave_de_prueba';
    public const CLAVE_SERVICIO = 'clave_del_servicio_de_prueba';

    private static ServidorLocal $falso;
    private static string $registroPeticiones;
    private static string $salida;
    private static Config $configBase;
    private Config $config;

    public static function setUpBeforeClass(): void
    {
        self::$salida = Config::normalizar(sys_get_temp_dir()) . '/autoevaluar_pruebas_' . bin2hex(random_bytes(4));
        self::$registroPeticiones = self::$salida . '_peticiones.jsonl';
        self::$falso = new ServidorLocal(__DIR__ . '/apoyo/moodle_y_gemini_falsos.php', [
            'AIOCR_FALSO_REGISTRO' => self::$registroPeticiones,
        ]);

        // Así las pruebas no dependen del .env real.
        foreach ([
            'AIOCR_ENV_FILE' => self::$salida . '_sin.env',
            'MOODLE_URL' => 'http://127.0.0.1:9',
            'MOODLE_PUBLIC_URL' => '',
            'MOODLE_TOKEN' => self::TOKEN,
            'GEMINI_API_KEY' => self::CLAVE,
            'SERVICE_API_KEY' => self::CLAVE_SERVICIO,
            'OUTPUT_DIR' => self::$salida,
        ] as $clave => $valor) {
            putenv("$clave=$valor");
        }
        // Valores fijos: las pruebas no dependen de lo que tenga el .env real.
        self::$configBase = Config::cargar()->con([
            'moodleUrl' => self::$falso->base(),
            'moodlePublicUrl' => '',
            'moodleToken' => self::TOKEN,
            'allowedFileHosts' => ['127.0.0.1', 'localhost'],
            'geminiApiKey' => self::CLAVE,
            'geminiUrl' => self::$falso->base() . '/gemini',
            'outputDir' => self::$salida,
            'serviceApiKey' => self::CLAVE_SERVICIO,
            'maxFileMb' => 20,
            'maxPages' => 20,
            'maxConcurrent' => 3,
            'saveTranscriptions' => true,
            'transcriptionsDays' => 180,
            'carpetaCupos' => self::$salida . '_cupos',
            // Las pruebas de siempre son del modo imagen (PdfFalso); las del
            // modo directo lo piden con con(['pdfModo' => 'directo']).
            'pdfModo' => 'imagen',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$falso->detener();
        Servicio::borrarCarpeta(self::$salida);
        Servicio::borrarCarpeta(self::$salida . '_cupos');
        @unlink(self::$registroPeticiones);
    }

    protected function setUp(): void
    {
        file_put_contents(self::$registroPeticiones, '');
        $this->config = self::$configBase;
        Servicio::borrarCarpeta(self::$salida);
        mkdir(self::$salida);
    }

    // --- Ayudas ---

    private function servicio(): Servicio
    {
        return new Servicio($this->config, new PdfFalso());
    }

    private function generar(array $solicitud): array
    {
        return $this->servicio()->generarRetroalimentacion($solicitud);
    }

    /** Ejecuta y devuelve el ErrorHttp que lanza (falla si no lanza ninguno). */
    private function errorDe(array $solicitud): ErrorHttp
    {
        try {
            $this->generar($solicitud);
        } catch (ErrorHttp $e) {
            return $e;
        }
        $this->fail('Se esperaba un ErrorHttp.');
    }

    private function peticiones(): array
    {
        $lineas = array_filter(explode("\n", (string) file_get_contents(self::$registroPeticiones)));
        return array_map(static fn ($l) => json_decode($l, true), array_values($lineas));
    }

    private function posts(): array
    {
        return array_values(array_filter($this->peticiones(), static fn ($p) => $p[0] === 'POST'));
    }

    private function gets(): array
    {
        return array_values(array_filter($this->peticiones(), static fn ($p) => $p[0] === 'GET'));
    }

    /** Archivos con esa extensión dentro de la carpeta de salida, en orden. */
    private function archivos(string $extension): array
    {
        $encontrados = [];
        if (is_dir(self::$salida)) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$salida, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterador as $archivo) {
                if ($archivo->isFile() && ($extension === '*' || $archivo->getExtension() === $extension)) {
                    $encontrados[] = str_replace('\\', '/', $archivo->getPathname());
                }
            }
        }
        sort($encontrados);
        return $encontrados;
    }

    private function registros(): array
    {
        return array_map(static fn ($r) => json_decode((string) file_get_contents($r), true), $this->archivos('json'));
    }

    private function transcripcion(): string
    {
        return (string) file_get_contents($this->archivos('txt')[0]);
    }

    private function solicitud(string $url, string $username = 'est_prueba'): array
    {
        return [
            'assignmentid' => 1, 'userid' => 7, 'username' => $username, 'onlinetext' => null,
            'files' => [['filename' => 'tarea.pdf', 'fileurl' => $url, 'mimetype' => 'application/pdf']],
        ];
    }

    private function solicitudVarios(array $nombresYUrls, ?string $onlinetext = null): array
    {
        return [
            'assignmentid' => 1, 'userid' => 7, 'username' => null, 'onlinetext' => $onlinetext,
            'files' => array_map(static fn ($p) => ['filename' => $p[0], 'fileurl' => $p[1], 'mimetype' => ''], $nombresYUrls),
        ];
    }

    private function urlMoodle(?string $base = null): string
    {
        return ($base ?? self::$falso->base()) . '/webservice/pluginfile.php/1/a/tarea.pdf';
    }

    // --- Pruebas ---

    public function testPromptsCargados(): void
    {
        $servicio = $this->servicio();
        $this->assertStringContainsString('CONTEXTO DEL PROFESOR', $servicio->promptExtraer);
        // La transcripción se pega al final del prompt de evaluación.
        $this->assertStringEndsWith("\n\n", $servicio->promptEvaluarBase);
    }

    public function testPipelineCompleto(): void
    {
        $temporalesAntes = glob(sys_get_temp_dir() . '/aiocr_*') ?: [];

        $r = $this->generar($this->solicitud($this->urlMoodle()));

        $this->assertSame(['feedback_text' => '<div>RETRO FALSA</div>', 'success' => true], $r);
        $gets = $this->gets();
        $posts = $this->posts();
        $this->assertCount(1, $gets);
        $this->assertStringEndsWith('?token=' . self::TOKEN, $gets[0][1]);
        $this->assertCount(3, $posts, '2 páginas + 1 evaluación');
        foreach ($posts as $p) {
            $this->assertSame(self::CLAVE, $p[3]);
        }

        // Transcripción y metadatos en tarea_<id>/usuario_<id>_<fecha>, sin el nombre de usuario.
        $txts = $this->archivos('txt');
        $this->assertCount(1, $txts);
        $this->assertMatchesRegularExpression('#/tarea_1/usuario_7_[^/]+\.txt$#', $txts[0]);
        $transcripcion = $this->transcripcion();
        $this->assertSame(2, substr_count($transcripcion, 'TRANSCRIPCION FALSA'));
        $promptFinal = $posts[2][2]['contents'][0]['parts'][0]['text'];
        $this->assertSame($this->servicio()->promptEvaluarBase . $transcripcion, $promptFinal);
        $registro = $this->registros()[0];
        $this->assertSame('ok', $registro['resultado']);
        $this->assertSame(2, $registro['paginas']);
        $this->assertSame(['transcripcion', 'transcripcion', 'evaluacion'], array_column($registro['llamadas'], 'paso'));
        $this->assertSame(45, $registro['tokens_total']);
        $this->assertSame(Version::VERSION, $registro['version_servicio']);
        $this->assertStringNotContainsString('est_prueba', json_encode($registro));

        $this->assertSame($temporalesAntes, glob(sys_get_temp_dir() . '/aiocr_*') ?: [], 'no deja carpetas temporales');
    }

    public function testHostNoPermitidoNoEnviaToken(): void
    {
        $e = $this->errorDe($this->solicitud('http://otro-sitio.example/archivo.pdf'));
        $this->assertSame(502, $e->estado);
        $this->assertStringContainsString('ALLOWED_FILE_HOSTS', $e->detalle);
        $this->assertSame([], $this->peticiones());
    }

    public function testUrlPublicaSeDescargaPorDentro(): void
    {
        // Moodle con wwwroot público distinto de la dirección interna (Docker, ngrok).
        $this->config = self::$configBase->con(['moodlePublicUrl' => 'https://moodle.publico.example']);

        $r = $this->generar($this->solicitud($this->urlMoodle('https://moodle.publico.example')));

        $this->assertTrue($r['success']);
        $this->assertSame('moodle.publico.example', $this->gets()[0][2], 'debe presentarse con el host público');
    }

    public function testErrorDeMoodleNoSeTrataComoPdf(): void
    {
        $this->config = self::$configBase->con(['moodleToken' => 'token_incorrecto']);
        $e = $this->errorDe($this->solicitud($this->urlMoodle()));
        $this->assertStringContainsString('Ficha (token) no válida', $e->detalle);
        $this->assertSame([], $this->posts(), 'no debe llamar a Gemini');
    }

    public function testEntregaSinArchivos(): void
    {
        $r = $this->generar(['assignmentid' => 1, 'userid' => 7]);
        $this->assertFalse($r['success']);
    }

    public function testCadaEvaluacionSeGuardaAparte(): void
    {
        $this->generar($this->solicitud($this->urlMoodle()));
        $this->generar($this->solicitud($this->urlMoodle()));
        $this->assertCount(2, $this->archivos('txt'));
        $this->assertCount(2, $this->registros());
    }

    public function testNoGuardarTranscripciones(): void
    {
        $this->config = self::$configBase->con(['saveTranscriptions' => false]);
        $this->assertTrue($this->generar($this->solicitud($this->urlMoodle()))['success']);
        $this->assertSame([], $this->archivos('*'));
    }

    public function testRutaQueNoEsDeArchivosNoRecibeToken(): void
    {
        $url = self::$falso->base() . '/webservice/rest/server.php?wsfunction=core_user_get_users';
        $e = $this->errorDe($this->solicitud($url));
        $this->assertStringContainsString('no es un archivo de Moodle', $e->detalle);
        $this->assertSame([], $this->peticiones());
    }

    public function testTipoDeArchivoNoAdmitido(): void
    {
        $e = $this->errorDe($this->solicitud($this->urlMoodle() . '/texto'));
        $this->assertStringContainsString('Tipo de archivo no admitido', $e->detalle);
        $this->assertSame([], $this->posts());
    }

    public function testArchivoDemasiadoGrande(): void
    {
        $this->config = self::$configBase->con(['maxFileMb' => 0]);
        $e = $this->errorDe($this->solicitud($this->urlMoodle()));
        $this->assertStringContainsString('máximo', $e->detalle);
        $this->assertSame([], $this->posts());
    }

    public function testDemasiadasPaginasNoLlamaAGemini(): void
    {
        $this->config = self::$configBase->con(['maxPages' => 1]);
        $e = $this->errorDe($this->solicitud($this->urlMoodle()));
        $this->assertSame(422, $e->estado);
        $this->assertStringContainsString('2 páginas', $e->detalle);
        $this->assertSame([], $this->posts());
        $this->assertSame('error', $this->registros()[0]['resultado']);
    }

    public function testLimiteDeEvaluacionesSimultaneas(): void
    {
        $tomados = [];
        for ($i = 0; $i < $this->config->maxConcurrent; $i++) {
            $cupo = Cupos::desde($this->config);
            $this->assertTrue($cupo->tomar());
            $tomados[] = $cupo;
        }
        try {
            $e = $this->errorDe($this->solicitud($this->urlMoodle()));
            $this->assertSame(503, $e->estado);
        } finally {
            foreach ($tomados as $cupo) {
                $cupo->liberar();
            }
        }
        $this->assertSame([], $this->peticiones());
        // Con los cupos libres vuelve a funcionar.
        $this->assertTrue($this->generar($this->solicitud($this->urlMoodle()))['success']);
    }

    public function testRetencionBorraLoAntiguo(): void
    {
        $carpeta = self::$salida . '/tarea_9';
        mkdir($carpeta);
        $viejo = "$carpeta/usuario_1_viejo.txt";
        $nuevo = "$carpeta/usuario_1_nuevo.txt";
        file_put_contents($viejo, 'x');
        file_put_contents($nuevo, 'y');
        touch($viejo, time() - 200 * 86400);
        $this->assertSame(1, Transcripciones::limpiarAntiguas(self::$configBase, true));
        $this->assertFileDoesNotExist($viejo);
        $this->assertFileExists($nuevo);
    }

    public function testVariosArchivosEnOrden(): void
    {
        $r = $this->generar($this->solicitudVarios([
            ['parte1.pdf', $this->urlMoodle()],                  // 2 páginas
            ['parte2.pdf', $this->urlMoodle() . '/una_pagina'],  // 1 página
        ]));
        $this->assertTrue($r['success']);
        $this->assertCount(4, $this->posts(), '3 páginas + 1 evaluación');
        $transcripcion = $this->transcripcion();
        $this->assertLessThan(
            strpos($transcripcion, 'Archivo: parte2.pdf | Página: 1'),
            strpos($transcripcion, 'Archivo: parte1.pdf | Página: 2')
        );
        $registro = $this->registros()[0];
        $this->assertSame(3, $registro['paginas']);
        $this->assertSame(
            [['parte1.pdf', 'procesado', 2], ['parte2.pdf', 'procesado', 1]],
            array_map(static fn ($a) => [$a['nombre'], $a['estado'], $a['paginas']], $registro['archivos'])
        );
    }

    public function testArchivoNoAdmitidoSeOmiteYSigue(): void
    {
        $r = $this->generar($this->solicitudVarios([
            ['notas.docx', $this->urlMoodle() . '/texto'],
            ['tarea.pdf', $this->urlMoodle() . '/una_pagina'],
        ]));
        $this->assertTrue($r['success']);
        $estados = array_column($this->registros()[0]['archivos'], 'estado', 'nombre');
        $this->assertStringStartsWith('omitido: Tipo de archivo no admitido', $estados['notas.docx']);
        $this->assertSame('procesado', $estados['tarea.pdf']);
    }

    public function testLimiteDePaginasSumaTodosLosArchivos(): void
    {
        $this->config = self::$configBase->con(['maxPages' => 3]);
        $e = $this->errorDe($this->solicitudVarios([
            ['a.pdf', $this->urlMoodle()], ['b.pdf', $this->urlMoodle()],  // 2 + 2
        ]));
        $this->assertSame(422, $e->estado);
        $this->assertStringContainsString('4 páginas en total', $e->detalle);
        $this->assertSame([], $this->posts());
    }

    public function testSoloTextoEnLinea(): void
    {
        $r = $this->generar($this->solicitudVarios([], 'x = 4 porque 2x = 8'));
        $this->assertTrue($r['success']);
        $this->assertCount(1, $this->posts(), 'solo la evaluación: el texto no se transcribe');
        $prompt = $this->posts()[0][2]['contents'][0]['parts'][0]['text'];
        $this->assertStringContainsString('Texto en línea de la entrega', $prompt);
        $this->assertStringEndsWith('x = 4 porque 2x = 8', $prompt);
        $this->assertSame(strlen('x = 4 porque 2x = 8'), $this->registros()[0]['texto_en_linea_caracteres']);
    }

    public function testArchivosYTextoEnLinea(): void
    {
        $r = $this->generar($this->solicitudVarios(
            [['tarea.pdf', $this->urlMoodle() . '/una_pagina']], 'Comentario del estudiante'
        ));
        $this->assertTrue($r['success']);
        $transcripcion = $this->transcripcion();
        $this->assertLessThan(strpos($transcripcion, 'Texto en línea'), strpos($transcripcion, 'Archivo: tarea.pdf'));
    }

    public function testSoloArchivosNoAdmitidos(): void
    {
        $e = $this->errorDe($this->solicitudVarios([['notas.docx', $this->urlMoodle() . '/texto']]));
        $this->assertSame(422, $e->estado);
        $this->assertStringContainsString('notas.docx', $e->detalle);
        $this->assertSame([], $this->posts());
    }

    public function testValidacionDeLaSolicitud(): void
    {
        $valida = Servicio::validarSolicitud(['assignmentid' => '5', 'userid' => 7, 'files' => [
            ['filename' => 'a.pdf', 'fileurl' => 'http://x/a.pdf', 'filesize' => 10],
        ]]);
        $this->assertSame(5, $valida['assignmentid'], 'acepta números en texto, como Pydantic');
        $this->assertSame('', $valida['files'][0]['mimetype']);

        foreach ([
            ['assignmentid' => -1, 'userid' => 7],
            ['userid' => 7],
            ['assignmentid' => 1, 'userid' => 7, 'username' => str_repeat('a', 101)],
            ['assignmentid' => 1, 'userid' => 7, 'files' => [['filename' => 'a.pdf']]],
            ['assignmentid' => 1, 'userid' => 7, 'files' => array_fill(0, 21, ['filename' => 'a', 'fileurl' => 'b'])],
            'no es un objeto',
        ] as $cuerpo) {
            try {
                Servicio::validarSolicitud($cuerpo);
                $this->fail('Debió rechazar: ' . json_encode($cuerpo));
            } catch (ErrorHttp $e) {
                $this->assertSame(422, $e->estado);
                $this->assertIsArray($e->detalle);
            }
        }
    }

    // --- PDF_MODO=directo: el PDF entero a Gemini, sin Imagick ---

    public function testModoDirectoUnaLlamadaPorPdf(): void
    {
        $this->config = self::$configBase->con(['pdfModo' => 'directo']);
        $r = $this->generar($this->solicitudVarios([
            ['parte1.pdf', $this->urlMoodle()],                  // 2 páginas
            ['parte2.pdf', $this->urlMoodle() . '/una_pagina'],  // 1 página
        ]));
        $this->assertTrue($r['success']);
        $posts = $this->posts();
        $this->assertCount(3, $posts, '1 llamada por PDF + 1 evaluación');
        $parte = $posts[0][2]['contents'][0]['parts'];
        $this->assertSame('application/pdf', $parte[0]['inline_data']['mime_type']);
        $this->assertStringStartsWith('%PDF', base64_decode($parte[0]['inline_data']['data']));
        $this->assertStringContainsString('PDF document with 2 page(s)', $parte[1]['text']);
        $this->assertStringContainsString('CONTEXTO DEL PROFESOR', $parte[1]['text']);

        // La transcripción queda separada por página, igual que en el modo imagen.
        $transcripcion = $this->transcripcion();
        foreach (['parte1.pdf | Página: 1', 'parte1.pdf | Página: 2', 'parte2.pdf | Página: 1'] as $bloque) {
            $this->assertStringContainsString("Archivo: $bloque\nTRANSCRIPCION FALSA", $transcripcion);
        }
        $this->assertStringNotContainsString('=== PAGINA', $transcripcion);

        $registro = $this->registros()[0];
        $this->assertSame('directo', $registro['modo_pdf']);
        $this->assertNull($registro['dpi']);
        $this->assertSame(3, $registro['paginas']);
        $this->assertSame(['1-2', 1], array_column(array_slice($registro['llamadas'], 0, 2), 'pagina'));
    }

    public function testModoDirectoRespetaElLimiteDePaginas(): void
    {
        $this->config = self::$configBase->con(['pdfModo' => 'directo', 'maxPages' => 1]);
        $e = $this->errorDe($this->solicitud($this->urlMoodle()));
        $this->assertSame(422, $e->estado);
        $this->assertStringContainsString('2 páginas', $e->detalle);
        $this->assertSame([], $this->posts());
    }

    public function testSepararPaginas(): void
    {
        $texto = "Nota previa\n=== PAGINA 1 ===\nuno\n\n**=== PÁGINA 2 ===**\n\n=== PAGINA 3 ===\ntres";
        $this->assertSame(
            [[1, "Nota previa\n\nuno"], [2, 'Página sin contenido visible'], [3, 'tres']],
            array_map(static fn ($p) => [$p['pagina'], $p['texto']], Servicio::separarPaginas('a.pdf', $texto, '1-3'))
        );
        // Sin marcas: un solo bloque con el rango.
        $sin = Servicio::separarPaginas('a.pdf', "todo junto\n", '1-3');
        $this->assertSame([['archivo' => 'a.pdf', 'pagina' => '1-3', 'texto' => 'todo junto', 'error' => null]], $sin);
    }

    public function testContarPaginasSinImagick(): void
    {
        $this->assertSame(3, PdfPaginas::contarEn(PdfSimple::crear(['a', 'b', 'c'])));

        // PDF moderno: el árbol de páginas va comprimido en un object stream.
        $comprimido = gzcompress('<< /Type /Pages /Kids [4 0 R 5 0 R 6 0 R 7 0 R] /Count 4 >>');
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /ObjStm /N 1 /First 4 /Filter /FlateDecode /Length "
            . strlen($comprimido) . " >>\nstream\n$comprimido\nendstream\nendobj\n%%EOF";
        $this->assertSame(4, PdfPaginas::contarEn($pdf));

        foreach (['no es un pdf' => 'no es un PDF', "%PDF-1.4\n<< /Encrypt 3 0 R >>" => 'cifrado', "%PDF-1.4\nnada" => 'contar'] as $contenido => $mensaje) {
            try {
                PdfPaginas::contarEn($contenido);
                $this->fail("Debía rechazar: $contenido");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }
    }
}
