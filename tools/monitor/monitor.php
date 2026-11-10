<?php
/*
 * monitor.php
 * Mide cada INTERVALO segundos el uso de CPU, RAM, disco y red del equipo y
 * de los procesos de Moodle (web y base de datos) y del servicio PHP de
 * Auto Evaluar, y lo guarda en un CSV. Junto al CSV escribe un .json con
 * los datos del experimento, para poder reproducir y comparar mediciones.
 *
 * Uso (desde la raíz del repositorio):
 *     php tools/monitor/monitor.php --etiqueta E1_gemini_flash_lite --notas "10 PDF de 2 páginas"
 *     -> data/mediciones/2026-11-03_101500_E1_gemini_flash_lite.csv
 *        data/mediciones/2026-11-03_101500_E1_gemini_flash_lite.json
 *
 * Ctrl+C termina la medición. Nunca sobrescribe un archivo existente.
 *
 * Opciones:
 *     --etiqueta TEXTO   nombre corto del experimento (va en el nombre del archivo)
 *     --notas TEXTO      descripción libre (modelo, cantidad de PDF, páginas...)
 *     --intervalo SEG    segundos entre mediciones (por defecto 1.0)
 *     --salida RUTA      ruta del CSV (por defecto data/mediciones/<fecha>_<etiqueta>.csv)
 *
 * PHP no tiene un equivalente de psutil, así que los datos se leen del
 * sistema operativo:
 *   - Windows: contadores de rendimiento por PowerShell (CIM/WMI). Un solo
 *     proceso de PowerShell queda abierto durante toda la medición.
 *   - Linux: los archivos de /proc.
 * macOS no está soportado.
 *
 * Identifica los procesos por nombre en Windows (paquete de Moodle/XAMPP) y
 * en Linux (Apache/PHP-FPM, MariaDB/MySQL). El servicio de Auto Evaluar es
 * el proceso php cuyo comando incluye "php-service" (run.bat y run.sh lo
 * lanzan con la ruta completa). Con Docker Desktop, los procesos de los
 * contenedores corren dentro de una máquina virtual y no son visibles
 * desde Windows o macOS: las columnas por proceso quedarán en 0 y solo valen
 * las del equipo completo (cpu_total, ram_total, disco y red).
 *
 * Diferencias con la versión en Python (psutil), para comparar mediciones:
 *   - Las columnas python_* se llaman ahora servicio_* (proceso PHP).
 *   - En Windows la RAM por proceso es el "working set" (igual que psutil).
 */

namespace AutoEvaluar\Monitor;

const RAIZ_REPO = __DIR__ . '/../..';
const CARPETA_MEDICIONES = RAIZ_REPO . '/data/mediciones';

/** Nombres de proceso por grupo (en minúsculas), Windows y Linux. */
const PROCESOS_WEB = ['httpd.exe', 'apache.exe', 'php-cgi.exe', 'httpd', 'apache2', 'php-fpm', 'nginx', 'nginx.exe'];
const PROCESOS_PHP = ['php.exe', 'php'];
const PROCESOS_BD = ['mysqld.exe', 'mariadbd.exe', 'mysqld', 'mariadbd'];
/** Solo se cuentan como servicio los procesos php que ejecutan php-service. */
const MARCAS_SERVICIO = ['php-service'];

const COLUMNAS = [
    'fecha_hora',
    'cpu_total_pct',
    'ram_total_pct',
    'ram_disponible_mb',
    'moodle_web_cpu_pct',
    'moodle_web_ram_mb',
    'moodle_bd_cpu_pct',
    'moodle_bd_ram_mb',
    'servicio_cpu_pct',
    'servicio_ram_mb',
    'servicio_procesos',
    'disco_lectura_mb_s',
    'disco_escritura_mb_s',
    'red_envio_mb_s',
    'red_recepcion_mb_s',
];

/**
 * Una muestra del sistema, con contadores ACUMULADOS (el monitor calcula
 * las tasas con la diferencia entre dos muestras):
 *   cpu_ocupado, cpu_total   tiempo de CPU del equipo (unidades de la fuente)
 *   ram_total, ram_disponible  bytes
 *   disco_lectura, disco_escritura, red_envio, red_recepcion  bytes desde el arranque
 *   procesos  lista de [pid, nombre, inicio, cpu_s, rss_bytes, cmd]
 */
interface Fuente
{
    public function muestra(): array;

    public function cerrar(): void;

    public function procesador(): string;
}

/** Windows: un PowerShell abierto que responde una línea JSON por pedido. */
final class FuenteWindows implements Fuente
{
    private $proceso;
    private array $tuberias = [];

