<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Obliga a cambiar la contraseña temporal antes de usar la plataforma. */
class PasswordCambiada
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json(['message' => 'Debes cambiar tu contraseña antes de continuar.', 'codigo' => 'CAMBIAR_PASSWORD'], 403);
        }

        return $next($request);
    }
}
