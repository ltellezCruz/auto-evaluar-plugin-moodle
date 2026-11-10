<?php
// Mensajes del servicio por la salida de errores: en la consola de
// run.bat/run.sh (php -S) y en "docker compose logs" (Apache).

namespace AutoEvaluar\Servicio;

final class Log
{
    /** Las pruebas lo apagan para no ensuciar la salida. */
    public static bool $activo = true;

    public static function info(string $mensaje, string $origen = 'aiocr'): void
    {
        self::escribir('INFO', $mensaje, $origen);
    }

    public static function advertencia(string $mensaje, string $origen = 'aiocr'): void
    {
        self::escribir('WARNING', $mensaje, $origen);
    }

    public static function error(string $mensaje, string $origen = 'aiocr'): void
    {
        self::escribir('ERROR', $mensaje, $origen);
    }

    private static function escribir(string $nivel, string $mensaje, string $origen): void
    {
        if (!self::$activo) {
            return;
        }
        $linea = date('Y-m-d H:i:s') . " $nivel $origen: $mensaje\n";
        $flujo = @fopen('php://stderr', 'w');
        if ($flujo) {
            fwrite($flujo, $linea);
            fclose($flujo);
        } else {
            error_log(rtrim($linea));
        }
    }
}