    private const SCRIPT = <<<'PS'
$ErrorActionPreference = 'SilentlyContinue'
[Console]::OutputEncoding = [Text.Encoding]::UTF8
$nombres = @('httpd.exe','apache.exe','php.exe','php-cgi.exe','nginx.exe','mysqld.exe','mariadbd.exe')
$filtro = ($nombres | ForEach-Object { "Name='$_'" }) -join ' OR '
while ($true) {
    $pedido = [Console]::In.ReadLine()
    if ($pedido -eq $null -or $pedido -eq 'fin') { break }
    $cpu = Get-CimInstance Win32_PerfRawData_PerfOS_Processor -Filter "Name='_Total'"
    $so = Get-CimInstance Win32_OperatingSystem
    $disco = Get-CimInstance Win32_PerfRawData_PerfDisk_PhysicalDisk -Filter "Name='_Total'"
    $red = @(Get-CimInstance Win32_PerfRawData_Tcpip_NetworkInterface)
    $procesos = @(Get-CimInstance Win32_Process -Filter $filtro | ForEach-Object {
        ,@([int]$_.ProcessId, [string]$_.Name, [string]$_.CreationDate,
           (([double]$_.UserModeTime + [double]$_.KernelModeTime) / 1e7),
           [double]$_.WorkingSetSize, [string]$_.CommandLine)
    })
    $inactivo = [double]$cpu.PercentProcessorTime
    $marca = [double]$cpu.Timestamp_Sys100NS
    $muestra = @{
        cpu_ocupado = $marca - $inactivo
        cpu_total = $marca
        ram_total = [double]$so.TotalVisibleMemorySize * 1024
        ram_disponible = [double]$so.FreePhysicalMemory * 1024
        disco_lectura = [double]$disco.DiskReadBytesPersec
        disco_escritura = [double]$disco.DiskWriteBytesPersec
        red_envio = [double](($red | Measure-Object -Property BytesSentPersec -Sum).Sum)
        red_recepcion = [double](($red | Measure-Object -Property BytesReceivedPersec -Sum).Sum)
        procesos = $procesos
    }
    [Console]::Out.WriteLine(($muestra | ConvertTo-Json -Compress -Depth 4))
    [Console]::Out.Flush()
}
PS;

    public function __construct()
    {
        $codificado = base64_encode(mb_convert_encoding(self::SCRIPT, 'UTF-16LE', 'UTF-8'));
        $this->proceso = proc_open(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $codificado],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']],
            $this->tuberias
        );
        if (!is_resource($this->proceso)) {
            throw new \RuntimeException('No se pudo abrir PowerShell para leer los contadores del sistema.');
        }
    }

    public function muestra(): array
    {
        fwrite($this->tuberias[0], "muestra\n");
        fflush($this->tuberias[0]);
        $linea = fgets($this->tuberias[1]);
        $datos = $linea === false ? null : json_decode(ltrim($linea, "\xEF\xBB\xBF"), true);
        if (!is_array($datos)) {
            throw new \RuntimeException('PowerShell no devolvió una muestra válida.');
        }
        $procesos = $datos['procesos'] ?? [];
        // ConvertTo-Json convierte una lista de un solo elemento en ese elemento.
        if ($procesos && !is_array($procesos[0] ?? null)) {
            $procesos = [$procesos];
        }
        $datos['procesos'] = $procesos;
        return $datos;
    }

    public function cerrar(): void
    {
        if (is_resource($this->proceso)) {
            @fwrite($this->tuberias[0], "fin\n");
            foreach ($this->tuberias as $t) {
                @fclose($t);
            }
            proc_close($this->proceso);
        }
    }

    public function procesador(): string
    {
        return (string) (getenv('PROCESSOR_IDENTIFIER') ?: php_uname('m'));
    }
}

/** Linux: lectura directa de /proc. */
final class FuenteLinux implements Fuente
{
    private const TICKS = 100; // USER_HZ (casi siempre 100)

