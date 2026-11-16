<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v12: una percepción o deducción de un recibo (base de reportes y acumulados). */
class NominaReciboLinea extends Model
{
    protected $table = 'nomina_recibo_lineas';

    public $timestamps = false;

    protected $fillable = ['recibo_id', 'tipo', 'clave', 'nombre', 'clave_sat', 'importe', 'gravado', 'exento'];

    protected function casts(): array
    {
        return ['importe' => 'float', 'gravado' => 'float', 'exento' => 'float'];
    }
}
