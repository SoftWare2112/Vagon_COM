<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** v9: un trabajo del usuario con una o más hojas (pestañas). Es privado de su dueño. */
class Libro extends Model
{
    use SoftDeletes;

    protected $fillable = ['nombre', 'user_id', 'origen', 'es_personal', 'carga_id'];

    protected function casts(): array
    {
        return ['es_personal' => 'boolean'];
    }

    public function hojas(): HasMany
    {
        return $this->hasMany(Hoja::class)->orderBy('orden');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function carga(): BelongsTo
    {
        return $this->belongsTo(Carga::class);
    }

    /** Lo que este usuario puede ver: lo suyo, o todo si tiene hojas.ver_todas. */
    public function scopeVisiblesPara(Builder $q, User $u): Builder
    {
        return $u->tienePermiso('hojas.ver_todas') ? $q : $q->where('user_id', $u->id);
    }
}
