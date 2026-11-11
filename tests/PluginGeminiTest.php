<?php
// Pruebas del proveedor autónomo del plugin (classes/local/gemini/), sin
// Moodle y sin gastar cuota: Gemini se reemplaza por un transporte falso.
//
// Desde la raíz del repositorio:
//     vendor/bin/phpunit --testsuite scripts

namespace AutoEvaluar\Pruebas;

use assignfeedback_aiocr\local\gemini\cliente_gemini;
use assignfeedback_aiocr\local\gemini\evaluacion_exception;
use assignfeedback_aiocr\local\gemini\evaluador;
use assignfeedback_aiocr\local\gemini\pdf_paginas;
use assignfeedback_aiocr\local\gemini\prompts;
use AutoEvaluar\Scripts\DeployPlugin;
use AutoEvaluar\Servicio\PdfSimple;
use PHPUnit\Framework\TestCase;

final class PluginGeminiTest extends TestCase
{
    /** @var array peticiones que recibió el Gemini falso */
    private array $peticiones = [];

    /** @var array respuestas forzadas por número de llamada (1, 2...) */
    private array $forzadas = [];

    public static function setUpBeforeClass(): void
    {
        if (!defined('MOODLE_INTERNAL')) {
            define('MOODLE_INTERNAL', true);
        }
        foreach (['evaluacion_exception', 'prompts', 'pdf_paginas', 'cliente_gemini', 'evaluador'] as $clase) {
            require_once DeployPlugin::ORIGEN . "/classes/local/gemini/$clase.php";
        }
    }

