<?php
// Conversor de PDF para las pruebas: cuenta las páginas leyendo el PDF y
// genera una imagen PNG mínima por página. Así las pruebas del servicio no
// dependen de Imagick ni de Ghostscript (PdfImagickTest prueba el real).

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Servicio\ConversorPdf;

final class PdfFalso implements ConversorPdf
{
    /** PNG de 1×1 píxel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function contarPaginas(string $rutaPdf): int
    {
        $texto = (string) file_get_contents($rutaPdf);
        if (!str_starts_with($texto, '%PDF')) {
            throw new \RuntimeException('no es un PDF');
        }
        return preg_match_all('#/Type\s*/Page\b#', $texto);
    }

    public function convertir(string $rutaPdf, int $dpi, int $maxPaginas): array
    {
        $total = $this->contarPaginas($rutaPdf);
        if ($total > $maxPaginas) {
            throw new \RuntimeException("El PDF tiene $total páginas; el máximo es $maxPaginas (MAX_PAGES).");
        }
        $carpeta = dirname($rutaPdf) . '/paginas';
        if (!is_dir($carpeta)) {
            mkdir($carpeta);
        }
        $rutas = [];
        for ($i = 1; $i <= $total; $i++) {
            $ruta = $carpeta . '/' . pathinfo($rutaPdf, PATHINFO_FILENAME) . "_pag$i.png";
            file_put_contents($ruta, base64_decode(self::PNG));
            $rutas[] = $ruta;
        }
        return $rutas;
    }
}
