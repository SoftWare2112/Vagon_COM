<?php

namespace App\Models;

use App\Support\Publicaciones\Audiencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** v10: una publicación (texto, documentos, imágenes, enlace a video o trabajo de la plataforma). */
class Publicacion extends Model
{
    use SoftDeletes;

    /** v11: 'trabajo' (publicar un libro de hojas de cálculo) se retiró con las hojas. */
    public const TIPOS = ['texto', 'documentos', 'imagenes', 'video'];

    public const ESTADOS = ['borrador', 'publicada', 'archivada'];

    protected $table = 'publicaciones';

    protected $fillable = [
        'user_id', 'tipo', 'titulo', 'cuerpo', 'categoria', 'etiquetas', 'audiencia', 'audiencia_ids', 'estado', 'fijada', 'oficial',
        'permite_descarga', 'permite_copia', 'video_url', 'video_proveedor', 'publicada_at', 'caduca_at',
    ];

    protected function casts(): array
    {
        return [
            'etiquetas' => 'array', 'audiencia_ids' => 'array', 'fijada' => 'boolean', 'oficial' => 'boolean',
            'permite_descarga' => 'boolean', 'permite_copia' => 'boolean', 'publicada_at' => 'datetime', 'caduca_at' => 'datetime',
        ];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function adjuntos(): HasMany
    {
        return $this->hasMany(PublicacionAdjunto::class)->orderBy('orden')->orderBy('id');
    }

    public function lectores(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'publicacion_lecturas')->withPivot('leida_at');
    }

    public function esModerador(User $u): bool
    {
        return $u->tienePermiso('publicaciones.moderar');
    }

    /** Puede editarla: su autor (con publicaciones.crear) o un moderador. */
    public function editablePor(User $u): bool
    {
        return ($this->user_id === $u->id && $u->tienePermiso('publicaciones.crear')) || $this->esModerador($u);
    }

    /**
     * Lo que este usuario puede ver: lo publicado (vigente) dirigido a él o a su rol, y además lo suyo
     * (borradores y archivadas). Un moderador ve todo.
     */
    public function scopeVisiblesPara(Builder $q, User $u): Builder
    {
        if ($u->tienePermiso('publicaciones.moderar')) {
            return $q;
        }
        $rol = $u->rol?->nombre;

        return $q->where(fn ($q) => $q
            ->where('user_id', $u->id)
            ->orWhere(fn ($q) => $q
                ->where('estado', 'publicada')
                ->where(fn ($q) => $q->whereNull('caduca_at')->orWhere('caduca_at', '>', now()))
                ->where(fn ($q) => $q
                    ->where('audiencia', Audiencia::TODOS)
                    ->orWhere(fn ($q) => $q->where('audiencia', Audiencia::ROLES)->whereJsonContains('audiencia_ids', $rol))
                    ->orWhere(fn ($q) => $q->where('audiencia', Audiencia::USUARIOS)->whereJsonContains('audiencia_ids', $u->id)))));
    }
}
