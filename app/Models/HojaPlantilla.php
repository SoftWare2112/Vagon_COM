<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HojaPlantilla extends Model
{
    protected $fillable = ['clave', 'nombre', 'descripcion', 'definicion', 'sistema', 'creado_por'];

    protected function casts(): array
    {
        return ['definicion' => 'array', 'sistema' => 'boolean'];
    }
}
