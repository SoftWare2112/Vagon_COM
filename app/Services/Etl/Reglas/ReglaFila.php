<?php

namespace App\Services\Etl\Reglas;

use App\Services\Etl\Dto\MapaColumnas;
use App\Services\Etl\Dto\Mensaje;

/**
 * Regla de negocio que se evalúa sobre una fila YA transformada.
 * Patrón Strategy/Chain: agregar una regla = crear una clase y registrarla
 * en ProcesadorHoja, sin tocar las demás.
 */
interface ReglaFila
{
    /** @return list<Mensaje> */
    public function evaluar(int $fila, array $datos, MapaColumnas $mapa): array;
}
