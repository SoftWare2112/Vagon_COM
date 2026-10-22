<?php

namespace App\Models;

use App\Support\Texto;
use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    protected $fillable = ['nombre'];

    /** Evita "Asesor" vs "ASESOR " duplicados en el catálogo. */
    public static function resolver(?string $nombre): ?self
    {
        $limpio = Texto::limpiar($nombre);
        if ($limpio === '') {
            return null;
        }
        $existente = static::all()->first(fn (self $p) => Texto::normalizar($p->nombre) === Texto::normalizar($limpio));

        return $existente ?? static::create(['nombre' => self::formatear($limpio)]);
    }

    /** "SUPERVISOR MSI" -> "Supervisor MSI", "JEFE DE AREA" -> "Jefe de Area" (siglas cortas se conservan). */
    public static function formatear(string $nombre): string
    {
        $palabras = explode(' ', $nombre);
        foreach ($palabras as $i => $p) {
            $minus = mb_strtolower($p);
            $palabras[$i] = match (true) {
                $i > 0 && in_array($minus, ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en'], true) => $minus,
                mb_strlen($p) <= 3 && $p === mb_strtoupper($p) && preg_match('/\p{L}/u', $p) => $p,   // MSI, RH, TI
                default => mb_convert_case($minus, MB_CASE_TITLE),
            };
        }

        return implode(' ', $palabras);
    }
}
