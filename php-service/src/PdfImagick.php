<?php
// PdfImagick.php
// Conversión de un PDF a imágenes, una por página, con la extensión
// imagick de PHP (ImageMagick). ImageMagick usa Ghostscript para leer PDF:
// los dos tienen que estar instalados (ver README y docs/).

namespace AutoEvaluar\Servicio;

final class PdfImagick implements ConversorPdf
{
    private static function exigirImagick(): void
    {
        if (!extension_loaded('imagick')) {
            throw new \RuntimeException(
                'Falta la extensión imagick de PHP (y Ghostscript) para convertir PDF a imágenes. '
                . 'Ver la sección «Servicio PHP» del README.'
            );
        }
    }

    public function contarPaginas(string $rutaPdf): int
    {
        self::exigirImagick();
        $imagen = new \Imagick();
        // pingImage lee la estructura sin dibujar las páginas.
        $imagen->pingImage($rutaPdf);
        $total = $imagen->getNumberImages();
        $imagen->clear();
        return $total;
    }

    /** Convierte CADA página del PDF en una imagen PNG separada.
     *  Rechaza el PDF antes de convertir si tiene más de $maxPaginas: cada
     *  página es una llamada a Gemini y una imagen en disco. */
    public function convertir(string $rutaPdf, int $dpi, int $maxPaginas): array
    {
        self::exigirImagick();

        $carpetaSalida = dirname($rutaPdf) . '/paginas';
        if (!is_dir($carpetaSalida)) {
            mkdir($carpetaSalida, 0700, true);
        }
        $nombreBase = pathinfo($rutaPdf, PATHINFO_FILENAME);

        $total = $this->contarPaginas($rutaPdf);
        if ($total > $maxPaginas) {
            throw new \RuntimeException("El PDF tiene $total páginas; el máximo es $maxPaginas (MAX_PAGES).");
        }

        $rutas = [];
        for ($i = 0; $i < $total; $i++) {
            $pagina = new \Imagick();
            // La resolución va ANTES de leer: es la de rasterización (como el
            // zoom dpi/72 de PyMuPDF).
            $pagina->setResolution($dpi, $dpi);
            $pagina->readImage($rutaPdf . '[' . $i . ']');
            // Fondo blanco: sin esto, las zonas vacías quedan transparentes.
            $pagina->setImageBackgroundColor('white');
            $pagina->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $pagina = $pagina->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $pagina->setImageFormat('png');
            $ruta = $carpetaSalida . '/' . $nombreBase . '_pag' . ($i + 1) . '.png';
            $pagina->writeImage($ruta);
            $pagina->clear();
            $rutas[] = $ruta;
        }
        return $rutas;
    }
}