    public function muestra(): array
    {
        $cpu = preg_split('/\s+/', trim((string) strtok((string) file_get_contents('/proc/stat'), "\n")));
        $tiempos = array_map('floatval', array_slice($cpu, 1, 8)); // user nice system idle iowait irq softirq steal
        $total = array_sum($tiempos);
        $inactivo = $tiempos[3] + ($tiempos[4] ?? 0);

        $memoria = [];
        foreach (file('/proc/meminfo') ?: [] as $linea) {
            if (preg_match('/^(\w+):\s+(\d+)/', $linea, $m)) {
                $memoria[$m[1]] = (float) $m[2] * 1024;
            }
        }

        $lectura = $escritura = 0.0;
        foreach (file('/proc/diskstats') ?: [] as $linea) {
            $c = preg_split('/\s+/', trim($linea));
            // Solo discos enteros (no particiones ni dispositivos virtuales).
            if (count($c) >= 10 && is_dir('/sys/block/' . $c[2]) && !preg_match('/^(loop|ram|zram|dm-)/', $c[2])) {
                $lectura += (float) $c[5] * 512;
                $escritura += (float) $c[9] * 512;
            }
        }

        $envio = $recepcion = 0.0;
        foreach (array_slice(file('/proc/net/dev') ?: [], 2) as $linea) {
            [, $valores] = explode(':', $linea, 2) + [1 => ''];
            $c = preg_split('/\s+/', trim($valores));
            $recepcion += (float) ($c[0] ?? 0);
            $envio += (float) ($c[8] ?? 0);
        }

        $procesos = [];
        foreach (scandir('/proc') ?: [] as $pid) {
            if (!ctype_digit($pid)) {
                continue;
            }
            $nombre = @file_get_contents("/proc/$pid/comm");
            $stat = @file_get_contents("/proc/$pid/stat");
            if ($nombre === false || $stat === false) {
                continue;
            }
            $nombre = trim($nombre);
            if (!clasificar($nombre, '') && !in_array(nombreBase($nombre), PROCESOS_PHP, true)) {
                continue;
            }
            // Los campos después del nombre entre paréntesis.
            $campos = preg_split('/\s+/', trim(substr($stat, strrpos($stat, ')') + 1)));
            $rss = 0.0;
            if (preg_match('/^VmRSS:\s+(\d+)/m', (string) @file_get_contents("/proc/$pid/status"), $m)) {
                $rss = (float) $m[1] * 1024;
            }
            $cmd = str_replace("\0", ' ', (string) @file_get_contents("/proc/$pid/cmdline"));
            $procesos[] = [
                (int) $pid, $nombre, $campos[19] ?? '',
                ((float) ($campos[11] ?? 0) + (float) ($campos[12] ?? 0)) / self::TICKS,
                $rss, $cmd,
            ];
        }

        return [
            'cpu_ocupado' => $total - $inactivo,
            'cpu_total' => $total,
            'ram_total' => $memoria['MemTotal'] ?? 0,
            'ram_disponible' => $memoria['MemAvailable'] ?? ($memoria['MemFree'] ?? 0),
            'disco_lectura' => $lectura,
            'disco_escritura' => $escritura,
            'red_envio' => $envio,
            'red_recepcion' => $recepcion,
            'procesos' => $procesos,
        ];
    }

    public function cerrar(): void
    {
    }

    public function procesador(): string
    {
        $info = (string) @file_get_contents('/proc/cpuinfo');
        return preg_match('/^model name\s*:\s*(.+)$/m', $info, $m) ? trim($m[1]) : php_uname('m');
    }
}

/** php-fpm aparece como "php-fpm8.2" y php como "php8.2" en algunas distribuciones. */
function nombreBase(string $nombre): string
{
    return (string) preg_replace('/[\d.]+$/', '', strtolower($nombre));
}

/** Grupo del proceso, o null si no interesa. El comando se inspecciona SOLO
 *  para identificar el servicio: nunca se guarda en el CSV. */
function clasificar(string $nombre, string $comando): ?string
{
    $nombre = strtolower($nombre);
    $base = nombreBase($nombre);

    if (in_array($nombre, PROCESOS_PHP, true) || in_array($base, PROCESOS_PHP, true)) {
        $comando = strtolower($comando);
        foreach (MARCAS_SERVICIO as $marca) {
            if (str_contains($comando, $marca)) {
                return 'Servicio_PHP';
            }
        }
        // Otros php (cron o CLI de Moodle) cuentan como parte de Moodle web.
        return 'Moodle_Web';
    }
    if (in_array($nombre, PROCESOS_WEB, true) || in_array($base, PROCESOS_WEB, true)) {
        return 'Moodle_Web';
    }
    if (in_array($nombre, PROCESOS_BD, true)) {
        return 'Moodle_BD';
    }
    return null;
}

function rutaSinSobrescribir(string $ruta): string
{
    if (!file_exists($ruta)) {
        return $ruta;
    }
    $info = pathinfo($ruta);
    $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
    for ($n = 2; ; $n++) {
        $candidata = $info['dirname'] . '/' . $info['filename'] . "_$n" . $extension;
        if (!file_exists($candidata)) {
            return $candidata;
        }
    }
}

