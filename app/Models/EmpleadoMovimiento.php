<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v12: trayectoria del trabajador (alta, baja, reingreso, cambio de salario, de contrato o de puesto). */
class EmpleadoMovimiento extends Model
{
    public const TIPOS = ['alta' => 'Alta', 'baja' => 'Baja', 'reingreso' => 'Reingreso', 'salario' => 'Cambio de salario',
        'contrato' => 'Contrato', 'puesto' => 'Cambio de puesto'];

    protected $fillable = ['empleado_id', 'tipo', 'fecha', 'descripcion', 'datos', 'creado_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'datos' => 'array'];
    }

    public function autor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public static function registrar(int $empleadoId, string $tipo, string $fecha, string $descripcion, ?array $datos = null): self
    {
        return static::create(['empleado_id' => $empleadoId, 'tipo' => $tipo, 'fecha' => $fecha, 'descripcion' => $descripcion,
            'datos' => $datos, 'creado_por' => auth()->id()]);
    }
}
