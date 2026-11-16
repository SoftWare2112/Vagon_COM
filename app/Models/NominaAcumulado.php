<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v12: acumulado anual por empleado y concepto; se alimenta al autorizar y se puede reconstruir desde los recibos. */
class NominaAcumulado extends Model
{
    protected $table = 'nomina_acumulados';

    protected $fillable = ['empresa_id', 'empleado_id', 'ejercicio', 'tipo', 'clave', 'nombre', 'importe', 'gravado', 'exento', 'periodos'];

    protected function casts(): array
    {
        return ['importe' => 'float', 'gravado' => 'float', 'exento' => 'float'];
    }
}
