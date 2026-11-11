<?php
// This file is part of Moodle - https://moodle.org/
//
// Cuenta las páginas de un PDF sin Imagick ni otras extensiones. El PDF se
// envía entero a Gemini, pero MAX_PAGES (ajuste max_pages) se aplica antes
// de llamar. Port de php-service/src/PdfPaginas.php.
//
// Cómo cuenta: el número de páginas está en el objeto raíz /Type /Pages
// (/Count N). En los PDF modernos ese objeto puede ir comprimido dentro de
// un "object stream" (FlateDecode); por eso, si no aparece en el archivo tal
// cual, se descomprimen los flujos y se busca ahí.

namespace assignfeedback_aiocr\local\gemini;

defined('MOODLE_INTERNAL') || die();

/**
 * Contador de páginas de PDF en PHP puro.
 */
final class pdf_paginas {

    /**
     * @param string $contenido bytes del PDF
     * @return int páginas
     * @throws evaluacion_exception pdfinvalid, pdfencrypted o pdfnopages
     */
    public static function contar(string $contenido): int {
        if (!str_starts_with(ltrim(substr($contenido, 0, 1024)), '%PDF')) {
            throw new evaluacion_exception('pdfinvalid');
        }
        if (preg_match('#/Encrypt\b#', $contenido)) {
            throw new evaluacion_exception('pdfencrypted');
        }
        $total = self::buscar($contenido);
        if ($total === 0) {
            $total = self::buscar(self::flujos_descomprimidos($contenido));
        }
        if ($total === 0) {
            throw new evaluacion_exception('pdfnopages');
        }
        return $total;
    }

    /**
     * El /Count más grande de los nodos /Type /Pages (el raíz los suma todos);
     * si no hay ninguno, la cantidad de objetos /Type /Page.
     */
    private static function buscar(string $texto): int {
        $maximo = 0;
        if (preg_match_all('#/Type\s*/Pages\b#', $texto, $nodos, PREG_OFFSET_CAPTURE)) {
            foreach ($nodos[0] as [, $posicion]) {
                $inicio = strrpos(substr($texto, 0, $posicion), '<<');
                $fin = strpos($texto, '>>', $posicion);
                if ($inicio === false || $fin === false) {
                    continue;
                }
                if (preg_match('#/Count\s+(\d+)#', substr($texto, $inicio, $fin - $inicio), $m)) {
                    $maximo = max($maximo, (int) $m[1]);
                }
            }
        }
        return $maximo ?: preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $texto);
    }

    /** Une el contenido descomprimido de los flujos FlateDecode del PDF. */
    private static function flujos_descomprimidos(string $contenido): string {
        $salida = '';
        if (!preg_match_all('#stream\r?\n#', $contenido, $inicios, PREG_OFFSET_CAPTURE)) {
            return $salida;
        }
        foreach ($inicios[0] as [$marca, $posicion]) {
            $desde = $posicion + strlen($marca);
            $hasta = strpos($contenido, 'endstream', $desde);
            if ($hasta === false) {
                continue;
            }
            $datos = @gzuncompress(rtrim(substr($contenido, $desde, $hasta - $desde), "\r\n"));
            if ($datos !== false) {
                $salida .= $datos . "\n";
            }
        }
        return $salida;
    }
}