function nombreSeguro(string $texto): string
{
    $limpio = trim((string) preg_replace('/[^\w.-]+/u', '_', trim($texto)), '_');
    return $limpio !== '' ? $limpio : 'medicion';
}

function escribirMetadatos(string $ruta, array $datos): void
{
    $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    $json = preg_replace_callback('/^( +)/m', static fn ($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    file_put_contents($ruta, $json);
}

function numeroCpus(): int
{
    if (PHP_OS_FAMILY === 'Windows') {
        return max(1, (int) getenv('NUMBER_OF_PROCESSORS'));
    }
    $info = (string) @file_get_contents('/proc/cpuinfo');
    return max(1, preg_match_all('/^processor\s*:/m', $info));
}

function main(array $argv): int
{
    $opciones = ['--etiqueta' => 'medicion', '--notas' => '', '--intervalo' => '1.0', '--salida' => null];
    for ($i = 0; $i < count($argv); $i++) {
        [$clave, $valor] = str_contains($argv[$i], '=') ? explode('=', $argv[$i], 2) : [$argv[$i], null];
        if (!array_key_exists($clave, $opciones)) {
            fwrite(STDERR, "Uso: php tools/monitor/monitor.php [--etiqueta TEXTO] [--notas TEXTO] [--intervalo SEG] [--salida RUTA]\n");
            return 2;
        }
        $opciones[$clave] = $valor ?? ($argv[++$i] ?? '');
    }
    $intervalo = max(0.1, (float) $opciones['--intervalo']);

    if (!date_default_timezone_set(getenv('TIMEZONE') ?: 'America/Bogota')) {
        date_default_timezone_set('UTC');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $fuente = new FuenteWindows();
    } elseif (PHP_OS_FAMILY === 'Linux') {
        $fuente = new FuenteLinux();
    } else {
        fwrite(STDERR, 'ERROR: el monitor funciona en Windows y Linux (no en ' . PHP_OS_FAMILY . ").\n");
        return 1;
    }

    $nCpu = numeroCpus();
    $inicio = new \DateTimeImmutable();
    if ($opciones['--salida']) {
        $archivo = $opciones['--salida'];
    } else {
        $archivo = CARPETA_MEDICIONES . '/' . $inicio->format('Y-m-d_His') . '_' . nombreSeguro($opciones['--etiqueta']) . '.csv';
    }
    $archivo = rutaSinSobrescribir($archivo);
    if (!is_dir(dirname($archivo))) {
        mkdir(dirname($archivo), 0777, true);
    }
    $archivo = realpath(dirname($archivo)) . DIRECTORY_SEPARATOR . basename($archivo);
    $archivoMeta = preg_replace('/\.[^.\\\\\/]*$/', '', $archivo) . '.json';

    $anterior = $fuente->muestra();
    $metadatos = [
        'etiqueta' => $opciones['--etiqueta'],
        'notas' => $opciones['--notas'],
        'inicio' => $inicio->format('Y-m-d\TH:i:s'),
        'fin' => null,
        'intervalo_s' => $intervalo,
        'filas' => 0,
        'csv' => basename($archivo),
        'equipo' => [
            'sistema' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'procesador' => $fuente->procesador(),
            'cpu_logicos' => $nCpu,
            'ram_total_mb' => (int) round($anterior['ram_total'] / 1024 ** 2),
            'php' => PHP_VERSION,
        ],
    ];
    escribirMetadatos($archivoMeta, $metadatos);

    echo "Monitor Auto Evaluar\n";
    echo "Intervalo: $intervalo segundos\n";
    echo "Archivo: $archivo\n";
    echo "Procesadores lógicos: $nCpu\n";
    echo "Presiona Ctrl+C para finalizar.\n\n";

    // Ctrl+C: se termina la fila en curso y se cierran los archivos.
    $parar = false;
    if (function_exists('sapi_windows_set_ctrl_handler')) {
        sapi_windows_set_ctrl_handler(static function () use (&$parar) { $parar = true; });
    } elseif (function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use (&$parar) { $parar = true; });
        pcntl_signal(SIGTERM, static function () use (&$parar) { $parar = true; });
    }

    // CPU acumulada por proceso (pid + inicio), para calcular el porcentaje.
    $cpuAnterior = [];
    foreach ($anterior['procesos'] as $p) {
        $cpuAnterior[$p[0] . '|' . $p[2]] = (float) $p[3];
    }
    $tiempoAnterior = hrtime(true);
    $filas = 0;

    $f = fopen($archivo, 'wb');
    fwrite($f, "\xEF\xBB\xBF"); // UTF-8 con BOM: Excel lo abre con tildes
    fputcsv($f, COLUMNAS, ',', '"', '', "\r\n");

    while (!$parar) {
        // Espera en pasos cortos para responder rápido a Ctrl+C.
        $hasta = hrtime(true) + (int) ($intervalo * 1e9);
        while (!$parar && hrtime(true) < $hasta) {
            usleep(50000);
        }
        if ($parar) {
            break;
        }

        try {
            $actual = $fuente->muestra();
        } catch (\RuntimeException $e) {
            fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
            break;
        }
        $ahora = hrtime(true);
        $dt = max(($ahora - $tiempoAnterior) / 1e9, 0.001);

        $medidas = ['Moodle_Web' => [0.0, 0.0, 0], 'Moodle_BD' => [0.0, 0.0, 0], 'Servicio_PHP' => [0.0, 0.0, 0]];
        $cpuActual = [];
        foreach ($actual['procesos'] as [$pid, $nombre, $inicioProceso, $cpuSegundos, $rss, $comando]) {
            // El monitor también es un proceso php: no se mide a sí mismo.
            $grupo = (int) $pid === getmypid() ? null : clasificar((string) $nombre, (string) $comando);
            if ($grupo === null) {
                continue;
            }
            $clave = $pid . '|' . $inicioProceso;
            if (isset($cpuAnterior[$clave])) {
                $diferencia = max(0.0, (float) $cpuSegundos - $cpuAnterior[$clave]);
                $medidas[$grupo][0] += 100 * $diferencia / ($dt * $nCpu);
            }
            $cpuActual[$clave] = (float) $cpuSegundos;
            $medidas[$grupo][1] += (float) $rss / 1024 ** 2;
            $medidas[$grupo][2]++;
        }
        $cpuAnterior = $cpuActual;
        $tiempoAnterior = $ahora;

        $tasa = static fn (string $campo) => max(0.0, $actual[$campo] - $anterior[$campo]) / $dt / 1024 ** 2;
        $cpuTotal = $actual['cpu_total'] - $anterior['cpu_total'];
        $cpuTotalPct = $cpuTotal > 0 ? 100 * max(0.0, $actual['cpu_ocupado'] - $anterior['cpu_ocupado']) / $cpuTotal : 0.0;
        $ramTotal = max(1.0, (float) $actual['ram_total']);

        $fila = [
            'fecha_hora' => date('Y-m-d\TH:i:s'),
            'cpu_total_pct' => round(min(100.0, $cpuTotalPct), 1),
            'ram_total_pct' => round(100 * ($ramTotal - $actual['ram_disponible']) / $ramTotal, 1),
            'ram_disponible_mb' => round($actual['ram_disponible'] / 1024 ** 2, 2),
            'moodle_web_cpu_pct' => round($medidas['Moodle_Web'][0], 2),
            'moodle_web_ram_mb' => round($medidas['Moodle_Web'][1], 2),
            'moodle_bd_cpu_pct' => round($medidas['Moodle_BD'][0], 2),
            'moodle_bd_ram_mb' => round($medidas['Moodle_BD'][1], 2),
            'servicio_cpu_pct' => round($medidas['Servicio_PHP'][0], 2),
            'servicio_ram_mb' => round($medidas['Servicio_PHP'][1], 2),
            'servicio_procesos' => $medidas['Servicio_PHP'][2],
            'disco_lectura_mb_s' => round($tasa('disco_lectura'), 3),
            'disco_escritura_mb_s' => round($tasa('disco_escritura'), 3),
            'red_envio_mb_s' => round($tasa('red_envio'), 3),
            'red_recepcion_mb_s' => round($tasa('red_recepcion'), 3),
        ];
        $anterior = $actual;

        fputcsv($f, array_values($fila), ',', '"', '', "\r\n");
        fflush($f);
        $filas++;

        printf(
            "CPU total: %5.1f%% | Moodle web: %7.1f MB | BD: %7.1f MB | Servicio: %7.1f MB\n",
            $fila['cpu_total_pct'], $fila['moodle_web_ram_mb'], $fila['moodle_bd_ram_mb'], $fila['servicio_ram_mb']
        );
    }

    fclose($f);
    $fuente->cerrar();
    $metadatos['fin'] = date('Y-m-d\TH:i:s');
    $metadatos['filas'] = $filas;
    escribirMetadatos($archivoMeta, $metadatos);
    echo "\nMedición finalizada.\n";
    echo "Resultados: $archivo\n";
    return 0;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')) {
        sapi_windows_cp_set(65001);
    }
    exit(main(array_slice($argv, 1)));
}
