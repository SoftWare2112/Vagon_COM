<?php

namespace Database\Seeders;

use App\Models\Ajuste;
use App\Models\Columna;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\User;
use App\Support\CatalogoColumnas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesPermisosSeeder::class);

        foreach (config('reportes.puestos_base') as $p) {
            Puesto::firstOrCreate(['nombre' => $p]);
        }

        // Catálogo de columnas en el orden del Excel final (RH puede agregar más desde la web)
        foreach (CatalogoColumnas::SISTEMA as $i => $c) {
            Columna::updateOrCreate(['clave' => $c['clave']], [
                'etiqueta' => $c['etiqueta'], 'tipo' => $c['tipo'], 'origen' => $c['origen'], 'orden' => $i + 1,
                'alias' => $c['alias'], 'obligatoria' => $c['obligatoria'] ?? false, 'editable' => $c['editable'] ?? true,
            ]);
        }

        DB::table('secuencias')->insertOrIgnore(['clave' => 'numero_empleado', 'valor' => 0]);
        Ajuste::guardar('modo_etl_predeterminado', 'MANTENER');
        Ajuste::guardar('filas_esperadas', '48');

        // Usuarios de DESARROLLO (no ejecutar en producción: ahí el admin se crea con `php artisan tinker`)
        if (app()->environment('local', 'testing')) {
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
