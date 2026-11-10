<?php
// Cupos.php
// Límite de evaluaciones en curso al mismo tiempo (MAX_CONCURRENT). Las que
// superan el límite se rechazan de inmediato (503) en vez de acumularse.
//
// Cada solicitud de PHP es un proceso o hilo aparte, así que el semáforo
// de la versión en Python se reemplaza por candados de archivo (flock):
// hay MAX_CONCURRENT archivos y cada evaluación toma uno libre. El sistema
// operativo suelta el candado aunque el proceso termine con error.

namespace AutoEvaluar\Servicio;

final class Cupos
{
    /** @var resource|null */
    private $candado = null;

    public function __construct(
        private readonly string $carpeta,
        private readonly int $maximo,
    ) {
    }

    public static function desde(Config $config): self
    {
        return new self($config->carpetaCupos, $config->maxConcurrent);
    }

    /** Toma un cupo sin esperar. Devuelve false si están todos ocupados. */
    public function tomar(): bool
    {
        if ($this->candado !== null) {
            return true;
        }
        if (!is_dir($this->carpeta)) {
            @mkdir($this->carpeta, 0777, true);
        }
        for ($i = 1; $i <= $this->maximo; $i++) {
            $archivo = @fopen($this->carpeta . "/cupo_$i.lock", 'c');
            if ($archivo === false) {
                continue;
            }
            if (flock($archivo, LOCK_EX | LOCK_NB)) {
                $this->candado = $archivo;
                return true;
            }
            fclose($archivo);
        }
        return false;
    }

    public function liberar(): void
    {
        if ($this->candado !== null) {
            flock($this->candado, LOCK_UN);
            fclose($this->candado);
            $this->candado = null;
        }
    }

    public function __destruct()
    {
        $this->liberar();
    }
}
