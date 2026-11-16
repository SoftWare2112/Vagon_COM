<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * v11 — Deja la plataforma lista para datos reales: borra los datos FICTICIOS de las versiones anteriores.
 *
 *   php artisan reportes:limpiar-datos-prueba                      (pregunta antes de borrar)
 *   php artisan reportes:limpiar-datos-prueba --publicaciones --usuarios-demo --forzar
 *
 * Siempre borra: incidencias y quincenas cargadas por Excel, historial de cargas, exportaciones,
 * hojas de cálculo y libros, y los empleados SIN RFC (los que vinieron de los Excel de prueba).
 * Con --publicaciones: todas las publicaciones y sus archivos.
 * Con --usuarios-demo: las cuentas @demo.test (solo si queda otro administrador activo).
 * NO toca: empleados capturados en la v11 (tienen RFC), empresa, conceptos, tablas fiscales ni usuarios reales.
 */
class LimpiarDatosPrueba extends Command
{
    protected $signature = 'reportes:limpiar-datos-prueba {--publicaciones : También borra todas las publicaciones} {--usuarios-demo : También borra las cuentas @demo.test} {--forzar : No preguntar}';

    protected $description = 'Borra los datos ficticios de las versiones anteriores (incidencias, cargas, hojas, empleados sin RFC)';

    /** Tablas de los módulos retirados, en orden para respetar las llaves foráneas. */
    private const TABLAS = [
        'registro_domingos', 'registros', 'carga_mensajes', 'carga_filas', 'informes_exportados',
        'hoja_imagenes', 'hoja_combinadas', 'hoja_celdas', 'hoja_estilos', 'hoja_filas', 'hoja_columnas', 'hojas', 'publicacion_versiones', 'libros',
        'cargas', 'periodos',
    ];

    public function handle(): int
    {
        $conteo = [];
        foreach (self::TABLAS as $t) {
            if (Schema::hasTable($t)) {
                $conteo[$t] = DB::table($t)->count();
            }
        }
        $empleados = DB::table('empleados')->whereNull('rfc')->count();
        $this->info('Se borrará:');
        $this->table(['Datos', 'Registros'], [
            ['Incidencias (registros de quincenas)', $conteo['registros'] ?? 0],
            ['Cargas de Excel e historial', $conteo['cargas'] ?? 0],
            ['Quincenas cargadas (periodos antiguos)', $conteo['periodos'] ?? 0],
            ['Libros y hojas de cálculo', ($conteo['libros'] ?? 0).' libros / '.($conteo['hojas'] ?? 0).' hojas'],
            ['Exportaciones', $conteo['informes_exportados'] ?? 0],
            ['Empleados sin RFC (de los Excel de prueba)', $empleados],
            ['Publicaciones', $this->option('publicaciones') ? DB::table('publicaciones')->count() : 'se conservan'],
            ['Cuentas @demo.test', $this->option('usuarios-demo') ? User::where('email', 'like', '%@demo.test')->count() : 'se conservan'],
        ]);
        if (! $this->option('forzar') && ! $this->confirm('Esto NO se puede deshacer. ¿Continuar?', false)) {
            $this->warn('Cancelado. No se borró nada.');

            return self::FAILURE;
        }

        DB::transaction(function () use (&$empleados) {
            foreach (self::TABLAS as $t) {
                if (Schema::hasTable($t)) {
                    DB::table($t)->delete();
                }
            }
            if (Schema::hasTable('publicaciones') && Schema::hasColumn('publicaciones', 'libro_id')) {
                DB::table('publicaciones')->where('tipo', 'trabajo')->delete();   // dependían de las hojas retiradas
            }
            $empleados = DB::table('empleados')->whereNull('rfc')->delete();
            if ($this->option('publicaciones')) {
                DB::table('publicaciones')->delete();   // adjuntos, lecturas y favoritas se borran en cascada
            }
        });

        $disco = Storage::disk(config('reportes.etl.disco', 'local'));
        foreach (['cargas', 'exportaciones', 'hojas', ...($this->option('publicaciones') ? ['publicaciones'] : [])] as $carpeta) {
            $disco->deleteDirectory($carpeta);
        }

        $demo = 0;
        if ($this->option('usuarios-demo')) {
            $demo = $this->borrarDemo();
        }

        AuditLog::registrar('LIMPIAR_DATOS_PRUEBA', 'Sistema', null, null, ['empleados' => $empleados, 'tablas' => self::TABLAS,
            'publicaciones' => (bool) $this->option('publicaciones'), 'usuarios_demo' => $demo]);
        $this->info("Listo. Se borraron {$empleados} empleado(s) sin RFC".($demo ? " y {$demo} cuenta(s) de demostración" : '').'.');
        $this->line('Captura a tus empleados reales en la plataforma: Empleados → Alta de empleado.');

        return self::SUCCESS;
    }

    private function borrarDemo(): int
    {
        $demo = User::where('email', 'like', '%@demo.test')->get();
        $quedan = User::where('email', 'not like', '%@demo.test')->where('is_active', true)->get()->filter(fn (User $u) => $u->tienePermiso('usuarios.gestionar'));
        if ($quedan->isEmpty()) {
            $this->warn('No se borraron las cuentas @demo.test: no hay otro administrador activo. Crea uno con: php artisan reportes:usuario tu@correo.com --rol=admin_rh');

            return 0;
        }
        $borrados = 0;
        foreach ($demo as $u) {
            if (DB::table('publicaciones')->where('user_id', $u->id)->exists()) {
                $this->warn("{$u->email} tiene publicaciones; se desactiva en lugar de borrarse (usa --publicaciones para borrarlas).");
                $u->update(['is_active' => false]);

                continue;
            }
            DB::table('audit_logs')->where('user_id', $u->id)->update(['user_id' => null]);
            $u->delete();
            $borrados++;
        }

        return $borrados;
    }
}
