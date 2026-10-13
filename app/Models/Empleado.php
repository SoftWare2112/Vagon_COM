<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Support\Texto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Datos estables de la persona. No se borra: se borra/restaura su Registro de cada quincena. */
class Empleado extends Model
{
    use Auditable;

    protected $fillable = ['numero_empleado', 'nombre_completo', 'fecha_ingreso', 'origen'];

    protected function casts(): array
    {
        return ['fecha_ingreso' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $e) {
            $e->nombre_normalizado = Texto::normalizar($e->nombre_completo);
        });
    }

    public function registros(): HasMany
    {
        return $this->hasMany(Registro::class);
    }
}
