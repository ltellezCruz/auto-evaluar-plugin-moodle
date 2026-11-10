<?php
// El archivo existe y se pudo pedir, pero no es PDF ni imagen: en una
// entrega con varios archivos se omite en vez de cancelar todo.

namespace AutoEvaluar\Servicio;

final class TipoNoAdmitido extends \RuntimeException
{
}
