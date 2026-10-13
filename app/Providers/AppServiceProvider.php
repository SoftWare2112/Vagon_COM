<?php

namespace App\Providers;

use App\Models\User;
use App\Repositories\Contracts\RegistroRepository;
use App\Repositories\Eloquent\EloquentRegistroRepository;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Inyección de dependencias: interfaz -> implementación (principio D de SOLID). */
    public array $bindings = [
        RegistroRepository::class => EloquentRegistroRepository::class,
    ];

    public function boot(): void
    {
        // RBAC: cualquier ->can('registros.editar') se resuelve contra los permisos del rol.
        // Deny by default: si el permiso no está asignado, se niega.
        Gate::before(fn (User $user, string $ability) => $user->tienePermiso($ability) ?: null);

        // El enlace de recuperación apunta al frontend React, no a Laravel
        ResetPassword::createUrlUsing(fn (User $user, string $token) => config('app.frontend_url').'/restablecer?token='.$token.'&email='.urlencode($user->email));

        // Límites anti fuerza bruta / abuso
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(20)->by($r->ip()),
            Limit::perMinute(10)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
        ]);
        RateLimiter::for('recuperacion', fn (Request $r) => Limit::perHour(5)->by($r->ip()));
        RateLimiter::for('cargas', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}
