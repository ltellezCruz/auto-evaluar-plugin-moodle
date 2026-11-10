<?php
// PdfSimple.php
// Genera un PDF válido y pequeño con una línea de texto por página. Lo usan
// las pruebas (como hacía pymupdf) y bin/comprobar.php, para revisar que
// Imagick + Ghostscript pueden leer PDF.

namespace AutoEvaluar\Servicio;

final class PdfSimple
{
    /** @param string[] $textos uno por página (solo ASCII) */
    public static function crear(array $textos): string
    {
        $objetos = [];
        $objetos[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $hijos = [];
        $siguiente = 4;
        $paginas = [];
        foreach ($textos as $texto) {
            $pagina = $siguiente++;
            $contenido = $siguiente++;
            $hijos[] = "$pagina 0 R";
            $texto = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
            $flujo = "BT /F1 12 Tf 72 720 Td ($texto) Tj ET";
            $paginas[$pagina] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
                . "/Resources << /Font << /F1 3 0 R >> >> /Contents $contenido 0 R >>";
            $paginas[$contenido] = '<< /Length ' . strlen($flujo) . " >>\nstream\n$flujo\nendstream";
        }
        $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $hijos) . '] /Count ' . count($textos) . ' >>';
        $objetos[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objetos += $paginas;
        ksort($objetos);

        $pdf = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $numero => $cuerpo) {
            $posiciones[$numero] = strlen($pdf);
            $pdf .= "$numero 0 obj\n$cuerpo\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = count($objetos) + 1;
        $pdf .= "xref\n0 $total\n0000000000 65535 f \n";
        foreach ($posiciones as $posicion) {
            $pdf .= sprintf("%010d 00000 n \n", $posicion);
        }
        $pdf .= "trailer\n<< /Size $total /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
