<?php
// Config.php
// Toda la configuración del servicio sale de aquí. Los valores se leen de
// variables de entorno y, si no están, del archivo php-service/.env.
// Nada de claves, rutas ni direcciones escritas en el código.
//
// Ver .env.example para la lista completa y su significado.

namespace AutoEvaluar\Servicio;

final class Config
{
    /** Carpeta php-service/: las rutas relativas se resuelven desde aquí. */
    public const CARPETA_SERVICIO = __DIR__ . '/..';

    public function __construct(
        public readonly string $moodleUrl,
        public readonly string $moodlePublicUrl,
        public readonly string $moodleToken,
        /** @var string[] */
        public readonly array $allowedFileHosts,
        public readonly string $geminiApiKey,
        public readonly string $geminiModel,
        public readonly string $geminiUrl,
        public readonly string $outputDir,
        public readonly string $promptsDir,
        public readonly int $pdfDpi,
        /** Cómo se envían los PDF a Gemini: 'imagen' (una por página, con
         *  Imagick) o 'directo' (el PDF entero). Ver PDF_MODO en .env.example. */
        public readonly string $pdfModo,
        public readonly int $moodleTimeout,
        public readonly int $downloadTimeout,
        public readonly int $geminiTimeout,
        public readonly string $serviceApiKey,
        public readonly int $maxFileMb,
        public readonly int $maxPages,
        public readonly int $maxConcurrent,
        public readonly bool $saveTranscriptions,
        public readonly int $transcriptionsDays,
        public readonly bool $enableDocs,
        public readonly string $zonaHoraria,
        /** Carpeta de los candados de MAX_CONCURRENT (ver Cupos.php). */
        public readonly string $carpetaCupos,
    ) {
    }

    /** Copia con algunos valores cambiados (lo usan las pruebas). */
    public function con(array $cambios): self
    {
        return new self(...array_merge(get_object_vars($this), $cambios));
    }

    /** Lector mínimo de archivos .env (CLAVE=valor, # comentarios). */
    public static function leerEnv(string $ruta): array
    {
        $valores = [];
        if (!is_file($ruta)) {
            return $valores;
        }
        $texto = (string) file_get_contents($ruta);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto); // BOM del Bloc de notas
        foreach (preg_split('/\r\n|\r|\n/', $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            $valor = trim($valor);
            if (strlen($valor) >= 2 && $valor[0] === $valor[-1] && ($valor[0] === '"' || $valor[0] === "'")) {
                $valor = substr($valor, 1, -1);
            }
            $valores[trim($clave)] = $valor;
        }
        return $valores;
    }

    public static function booleano(string $valor): bool
    {
        $valor = trim($valor);
        $valor = function_exists('mb_strtolower') ? mb_strtolower($valor, 'UTF-8') : strtolower($valor);
        return in_array($valor, ['1', 'true', 'si', 'sí', 'yes', 'on'], true);
    }

    /** Las rutas relativas se resuelven desde php-service/, no desde la
     *  carpeta donde se lanzó PHP. */
    public static function ruta(string $valor): string
    {
        $valor = trim($valor);
        if (str_starts_with($valor, '~')) {
            $casa = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
            $valor = $casa . substr($valor, 1);
        }
        $absoluta = preg_match('#^([A-Za-z]:)?[\\\\/]#', $valor) === 1;
        return self::normalizar($absoluta ? $valor : self::CARPETA_SERVICIO . '/' . $valor);
    }

