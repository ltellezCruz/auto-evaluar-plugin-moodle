<?php
/*
 * deploy_plugin.php
 * Copia el plugin desde el repositorio (moodle-plugin/aiocr) a una
 * instalación de Moodle y, si se pide, ejecuta la actualización de Moodle.
 *
 * El código se edita SOLO en el repositorio. La carpeta
 * <moodle>/mod/assign/feedback/aiocr es un destino: este script la borra
 * y la vuelve a crear en cada despliegue (antes guarda una copia de
 * respaldo en dist/backups/).
 *
 * Destinos soportados:
 *     1. Carpeta local (Windows, Linux o volumen montado de Docker):
 *            php scripts/deploy_plugin.php --moodle-dir "C:\Moodle45\server\moodle"
 *     2. Contenedor Docker con Moodle dentro de la imagen:
 *            php scripts/deploy_plugin.php --container moodle_web
 *     3. Volumen montado + comandos dentro del contenedor (moodle-docker):
 *            php scripts/deploy_plugin.php --moodle-dir ./moodle --container moodle-webserver-1 --upgrade
 *
 * Opciones útiles:
 *     --upgrade       ejecuta admin/cli/upgrade.php --non-interactive
 *     --purge-caches  ejecuta admin/cli/purge_caches.php
 *     --php RUTA      intérprete PHP para los comandos de Moodle (por defecto, el mismo que ejecuta este script)
 *     --owner USUARIO cambia el dueño de los archivos (Linux/Docker, ej. www-data)
 *     --dry-run       muestra lo que haría sin cambiar nada
 *     --permitir-retroceso  copia aunque sea una versión anterior (ver docs/actualizacion.md)
 *
 * --moodle-dir también se puede dar con la variable de entorno MOODLE_DIR.
 * Solo usa funciones estándar de PHP (8.1+). Con el paquete de Moodle para
 * Windows sirve su propio PHP: <CARPETA_MOODLE>\server\php\php.exe.
 */

namespace AutoEvaluar\Scripts;

final class ErrorDespliegue extends \RuntimeException
{
}

final class DeployPlugin
{
    public const COMPONENTE = 'assignfeedback_aiocr';
    public const RUTA_EN_MOODLE = ['mod', 'assign', 'feedback', 'aiocr'];
    public const ORIGEN = __DIR__ . '/../moodle-plugin/aiocr';

    /** Carpeta de respaldos (las pruebas la cambian por una temporal). */
    public static string $carpetaRespaldos = __DIR__ . '/../dist/backups';
    /** Las pruebas mandan también los errores a la salida normal para leerlos. */
    public static bool $erroresALaSalida = false;

    /** Nombres que no se copian a Moodle. */
    private const IGNORAR = ['.DS_Store', 'Thumbs.db', 'desktop.ini'];
    private const IGNORAR_SUFIJOS = ['~', '.swp'];

    private static function decir(string $texto): void
    {
        echo $texto, "\n";
    }

    private static function avisarError(string $texto): void
    {
        if (self::$erroresALaSalida) {
            echo $texto, "\n";
        } else {
            fwrite(STDERR, $texto . "\n");
        }
    }

    /** Muestra un comando como se escribiría en la terminal. */
    public static function comoTexto(array $comando): string
    {
        return implode(' ', array_map(static function (string $arg): string {
            if ($arg !== '' && strpbrk($arg, " \t\"") === false) {
                return $arg;
            }
            return '"' . str_replace('"', '\\"', $arg) . '"';
        }, $comando));
    }

    public static function ejecutar(array $comando, bool $dryRun): void
    {
        self::decir('  $ ' . self::comoTexto($comando));
        if ($dryRun) {
            return;
        }
        $proceso = proc_open($comando, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $tuberias);
        if (!is_resource($proceso)) {
            throw new ErrorDespliegue('No se pudo ejecutar ' . $comando[0] . '.');
        }
        $codigo = proc_close($proceso);
        if ($codigo !== 0) {
            throw new ErrorDespliegue("El comando terminó con código $codigo.");
        }
    }

    /** Ejecuta y devuelve [código, salida] sin mostrar nada. */
    private static function capturar(array $comando): array
    {
        $proceso = @proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
        if (!is_resource($proceso)) {
            return [1, ''];
        }
        $salida = stream_get_contents($tuberias[1]);
        stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        return [proc_close($proceso), (string) $salida];
    }

    public static function esPluginAiocr(string $carpeta): bool
    {
        $version = $carpeta . '/version.php';
        return is_file($version) && str_contains((string) file_get_contents($version), self::COMPONENTE);
    }

