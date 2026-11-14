<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Combinación de formato guardada una sola vez por hoja (las celdas apuntan a ella). */
class HojaEstilo extends Model
{
    public $timestamps = false;

    protected $fillable = ['hoja_id', 'huella', 'definicion'];

    protected function casts(): array
    {
        return ['definicion' => 'array'];
    }
}
