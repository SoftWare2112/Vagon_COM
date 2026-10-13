<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'rol' => $this->rol?->nombre,
            'rol_id' => $this->rol_id,
            'permisos' => $this->permisos(),
            'is_active' => $this->is_active,
            'bloqueado' => $this->estaBloqueado(),
            'locked_until' => $this->locked_until?->format('d/m/Y H:i'),
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->format('d/m/Y H:i'),
        ];
    }
}
