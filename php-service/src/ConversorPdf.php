<?php
// Lo que el servicio necesita de un PDF: cuántas páginas tiene y una imagen
// por página. La implementación real es PdfImagick; las pruebas usan una
// falsa para no depender de Imagick ni de Ghostscript.

namespace AutoEvaluar\Servicio;

interface ConversorPdf
{
    public function contarPaginas(string $rutaPdf): int;

    /** @return string[] rutas de las imágenes PNG, en orden */
    public function convertir(string $rutaPdf, int $dpi, int $maxPaginas): array;
}
