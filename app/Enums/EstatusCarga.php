<?php

namespace App\Enums;

enum EstatusCarga: string
{
    case ConErrores = 'CON_ERRORES';
    case Lista = 'LISTA';
    case Aplicada = 'APLICADA';
    case Fallida = 'FALLIDA';
    case Descartada = 'DESCARTADA';
}
