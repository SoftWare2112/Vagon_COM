<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** v8: una hoja de cálculo. v9: es una pestaña de un Libro (el dueño es el del libro). */
class Hoja extends Model
{
    use SoftDeletes;

    protected $fillable = ['libro_id', 'orden', 'nombre', 'user_id', 'plantilla_id', 'version', 'editado_por'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function libro(): BelongsTo
    {
        return $this->belongsTo(Libro::class);
    }

    public function combinadas(): HasMany
    {
        return $this->hasMany(HojaCombinada::class);
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(HojaImagen::class);
    }

    public function filas(): HasMany
    {
        return $this->hasMany(HojaFila::class)->orderBy('orden');
    }

    public function columnas(): HasMany
    {
        return $this->hasMany(HojaColumna::class)->orderBy('orden');
    }

    public function celdas(): HasMany
    {
        return $this->hasMany(HojaCelda::class);
    }

    public function estilos(): HasMany
    {
        return $this->hasMany(HojaEstilo::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
