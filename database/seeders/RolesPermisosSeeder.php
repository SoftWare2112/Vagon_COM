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
        foreach (Permiso::cases() as $p) {
            PermisoModel::firstOrCreate(['nombre' => $p->value]);
        }

        $descripciones = [
            'admin_rh' => 'Administrador (RH): control total',
            'supervisor' => 'Supervisor: consulta, carga de archivos, exportación e histórico (solo lectura)',
            'lector' => 'Lector (Jefe / Nóminas): consulta y exportación',
        ];

        foreach (Permiso::porRol() as $rol => $permisos) {
            $r = Rol::firstOrCreate(['nombre' => $rol], ['descripcion' => $descripciones[$rol]]);
            $r->permisos()->sync(PermisoModel::whereIn('nombre', $permisos)->pluck('id'));
        }
    }
}
