<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** v13: resultado del cálculo anual de ISR (art. 97 LISR) de un empleado en un ejercicio. */
class AjusteAnualIsr extends Model
{
    protected $table = 'ajustes_anuales';

    protected $fillable = ['empresa_id', 'empleado_id', 'ejercicio', 'declara_por_su_cuenta', 'aplica', 'motivo', 'diferencia', 'resultado', 'datos',
        'estado', 'periodo_aplicacion_id'];

    protected function casts(): array
    {
        return ['declara_por_su_cuenta' => 'boolean', 'aplica' => 'boolean', 'diferencia' => 'float', 'datos' => 'array'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoNomina::class, 'periodo_aplicacion_id');
    }
}
