<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permiso;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permiso as PermisoModel;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Usuarios > Roles y permisos: el Administrador ajusta qué puede hacer cada rol. */
class RolController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'permisos' => array_map(fn (Permiso $p) => ['clave' => $p->value, 'descripcion' => $p->descripcion()], Permiso::cases()),
            'roles' => Rol::with('permisos:id,nombre')->orderBy('id')->get()->map(fn (Rol $r) => [
                'id' => $r->id, 'nombre' => $r->nombre, 'descripcion' => $r->descripcion,
                'permisos' => $r->permisos->pluck('nombre'),
            ]),
        ]);
    }

    public function actualizarPermisos(Request $request, Rol $rol): JsonResponse
    {
        $datos = $request->validate([
            'permisos' => ['present', 'array'],
            'permisos.*' => [Rule::in(array_map(fn (Permiso $p) => $p->value, Permiso::cases()))],
        ]);

        // Candado: el Administrador nunca puede quitarse la gestión de usuarios (se quedaría sin acceso)
        if ($rol->nombre === 'admin_rh') {
            $datos['permisos'] = array_values(array_unique([...$datos['permisos'], Permiso::UsuariosGestionar->value]));
        }

        $antes = $rol->permisos()->pluck('nombre')->all();
        $rol->permisos()->sync(PermisoModel::whereIn('nombre', $datos['permisos'])->pluck('id'));
        AuditLog::registrar('EDITAR', 'Rol', $rol->id, ['permisos' => $antes], ['permisos' => $datos['permisos']]);

        return $this->index();
    }
}
