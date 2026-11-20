<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** v11: tarifa o valor fiscal con su fecha de vigencia (ISR, UMA, subsidio, salario mínimo, IMSS). */
class TablaFiscal extends Model
{
    use Auditable;

    public const TIPOS = [
        'uma' => 'UMA (Unidad de Medida y Actualización)', 'salario_minimo' => 'Salario mínimo', 'subsidio' => 'Subsidio para el empleo',
        'imss_obrero' => 'Cuotas obreras del IMSS', 'isr_semanal' => 'Tarifa ISR semanal', 'isr_quincenal' => 'Tarifa ISR quincenal', 'isr_mensual' => 'Tarifa ISR mensual',
        'isr_anual' => 'Tarifa ISR anual (cálculo anual, art. 152)',
    ];

    protected $table = 'tablas_fiscales';

    protected $fillable = ['tipo', 'vigente_desde', 'datos', 'fuente'];

    protected function casts(): array
    {
        return ['vigente_desde' => 'date', 'datos' => 'array'];
    }
}
