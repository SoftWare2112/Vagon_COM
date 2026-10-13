<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bitácora de solo inserción. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'entidad', 'entidad_id', 'evento', 'antes', 'despues', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['antes' => 'array', 'despues' => 'array'];
    }

    public static function registrar(string $evento, string $entidad, ?int $entidadId = null, ?array $antes = null, ?array $despues = null, ?int $userId = null): self
    {
        $req = app()->runningInConsole() ? null : request();

        return static::create([
            'user_id' => $userId ?? auth()->id(),
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'evento' => $evento,
            'antes' => $antes,
            'despues' => $despues,
            'ip' => $req?->ip(),
            'user_agent' => $req ? mb_substr((string) $req->userAgent(), 0, 255) : null,
        ]);
    }

    // La auditoría no se modifica ni se borra.
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('audit_logs es de solo inserción.');
    }

    public function delete(): ?bool
    {
        throw new \LogicException('audit_logs es de solo inserción.');
    }
}
