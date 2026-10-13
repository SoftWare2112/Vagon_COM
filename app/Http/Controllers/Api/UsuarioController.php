<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UsuarioRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Solo Administrador (RH). */
class UsuarioController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::with('rol')->orderBy('name')->get());
    }

    /** Alta: se crea con contraseña aleatoria y se envía enlace para que la persona defina la suya. */
    public function store(UsuarioRequest $request): JsonResponse
    {
        $user = User::create([
            ...$request->validated(),
            'email' => mb_strtolower($request->input('email')),
            'password' => Str::password(32),
            'must_change_password' => true,
        ]);
        Password::sendResetLink(['email' => $user->email]);

        return (new UserResource($user->load('rol')))->additional(['message' => 'Usuario creado. Se envió un correo para que defina su contraseña.'])->response()->setStatusCode(201);
    }

    public function update(UsuarioRequest $request, User $usuario): UserResource
    {
        $this->noAutoModificar($request, $usuario, ['rol_id', 'is_active']);
        $usuario->update($request->validated());
        if ($usuario->wasChanged('is_active') && ! $usuario->is_active) {
            DB::table('sessions')->where('user_id', $usuario->id)->delete();   // expulsa sesiones activas
        }

        return new UserResource($usuario->load('rol'));
    }

    public function desbloquear(User $usuario): JsonResponse
    {
        $usuario->forceFill(['failed_attempts' => 0, 'locked_until' => null])->saveQuietly();
        AuditLog::registrar('DESBLOQUEO', 'User', $usuario->id);

        return response()->json(['message' => 'Cuenta desbloqueada.']);
    }

    /** No se borran usuarios (romperían la auditoría): se desactivan. */
    public function destroy(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->id === $request->user()->id, 422, 'No puedes desactivar tu propia cuenta.');
        $usuario->update(['is_active' => false]);
        DB::table('sessions')->where('user_id', $usuario->id)->delete();

        return response()->json(['message' => 'Usuario desactivado.']);
    }

    private function noAutoModificar(Request $request, User $usuario, array $campos): void
    {
        abort_if($usuario->id === $request->user()->id && $request->hasAny($campos), 422, 'No puedes cambiar tu propio rol ni desactivarte.');
    }
}
