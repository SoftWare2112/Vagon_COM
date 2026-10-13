<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * php artisan reportes:diagnostico
 * Revisa en un minuto lo que suele romper el inicio de sesión y las pruebas:
 * base de datos, migraciones, seeders, usuarios demo, sesión/Sanctum y restos de un "starter kit".
 */
class Diagnostico extends Command
{
    protected $signature = 'reportes:diagnostico';

    protected $description = 'Revisa configuración, base de datos, usuarios demo y sesión de la plataforma';

    private int $fallas = 0;

    public function handle(): int
    {
        $this->info('Diagnóstico de la Plataforma de Reportes');

        $this->seccion('Entorno');
        $this->check(true, 'APP_ENV = '.app()->environment().' · APP_URL = '.config('app.url'));
        $this->check(config('app.frontend_url') !== null, "config('app.frontend_url') = ".(config('app.frontend_url') ?? 'NO DEFINIDO'),
            "Agrega en config/app.php:  'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),");

        $this->seccion('Proyecto base vs. starter kit');
        $restos = array_filter([
            'Laravel Fortify' => class_exists(\Laravel\Fortify\Fortify::class),
            'Inertia' => class_exists(\Inertia\Inertia::class),
            'routes/settings.php' => file_exists(base_path('routes/settings.php')),
            'tests/Feature/Auth' => is_dir(base_path('tests/Feature/Auth')),
            'resources/js (páginas Inertia)' => is_dir(base_path('resources/js/pages')) || is_dir(base_path('resources/js/Pages')),
        ]);
        $this->check($restos === [], $restos === [] ? 'Proyecto API limpio (sin starter kit)' : 'Se detectó un starter kit: '.implode(', ', array_keys($restos)),
            'Este proyecto es una API + SPA React aparte. Crea el backend SIN starter kit: composer create-project laravel/laravel backend-app "12.*" y vuelve a copiar las carpetas de backend/.');

        $this->seccion('Base de datos');
        try {
            DB::connection()->getPdo();
            $this->check(true, 'Conexión '.config('database.default').' a "'.DB::connection()->getDatabaseName().'"');
        } catch (Throwable $e) {
            $this->check(false, 'No hay conexión a la base de datos: '.$e->getMessage(), 'Revisa DB_HOST, DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env');

            return $this->resumen();
        }
        foreach (['roles', 'permisos', 'columnas', 'empleados', 'registros', 'cargas', 'sessions'] as $tabla) {
            $this->check(Schema::hasTable($tabla), "Tabla {$tabla}", 'Ejecuta: php artisan migrate:fresh --seed');
        }
        $this->check(Schema::hasColumn('users', 'rol_id'), 'users.rol_id existe', 'Falta la migración 2026_01_01_000002 (¿se copiaron las migraciones?)');
        if (Schema::hasTable('roles')) {
            $this->check(DB::table('roles')->count() === 3, 'Roles sembrados: '.DB::table('roles')->count().' de 3', 'Ejecuta: php artisan db:seed');
            $this->check(DB::table('permisos')->count() >= 12, 'Permisos sembrados: '.DB::table('permisos')->count(), 'Ejecuta: php artisan db:seed');
            $this->check(DB::table('columnas')->count() >= 13, 'Columnas sembradas: '.DB::table('columnas')->count(), 'Ejecuta: php artisan db:seed');
        }

        $this->seccion('Usuarios demo');
        foreach (['admin@demo.test', 'supervisor@demo.test', 'lector@demo.test'] as $email) {
            $u = User::where('email', $email)->first();
            if (! $u) {
                $this->check(false, "{$email} no existe", app()->environment('local', 'testing')
                    ? 'Ejecuta: php artisan db:seed'
                    : 'El seeder solo crea usuarios demo con APP_ENV=local. Crea uno con: php artisan reportes:usuario '.$email.' --rol=admin_rh');

                continue;
            }
            $ok = Hash::check('Demo12345!', $u->password);
            $this->check($ok, "{$email}: contraseña Demo12345! ".($ok ? 'correcta' : 'NO coincide'),
                'Restablécela con: php artisan reportes:usuario '.$email.' --password=Demo12345!');
            $this->check($u->rol_id !== null, "{$email}: rol ".($u->rol?->nombre ?? 'SIN ROL'), 'Asigna rol con: php artisan reportes:usuario '.$email.' --rol=admin_rh');
            $this->check($u->is_active && ! $u->estaBloqueado(), "{$email}: ".(! $u->is_active ? 'INACTIVO' : ($u->estaBloqueado() ? 'BLOQUEADO hasta '.$u->locked_until : 'activo')),
                'Desbloquea con: php artisan reportes:usuario '.$email);
        }

        $this->seccion('Sesión y Sanctum (inicio de sesión desde React)');
        $this->check(config('session.driver') === 'database' ? Schema::hasTable('sessions') : true, 'SESSION_DRIVER = '.config('session.driver'));
        $front = parse_url((string) config('app.frontend_url'));
        $hostFront = ($front['host'] ?? '').(isset($front['port']) ? ':'.$front['port'] : '');
        $stateful = (array) config('sanctum.stateful');
        $this->check(in_array($hostFront, $stateful, true), 'SANCTUM_STATEFUL_DOMAINS incluye '.$hostFront.' → '.implode(',', $stateful),
            "Agrega {$hostFront} a SANCTUM_STATEFUL_DOMAINS en .env y ejecuta php artisan config:clear");
        $dominio = config('session.domain');
        $this->check($dominio === null || str_ends_with($front['host'] ?? '', ltrim((string) $dominio, '.')), 'SESSION_DOMAIN = '.($dominio ?? '(vacío)'),
            'Para desarrollo usa SESSION_DOMAIN=localhost (o déjalo vacío) y abre el frontend en http://localhost:5173, no en 127.0.0.1');
        $this->check(! config('session.secure') || str_starts_with((string) config('app.frontend_url'), 'https'), 'SESSION_SECURE_COOKIE = '.var_export(config('session.secure'), true),
            'Con http:// en desarrollo, SESSION_SECURE_COOKIE debe ser false (si es true el navegador no guarda la cookie).');

        $this->seccion('Rutas');
        $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri());
        $this->check($uris->contains('api/auth/login'), 'POST /api/auth/login registrada', 'bootstrap/app.php debe cargar routes/api.php (copia el de backend/)');
        $this->check($uris->contains('sanctum/csrf-cookie'), 'GET /sanctum/csrf-cookie registrada', 'Ejecuta: php artisan install:api');

        return $this->resumen();
    }

    private function seccion(string $titulo): void
    {
        $this->newLine();
        $this->line("<options=bold>{$titulo}</>");
    }

    private function check(bool $ok, string $texto, ?string $solucion = null): void
    {
        $this->line(($ok ? '  <fg=green>✔</> ' : '  <fg=red>✘</> ').$texto);
        if (! $ok) {
            $this->fallas++;
            if ($solucion) {
                $this->line("     <fg=yellow>→ {$solucion}</>");
            }
        }
    }

    private function resumen(): int
    {
        $this->newLine();
        $this->fallas === 0 ? $this->info('Todo en orden.') : $this->error("{$this->fallas} problema(s) encontrado(s). Corrige de arriba hacia abajo.");

        return $this->fallas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
