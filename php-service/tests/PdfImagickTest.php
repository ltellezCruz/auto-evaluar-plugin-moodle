<?php
// Conversión real de PDF a imágenes con Imagick + Ghostscript. Se salta si
// la extensión imagick no está instalada (en Docker sí está).

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Servicio\Config;
use AutoEvaluar\Servicio\PdfImagick;
use AutoEvaluar\Servicio\PdfSimple;
use AutoEvaluar\Servicio\Servicio;
use PHPUnit\Framework\TestCase;

final class PdfImagickTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Falta la extensión imagick.');
        }
        $this->carpeta = Config::normalizar(sys_get_temp_dir()) . '/autoevaluar_pdf_' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        if (isset($this->carpeta)) {
            Servicio::borrarCarpeta($this->carpeta);
        }
    }

    public function testUnaImagenPorPagina(): void
    {
        $pdf = $this->carpeta . '/entrega.pdf';
        file_put_contents($pdf, PdfSimple::crear(['Pagina 1', 'Pagina 2']));
        $conversor = new PdfImagick();

        $this->assertSame(2, $conversor->contarPaginas($pdf));
        $imagenes = $conversor->convertir($pdf, 100, 20);

        $this->assertCount(2, $imagenes);
        foreach ($imagenes as $imagen) {
            $tamano = getimagesize($imagen);
            $this->assertSame(IMAGETYPE_PNG, $tamano[2]);
            // 8,5 × 11 pulgadas a 100 dpi.
            $this->assertEqualsWithDelta(850, $tamano[0], 2);
            $this->assertEqualsWithDelta(1100, $tamano[1], 2);
        }
    }

    public function testRechazaDemasiadasPaginas(): void
    {
        $pdf = $this->carpeta . '/entrega.pdf';
        file_put_contents($pdf, PdfSimple::crear(['1', '2', '3']));
        $this->expectExceptionMessage('3 páginas');
        (new PdfImagick())->convertir($pdf, 72, 2);
    }
}