    /** Gemini falso: transcribe PDF con marcas de página, imágenes y evalúa. */
    private function transporte(): callable
    {
        return function (string $url, string $cuerpo, array $cabeceras, int $timeout): array {
            $datos = json_decode($cuerpo, true);
            $this->peticiones[] = ['url' => $url, 'cuerpo' => $datos, 'cabeceras' => $cabeceras];
            $n = count($this->peticiones);
            if (isset($this->forzadas[$n])) {
                return $this->forzadas[$n];
            }
            $texto = '<div>RETRO FALSA</div>';
            foreach ($datos['contents'][0]['parts'] as $parte) {
                $mime = $parte['inline_data']['mime_type'] ?? null;
                if ($mime === 'application/pdf') {
                    $paginas = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', base64_decode($parte['inline_data']['data']));
                    $texto = '';
                    for ($i = 1; $i <= $paginas; $i++) {
                        $texto .= "=== PAGINA $i ===\nTRANSCRIPCION FALSA\n";
                    }
                } elseif ($mime !== null) {
                    $texto = 'IMAGEN TRANSCRITA';
                }
            }
            return [200, json_encode([
                'candidates' => [['content' => ['parts' => [['text' => $texto]]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 15],
            ]), ''];
        };
    }

    private function evaluador(int $maxPaginas = 20): evaluador
    {
        return new evaluador(new cliente_gemini('clave_falsa', 'gemini-falso', 30, $this->transporte()), $maxPaginas);
    }

    private static function pdf(int $paginas): array
    {
        return ['nombre' => "doc_$paginas.pdf", 'mimetype' => 'application/pdf',
            'contenido' => PdfSimple::crear(array_fill(0, $paginas, 'pagina'))];
    }

    private function clave(callable $accion): evaluacion_exception
    {
        try {
            $accion();
        } catch (evaluacion_exception $e) {
            return $e;
        }
        $this->fail('Se esperaba una evaluacion_exception.');
    }

    public function testLosPromptsSonLosDelServicio(): void
    {
        $servicio = RAIZ_REPO . '/php-service/prompts';
        $this->assertSame(file_get_contents("$servicio/extraer.txt"), prompts::EXTRAER);
        $this->assertSame(file_get_contents("$servicio/extraer_pdf.txt"), prompts::EXTRAER_PDF);
        $this->assertSame(file_get_contents("$servicio/evaluar.txt"), prompts::EVALUAR);
        $this->assertStringEndsWith("\n\n", prompts::EVALUAR, 'la transcripción se pega al final');
    }

    public function testTodasLasClavesDeErrorTienenTexto(): void
    {
        foreach (['en', 'es'] as $idioma) {
            $texto = file_get_contents(DeployPlugin::ORIGEN . "/lang/$idioma/assignfeedback_aiocr.php");
            foreach (evaluacion_exception::CLAVES as $clave) {
                $this->assertStringContainsString("\$string['error:$clave']", $texto, "$idioma: error:$clave");
            }
        }
    }

    public function testEntregaCompleta(): void
    {
        $e = $this->evaluador();
        $imagen = ['nombre' => 'foto.jpg', 'mimetype' => 'image/jpeg', 'contenido' => 'JPEG'];
        $omitido = ['nombre' => 'notas.docx', 'omitido' => 'tipo no admitido'];

        $retro = $e->evaluar([self::pdf(2), $omitido, $imagen], "  15% de 80 = 12  ");

        $this->assertSame('<div>RETRO FALSA</div>', $retro);
        $this->assertCount(3, $this->peticiones, '1 PDF + 1 imagen + 1 evaluación');
        $this->assertSame(cliente_gemini::URL_BASE . 'gemini-falso:generateContent', $this->peticiones[0]['url']);
        $this->assertContains('x-goog-api-key: clave_falsa', $this->peticiones[0]['cabeceras']);
        $partes = $this->peticiones[0]['cuerpo']['contents'][0]['parts'];
        $this->assertSame('application/pdf', $partes[0]['inline_data']['mime_type']);
        $this->assertStringContainsString('PDF document with 2 page(s)', $partes[1]['text']);
        $this->assertSame(prompts::EXTRAER, $this->peticiones[1]['cuerpo']['contents'][0]['parts'][1]['text']);

        foreach (['doc_2.pdf | Página: 1', 'doc_2.pdf | Página: 2', 'foto.jpg | Página: 1'] as $bloque) {
            $this->assertStringContainsString("Archivo: $bloque\n", $e->transcripcion);
        }
        $this->assertStringContainsString("no incluye el enunciado del profesor) | Página: 1\n15% de 80 = 12", $e->transcripcion);
        $this->assertSame(prompts::EVALUAR . $e->transcripcion, $this->peticiones[2]['cuerpo']['contents'][0]['parts'][0]['text']);

        $r = $e->registro;
        $this->assertSame('ok', $r['resultado']);
        $this->assertSame(3, $r['paginas']);
        $this->assertSame(45, $r['tokens_total']);
        $this->assertSame(['1-2', 1], array_column(array_slice($r['llamadas'], 0, 2), 'pagina'));
        $this->assertSame(
            [['doc_2.pdf', 'procesado'], ['notas.docx', 'omitido: tipo no admitido'], ['foto.jpg', 'procesado']],
            array_map(static fn ($a) => [$a['nombre'], $a['estado']], $r['archivos'])
        );
        $this->assertSame(14, $r['texto_en_linea_caracteres'], 'sin los espacios de los extremos');
    }

    public function testDemasiadasPaginasNoLlamaAGemini(): void
    {
        $e = $this->evaluador(2);
        $error = $this->clave(fn () => $e->evaluar([self::pdf(2), self::pdf(1)], null));
        $this->assertSame('toomanypages', $error->clave);
        $this->assertEquals((object) ['pages' => 3, 'max' => 2], $error->a);
        $this->assertSame([], $this->peticiones);
        $this->assertSame('error', $e->registro['resultado']);
    }

    public function testSinNadaQueEvaluar(): void
    {
        $error = $this->clave(fn () => $this->evaluador()->evaluar([['nombre' => 'a.txt', 'omitido' => 'tipo']], '  '));
        $this->assertSame('nocontent', $error->clave);
        $this->assertStringContainsString('a.txt', $error->a);
        $this->assertSame([], $this->peticiones);
    }

    public function testPdfDanadoNoLlamaAGemini(): void
    {
        $error = $this->clave(fn () => $this->evaluador()->evaluar(
            [['nombre' => 'malo.pdf', 'mimetype' => 'application/pdf', 'contenido' => 'no es un pdf']], null));
        $this->assertSame('pdfopen', $error->clave);
        $this->assertEquals((object) ['name' => 'malo.pdf', 'error' => 'pdfinvalid'], $error->a);
        $this->assertSame([], $this->peticiones);
    }

    public function testSiFallaUnaTranscripcionNoSeEvalua(): void
    {
        $this->forzadas[1] = [429, json_encode(['error' => ['message' => 'Quota exceeded']]), ''];
        $e = $this->evaluador();
        $error = $this->clave(fn () => $e->evaluar([self::pdf(1), self::pdf(2)], null));
        $this->assertSame('transcription', $error->clave);
        $this->assertSame('doc_1.pdf: error 429: Quota exceeded', $error->a);
        $this->assertCount(2, $this->peticiones, 'transcribe los dos, pero no evalúa');
        $this->assertStringContainsString('doc_2.pdf | Página: 2', $e->transcripcion);
    }

    public function testErroresDeConexionYRespuestaVacia(): void
    {
        $this->forzadas[1] = [0, '', 'Connection timed out'];
        $error = $this->clave(fn () => $this->evaluador()->evaluar([], 'texto'));
        $this->assertSame('gemini', $error->clave);
        $this->assertStringContainsString('Connection timed out', $error->a);

        $this->peticiones = [];
        $this->forzadas = [1 => [200, json_encode(['candidates' => [['finishReason' => 'SAFETY']]]), '']];
        $error = $this->clave(fn () => $this->evaluador()->evaluar([], 'texto'));
        $this->assertStringContainsString('SAFETY', $error->a);
    }

    public function testModeloNoValido(): void
    {
        $error = $this->clave(fn () => new cliente_gemini('k', 'gemini/../otro?x=1', 30, $this->transporte()));
        $this->assertSame('gemini', $error->clave);
    }

    public function testTipoAdmitido(): void
    {
        $this->assertSame('application/pdf', evaluador::tipo_admitido('application/pdf', 'x'));
        $this->assertSame('image/jpeg', evaluador::tipo_admitido('document/unknown', 'FOTO.JPG'));
        $this->assertSame('image/png', evaluador::tipo_admitido('image/png; charset=binary', 'x'));
        $this->assertNull(evaluador::tipo_admitido('text/plain', 'respuesta.txt'));
    }

    public function testContarPaginas(): void
    {
        $this->assertSame(3, pdf_paginas::contar(PdfSimple::crear(['a', 'b', 'c'])));
        $comprimido = gzcompress('<< /Type /Pages /Kids [4 0 R 5 0 R 6 0 R 7 0 R] /Count 4 >>');
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /ObjStm /Filter /FlateDecode /Length " . strlen($comprimido)
            . " >>\nstream\n$comprimido\nendstream\nendobj\n%%EOF";
        $this->assertSame(4, pdf_paginas::contar($pdf));
        foreach (['no es un pdf' => 'pdfinvalid', "%PDF-1.4\n<< /Encrypt 3 0 R >>" => 'pdfencrypted', "%PDF-1.4\nnada" => 'pdfnopages'] as $contenido => $clave) {
            $this->assertSame($clave, $this->clave(fn () => pdf_paginas::contar($contenido))->clave);
        }
    }

    public function testSepararPaginas(): void
    {
        $texto = "Nota\n=== PAGINA 1 ===\nuno\n\n**=== PÁGINA 2 ===**\n\n=== PAGINA 3 ===\ntres";
        $this->assertSame([[1, "Nota\n\nuno"], [2, 'Página sin contenido visible'], [3, 'tres']], evaluador::separar_paginas($texto, '1-3'));
        $this->assertSame([['1-3', 'todo junto']], evaluador::separar_paginas("todo junto\n", '1-3'));
    }
}
