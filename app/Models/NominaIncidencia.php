<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v12: incidencias capturadas de un empleado en un periodo (faltas, retardos, domingos, montos de conceptos capturables). */
class NominaIncidencia extends Model
{
    protected $table = 'nomina_incidencias';

    protected $fillable = ['periodo_id', 'empleado_id', 'faltas', 'retardos', 'domingos', 'capturados', 'notas', 'capturado_por'];

    protected function casts(): array
    {
        return ['capturados' => 'array', 'faltas' => 'integer', 'retardos' => 'integer', 'domingos' => 'integer'];
    }
}
