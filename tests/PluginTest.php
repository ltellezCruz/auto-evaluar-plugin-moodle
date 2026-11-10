<?php
// Revisiones estáticas del código del plugin.
//
// Desde la raíz del repositorio:
//     vendor/bin/phpunit --testsuite scripts

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Scripts\DeployPlugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    private static function claves(string $idioma): array
    {
        preg_match_all("/\\\$string\['([^']+)'\]/", file_get_contents(DeployPlugin::ORIGEN . "/lang/$idioma/assignfeedback_aiocr.php"), $m);
        $claves = array_values(array_unique($m[1]));
        sort($claves);
        return $claves;
    }

    public function testIdiomasConLasMismasClaves(): void
    {
        $this->assertSame(self::claves('en'), self::claves('es'));
    }

    public function testTextosUsadosExisten(): void
    {
        $usados = [];
        foreach (archivos(DeployPlugin::ORIGEN) as $relativo) {
            if (!preg_match('/\.(php|mustache)$/', $relativo)) {
                continue;
            }
            $texto = file_get_contents(DeployPlugin::ORIGEN . '/' . $relativo);
            preg_match_all("/get_string\(\s*'([^'\$]+)'\s*,\s*'assignfeedback_aiocr'/", $texto, $m1);
            preg_match_all('/\{\{#str\}\}\s*([\w:]+)\s*,\s*assignfeedback_aiocr/', $texto, $m2);
            $usados = array_merge($usados, $m1[1], $m2[1]);
        }
        $fabrica = file_get_contents(DeployPlugin::ORIGEN . '/classes/local/provider_factory.php');
        $sinComentarios = implode("\n", array_filter(explode("\n", $fabrica), static fn ($l) => !str_starts_with(trim($l), '//')));
        preg_match_all("/'(\w+_adapter)'\s*=>/", $sinComentarios, $m3);
        foreach ($m3[1] as $proveedor) {
            $usados[] = "provider:$proveedor";
        }
        $this->assertNotEmpty($usados);
        $this->assertSame([], array_values(array_diff(array_unique($usados), self::claves('en'))));
    }

    /** Lo que llega del servidor (errores, IA) se inserta como texto. */
    public function testElJavascriptNoInsertaHtml(): void
    {
        $this->assertStringNotContainsString('innerHTML', file_get_contents(DeployPlugin::ORIGEN . '/locallib.php'));
    }

    public function testLaPlantillaNoInsertaHtmlSinEscapar(): void
    {
        $this->assertStringNotContainsString('{{{', file_get_contents(DeployPlugin::ORIGEN . '/templates/feedback_button.mustache'));
    }
}
