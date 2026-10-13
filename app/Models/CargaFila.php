<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargaFila extends Model
{
    public $timestamps = false;

    protected $fillable = ['carga_id', 'fila_excel', 'nombre_normalizado', 'fecha_ingreso', 'datos'];

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }
}
