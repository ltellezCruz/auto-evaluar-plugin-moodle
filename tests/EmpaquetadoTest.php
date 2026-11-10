<?php
// Pruebas de scripts/package_plugin.php.
//
// Desde la raíz del repositorio:
//     vendor/bin/phpunit --testsuite scripts

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Scripts\PackagePlugin;
use PHPUnit\Framework\TestCase;

final class EmpaquetadoTest extends TestCase
{
    public function testZipConAiocrEnLaRaiz(): void
    {
        $zipRuta = sys_get_temp_dir() . '/autoevaluar_plugin_' . bin2hex(random_bytes(4)) . '.zip';
        try {
            ob_start();
            $codigo = PackagePlugin::main(['--salida', $zipRuta]);
            ob_end_clean();
            $this->assertSame(0, $codigo);

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($zipRuta));
            $nombres = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombres[] = $zip->getNameIndex($i);
            }
            $zip->close();
        } finally {
            @unlink($zipRuta);
        }

        $this->assertContains('aiocr/version.php', $nombres);
        foreach ($nombres as $nombre) {
            $this->assertStringStartsWith('aiocr/', $nombre);
            $this->assertStringNotContainsString('\\', $nombre, 'rutas con / aunque se genere en Windows');
        }
        $esperados = array_map(static fn ($r) => "aiocr/$r", archivos(PackagePlugin::ORIGEN));
        sort($nombres);
        $this->assertSame($esperados, $nombres);
    }
}
