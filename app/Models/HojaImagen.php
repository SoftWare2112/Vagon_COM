<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v9: imagen anclada a una celda (logotipos, fotos). El archivo vive en el disco privado. */
class HojaImagen extends Model
{
    protected $table = 'hoja_imagenes';

    protected $fillable = ['hoja_id', 'fila_id', 'columna_id', 'desplaz_x', 'desplaz_y', 'ancho', 'alto', 'ruta', 'mime', 'nombre'];

    protected $hidden = ['ruta'];
}
