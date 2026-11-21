<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * php artisan reportes:diagnostico
 * Revisa en un minuto lo que suele romper el inicio de sesión y las pruebas:
 * base de datos, migraciones, tablas de nómina, administradores, datos de prueba, sesión/Sanctum y restos de un "starter kit".
 */
class Diagnostico extends Command
{
    protected $signature = 'reportes:diagnostico';

    protected $description = 'Revisa configuración, base de datos, administradores, datos de prueba y sesión de la plataforma';

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
        foreach (['roles', 'permisos', 'empresas', 'empleados', 'conceptos_nomina', 'tablas_fiscales', 'periodos_nomina', 'nomina_recibos', 'nomina_acumulados', 'empleado_movimientos', 'vacaciones', 'ajustes_anuales', 'recibo_envios', 'publicaciones', 'sessions'] as $tabla) {
            $this->check(Schema::hasTable($tabla), "Tabla {$tabla}", 'Ejecuta: php artisan migrate:fresh --seed');
        }
        $this->check(Schema::hasColumn('users', 'rol_id'), 'users.rol_id existe', 'Falta la migración 2026_01_01_000002 (¿se copiaron las migraciones?)');
        if (Schema::hasTable('roles')) {
            $this->check(DB::table('roles')->count() === 3, 'Roles sembrados: '.DB::table('roles')->count().' de 3', 'Ejecuta: php artisan db:seed');
            $this->check(DB::table('permisos')->count() >= 7, 'Permisos sembrados: '.DB::table('permisos')->count(), 'Ejecuta: php artisan db:seed');
            $this->check(DB::table('conceptos_nomina')->count() >= 3, 'Conceptos de nómina: '.DB::table('conceptos_nomina')->count(), 'Ejecuta: php artisan migrate');
            $this->check(DB::table('tablas_fiscales')->count() >= 5, 'Tablas fiscales: '.DB::table('tablas_fiscales')->count(), 'Ejecuta: php artisan migrate');
        }

        $this->seccion('Administradores');
        $admins = User::where('is_active', true)->get()->filter(fn (User $u) => $u->tienePermiso('usuarios.gestionar'));
        $this->check($admins->isNotEmpty(), 'Administradores activos: '.($admins->pluck('email')->implode(', ') ?: 'ninguno'),
            'Crea uno con: php artisan reportes:usuario tu@correo.com --rol=admin_rh');
        $demo = User::where('email', 'like', '%@demo.test')->where('is_active', true)->count();
        $this->check($demo === 0 || app()->environment('local', 'testing'), "Cuentas @demo.test activas: {$demo}",
            'Para datos reales bórralas con: php artisan reportes:limpiar-datos-prueba --usuarios-demo');
        if (Schema::hasTable('empleados') && Schema::hasColumn('empleados', 'rfc')) {
            $ficticios = DB::table('empleados')->whereNull('rfc')->count();
            $this->check($ficticios === 0, "Empleados sin RFC (de los Excel de prueba): {$ficticios}", 'Bórralos con: php artisan reportes:limpiar-datos-prueba');
        }

        $this->seccion('Recibos en PDF y correo (v13)');
        $this->check(class_exists(\Barryvdh\DomPDF\Facade\Pdf::class), 'Librería de PDF (dompdf)', 'Ejecuta: composer require barryvdh/laravel-dompdf');
        $mailer = (string) config('mail.default');
        $this->check(! in_array($mailer, ['log', 'array'], true), "MAIL_MAILER = {$mailer}".(in_array($mailer, ['log', 'array'], true) ? ' (los correos solo se escriben en storage/logs, no se envían)' : ''),
            'Para enviar recibos configura en .env: MAIL_MAILER=smtp, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_ADDRESS');
        if (Schema::hasTable('tablas_fiscales')) {
            $this->check(DB::table('tablas_fiscales')->where('tipo', 'isr_anual')->exists(), 'Tarifa anual de ISR (cálculo anual)', 'Ejecuta: php artisan migrate');
        }

        $this->seccion('CFDI de nómina (v14, opcional)');
        foreach (['openssl' => 'sellar el XML con el CSD', 'dom' => 'armar y leer los XML', 'zip' => 'importar XML en un ZIP', 'curl' => 'conectarse al PAC'] as $ext => $para) {
            $this->check(extension_loaded($ext), "Extensión PHP {$ext} (para {$para})", "Habilita extension={$ext} en php.ini y reinicia Apache");
        }
        if (Schema::hasColumn('empresas', 'pac')) {
            $emp = \App\Models\Empresa::query()->orderBy('id')->first();
            if ($emp?->timbrado_activo) {
                $this->check((bool) $emp->csd_vigente_hasta?->isFuture(), 'CSD vigente'.($emp->csd_vigente_hasta ? ' hasta '.$emp->csd_vigente_hasta->format('d/m/Y') : ''),
                    'Carga un CSD vigente en Configuración > Timbrado (CFDI)');
                $this->check($emp->pac !== 'simulado', 'Proveedor de timbrado: '.($emp->pac === 'sw' ? 'SW Sapien ('.$emp->pac_modo.')' : 'PAC simulado (sin validez fiscal)'),
                    'Con el PAC simulado los recibos NO son CFDI reales; para timbrar de verdad contrata un PAC y cámbialo en Configuración > Timbrado');
            } else {
                $this->check(true, 'Timbrado desactivado (RH decidió no usarlo; el departamento de nóminas puede importar sus XML)');
            }
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
