<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Periodo extends Model
{
    protected $fillable = ['fecha_inicio', 'fecha_fin', 'etiqueta', 'ejercicio', 'numero', 'tipo', 'formato'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_fin' => 'date', 'ejercicio' => 'integer', 'numero' => 'integer', 'formato' => 'array'];
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

    /** "Periodo 17 · 1 de octubre al 13 de octubre de 2026" (o solo la etiqueta si no trae número). */
    public function nombre(): string
    {
        $etiqueta = $this->etiqueta;
        if (! preg_match('/\d{4}/', $etiqueta)) {
            $etiqueta .= ' '.$this->fecha_fin->format('Y');
        }

        return $this->numero ? "Periodo {$this->numero} · {$etiqueta}" : $etiqueta;
    }

    public static function masReciente(): ?self
    {
        return static::orderByDesc('fecha_inicio')->first();
    }
}
