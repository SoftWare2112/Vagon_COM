<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v11: periodo de pago (semana, quincena o mes) del calendario de nómina. */
class PeriodoNomina extends Model
{
    protected $table = 'periodos_nomina';

    protected $fillable = ['empresa_id', 'tipo', 'ejercicio', 'numero', 'inicio', 'fin', 'fecha_pago', 'dias', 'fin_de_mes', 'estado',
        'calculado_at', 'autorizado_at', 'autorizado_por', 'notas'];

    /** v12: abierto (se capturan incidencias) → calculado (pre-nómina revisable) → autorizado (solo lectura). */
    public const ESTADOS = ['abierto' => 'Abierto', 'calculado' => 'Pre-nómina', 'autorizado' => 'Autorizado'];

    protected function casts(): array
    {
        return ['inicio' => 'date', 'fin' => 'date', 'fecha_pago' => 'date', 'fin_de_mes' => 'boolean', 'calculado_at' => 'datetime', 'autorizado_at' => 'datetime'];
    }

    public function recibos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NominaRecibo::class, 'periodo_id');
    }

    public function autorizador(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'autorizado_por');
    }

    public function autorizado(): bool
    {
        return $this->estado === 'autorizado';
    }

    public function etiqueta(): string
    {
        $tipo = ['semanal' => 'Semana', 'quincenal' => 'Quincena', 'mensual' => 'Mes'][$this->tipo] ?? 'Periodo';

        return "{$tipo} {$this->numero}/{$this->ejercicio} ({$this->inicio->format('d/m')} al {$this->fin->format('d/m/Y')})";
    }
}
