<?php

namespace App\Services\Etl\Sync;

use App\Enums\ModoSincronizacion;

/** Patrón FACTORY: el resto del código no sabe qué clase concreta se usa. */
class EstrategiaFactory
{
    public function para(ModoSincronizacion $modo): EstrategiaSincronizacion
    {
        return match ($modo) {
            ModoSincronizacion::Reemplazar => app(EstrategiaReemplazar::class),
            ModoSincronizacion::Mantener => app(EstrategiaMantener::class),
        };
    }
}
