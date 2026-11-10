<?php
// Error que termina la solicitud con un código HTTP y un detalle, igual
// que el HTTPException de FastAPI: la respuesta es {"detail": ...}. El
// plugin muestra ese detalle al profesor.

namespace AutoEvaluar\Servicio;

final class ErrorHttp extends \RuntimeException
{
    /** @param string|array $detalle texto, o la lista de errores de validación (422) */
    public function __construct(
        public readonly int $estado,
        public readonly string|array $detalle,
    ) {
        parent::__construct(is_string($detalle) ? $detalle : json_encode($detalle, JSON_UNESCAPED_UNICODE));
    }
}
