<?php
// Enciende un "php -S" en un puerto libre para las pruebas: el Moodle y el
// Gemini simulados, o el propio servicio.

namespace AutoEvaluar\Pruebas;

final class ServidorLocal
{
    /** @var resource */
    private $proceso;
    public readonly int $puerto;

    public function __construct(string $enrutador, array $entorno = [])
    {
        $this->puerto = self::puertoLibre();
        $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $this->proceso = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->puerto, '-t', dirname($enrutador), $enrutador],
            [0 => ['file', $nulo, 'r'], 1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']],
            $tuberias,
            null,
            array_merge(getenv(), $entorno)
        );
        if (!is_resource($this->proceso)) {
            throw new \RuntimeException('No se pudo encender php -S.');
        }
        // Espera a que acepte conexiones.
        for ($i = 0; $i < 100; $i++) {
            $conexion = @fsockopen('127.0.0.1', $this->puerto, $codigo, $mensaje, 0.1);
            if ($conexion) {
                fclose($conexion);
                return;
            }
            usleep(50000);
        }
        $this->detener();
        throw new \RuntimeException("php -S no respondió en el puerto {$this->puerto}.");
    }

    public function base(): string
    {
        return 'http://127.0.0.1:' . $this->puerto;
    }

    public function detener(): void
    {
        if (is_resource($this->proceso)) {
            proc_terminate($this->proceso);
            proc_close($this->proceso);
        }
    }

    public static function puertoLibre(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $codigo, $mensaje);
        $nombre = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($nombre, strrpos($nombre, ':') + 1);
    }

    /** Petición HTTP sencilla: devuelve [código, cuerpo]. */
    public static function pedir(string $metodo, string $url, array $cabeceras = [], ?string $cuerpo = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_TIMEOUT => 10,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$codigo, (string) $respuesta];
    }
}
