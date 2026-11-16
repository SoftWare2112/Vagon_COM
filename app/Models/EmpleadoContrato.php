<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v11: contratos del trabajador (capacitación → prueba → temporal → indeterminado). */
class EmpleadoContrato extends Model
{
    protected $fillable = ['empleado_id', 'tipo', 'inicio', 'fin', 'notas', 'creado_por'];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fin' => 'date'];
    }
}
