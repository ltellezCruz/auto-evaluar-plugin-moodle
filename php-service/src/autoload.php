<?php
// Carga automática de las clases de src/ (espacio de nombres
// AutoEvaluar\Servicio). Así el servicio corre sin Composer: Composer solo
// hace falta para las pruebas (PHPUnit).

spl_autoload_register(static function (string $clase): void {
    $prefijo = 'AutoEvaluar\\Servicio\\';
    if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
        return;
    }
    $archivo = __DIR__ . '/' . str_replace('\\', '/', substr($clase, strlen($prefijo))) . '.php';
    if (is_file($archivo)) {
        require $archivo;
    }
});
