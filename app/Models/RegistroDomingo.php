<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroDomingo extends Model
{
    public $timestamps = false;

    protected $fillable = ['registro_id', 'fecha', 'etiqueta', 'cantidad'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'cantidad' => 'decimal:2'];
    }
}
