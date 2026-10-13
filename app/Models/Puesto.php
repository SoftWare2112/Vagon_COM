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

        return $existente ?? static::create(['nombre' => mb_convert_case(mb_strtolower($limpio), MB_CASE_TITLE)]);
    }
}
