<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v10: archivo de una publicación (documento o imagen). El archivo vive en el disco privado. */
class PublicacionAdjunto extends Model
{
    protected $table = 'publicacion_adjuntos';

    protected $fillable = ['publicacion_id', 'nombre', 'ruta', 'miniatura', 'mime', 'tamano', 'ancho', 'alto', 'orden'];

    protected $hidden = ['ruta', 'miniatura'];

    public function esImagen(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
