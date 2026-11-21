<?php

namespace App\Support\Cfdi;

/** v14: error al armar, sellar, leer o timbrar un CFDI. `codigo` es estable para la interfaz y las pruebas. */
class CfdiException extends \RuntimeException
{
    public function __construct(string $mensaje, public readonly string $codigo = 'CFDI', public readonly array $datos = [])
    {
        parent::__construct($mensaje);
    }
}
