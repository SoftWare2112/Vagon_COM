<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** v13: días de vacaciones que tomó un empleado, cargados a un año de servicio. Su prima se paga en el periodo donde inician. */
class Vacacion extends Model
{
    protected $table = 'vacaciones';

    protected $fillable = ['empleado_id', 'desde', 'hasta', 'dias', 'anio_servicio', 'notas', 'creado_por'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'dias' => 'integer', 'anio_servicio' => 'integer'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