    /** $plugin->version de un version.php (null si no se encuentra). */
    public static function leerVersion(string $texto): ?int
    {
        return preg_match('/\$plugin->version\s*=\s*(\d+)/', $texto, $m) ? (int) $m[1] : null;
    }

    public static function versionOrigen(): int
    {
        return (int) self::leerVersion((string) file_get_contents(self::ORIGEN . '/version.php'));
    }

    /** Moodle no admite volver a una versión anterior del plugin: si el
     *  código es más viejo que lo registrado en la base de datos, bloquea TODO
     *  el sitio con "Cannot downgrade". Por eso no se copia código más viejo
     *  que el que ya está instalado. */
    public static function impedirRetroceso(?int $instalada, bool $permitir): void
    {
        $nueva = self::versionOrigen();
        if ($instalada === null || $nueva >= $instalada) {
            if ($instalada !== null) {
                self::decir("- Versión instalada $instalada -> nueva $nueva");
            }
            return;
        }
        $mensaje = "El repositorio tiene la versión $nueva, más antigua que la instalada ($instalada). "
            . 'Moodle no permite volver atrás: el sitio quedaría bloqueado con "Cannot downgrade". '
            . 'Para regresar a una versión anterior hay que restaurar también la base de datos '
            . '(ver docs/actualizacion.md).';
        if (!$permitir) {
            throw new ErrorDespliegue($mensaje);
        }
        self::decir('ADVERTENCIA: ' . $mensaje);
    }

    private static function validarOrigen(): void
    {
        if (!self::esPluginAiocr(self::ORIGEN)) {
            throw new ErrorDespliegue('No se encontró el plugin en ' . self::ruta(self::ORIGEN) . ' (falta version.php).');
        }
    }

    private static function validarMoodleLocal(string $moodleDir): string
    {
        if (!is_file("$moodleDir/version.php") || !is_file("$moodleDir/lib/moodlelib.php")) {
            throw new ErrorDespliegue(
                "$moodleDir no parece la carpeta de código de Moodle "
                . '(debe contener version.php y lib/moodlelib.php). '
                . 'En el paquete de Windows es <CARPETA_MOODLE>\\server\\moodle.'
            );
        }
        $padre = $moodleDir . '/' . implode('/', array_slice(self::RUTA_EN_MOODLE, 0, -1));
        if (!is_dir($padre)) {
            throw new ErrorDespliegue("No existe $padre: ¿está instalado mod_assign?");
        }
        return $moodleDir . '/' . implode('/', self::RUTA_EN_MOODLE);
    }