    /** Quita "." y ".." de una ruta sin exigir que exista (realpath sí lo exige). */
    public static function normalizar(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);
        $prefijo = '';
        if (preg_match('#^([A-Za-z]:)?/#', $ruta, $m)) {
            $prefijo = $m[0];
            $ruta = substr($ruta, strlen($prefijo));
        }
        $partes = [];
        foreach (explode('/', $ruta) as $parte) {
            if ($parte === '' || $parte === '.') {
                continue;
            }
            if ($parte === '..' && $partes && end($partes) !== '..') {
                array_pop($partes);
            } elseif ($parte !== '..' || $prefijo === '') {
                $partes[] = $parte;
            }
        }
        return $prefijo . implode('/', $partes);
    }

    public static function cargar(): self
    {
        $archivoEnv = self::leerEnv(getenv('AIOCR_ENV_FILE') ?: self::CARPETA_SERVICIO . '/.env');

        $valor = static function (string $clave, ?string $defecto = null, bool $obligatorio = false) use ($archivoEnv): ?string {
            $v = getenv($clave);
            if ($v === false || $v === '') {
                $v = $archivoEnv[$clave] ?? '';
            }
            if ($v === '') {
                $v = $defecto;
            }
            if ($obligatorio && ($v === null || $v === '')) {
                throw new \RuntimeException(
                    "Falta la variable $clave. Defínela en php-service/.env "
                    . '(copia .env.example) o como variable de entorno.'
                );
            }
            return $v;
        };
        $entero = static function (string $clave, string $defecto) use ($valor): int {
            $v = trim((string) $valor($clave, $defecto));
            if (preg_match('/^-?\d+$/', $v) !== 1) {
                throw new \RuntimeException("La variable $clave debe ser un número entero (vale «{$v}»).");
            }
            return (int) $v;
        };

        $moodleUrl = rtrim((string) $valor('MOODLE_URL', 'http://localhost'), '/');
        // Dirección pública de Moodle (su wwwroot), si es distinta de la que
        // usa el servicio. Ej.: Docker (interna http://moodle, pública
        // http://localhost:8080) o ngrok. Ver MoodleClient::aUrlInterna.
        $moodlePublicUrl = rtrim((string) $valor('MOODLE_PUBLIC_URL', ''), '/');

        // El token puede venir directo o en un archivo (Docker lo genera al
        // instalar Moodle y lo comparte por un volumen).
        $token = (string) $valor('MOODLE_TOKEN', '');
        $archivoToken = $valor('MOODLE_TOKEN_FILE');
        if ($token === '' && $archivoToken && is_file(self::ruta($archivoToken))) {
            $token = trim((string) file_get_contents(self::ruta($archivoToken)));
        }
        if ($token === '') {
            $valor('MOODLE_TOKEN', null, true);
        }

        // Hosts a los que se les puede enviar el token al descargar archivos.
        // Si no se indican, solo el host de MOODLE_URL.
        $permitidos = [];
        foreach (explode(',', (string) $valor('ALLOWED_FILE_HOSTS', '')) as $host) {
            if (trim($host) !== '') {
                $permitidos[] = strtolower(trim($host));
            }
        }
        $hostMoodle = strtolower((string) (parse_url($moodleUrl, PHP_URL_HOST) ?? ''));
        $permitidos[] = $hostMoodle;
        // localhost y 127.0.0.1 son el mismo equipo: si Moodle se configuró con
        // uno, las URL de los archivos pueden llegar con el otro.
        if (in_array($hostMoodle, ['localhost', '127.0.0.1'], true)) {
            array_push($permitidos, 'localhost', '127.0.0.1');
        }
        $permitidos = array_values(array_unique(array_filter($permitidos, 'strlen')));
        sort($permitidos);

        $modelo = (string) $valor('GEMINI_MODEL', 'gemini-3.5-flash-lite');

        // auto: imagen si está Imagick, si no, directo.
        $pdfModo = strtolower(trim((string) $valor('PDF_MODO', 'auto')));
        if (!in_array($pdfModo, ['auto', 'imagen', 'directo'], true)) {
            throw new \RuntimeException("PDF_MODO debe ser auto, imagen o directo (vale «{$pdfModo}»).");
        }
        if ($pdfModo === 'auto') {
            $pdfModo = extension_loaded('imagick') ? 'imagen' : 'directo';
        }

        return new self(
            moodleUrl: $moodleUrl,
            moodlePublicUrl: $moodlePublicUrl,
            moodleToken: $token,
            allowedFileHosts: $permitidos,
            geminiApiKey: (string) $valor('GEMINI_API_KEY', null, true),
            geminiModel: $modelo,
            geminiUrl: 'https://generativelanguage.googleapis.com/v1beta/models/' . $modelo . ':generateContent',
            outputDir: self::ruta((string) $valor('OUTPUT_DIR', '../data/transcripciones')),
            promptsDir: self::ruta((string) $valor('PROMPTS_DIR', 'prompts')),
            pdfDpi: $entero('PDF_DPI', '200'),
            pdfModo: $pdfModo,
            moodleTimeout: $entero('MOODLE_TIMEOUT', '10'),
            downloadTimeout: $entero('DOWNLOAD_TIMEOUT', '30'),
            geminiTimeout: $entero('GEMINI_TIMEOUT', '120'),
            // Clave que el plugin envía en cada solicitud (cabecera X-Aiocr-Key).
            // Sin ella nadie más puede usar el servicio ni gastar la cuota de Gemini.
            serviceApiKey: (string) $valor('SERVICE_API_KEY', null, true),
            // Límites por solicitud: tamaño del archivo, páginas que se procesan
            // y evaluaciones simultáneas (protegen memoria, tiempo y cuota).
            maxFileMb: $entero('MAX_FILE_MB', '20'),
            maxPages: $entero('MAX_PAGES', '20'),
            maxConcurrent: max(1, $entero('MAX_CONCURRENT', '3')),
            // Transcripciones en disco: se pueden desactivar, y las de más de
            // TRANSCRIPTIONS_DAYS días se borran solas (0 = no borrar nunca).
            saveTranscriptions: self::booleano((string) $valor('SAVE_TRANSCRIPTIONS', 'true')),
            transcriptionsDays: $entero('TRANSCRIPTIONS_DAYS', '180'),
            // /docs describe la API: apagado salvo para desarrollo.
            enableDocs: self::booleano((string) $valor('ENABLE_DOCS', 'false')),
            // Hora de las transcripciones y del registro (la de php.ini puede ser otra).
            zonaHoraria: (string) $valor('TIMEZONE', 'America/Bogota'),
            carpetaCupos: self::normalizar(sys_get_temp_dir() . '/autoevaluar_cupos_'
                . substr(md5((string) realpath(self::CARPETA_SERVICIO)), 0, 8)),
        );
    }

    public function leerPrompt(string $nombre): string
    {
        // Se lee tal cual: el prompt de evaluación termina en una línea en
        // blanco a la que se le pega la transcripción.
        $ruta = $this->promptsDir . '/' . $nombre;
        $texto = @file_get_contents($ruta);
        if ($texto === false) {
            throw new \RuntimeException("No se encontró el prompt $ruta (revisa PROMPTS_DIR).");
        }
        // Finales de línea de Windows (si git los convirtió) como \n.
        return str_replace("\r\n", "\n", $texto);
    }

    /** Aplica TIMEZONE (si no es válida, se queda la de php.ini). */
    public function aplicarZonaHoraria(): void
    {
        if (!@date_default_timezone_set($this->zonaHoraria)) {
            Log::advertencia("TIMEZONE «{$this->zonaHoraria}» no es válida; se usa " . date_default_timezone_get());
        }
    }
}
