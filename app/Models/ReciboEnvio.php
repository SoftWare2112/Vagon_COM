<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v13: bitácora de recibos enviados por correo (a quién, cuándo, si falló). */
class ReciboEnvio extends Model
{
    protected $table = 'recibo_envios';

    protected $fillable = ['recibo_id', 'email', 'estado', 'error', 'enviado_por'];
}
