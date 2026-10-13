<?php

namespace App\Models;

use App\Support\Texto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Catálogo de columnas del reporte (orden, tipo, alias). */
class Columna extends Model
{
    protected $fillable = ['clave', 'etiqueta', 'tipo', 'origen', 'orden', 'alias', 'obligatoria', 'editable', 'visible', 'activa'];

    protected function casts(): array
    {
        return [
            'alias' => 'array', 'obligatoria' => 'boolean', 'editable' => 'boolean',
            'visible' => 'boolean', 'activa' => 'boolean', 'orden' => 'integer',
        ];
    }

    public function scopeActivas(Builder $q): Builder
    {
        return $q->where('activa', true)->orderBy('orden');
    }

    /** Catálogo para el ETL (arreglos simples). */
    public static function paraEtl(): array
    {
        return static::activas()->get()->map(fn (self $c) => [
            'clave' => $c->clave, 'etiqueta' => $c->etiqueta, 'tipo' => $c->tipo, 'origen' => $c->origen,
            'alias' => $c->alias ?? [], 'obligatoria' => $c->obligatoria, 'activa' => true,
        ])->all();
    }

    /** Clave estable a partir del nombre: "Bono de transporte" -> "bono_de_transporte". */
    public static function claveDesde(string $etiqueta): string
    {
        $base = Str::limit(str_replace(' ', '_', preg_replace('/[^a-z0-9 ]/', '', Texto::normalizar($etiqueta))), 50, '');
        $clave = $base ?: 'columna';
        for ($i = 2; static::where('clave', $clave)->exists(); $i++) {
            $clave = $base.'_'.$i;
        }

        return $clave;
    }

    /**
     * Inserta en la posición $orden recorriendo las siguientes. Los DATOS no se mueven:
     * se guardan por clave, no por posición.
     */
    public static function insertarEn(int $orden, array $atributos): self
    {
        return DB::transaction(function () use ($orden, $atributos) {
            static::where('orden', '>=', $orden)->lockForUpdate()->increment('orden');

            return static::create([...$atributos, 'orden' => $orden]);
        });
    }
}
