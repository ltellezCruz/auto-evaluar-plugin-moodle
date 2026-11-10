<?php
// La autenticación y las rutas se prueban con el servicio real encendido
// en un "php -S" (public/index.php), como lo arranca run.bat.

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Servicio\Config;
use AutoEvaluar\Servicio\Servicio;
use AutoEvaluar\Servicio\Version;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    private static ServidorLocal $servicio;
    private static string $salida;

    public static function setUpBeforeClass(): void
    {
        self::$salida = Config::normalizar(sys_get_temp_dir()) . '/autoevaluar_http_' . bin2hex(random_bytes(4));
        self::$servicio = new ServidorLocal(dirname(__DIR__) . '/public/index.php', [
            'AIOCR_ENV_FILE' => self::$salida . '_sin.env',
            'MOODLE_URL' => 'http://127.0.0.1:9',
            'MOODLE_PUBLIC_URL' => '',
            'MOODLE_TOKEN' => ServicioTest::TOKEN,
            'GEMINI_API_KEY' => ServicioTest::CLAVE,
            'SERVICE_API_KEY' => ServicioTest::CLAVE_SERVICIO,
            'OUTPUT_DIR' => self::$salida,
            'ENABLE_DOCS' => 'false',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$servicio->detener();
        Servicio::borrarCarpeta(self::$salida);
    }

    private function post(array $cabeceras, ?array $cuerpo = null): array
    {
        $cuerpo ??= ['assignmentid' => 1, 'userid' => 7, 'files' => []];
        return ServidorLocal::pedir('POST', self::$servicio->base() . '/ia/generar-retroalimentacion',
            array_merge(['Content-Type: application/json'], $cabeceras), json_encode($cuerpo));
    }

    public function testRaizSinClaveMuestraVersion(): void
    {
        [$codigo, $cuerpo] = ServidorLocal::pedir('GET', self::$servicio->base() . '/');
        $this->assertSame(200, $codigo);
        $this->assertSame(Version::VERSION, json_decode($cuerpo, true)['version']);
    }

    public function testSinClave401(): void
    {
        [$codigo, $cuerpo] = $this->post([]);
        $this->assertSame(401, $codigo);
        $this->assertIsString(json_decode($cuerpo, true)['detail'], 'el plugin lee "detail"');
    }

    public function testClaveIncorrecta401(): void
    {
        $this->assertSame(401, $this->post(['X-Aiocr-Key: otra'])[0]);
    }

    public function testMoodleInfoRequiereClave(): void
    {
        $this->assertSame(401, ServidorLocal::pedir('GET', self::$servicio->base() . '/moodle/info')[0]);
    }

    public function testClaveCorrecta(): void
    {
        [$codigo, $cuerpo] = $this->post(['X-Aiocr-Key: ' . ServicioTest::CLAVE_SERVICIO]);
        $this->assertSame(200, $codigo);
        $this->assertFalse(json_decode($cuerpo, true)['success']); // entrega sin archivos
    }

    public function testValidacionDeLaSolicitud(): void
    {
        [$codigo] = $this->post(['X-Aiocr-Key: ' . ServicioTest::CLAVE_SERVICIO], ['assignmentid' => -1, 'userid' => 7]);
        $this->assertSame(422, $codigo);
    }

    public function testVersionDelPluginDemasiadoLarga(): void
    {
        [$codigo] = $this->post(['X-Aiocr-Key: ' . ServicioTest::CLAVE_SERVICIO, 'X-Aiocr-Plugin-Version: ' . str_repeat('9', 21)]);
        $this->assertSame(422, $codigo);
    }

    public function testDocsApagado(): void
    {
        $this->assertSame(404, ServidorLocal::pedir('GET', self::$servicio->base() . '/docs')[0]);
        $this->assertSame(404, ServidorLocal::pedir('GET', self::$servicio->base() . '/openapi.json')[0]);
    }

    public function testRutaDesconocidaYMetodoIncorrecto(): void
    {
        $this->assertSame(404, ServidorLocal::pedir('GET', self::$servicio->base() . '/no-existe')[0]);
        $this->assertSame(405, ServidorLocal::pedir('GET', self::$servicio->base() . '/ia/generar-retroalimentacion')[0]);
    }
}
