<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargaMensaje extends Model
{
    public $timestamps = false;

    protected $fillable = ['carga_id', 'nivel', 'fila', 'columna', 'celda', 'codigo', 'mensaje', 'valor_recibido'];
}
