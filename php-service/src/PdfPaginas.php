<?php
// PdfPaginas.php
// Cuenta las páginas de un PDF sin Imagick ni otras extensiones. Lo usa el
// modo PDF_MODO=directo, en el que el PDF se envía entero a Gemini y no se
// convierte a imágenes, pero MAX_PAGES se sigue aplicando antes de llamar.
//
// Cómo cuenta: el número de páginas está en el objeto raíz /Type /Pages
// (/Count N). En los PDF modernos ese objeto puede ir comprimido dentro de
// un "object stream" (FlateDecode); por eso, si no aparece en el archivo tal
// cual, se descomprimen los flujos y se busca ahí.

namespace AutoEvaluar\Servicio;

final class PdfPaginas
{
    public static function contar(string $rutaPdf): int
    {
        $contenido = @file_get_contents($rutaPdf);
        if ($contenido === false) {
            throw new \RuntimeException('No se pudo leer el archivo.');
        }
        return self::contarEn($contenido);
    }

    public static function contarEn(string $contenido): int
    {
        if (!str_starts_with(ltrim(substr($contenido, 0, 1024)), '%PDF')) {
            throw new \RuntimeException('El archivo no es un PDF.');
        }
        if (preg_match('#/Encrypt\b#', $contenido)) {
            throw new \RuntimeException('El PDF está protegido con contraseña o cifrado.');
        }

        $total = self::buscar($contenido);
        if ($total === 0) {
            $total = self::buscar(self::flujosDescomprimidos($contenido));
        }
        if ($total === 0) {
            throw new \RuntimeException('No se pudo contar las páginas del PDF (¿archivo dañado?).');
        }
        return $total;
    }

    /** El /Count más grande de los nodos /Type /Pages (el raíz los suma todos);
     *  si no hay ninguno, la cantidad de objetos /Type /Page. */
    private static function buscar(string $texto): int
    {
        $maximo = 0;
        if (preg_match_all('#/Type\s*/Pages\b#', $texto, $nodos, PREG_OFFSET_CAPTURE)) {
            foreach ($nodos[0] as [, $posicion]) {
                // /Count está en el mismo diccionario, antes o después de /Type.
                $inicio = strrpos(substr($texto, 0, $posicion), '<<');
                $fin = strpos($texto, '>>', $posicion);
                if ($inicio === false || $fin === false) {
                    continue;
                }
                $diccionario = substr($texto, $inicio, $fin - $inicio);
                if (preg_match('#/Count\s+(\d+)#', $diccionario, $m)) {
                    $maximo = max($maximo, (int) $m[1]);
                }
            }
        }
        return $maximo ?: preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $texto);
    }

    /** Une el contenido descomprimido de los flujos FlateDecode del PDF. */
    private static function flujosDescomprimidos(string $contenido): string
    {
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
