<?php
/*
 * package_plugin.php
 * Genera el zip instalable del plugin a partir de moodle-plugin/aiocr:
 *
 *     php scripts/package_plugin.php
 *     -> dist/assignfeedback_aiocr_<release>_<version>.zip
 *
 * El zip tiene la carpeta aiocr/ en la raíz, que es el formato que acepta
 * Administración del sitio → Extensiones → Instalar complementos, y el que
 * se descomprime a mano en <moodle>/mod/assign/feedback/.
 *
 * Opciones:
 *     --salida RUTA   ruta del zip (por defecto en dist/)
 *
 * Usa la extensión zip de PHP (ZipArchive), que trae el PHP del paquete de
 * Moodle para Windows.
 */

namespace AutoEvaluar\Scripts;

final class PackagePlugin
{
    public const ORIGEN = __DIR__ . '/../moodle-plugin/aiocr';
    public const COMPONENTE = 'assignfeedback_aiocr';
    public const CARPETA_DIST = __DIR__ . '/../dist';

    private const IGNORAR_NOMBRES = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];
    private const IGNORAR_SUFIJOS = ['~', '.swp', '.orig', '.bak'];

    /** @return array{0: string, 1: string} [version, release] */
    public static function leerVersion(string $versionPhp): array
    {
        $texto = (string) @file_get_contents($versionPhp);
        if (!str_contains($texto, "'" . self::COMPONENTE . "'")) {
            throw new \RuntimeException("$versionPhp no declara el componente " . self::COMPONENTE . '.');
        }
        if (!preg_match('/\$plugin->version\s*=\s*(\d+)/', $texto, $version)) {
            throw new \RuntimeException("no se encontró \$plugin->version en $versionPhp.");
        }
        $release = preg_match("/\\\$plugin->release\s*=\s*'([^']+)'/", $texto, $r) ? $r[1] : 'dev';
        return [$version[1], $release];
    }

    /** @return array<string, string> ruta relativa (con "/") => ruta en disco, en orden */
    public static function archivosDelPlugin(): array
    {
        $archivos = [];
        $base = realpath(self::ORIGEN);
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterador as $archivo) {
            /** @var \SplFileInfo $archivo */
            $relativa = str_replace('\\', '/', substr($archivo->getPathname(), strlen($base) + 1));
            $partes = explode('/', $relativa);
            foreach ($partes as $parte) {
                if (in_array($parte, self::IGNORAR_NOMBRES, true) || str_starts_with($parte, '{')) {
                    continue 2;
                }
            }
            foreach (self::IGNORAR_SUFIJOS as $sufijo) {
                if (str_ends_with($archivo->getFilename(), $sufijo)) {
                    continue 2;
                }
            }
            if ($archivo->isFile()) {
                $archivos[$relativa] = $archivo->getPathname();
            }
        }
        ksort($archivos, SORT_STRING);
        return $archivos;
    }

    public static function main(array $argv): int
    {
        $salida = null;
        for ($i = 0; $i < count($argv); $i++) {
            if ($argv[$i] === '--salida' && isset($argv[$i + 1])) {
                $salida = $argv[++$i];
            } elseif (str_starts_with($argv[$i], '--salida=')) {
                $salida = substr($argv[$i], strlen('--salida='));
            } else {
                fwrite(STDERR, "Uso: php scripts/package_plugin.php [--salida RUTA]\n");
                return 2;
            }
        }

        if (!class_exists(\ZipArchive::class)) {
            fwrite(STDERR, "ERROR: falta la extensión zip de PHP (actívala en php.ini).\n");
            return 1;
        }
        try {
            [$version, $release] = self::leerVersion(self::ORIGEN . '/version.php');
        } catch (\RuntimeException $e) {
            fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
            return 1;
        }

        $salida ??= self::CARPETA_DIST . '/' . self::COMPONENTE . "_{$release}_{$version}.zip";
        if (!is_dir(dirname($salida))) {
            mkdir(dirname($salida), 0777, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($salida, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            fwrite(STDERR, "ERROR: no se pudo crear $salida.\n");
            return 1;
        }
        $total = 0;
        foreach (self::archivosDelPlugin() as $relativa => $ruta) {
            // Rutas con "/" aunque se genere en Windows: Moodle las espera así.
            $zip->addFile($ruta, 'aiocr/' . $relativa);
            $total++;
        }
        $zip->close();

        $mostrar = realpath($salida) ?: $salida;
        echo "$mostrar ($total archivos, versión $version, release $release)\n";
        return 0;
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')) {
        sapi_windows_cp_set(65001);
    }
    exit(PackagePlugin::main(array_slice($argv, 1)));
}
