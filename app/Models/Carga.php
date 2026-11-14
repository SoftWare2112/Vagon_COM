<?php

namespace App\Models;

use App\Enums\EstatusCarga;
use App\Enums\ModoSincronizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Historial de importaciones. v9: tipo nomina (incidencias) o libro (cualquier Excel como hoja). */
class Carga extends Model
{
    use SoftDeletes;

    public const NOMINA = 'nomina';

    public const LIBRO = 'libro';

    protected $fillable = [
        'tipo', 'user_id', 'periodo_id', 'libro_id', 'nombre_original', 'tamano', 'eliminado_por', 'ruta_archivo', 'sha256', 'contenido_hash',
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

    public function libro(): BelongsTo
    {
        return $this->belongsTo(Libro::class)->withTrashed();
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
