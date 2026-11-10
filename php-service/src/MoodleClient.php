<?php
// MoodleClient.php
// Todo lo que habla con Moodle: llamadas a la API REST y descarga de los
// archivos de una entrega usando el token (extensión curl de PHP).

namespace AutoEvaluar\Servicio;

final class MoodleClient
{
    /** Tipos de archivo que se procesan, con la extensión con que se guardan. */
    public const TIPOS_PERMITIDOS = [
        'application/pdf' => '.pdf',
        'image/png' => '.png',
        'image/jpeg' => '.jpg',
    ];
    /** Única ruta de Moodle a la que se le envía el token: archivos por servicio web. */
    public const RUTA_ARCHIVOS = '/webservice/pluginfile.php/';

    /** Tope de lo que se guarda de una respuesta de error (JSON o HTML) de Moodle. */
    private const TOPE_ERROR = 64 * 1024;

    public function __construct(private readonly Config $config)
    {
    }

    /** Moodle rechaza (redirige) las peticiones cuyo Host no coincide con
     *  su wwwroot. Si el servicio entra por una dirección interna, se
     *  presenta con el host público. */
    public function cabeceraHost(): array
    {
        if ($this->config->moodlePublicUrl === '') {
            return [];
        }
        $partes = parse_url($this->config->moodlePublicUrl);
        $host = $partes['host'] ?? '';
        if (isset($partes['port'])) {
            $host .= ':' . $partes['port'];
        }
        return ['Host: ' . $host];
    }

    /** Las URL de los archivos llegan con la dirección pública de Moodle
     *  (su wwwroot). Si MOODLE_PUBLIC_URL está definida, se cambian por
     *  MOODLE_URL para descargar por la red interna (contenedores, o el mismo
     *  equipo en vez de dar la vuelta por ngrok). */
    public function aUrlInterna(string $url): string
    {
        $publica = $this->config->moodlePublicUrl;
        if ($publica !== '' && ($url === $publica || str_starts_with($url, $publica . '/'))) {
            return $this->config->moodleUrl . substr($url, strlen($publica));
        }
        return $url;
    }

