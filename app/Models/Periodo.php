<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Periodo extends Model
{
    protected $fillable = ['fecha_inicio', 'fecha_fin', 'etiqueta'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_fin' => 'date'];
    }

    public function registros(): HasMany
    {
        return $this->hasMany(Registro::class);
    }

    /** Encabezados dinámicos de los domingos de esta quincena: [['fecha'=>..., 'etiqueta'=>'Domingo 16'], ...] */
    public function columnasDomingo(): array
    {
        return RegistroDomingo::query()
            ->whereIn('registro_id', Registro::withTrashed()->where('periodo_id', $this->id)->select('id'))
            ->select('fecha', 'etiqueta')->distinct()->orderBy('fecha')
            ->get()->map(fn ($d) => ['fecha' => $d->fecha->format('Y-m-d'), 'etiqueta' => $d->etiqueta])
            ->unique('fecha')->values()->all();
    }

    public static function masReciente(): ?self
    {
        return static::orderByDesc('fecha_inicio')->first();
    }
}
