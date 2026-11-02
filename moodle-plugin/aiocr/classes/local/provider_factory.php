<?php
namespace assignfeedback_aiocr\local;

defined('MOODLE_INTERNAL') || die();

/**
 * provider_factory
 *
 * Punto ÚNICO donde se decide qué implementación concreta de
 * ai_provider_interface se usa. Lee el nombre del proveedor desde la
 * configuración del plugin (la que se ve en Administración del sitio),
 * y construye la clase correspondiente dinámicamente.
 *
 * Para agregar un modelo de IA nuevo en el futuro:
 *   1. Crea classes/local/mi_nuevo_adapter.php implementando ai_provider_interface.
 *   2. Agrégalo al arreglo PROVIDERS de abajo.
 *   3. Selecciónalo en la configuración del plugin. Listo — nada más
 *      del plugin necesita cambiar.
 */
class provider_factory {

    /** @var array Mapa nombre-corto => nombre de clase completo. */
    private const PROVIDERS = [
        'fastapi_adapter' => \assignfeedback_aiocr\local\fastapi_adapter::class,
        // 'openai_adapter' => \assignfeedback_aiocr\local\openai_adapter::class,
        // 'gemini_adapter' => \assignfeedback_aiocr\local\gemini_adapter::class,
        // 'mock_adapter'   => \assignfeedback_aiocr\local\mock_adapter::class,
    ];

    /**
     * Devuelve una instancia lista para usar del proveedor configurado.
     *
     * @return ai_provider_interface
     * @throws \moodle_exception si el proveedor configurado no existe.
     */
    public static function get_provider(): ai_provider_interface {
        $configurado = get_config('assignfeedback_aiocr', 'provider');
        $configurado = $configurado ?: 'fastapi_adapter'; // valor por defecto

        if (!isset(self::PROVIDERS[$configurado])) {
            throw new \moodle_exception(
                'erroraiservice',
                'assignfeedback_aiocr',
                '',
                "Proveedor de IA desconocido: '{$configurado}'"
            );
        }

        $clase = self::PROVIDERS[$configurado];
        return new $clase();
    }

    /**
     * Lista de proveedores disponibles, para poblar el <select> de
     * configuración del plugin (ver settings.php).
     *
     * @return array [nombre_corto => nombre_legible]
     */
    public static function get_available_providers(): array {
        $opciones = [];
        foreach (array_keys(self::PROVIDERS) as $key) {
            $opciones[$key] = get_string("provider:{$key}", 'assignfeedback_aiocr') ?? $key;
        }
        return $opciones;
    }
}
