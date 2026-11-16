<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * v12: recibo de nómina de un empleado en un periodo.
 * Autorizado = fotografía congelada (solo lectura): no se puede modificar ni borrar; su huella detecta cambios.
 */
class NominaRecibo extends Model
{
    protected $table = 'nomina_recibos';

    protected $fillable = ['empresa_id', 'periodo_id', 'empleado_id', 'ejercicio', 'estado', 'numero_empleado', 'nombre', 'rfc', 'curp', 'nss', 'puesto',
        'salario_mensual', 'salario_diario', 'sdi', 'sbc', 'dias_pagados', 'faltas', 'retardos', 'domingos', 'percepciones', 'gravado', 'exento',
        'deducciones', 'isr_retenido', 'subsidio_causado', 'subsidio_aplicado', 'imss', 'neto', 'detalle', 'huella', 'uuid', 'timbrado_at'];

    protected function casts(): array
    {
        $dinero = array_fill_keys(['salario_mensual', 'salario_diario', 'sdi', 'sbc', 'percepciones', 'gravado', 'exento', 'deducciones',
            'isr_retenido', 'subsidio_causado', 'subsidio_aplicado', 'imss', 'neto'], 'float');

        return [...$dinero, 'detalle' => 'array', 'timbrado_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // Integridad del historial: un recibo autorizado no se vuelve a escribir desde la aplicación.
        static::updating(function (self $r) {
            if ($r->getOriginal('estado') === 'autorizado' && ! $r->permitirCambio) {
                throw new \LogicException('El recibo está autorizado: es de solo lectura.');
            }
        });
        static::deleting(function (self $r) {
            if ($r->estado === 'autorizado' && ! $r->permitirCambio) {
                throw new \LogicException('El recibo está autorizado: no se puede borrar.');
            }
        });
    }

    /** Solo lo activa NominaService al reabrir el último periodo sin timbrar (queda en la bitácora). */
    public bool $permitirCambio = false;

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(PeriodoNomina::class, 'periodo_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(NominaReciboLinea::class, 'recibo_id');
    }

    public function timbrado(): bool
    {
        return $this->uuid !== null;
    }
}