    public function llamar(string $wsfunction, array $params = []): mixed
    {
        $consulta = http_build_query(array_merge([
            'wstoken' => $this->config->moodleToken,
            'wsfunction' => $wsfunction,
            'moodlewsrestformat' => 'json',
        ], $params));

        $ch = curl_init($this->config->moodleUrl . '/webservice/rest/server.php?' . $consulta);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->cabeceraHost(),
            CURLOPT_TIMEOUT => $this->config->moodleTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->config->moodleTimeout,
        ]);
        $cuerpo = curl_exec($ch);
        $estado = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($cuerpo === false || $estado !== 200) {
            throw new ErrorHttp(502, 'Moodle no respondió correctamente');
        }
        $datos = json_decode((string) $cuerpo, true);
        if (is_array($datos) && array_key_exists('exception', $datos)) {
            throw new ErrorHttp(400, (string) ($datos['message'] ?? 'Error de Moodle'));
        }
        return $datos;
    }

    /** Evita que el token de Moodle se envíe a un servidor ajeno si
     *  alguien llama al servicio con una fileurl de otro sitio. */
    public function validarHostPermitido(string $fileurl): void
    {
        $partes = parse_url($fileurl);
        $host = strtolower((string) (is_array($partes) ? ($partes['host'] ?? '') : ''));
        $esquema = strtolower((string) (is_array($partes) ? ($partes['scheme'] ?? '') : ''));
        if (!in_array($esquema, ['http', 'https'], true) || !in_array($host, $this->config->allowedFileHosts, true)) {
            throw new \RuntimeException(
                "El host '$host' no está en ALLOWED_FILE_HOSTS ("
                . implode(', ', $this->config->allowedFileHosts) . '); no se envía el token.'
            );
        }
        // Ni siquiera dentro de Moodle se envía el token a otra ruta que no sea
        // la de archivos (evita usarlo contra otras páginas o funciones).
        $ruta = (string) ($partes['path'] ?? '');
        if (!str_contains($ruta, self::RUTA_ARCHIVOS) || str_contains($ruta, '..')) {
            throw new \RuntimeException('La URL no es un archivo de Moodle (' . self::RUTA_ARCHIVOS . '); no se envía el token.');
        }
    }

    /** Descarga el archivo a la carpeta y devuelve su ruta. El nombre en
     *  disco lo decide el tipo de archivo, no la petición.
     *
     *  @throws TipoNoAdmitido si no es PDF ni imagen (se omite y se sigue)
     *  @throws \RuntimeException con cualquier otro problema */
    public function descargarArchivo(string $fileurl, string $carpeta, string $nombre = 'entrega'): string
    {
        $fileurl = $this->aUrlInterna($fileurl);
        $this->validarHostPermitido($fileurl);

        $separador = str_contains($fileurl, '?') ? '&' : '?';
        $urlConToken = $fileurl . $separador . 'token=' . rawurlencode($this->config->moodleToken);
        $limite = $this->config->maxFileMb * 1024 * 1024;

        // Estado compartido con las funciones que curl llama mientras recibe.
        $estado = 0;
        $cabeceras = [];
        $revisado = false;
        $modo = null;            // 'archivo' | 'error_json'
        $destino = null;
        $manejador = null;
        $recibidos = 0;
        $textoError = '';
        $falla = null;           // excepción que corta la descarga

        // Equivale a lo que Python revisaba antes de leer el cuerpo: redirección,
        // código de error, tipo de contenido y tamaño declarado.
        $revisar = function () use (&$estado, &$cabeceras, &$revisado, &$modo, &$destino, &$manejador, $carpeta, $nombre, $limite): void {
            $revisado = true;
            if ($estado >= 300 && $estado < 400) {
                throw new \RuntimeException(
                    'Moodle redirigió la descarga a ' . ($cabeceras['location'] ?? '(sin dirección)') . '. '
                    . 'Revisa MOODLE_URL y MOODLE_PUBLIC_URL (deben coincidir con el wwwroot de Moodle).'
                );
            }
            if ($estado >= 400) {
                throw new \RuntimeException("Moodle respondió con el código $estado al descargar el archivo.");
            }
            // Con token inválido, host incorrecto o sin permisos, Moodle responde
            // con un JSON de error (o una página HTML) en vez del archivo.
            $tipo = $cabeceras['content-type'] ?? '';
            if (str_starts_with($tipo, 'application/json')) {
                $modo = 'error_json';
                return;
            }
            if (str_starts_with($tipo, 'text/html')) {
                throw new \RuntimeException(
                    'Moodle devolvió una página HTML en vez del archivo '
                    . '(token inválido o vencido, o falta el permiso del servicio web).'
                );
            }
            $tipoBase = strtolower(trim(explode(';', $tipo)[0]));
            if (!isset(self::TIPOS_PERMITIDOS[$tipoBase])) {
                throw new TipoNoAdmitido('Tipo de archivo no admitido: ' . ($tipoBase ?: 'desconocido') . ' (solo PDF, PNG o JPG).');
            }
            // El archivo nunca se carga completo en memoria y se corta al pasar el límite.
            $declarado = (int) ($cabeceras['content-length'] ?? 0);
            if ($declarado > $limite) {
                throw new \RuntimeException(sprintf(
                    'El archivo pesa %.1f MB; el máximo es %d MB.', $declarado / 1024 ** 2, $this->config->maxFileMb
                ));
            }
            $destino = $carpeta . '/' . $nombre . self::TIPOS_PERMITIDOS[$tipoBase];
            $manejador = fopen($destino, 'wb');
            if (!$manejador) {
                throw new \RuntimeException("No se pudo crear $destino.");
            }
            $modo = 'archivo';
        };

        $ch = curl_init($urlConToken);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $this->cabeceraHost(),
            CURLOPT_TIMEOUT => $this->config->downloadTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->config->downloadTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $linea) use (&$estado, &$cabeceras): int {
                $limpia = trim($linea);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $limpia, $m)) {
                    $estado = (int) $m[1];
                    $cabeceras = [];
                } elseif (str_contains($limpia, ':')) {
                    [$clave, $valor] = explode(':', $limpia, 2);
                    $cabeceras[strtolower(trim($clave))] = trim($valor);
                }
                return strlen($linea);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $bloque) use (&$revisado, &$modo, &$manejador, &$recibidos, &$textoError, &$falla, $revisar, $limite): int {
                try {
                    if (!$revisado) {
                        $revisar();
                    }
                    if ($modo === 'error_json') {
                        if (strlen($textoError) < self::TOPE_ERROR) {
                            $textoError .= $bloque;
                        }
                        return strlen($bloque);
                    }
                    $recibidos += strlen($bloque);
                    if ($recibidos > $limite) {
                        throw new \RuntimeException("El archivo supera el máximo de {$this->config->maxFileMb} MB.");
                    }
                    fwrite($manejador, $bloque);
                    return strlen($bloque);
                } catch (\Throwable $e) {
                    $falla = $e;
                    return -1; // cualquier valor distinto del tamaño corta la descarga
                }
            },
        ]);
        $ok = curl_exec($ch);
        $errorCurl = curl_error($ch);
        curl_close($ch);
        if (is_resource($manejador)) {
            fclose($manejador);
        }

        try {
            if ($falla) {
                throw $falla;
            }
            if ($ok === false) {
                throw new \RuntimeException($errorCurl ?: 'Error de conexión con Moodle.');
            }
            if (!$revisado) {
                $revisar(); // respuesta sin cuerpo (una redirección, por ejemplo)
                if (is_resource($manejador)) {
                    fclose($manejador);
                }
            }
            if ($modo === 'error_json') {
                $datos = json_decode($textoError, true);
                $mensaje = is_array($datos) && !empty($datos['error']) ? $datos['error'] : $textoError;
                throw new \RuntimeException('Moodle no entregó el archivo: ' . $mensaje);
            }
        } catch (\Throwable $e) {
            if ($destino !== null && is_file($destino)) {
                @unlink($destino);
            }
            throw $e;
        }
        return $destino;
    }
}
