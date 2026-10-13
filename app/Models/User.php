<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Auditable, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'rol_id', 'is_active', 'must_change_password'];

    protected $hidden = ['password', 'remember_token'];

    /** Nunca auditar el hash de la contraseña. */
    protected array $auditExclude = ['password', 'remember_token', 'last_login_at', 'failed_attempts'];

    private ?array $permisosCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    /** @return list<string> */
    public function permisos(): array
    {
        return $this->permisosCache ??= $this->rol?->permisos()->pluck('nombre')->all() ?? [];
    }

    public function tienePermiso(string $permiso): bool
    {
        return $this->is_active && in_array($permiso, $this->permisos(), true);
    }

    public function estaBloqueado(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}
