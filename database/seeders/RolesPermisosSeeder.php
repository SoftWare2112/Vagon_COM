<?php

namespace Database\Seeders;

use App\Enums\Permiso;
use App\Models\Permiso as PermisoModel;
use App\Models\Rol;
use Illuminate\Database\Seeder;

class RolesPermisosSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permiso::vigentes() as $p) {
            PermisoModel::updateOrCreate(['nombre' => $p->value], ['descripcion' => $p->descripcion()]);
        }

        $descripciones = [
            'admin_rh' => 'Administrador (RH): control total',
            'supervisor' => 'Supervisor: consulta de empleados y publicaciones',
            'lector' => 'Lector: consulta de empleados y publicaciones',
        ];

        foreach (Permiso::porRol() as $rol => $permisos) {
            $r = Rol::firstOrCreate(['nombre' => $rol], ['descripcion' => $descripciones[$rol]]);
            $r->permisos()->sync(PermisoModel::whereIn('nombre', $permisos)->pluck('id'));
        }
    }
}
