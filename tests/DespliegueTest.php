<?php
// Pruebas de scripts/deploy_plugin.php contra un Moodle simulado en una
// carpeta temporal.
//
// Desde la raíz del repositorio:
//     vendor/bin/phpunit --testsuite scripts

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Scripts\DeployPlugin;
use AutoEvaluar\Servicio\Servicio;
use PHPUnit\Framework\TestCase;

final class DespliegueTest extends TestCase
{
    private string $tmp;
    private string $moodle;
    private string $destino;
    private string $respaldosOriginales;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/autoevaluar_deploy_' . bin2hex(random_bytes(4));
        $this->moodle = $this->tmp . '/moodle';
        mkdir($this->moodle . '/lib', 0777, true);
        mkdir($this->moodle . '/mod/assign/feedback', 0777, true);
        file_put_contents($this->moodle . '/version.php', '<?php');
        file_put_contents($this->moodle . '/lib/moodlelib.php', '<?php');
        $this->destino = $this->moodle . '/mod/assign/feedback/aiocr';
        // Los respaldos van a una carpeta temporal, no a dist/ del repositorio.
        $this->respaldosOriginales = DeployPlugin::$carpetaRespaldos;
        DeployPlugin::$carpetaRespaldos = $this->tmp . '/respaldos';
    }

    protected function tearDown(): void
    {
        DeployPlugin::$carpetaRespaldos = $this->respaldosOriginales;
        Servicio::borrarCarpeta($this->tmp);
    }

    private function desplegar(array $extra = []): array
    {
        return ejecutar([DeployPlugin::class, 'main'], array_merge(['--moodle-dir', $this->moodle], $extra));
    }

    public function testPrimeraCopiaIdenticaAlRepositorio(): void
    {
        [$codigo] = $this->desplegar();
        $this->assertSame(0, $codigo);
        $this->assertSame(archivos(DeployPlugin::ORIGEN), archivos($this->destino));
    }

    public function testSegundaCopiaRespaldaYQuitaArchivosViejos(): void
    {
        $this->desplegar();
        mkdir($this->destino . '/amd');
        file_put_contents($this->destino . '/amd/viejo.js', '//');

        [$codigo] = $this->desplegar();

        $this->assertSame(0, $codigo);
        $this->assertDirectoryDoesNotExist($this->destino . '/amd');
        $respaldos = glob($this->tmp . '/respaldos/*');
        $this->assertCount(1, $respaldos);
        $this->assertFileExists($respaldos[0] . '/amd/viejo.js');
    }

    public function testNoBorraUnaCarpetaAjena(): void
    {
        mkdir($this->destino);
        file_put_contents($this->destino . '/otro.txt', 'x');
        [$codigo, $salida] = $this->desplegar();
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('no se borrará', $salida);
        $this->assertFileExists($this->destino . '/otro.txt');
    }

    private function ponerVersionInstalada(int $version): void
    {
        $this->desplegar();
        $archivo = $this->destino . '/version.php';
        $texto = preg_replace('/\$plugin->version\s*=\s*\d+/', '$plugin->version = ' . $version, file_get_contents($archivo));
        file_put_contents($archivo, $texto);
    }

    public function testNoInstalaUnaVersionAnterior(): void
    {
        $this->ponerVersionInstalada(DeployPlugin::versionOrigen() + 1);
        [$codigo, $salida] = $this->desplegar();
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('Cannot downgrade', $salida);
        // No tocó lo instalado ni hizo respaldo.
        $this->assertSame(DeployPlugin::versionOrigen() + 1,
            DeployPlugin::leerVersion(file_get_contents($this->destino . '/version.php')));
        $this->assertDirectoryDoesNotExist($this->tmp . '/respaldos');
    }

    public function testPermitirRetrocesoExplicito(): void
    {
        $this->ponerVersionInstalada(DeployPlugin::versionOrigen() + 1);
        [$codigo, $salida] = $this->desplegar(['--permitir-retroceso']);
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('ADVERTENCIA', $salida);
    }

    public function testActualizacionMuestraVersiones(): void
    {
        $this->ponerVersionInstalada(DeployPlugin::versionOrigen() - 1);
        [$codigo, $salida] = $this->desplegar();
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('-> nueva ' . DeployPlugin::versionOrigen(), $salida);
    }

    public function testRechazaCarpetaQueNoEsMoodle(): void
    {
        [$codigo, $salida] = ejecutar([DeployPlugin::class, 'main'], ['--moodle-dir', $this->tmp]);
        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('no parece la carpeta de código de Moodle', $salida);
    }

    public function testDryRunNoCambiaNada(): void
    {
        [$codigo] = $this->desplegar(['--dry-run', '--upgrade']);
        $this->assertSame(0, $codigo);
        $this->assertFileDoesNotExist($this->destino);
    }

    public function testComandosDocker(): void
    {
        [$codigo, $salida] = ejecutar([DeployPlugin::class, 'main'], [
            '--container', 'moodle_web', '--owner', 'www-data', '--upgrade', '--dry-run',
        ]);
        $this->assertSame(0, $codigo);
        $this->assertStringContainsString('docker exec moodle_web rm -rf /var/www/html/mod/assign/feedback/aiocr', $salida);
        $this->assertStringContainsString('docker exec moodle_web chown -R www-data', $salida);
        $this->assertStringContainsString('docker exec -u www-data moodle_web php /var/www/html/admin/cli/upgrade.php --non-interactive', $salida);
    }

    public function testSinDestinoMuestraElUso(): void
    {
        $anterior = getenv('MOODLE_DIR');
        putenv('MOODLE_DIR');
        try {
            [$codigo, $salida] = ejecutar([DeployPlugin::class, 'main'], []);
        } finally {
            if ($anterior !== false) {
                putenv("MOODLE_DIR=$anterior");
            }
        }
        $this->assertSame(2, $codigo);
        $this->assertStringContainsString('--moodle-dir', $salida);
    }
}