    /** Ruta legible: absoluta y con el separador del sistema. */
    public static function ruta(string $ruta): string
    {
        $real = realpath($ruta);
        return $real !== false ? $real : str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);
    }

    private static function ignorado(string $nombre): bool
    {
        if (in_array($nombre, self::IGNORAR, true)) {
            return true;
        }
        foreach (self::IGNORAR_SUFIJOS as $sufijo) {
            if (str_ends_with($nombre, $sufijo)) {
                return true;
            }
        }
        return false;
    }

    public static function copiarArbol(string $origen, string $destino, bool $conIgnorados = false): void
    {
        if (!is_dir($destino) && !mkdir($destino, 0777, true) && !is_dir($destino)) {
            throw new ErrorDespliegue("No se pudo crear $destino.");
        }
        foreach (scandir($origen) ?: [] as $nombre) {
            if ($nombre === '.' || $nombre === '..' || (!$conIgnorados && self::ignorado($nombre))) {
                continue;
            }
            $de = "$origen/$nombre";
            $a = "$destino/$nombre";
            if (is_dir($de) && !is_link($de)) {
                self::copiarArbol($de, $a, $conIgnorados);
            } elseif (!copy($de, $a)) {
                throw new ErrorDespliegue("No se pudo copiar $de.");
            }
        }
    }

    public static function borrarArbol(string $ruta): void
    {
        foreach (scandir($ruta) ?: [] as $nombre) {
            if ($nombre === '.' || $nombre === '..') {
                continue;
            }
            $hijo = "$ruta/$nombre";
            if (is_dir($hijo) && !is_link($hijo)) {
                self::borrarArbol($hijo);
            } elseif (!@unlink($hijo)) {
                throw new ErrorDespliegue("No se pudo borrar $hijo.");
            }
        }
        if (!@rmdir($ruta)) {
            throw new ErrorDespliegue("No se pudo borrar $ruta.");
        }
    }

    private static function respaldar(string $destino, bool $dryRun): void
    {
        $respaldo = self::$carpetaRespaldos . '/aiocr_' . date('Ymd_His');
        self::decir('- Respaldo de la versión instalada en ' . self::ruta(self::$carpetaRespaldos) . DIRECTORY_SEPARATOR . basename($respaldo));
        if (!$dryRun) {
            self::copiarArbol($destino, $respaldo, true);
        }
    }

    private static function desplegarLocal(string $moodleDir, ?string $owner, bool $dryRun, bool $permitirRetroceso): void
    {
        $destino = self::validarMoodleLocal($moodleDir);

        // is_link también reconoce los enlaces "mklink /J" (junction) de Windows.
        if (is_link($destino)) {
            throw new ErrorDespliegue(
                "$destino es un enlace. Bórralo a mano (solo el enlace) antes de desplegar por copia."
            );
        }

        if (file_exists($destino)) {
            if (!self::esPluginAiocr($destino)) {
                throw new ErrorDespliegue("$destino existe pero no contiene " . self::COMPONENTE . '; no se borrará por seguridad.');
            }
            self::impedirRetroceso(self::leerVersion((string) file_get_contents("$destino/version.php")), $permitirRetroceso);
            self::respaldar($destino, $dryRun);
            self::decir("- Borrando $destino");
            if (!$dryRun) {
                self::borrarArbol($destino);
            }
        }

        self::decir('- Copiando ' . self::ruta(self::ORIGEN) . " -> $destino");
        if (!$dryRun) {
            self::copiarArbol(self::ORIGEN, $destino);
        }

        if ($owner) {
            if (PHP_OS_FAMILY === 'Windows') {
                self::decir('- --owner se ignora en Windows.');
            } else {
                self::ejecutar(['chown', '-R', $owner, $destino], $dryRun);
            }
        }
    }

    private static function desplegarContenedor(string $contenedor, string $rutaMoodle, ?string $owner, bool $dryRun, bool $permitirRetroceso): void
    {
        $destino = rtrim($rutaMoodle, '/') . '/' . implode('/', self::RUTA_EN_MOODLE);
        $padre = substr($destino, 0, strrpos($destino, '/'));

        self::decir("- Verificando $padre dentro de $contenedor");
        self::ejecutar(['docker', 'exec', $contenedor, 'test', '-d', $padre], $dryRun);

        if (!$dryRun) {
            [$codigo, $actual] = self::capturar(['docker', 'exec', $contenedor, 'cat', "$destino/version.php"]);
            if ($codigo === 0) {
                self::impedirRetroceso(self::leerVersion($actual), $permitirRetroceso);
            }
        }

        // docker cp copia DENTRO del destino si ya existe; por eso se borra antes.
        self::decir("- Reemplazando $destino en $contenedor");
        self::ejecutar(['docker', 'exec', $contenedor, 'rm', '-rf', $destino], $dryRun);
        self::ejecutar(['docker', 'cp', self::ruta(self::ORIGEN), "$contenedor:$destino"], $dryRun);

        if ($owner) {
            self::ejecutar(['docker', 'exec', $contenedor, 'chown', '-R', $owner, $destino], $dryRun);
        }
    }

    public static function comandoCli(string $script, array $args, string $php, ?string $contenedor, string $rutaContenedor, ?string $moodleDir, ?string $owner = null): array
    {
        // Los comandos de Moodle se ejecutan como el dueño de los archivos (el
        // usuario del servidor web): si corren como root, dejan cachés en
        // moodledata que Apache después no puede modificar.
        $usuario = $owner ? explode(':', $owner)[0] : null;
        if ($contenedor) {
            $como = $usuario ? ['-u', $usuario] : [];
            return ['docker', 'exec', ...$como, $contenedor, 'php', rtrim($rutaContenedor, '/') . "/admin/cli/$script", ...$args];
        }
        $comando = [$php, self::ruta("$moodleDir/admin/cli/$script"), ...$args];
        if ($usuario && PHP_OS_FAMILY !== 'Windows') {
            return ['sudo', '-u', $usuario, ...$comando];
        }
        return $comando;
    }

    /** Lector de opciones al estilo argparse: --opcion valor, --opcion=valor y banderas. */
    public static function leerOpciones(array $argv, array $conValor, array $banderas): array
    {
        $opciones = [];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            $valor = null;
            if (str_contains($arg, '=') && str_starts_with($arg, '--')) {
                [$arg, $valor] = explode('=', $arg, 2);
            }
            if (in_array($arg, $banderas, true) && $valor === null) {
                $opciones[$arg] = true;
            } elseif (in_array($arg, $conValor, true)) {
                if ($valor === null) {
                    if (!isset($argv[$i + 1])) {
                        throw new \InvalidArgumentException("$arg necesita un valor.");
                    }
                    $valor = $argv[++$i];
                }
                $opciones[$arg] = $valor;
            } elseif ($arg === '--help' || $arg === '-h') {
                $opciones['--help'] = true;
            } else {
                throw new \InvalidArgumentException("opción desconocida: $arg");
            }
        }
        return $opciones;
    }

    public static function main(array $argv): int
    {
        $uso = 'Uso: php scripts/deploy_plugin.php (--moodle-dir RUTA | --container NOMBRE) '
            . '[--container-path RUTA] [--php RUTA] [--owner USUARIO] [--upgrade] [--purge-caches] '
            . '[--dry-run] [--permitir-retroceso]';
        try {
            $o = self::leerOpciones(
                $argv,
                ['--moodle-dir', '--container', '--container-path', '--php', '--owner'],
                ['--upgrade', '--purge-caches', '--dry-run', '--permitir-retroceso']
            );
        } catch (\InvalidArgumentException $e) {
            self::avisarError($uso);
            self::avisarError('error: ' . $e->getMessage());
            return 2;
        }
        if (!empty($o['--help'])) {
            self::decir($uso);
            return 0;
        }

        $moodleDirTexto = $o['--moodle-dir'] ?? (getenv('MOODLE_DIR') ?: null);
        $contenedor = $o['--container'] ?? null;
        $rutaContenedor = $o['--container-path'] ?? '/var/www/html';
        $owner = $o['--owner'] ?? null;
        $dryRun = !empty($o['--dry-run']);
        $permitir = !empty($o['--permitir-retroceso']);
        // Por defecto, el mismo PHP que ejecuta este script (en Windows, el del paquete de Moodle).
        $php = $o['--php'] ?? (PHP_BINARY ?: 'php');

        if (!$moodleDirTexto && !$contenedor) {
            self::avisarError($uso);
            self::avisarError('error: indica --moodle-dir (o MOODLE_DIR) o --container.');
            return 2;
        }

        $moodleDir = null;
        if ($moodleDirTexto) {
            $moodleDir = realpath($moodleDirTexto) ?: $moodleDirTexto;
        }
        // Una ruta relativa como "server/php/php.exe" falla al lanzar el
        // proceso; se convierte a absoluta. "php" (en el PATH) se deja.
        if (is_file($php)) {
            $php = realpath($php) ?: $php;
        }

        try {
            self::validarOrigen();
            if ($dryRun) {
                self::decir('(dry-run: no se cambiará nada)');
            }

            if ($moodleDir) {
                // Con --container además, se asume volumen montado: se copia en
                // disco y los comandos de Moodle se ejecutan en el contenedor.
                self::desplegarLocal($moodleDir, $contenedor ? null : $owner, $dryRun, $permitir);
                if ($contenedor && $owner) {
                    $destino = rtrim($rutaContenedor, '/') . '/' . implode('/', self::RUTA_EN_MOODLE);
                    self::ejecutar(['docker', 'exec', $contenedor, 'chown', '-R', $owner, $destino], $dryRun);
                }
            } else {
                self::desplegarContenedor($contenedor, $rutaContenedor, $owner, $dryRun, $permitir);
            }

            if (!empty($o['--upgrade'])) {
                self::decir('- Actualizando Moodle');
                self::ejecutar(self::comandoCli('upgrade.php', ['--non-interactive'], $php, $contenedor, $rutaContenedor, $moodleDir, $owner), $dryRun);
            }
            if (!empty($o['--purge-caches'])) {
                self::decir('- Purgando cachés');
                self::ejecutar(self::comandoCli('purge_caches.php', [], $php, $contenedor, $rutaContenedor, $moodleDir, $owner), $dryRun);
            }
        } catch (ErrorDespliegue $e) {
            self::avisarError('ERROR: ' . $e->getMessage());
            return 1;
        }

        self::decir($dryRun ? 'Fin del dry-run.' : 'Listo.');
        if (empty($o['--upgrade'])) {
            self::decir('Recuerda: si cambiaste db/ o version.php, entra a Administración del sitio → Notificaciones '
                . '(o usa --upgrade). Si cambiaste plantillas o textos, purga cachés.');
        }
        return 0;
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    // La consola de Windows muestra bien las tildes con la página de códigos UTF-8.
    if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')) {
        sapi_windows_cp_set(65001);
    }
    exit(DeployPlugin::main(array_slice($argv, 1)));
}
