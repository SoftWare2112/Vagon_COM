<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HojaColumna extends Model
{
    public $timestamps = false;

    protected $fillable = ['hoja_id', 'orden', 'ancho'];
}
