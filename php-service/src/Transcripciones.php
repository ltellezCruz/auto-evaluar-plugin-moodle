<?php
// Transcripciones.php
// Registro en disco de cada evaluación, para auditoría y para los análisis
// de la tesis (tiempos, tokens, errores):
//
//     <OUTPUT_DIR>/tarea_<assignmentid>/usuario_<userid>_<fecha>.txt   transcripción
//     <OUTPUT_DIR>/tarea_<assignmentid>/usuario_<userid>_<fecha>.json  metadatos
//
// Cada clic en "Auto Evaluar" crea archivos nuevos (no se sobrescribe nada).
// Se usan los id numéricos de Moodle, no el nombre de usuario.
//
// Para que la carpeta no crezca sin límite, los archivos con más de
// TRANSCRIPTIONS_DAYS días se borran (como mucho una revisión por hora).

namespace AutoEvaluar\Servicio;

final class Transcripciones
{
    public const INTERVALO_LIMPIEZA = 3600;
    /** En OUTPUT_DIR: hora de la última limpieza. PHP no guarda nada en
     *  memoria entre solicitudes, por eso va en un archivo. */
    private const MARCA_LIMPIEZA = '.ultima_limpieza';

    /** Guarda la transcripción y los metadatos. Nunca lanza excepciones:
     *  el registro de auditoría no debe tumbar la respuesta al plugin. */
    public static function guardarEvaluacion(Config $config, array $registro, string $transcripcion): void
    {
        if (!$config->saveTranscriptions) {
            return;
        }
        try {
            $carpeta = $config->outputDir . '/tarea_' . (int) $registro['assignmentid'];
            if (!is_dir($carpeta) && !@mkdir($carpeta, 0775, true) && !is_dir($carpeta)) {
                throw new \RuntimeException("No se pudo crear $carpeta");
            }
            $base = 'usuario_' . (int) $registro['userid'] . '_' . date('Y-m-d_His');
            // Dos clics en el mismo segundo no se pisan: 'x' falla si ya existe.
            $n = 1;
            $nombre = $base;
            while (($json = @fopen("$carpeta/$nombre.json", 'x')) === false) {
                if (!file_exists("$carpeta/$nombre.json") || $n > 1000) {
                    throw new \RuntimeException("No se pudo crear $carpeta/$nombre.json");
                }
                $n++;
                $nombre = "{$base}_$n";
            }

            if ($transcripcion !== '') {
                file_put_contents("$carpeta/$nombre.txt", $transcripcion);
                $registro['transcripcion'] = "$nombre.txt";
            }
            fwrite($json, self::aJson($registro));
            fclose($json);
        } catch (\Throwable $e) {
            Log::error('No se pudo guardar el registro de la evaluación: ' . $e->getMessage(), 'aiocr.transcripciones');
        }

        self::limpiarAntiguas($config);
    }

    /** JSON legible con sangría de 2 espacios (como el de la versión en Python). */
    public static function aJson(array $datos): string
    {
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        return preg_replace_callback('/^( +)/m', static fn ($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    }

    /** Borra transcripciones y metadatos con más de TRANSCRIPTIONS_DAYS días.
     *  Devuelve cuántos archivos borró. */
    public static function limpiarAntiguas(Config $config, bool $forzar = false): int
    {
        if ($config->transcriptionsDays <= 0 || !is_dir($config->outputDir)) {
            return 0;
        }

        // Una sola revisión por hora, aunque lleguen varias solicitudes a la vez.
        $ahora = time();
        $marca = @fopen($config->outputDir . '/' . self::MARCA_LIMPIEZA, 'c+');
        if ($marca) {
            if (!flock($marca, LOCK_EX | LOCK_NB)) {
                fclose($marca);
                return 0; // otra solicitud ya está limpiando
            }
            $ultima = (int) stream_get_contents($marca);
            if (!$forzar && $ahora - $ultima < self::INTERVALO_LIMPIEZA) {
                flock($marca, LOCK_UN);
                fclose($marca);
                return 0;
            }
            ftruncate($marca, 0);
            rewind($marca);
            fwrite($marca, (string) $ahora);
            fflush($marca);
        }

        $limite = $ahora - $config->transcriptionsDays * 86400;
        $borrados = 0;
        try {
            $iterador = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($config->outputDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterador as $archivo) {
                /** @var \SplFileInfo $archivo */
                if (!$archivo->isFile() || !in_array(strtolower($archivo->getExtension()), ['txt', 'json'], true)) {
                    continue;
                }
                clearstatcache(true, $archivo->getPathname());
                if ($archivo->getMTime() < $limite) {
                    if (@unlink($archivo->getPathname())) {
                        $borrados++;
                    } else {
                        Log::advertencia('No se pudo borrar ' . $archivo->getPathname(), 'aiocr.transcripciones');
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::advertencia('Error al limpiar transcripciones: ' . $e->getMessage(), 'aiocr.transcripciones');
        }

        // Carpetas de tareas que quedaron vacías.
        foreach (glob($config->outputDir . '/tarea_*', GLOB_ONLYDIR) ?: [] as $carpeta) {
            if (count(scandir($carpeta) ?: []) <= 2) {
                @rmdir($carpeta);
            }
        }

        if ($marca) {
            flock($marca, LOCK_UN);
            fclose($marca);
        }
        if ($borrados) {
            Log::info("Borradas $borrados transcripciones con más de {$config->transcriptionsDays} días", 'aiocr.transcripciones');
        }
        return $borrados;
    }
}
