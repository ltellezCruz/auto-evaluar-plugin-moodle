<?php
// This file is part of Moodle - https://moodle.org/

namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

use assignfeedback_aiocr\local\gemini\evaluacion_exception;

/**
 * rasterizador
 *
 * Convierte un PDF en una imagen PNG por página, para los modelos que no
 * leen PDF (modelo local). Usa, en este orden:
 *   1. Ghostscript, con la ruta que Moodle ya tiene en Administración del
 *      sitio > Servidor > Rutas del sistema > «Ruta a ghostscript»
 *      ($CFG->pathtogs; la misma que usa la anotación de PDF de Tarea);
 *   2. la extensión imagick de PHP (que a su vez usa Ghostscript).
 * Si no hay ninguno, los PDF no se pueden evaluar con el modelo local (las
 * imágenes y el texto en línea sí).
 */
class rasterizador {

    /** @return string|null 'ghostscript', 'imagick' o null */
    public static function disponible(): ?string {
        return self::ghostscript() !== null ? 'ghostscript' : (extension_loaded('imagick') ? 'imagick' : null);
    }

    /**
     * @param string $pdf bytes del PDF
     * @param int $dpi resolución
     * @param int $paginas cuántas páginas convertir (ya contadas y dentro del límite)
     * @return string[] PNG de cada página, en orden
     * @throws evaluacion_exception norasterizer
     */
    public static function convertir(string $pdf, int $dpi, int $paginas): array {
        $carpeta = make_request_directory();
        $entrada = $carpeta . '/entrada.pdf';
        file_put_contents($entrada, $pdf);

        $gs = self::ghostscript();
        if ($gs !== null) {
            return self::con_ghostscript($gs, $entrada, $carpeta, $dpi, $paginas);
        }
        if (extension_loaded('imagick')) {
            return self::con_imagick($entrada, $dpi, $paginas);
        }
        throw new evaluacion_exception('norasterizer');
    }

    /** Ruta de Ghostscript configurada en Moodle, si existe. */
    private static function ghostscript(): ?string {
        global $CFG;
        $ruta = trim((string) ($CFG->pathtogs ?? ''));
        return ($ruta !== '' && is_file($ruta) && is_executable($ruta)) ? $ruta : null;
    }

    private static function con_ghostscript(string $gs, string $entrada, string $carpeta, int $dpi, int $paginas): array {
        // Lista de argumentos (sin pasar por la consola): las rutas pueden
        // tener espacios y el patrón %03d no debe tocarse. -dSAFER impide que
        // el PDF lea o escriba otros archivos.
        $comando = [$gs, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=png16m',
            '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4', '-r' . $dpi,
            '-dFirstPage=1', '-dLastPage=' . $paginas,
            '-sOutputFile=' . $carpeta . '/pagina_%03d.png', $entrada];
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
        if (!is_resource($proceso)) {
            throw new \RuntimeException('no se pudo ejecutar Ghostscript');
        }
        $salida = stream_get_contents($tubos[1]) . stream_get_contents($tubos[2]);
        fclose($tubos[1]);
        fclose($tubos[2]);
        $codigo = proc_close($proceso);
        $imagenes = glob($carpeta . '/pagina_*.png') ?: [];
        sort($imagenes);
        if ($codigo !== 0 || !$imagenes) {
            throw new \RuntimeException('Ghostscript terminó con código ' . $codigo . ': ' . substr(trim($salida), 0, 200));
        }
        return array_map(static fn ($ruta) => (string) file_get_contents($ruta), $imagenes);
    }

    private static function con_imagick(string $entrada, int $dpi, int $paginas): array {
        $imagenes = [];
        for ($i = 0; $i < $paginas; $i++) {
            $pagina = new \Imagick();
            $pagina->setResolution($dpi, $dpi);
            $pagina->readImage($entrada . '[' . $i . ']');
            $pagina->setImageBackgroundColor('white');
            $pagina->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
            $pagina = $pagina->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $pagina->setImageFormat('png');
            $imagenes[] = $pagina->getImagesBlob();
            $pagina->clear();
        }
        return $imagenes;
    }
}
