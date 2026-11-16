<?php

namespace Database\Seeders;

use App\Models\Puesto;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * v11: roles y permisos, puestos base y el contador de números de empleado.
 * La empresa, el catálogo de conceptos y las tablas fiscales los crea la migración 000012.
 * NO se crean empleados ni datos ficticios: el expediente se captura a mano en la plataforma.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesPermisosSeeder::class);

        foreach (config('reportes.puestos_base') as $p) {
            Puesto::firstOrCreate(['nombre' => $p]);
        }
        DB::table('secuencias')->insertOrIgnore(['clave' => 'numero_empleado', 'valor' => 0]);

        // Usuarios SOLO para las pruebas automáticas. En la instalación real el administrador se crea con:
        //   php artisan reportes:usuario correo@empresa.com --rol=admin_rh
        if (app()->environment('testing')) {
            foreach (['admin_rh' => 'admin@demo.test', 'supervisor' => 'supervisor@demo.test', 'lector' => 'lector@demo.test'] as $rol => $email) {
                User::firstOrCreate(['email' => $email], [
                    'name' => ucfirst(str_replace('_', ' ', $rol)),
                    'password' => 'Demo12345!',
                    'rol_id' => Rol::where('nombre', $rol)->value('id'),
                    'must_change_password' => false,
                    'email_verified_at' => now(),
                ]);
            }
        }
    }
}
