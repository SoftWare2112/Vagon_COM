<?php

namespace App\Models;

use App\Enums\EstatusCarga;
use App\Enums\ModoSincronizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carga extends Model
{
    protected $fillable = [
        'user_id', 'periodo_id', 'nombre_original', 'ruta_archivo', 'sha256', 'contenido_hash',
        'sha256_aplicado', 'contenido_hash_aplicado', 'modo', 'estatus', 'encabezados',
        'filas_total', 'num_errores', 'num_advertencias', 'resumen', 'aplicada_at',
    ];

    protected $hidden = ['ruta_archivo'];

    protected function casts(): array
    {
        return [
            'modo' => ModoSincronizacion::class,
            'estatus' => EstatusCarga::class,
            'encabezados' => 'array',
            'resumen' => 'array',
            'aplicada_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function periodo(): BelongsTo
    {
        return $this->belongsTo(Periodo::class);
    }

    public function filas(): HasMany
    {
        return $this->hasMany(CargaFila::class)->orderBy('fila_excel');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(CargaMensaje::class);
    }
}
