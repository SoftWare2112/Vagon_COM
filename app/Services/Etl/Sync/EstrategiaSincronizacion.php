<?php

namespace App\Services\Etl\Sync;

use App\Models\Carga;
use App\Models\Periodo;
use Illuminate\Support\Collection;

/**
 * Patrón STRATEGY: cada modo de carga (Reemplazar / Mantener) es una clase.
 * Un modo nuevo (p. ej. "Solo agregar nuevos") = una clase nueva + un case en el enum.
 */
interface EstrategiaSincronizacion
{
    /** Calcula el impacto SIN escribir nada (vista previa). */
    public function previsualizar(Collection $filas, ?Periodo $periodo): array;

    /** Aplica los cambios. Se ejecuta dentro de una transacción abierta por CargaService. */
    public function aplicar(Carga $carga, Collection $filas, Periodo $periodo, int $userId): array;
}
