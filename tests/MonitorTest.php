<?php
// Pruebas de tools/monitor/monitor.php (clasificación de procesos y nombres).
//
// Desde la raíz del repositorio:
//     vendor/bin/phpunit --testsuite scripts

namespace AutoEvaluar\Pruebas;

use PHPUnit\Framework\TestCase;

use function AutoEvaluar\Monitor\clasificar;
use function AutoEvaluar\Monitor\nombreSeguro;

final class MonitorTest extends TestCase
{
    public function testClasificaLosProcesos(): void
    {
        $this->assertSame('Servicio_PHP', clasificar('php.exe', 'php.exe -S 127.0.0.1:8000 "C:\repo\php-service\public\index.php"'));
        $this->assertSame('Servicio_PHP', clasificar('php8.2', 'php -S 127.0.0.1:8000 /srv/auto-evaluar/php-service/public/index.php'));
        $this->assertSame('Moodle_Web', clasificar('php.exe', 'php admin/cli/cron.php'));
        $this->assertSame('Moodle_Web', clasificar('httpd.exe', ''));
        $this->assertSame('Moodle_Web', clasificar('php-fpm8.2', ''));
        $this->assertSame('Moodle_BD', clasificar('mariadbd.exe', ''));
        $this->assertNull(clasificar('chrome.exe', ''));
    }

    public function testNombresDeArchivo(): void
    {
        $this->assertSame('E1_gemini_flash', nombreSeguro(' E1 gemini/flash '));
        $this->assertSame('medicion', nombreSeguro('///'));
    }
}
