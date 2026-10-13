<?php

namespace App\Repositories\Contracts;

use App\Models\Registro;
use App\Repositories\FiltrosTabla;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Patrón REPOSITORY: los controladores piden "la tabla filtrada" sin saber SQL.
 * Si mañana cambia el motor o se agrega caché, solo cambia la implementación.
 */
interface RegistroRepository
{
    public function tabla(FiltrosTabla $filtros): LengthAwarePaginator;

    public function paraExportar(FiltrosTabla $filtros): Collection;

    public function faltasDelMes(Registro $registro): int;
}
