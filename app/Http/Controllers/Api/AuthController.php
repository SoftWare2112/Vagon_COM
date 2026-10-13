<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\CuentaBloqueada;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * Autenticación SPA con Laravel Sanctum (cookie HttpOnly + CSRF).
 * Flujo React: GET /sanctum/csrf-cookie  ->  POST /api/auth/login
 */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $cred = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', mb_strtolower($cred['email']))->first();

        if ($user?->estaBloqueado()) {
            return response()->json([
                'message' => 'Cuenta bloqueada temporalmente por intentos fallidos hasta las '.$user->locked_until->format('H:i').'. Espera o usa "¿Olvidaste tu contraseña?".',
                'codigo' => 'CUENTA_BLOQUEADA',
            ], 423);
        }

        if (! $user || ! Hash::check($cred['password'], $user->password)) {
            $this->registrarFallo($user, $cred['email']);

            // Mismo mensaje exista o no el correo: evita enumerar usuarios
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Tu cuenta está desactivada. Contacta a RH.', 'codigo' => 'CUENTA_INACTIVA'], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();   // evita fijación de sesión

        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->saveQuietly();
        AuditLog::registrar('LOGIN', 'User', $user->id, userId: $user->id);

        return response()->json(['user' => new UserResource($user->load('rol'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('rol'));
    }

    /** Recuperación de cuenta: enlace de un solo uso por correo (expira en 60 min). */
    public function olvide(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => mb_strtolower($request->input('email'))]);

        // Respuesta idéntica exista o no la cuenta
        return response()->json(['message' => 'Si el correo está registrado, recibirás un enlace para restablecer tu contraseña.']);
    }

    public function restablecer(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', $this->reglaPassword()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'failed_attempts' => 0,
                    'locked_until' => null,          // restablecer también desbloquea
                    'must_change_password' => false,
                ])->setRememberToken(\Illuminate\Support\Str::random(60));
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();   // cierra sesiones abiertas en otros dispositivos
                event(new PasswordReset($user));
                AuditLog::registrar('PASSWORD_RESET', 'User', $user->id, userId: $user->id);
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.'])
            : response()->json(['message' => 'El enlace no es válido o ya expiró. Solicita uno nuevo.'], 422);
    }

    public function cambiarPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password_actual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:password_actual', $this->reglaPassword()],
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false])->save();

        // Cierra las demás sesiones, conserva la actual
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    private function registrarFallo(?User $user, string $email): void
    {
        AuditLog::registrar('LOGIN_FALLIDO', 'User', $user?->id, null, ['email' => mb_substr($email, 0, 150)], $user?->id);
        if (! $user) {
            return;
        }

        $max = config('reportes.seguridad.max_intentos');
        $intentos = $user->failed_attempts + 1;

        if ($intentos >= $max) {
            $hasta = now()->addMinutes(config('reportes.seguridad.minutos_bloqueo'));
            $user->forceFill(['failed_attempts' => 0, 'locked_until' => $hasta])->saveQuietly();
            AuditLog::registrar('BLOQUEO', 'User', $user->id, userId: $user->id);
            $user->notify(new CuentaBloqueada($hasta->format('H:i'), request()->ip()));   // aviso por correo
        } else {
            $user->forceFill(['failed_attempts' => $intentos])->saveQuietly();
        }
    }

    private function reglaPassword(): PasswordRule
    {
        return PasswordRule::min(10)->letters()->mixedCase()->numbers();
    }
}
