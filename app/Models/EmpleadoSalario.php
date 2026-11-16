<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v11: historial de sueldos (cada cambio recalcula el SDI para el IMSS). */
class EmpleadoSalario extends Model
{
    protected $fillable = ['empleado_id', 'salario_mensual', 'desde', 'motivo', 'creado_por'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'salario_mensual' => 'float'];
    }
}
