<?php
// Funciones comunes de las pruebas de tests/ (las carga tests/bootstrap.php).

namespace AutoEvaluar\Pruebas;

use AutoEvaluar\Scripts\DeployPlugin;

const RAIZ_REPO = __DIR__ . '/..';

/** Ejecuta main() y devuelve [código, todo lo que escribió]. */
function ejecutar(callable $funcion, array $args): array
{
    DeployPlugin::$erroresALaSalida = true;
    ob_start();
    try {
        $codigo = $funcion($args);
    } finally {
        $salida = (string) ob_get_clean();
        DeployPlugin::$erroresALaSalida = false;
    }
    return [$codigo, $salida];
}

/** Archivos de una carpeta, relativos y con "/", en orden. */
function archivos(string $carpeta): array
{
    $base = realpath($carpeta);
    $lista = [];
    $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterador as $archivo) {
        if ($archivo->isFile()) {
            $lista[] = str_replace('\\', '/', substr($archivo->getPathname(), strlen($base) + 1));
        }
    }
    sort($lista);
    return $lista;
}

/** Release del plugin ($plugin->release en version.php). */
function releasePlugin(): string
{
    preg_match("/\\\$plugin->release\s*=\s*'([^']+)'/", file_get_contents(DeployPlugin::ORIGEN . '/version.php'), $m);
    return $m[1];
}
