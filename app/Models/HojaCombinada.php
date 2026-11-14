<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v9: rango de celdas combinadas (de la esquina fila_id/columna_id a fila_fin_id/columna_fin_id). */
class HojaCombinada extends Model
{
    public $timestamps = false;

    protected $table = 'hoja_combinadas';

    protected $fillable = ['hoja_id', 'fila_id', 'columna_id', 'fila_fin_id', 'columna_fin_id'];
}
