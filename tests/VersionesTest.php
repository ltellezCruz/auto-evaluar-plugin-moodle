<?php
// Versiones del plugin y del servicio, CHANGELOG y db/upgrade.php.
//
// Desde la raíz del repositorio:
//     vendor/bin/phpunit --testsuite scripts

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Scripts\DeployPlugin;
use AutoEvaluar\Servicio\Version;
use PHPUnit\Framework\TestCase;

final class VersionesTest extends TestCase
{
    /** El release del plugin y la versión del servicio van a la par (CHANGELOG.md). */
    public function testPluginYServicioConLaMismaVersion(): void
    {
        $this->assertSame(releasePlugin(), Version::VERSION);
    }

    public function testChangelogMencionaLaVersion(): void
    {
        $this->assertStringContainsString('## ' . releasePlugin(), file_get_contents(RAIZ_REPO . '/CHANGELOG.md'));
    }

    /** Si se sube $plugin->version con un paso de actualización, el último
     *  savepoint de db/upgrade.php no puede ser mayor que la versión. */
    public function testUpgradePhpLlegaALaVersionActual(): void
    {
        $upgrade = file_get_contents(DeployPlugin::ORIGEN . '/db/upgrade.php');
        preg_match_all('/upgrade_plugin_savepoint\(true,\s*(\d+)/', $upgrade, $m);
        $puntos = array_map('intval', $m[1]);
        $this->assertNotEmpty($puntos);
        $this->assertLessThanOrEqual(DeployPlugin::versionOrigen(), max($puntos));
        $ordenados = $puntos;
        sort($ordenados);
        $this->assertSame($ordenados, $puntos, 'los pasos van en orden');
    }

    /** Todo el proyecto quedó en PHP (rama feature-simulacion). */
    public function testNoQuedaCodigoPython(): void
    {
        $python = [];
        foreach (['php-service', 'scripts', 'tests', 'tools', 'docker', 'moodle-plugin'] as $carpeta) {
            foreach (archivos(RAIZ_REPO . '/' . $carpeta) as $archivo) {
                if (str_ends_with($archivo, '.py')) {
                    $python[] = "$carpeta/$archivo";
                }
            }
        }
        $this->assertSame([], $python);
    }
}
