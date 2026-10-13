<?php

namespace App\Console\Commands;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * php artisan reportes:usuario rh@empresa.com --nombre="Ana RH" --rol=admin_rh
 * Crea o actualiza un usuario, le asigna rol, le pone contraseña y lo desbloquea.
 * Sirve para crear el primer Administrador en producción (allí no hay usuarios demo).
 */
class CrearUsuario extends Command
{
    protected $signature = 'reportes:usuario {email} {--nombre=} {--rol=} {--password= : Si se omite, se pide de forma oculta}';

    protected $description = 'Crea o restablece un usuario (rol, contraseña, desbloqueo)';

    public function handle(): int
    {
        $email = mb_strtolower($this->argument('email'));
        $usuario = User::firstOrNew(['email' => $email]);
        $nuevo = ! $usuario->exists;

        $rolNombre = $this->option('rol') ?? ($nuevo ? $this->choice('Rol', Rol::pluck('nombre')->all(), 0) : null);
        if ($rolNombre) {
            $rol = Rol::where('nombre', $rolNombre)->first();
            if (! $rol) {
                $this->error("No existe el rol '{$rolNombre}'. Roles: ".Rol::pluck('nombre')->implode(', ').'. ¿Corriste php artisan db:seed?');

                return self::FAILURE;
            }
            $usuario->rol_id = $rol->id;
        }

        $password = $this->option('password') ?? ($nuevo || $this->confirm('¿Cambiar la contraseña?', false) ? $this->secret('Contraseña (mín. 10, mayúsculas, minúsculas y números)') : null);
        if ($password !== null) {
            $v = Validator::make(['password' => $password], ['password' => [Password::min(10)->letters()->mixedCase()->numbers()]]);
            if ($v->fails()) {
                $this->error(implode(' ', $v->errors()->all()));

                return self::FAILURE;
            }
            $usuario->password = $password;          // el cast "hashed" la cifra
            $usuario->must_change_password = false;
        }

        $usuario->name = $this->option('nombre') ?? $usuario->name ?? strstr($email, '@', true);
        $usuario->is_active = true;
        $usuario->failed_attempts = 0;
        $usuario->locked_until = null;
        $usuario->email_verified_at ??= now();
        $usuario->save();

        $this->info(($nuevo ? 'Usuario creado' : 'Usuario actualizado').": {$usuario->email} · rol ".($usuario->rol?->nombre ?? 'sin rol').' · activo y desbloqueado.');

        return self::SUCCESS;
    }
}
