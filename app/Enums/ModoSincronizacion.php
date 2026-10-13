<?php

namespace App\Enums;

enum ModoSincronizacion: string
{
    case Reemplazar = 'REEMPLAZAR';
    case Mantener = 'MANTENER';
}
