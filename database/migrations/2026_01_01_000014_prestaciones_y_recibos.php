<?php

use App\Support\Nomina\ValoresIniciales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v13 — Prestaciones y recibos:
 *  - `vacaciones`: días de vacaciones tomados, cargados a un año de servicio (saldo por aniversario, art. 76 LFT).
 *  - Nómina especial de aguinaldo: `periodos_nomina.clase` (ordinaria | aguinaldo) con tipo «especial».
 *  - `empresas.metodo_isr_extraordinario`: art. 174 RLISR (por defecto) o art. 96 LISR para el ISR del aguinaldo.
 *  - `ajustes_anuales`: cálculo anual del ISR (art. 97) por empleado y ejercicio, y en qué periodo se aplicó.
 *  - `recibo_envios`: bitácora de recibos enviados por correo.
 *  - Conceptos P006 Prima vacacional, P007 Aguinaldo, D003 ISR a cargo y O001 ISR a favor del ajuste anual.
 *  - Tabla fiscal `isr_anual` 2026 (art. 152 LISR, Anexo 8 RMF 2026).
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conceptos_nomina', fn (Blueprint $t) => $t->string('metodo', 20)->change());   // «prima_vacacional»
        Schema::table('empresas', function (Blueprint $t) {
            if (! Schema::hasColumn('empresas', 'metodo_isr_extraordinario')) {
                $t->string('metodo_isr_extraordinario', 10)->default('art174');
            }
        });
        Schema::table('periodos_nomina', function (Blueprint $t) {
            if (! Schema::hasColumn('periodos_nomina', 'clase')) {
                $t->string('clase', 12)->default('ordinaria');   // ordinaria | aguinaldo
            }
        });

        if (! Schema::hasTable('vacaciones')) {
            Schema::create('vacaciones', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
                $t->date('desde');
                $t->date('hasta');
                $t->unsignedTinyInteger('dias');                 // días hábiles que se descuentan del saldo
                $t->unsignedTinyInteger('anio_servicio');        // 1 = derecho ganado en el primer aniversario
                $t->string('notas', 200)->nullable();
                $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['empleado_id', 'desde']);
            });
        }

        if (! Schema::hasTable('ajustes_anuales')) {
            Schema::create('ajustes_anuales', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
                $t->unsignedSmallInteger('ejercicio');
                $t->boolean('declara_por_su_cuenta')->default(false);
                $t->boolean('aplica')->default(false);
                $t->string('motivo', 150)->nullable();
                $t->decimal('diferencia', 12, 2)->default(0);
                $t->string('resultado', 10)->default('cero');   // a_cargo | a_favor | cero
                $t->json('datos');
                $t->string('estado', 10)->default('calculado');  // calculado | aplicado
                $t->foreignId('periodo_aplicacion_id')->nullable()->constrained('periodos_nomina')->nullOnDelete();
                $t->timestamps();
                $t->unique(['empleado_id', 'ejercicio']);
            });
        }

        if (! Schema::hasTable('recibo_envios')) {
            Schema::create('recibo_envios', function (Blueprint $t) {
                $t->id();
                $t->foreignId('recibo_id')->constrained('nomina_recibos')->cascadeOnDelete();
                $t->string('email', 150);
                $t->string('estado', 10);                        // enviado | error
                $t->string('error', 300)->nullable();
                $t->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['recibo_id', 'created_at']);
            });
        }

        // Conceptos nuevos (sin pisar los existentes) y tarifa anual
        $ahora = now();
        $nuevos = array_filter(ValoresIniciales::conceptos(), fn ($c) => in_array($c['clave'], ['P006', 'P007', 'D003', 'O001'], true));
        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            foreach ($nuevos as $c) {
                DB::table('conceptos_nomina')->insertOrIgnore([
                    'empresa_id' => $empresaId, 'clave' => $c['clave'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'], 'clave_sat' => $c['clave_sat'],
                    'metodo' => $c['metodo'], 'monto' => $c['monto'], 'porcentaje' => $c['porcentaje'], 'regla' => json_encode((object) $c['regla']),
                    'exencion' => json_encode($c['exencion']), 'sistema' => true, 'activo' => true, 'orden' => $c['orden'], 'descripcion' => $c['descripcion'],
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
        foreach (ValoresIniciales::tablasFiscales() as [$tipo, $desde, $datos]) {
            if ($tipo === 'isr_anual') {
                DB::table('tablas_fiscales')->insertOrIgnore(['tipo' => $tipo, 'vigente_desde' => $desde, 'datos' => json_encode($datos),
                    'fuente' => 'Anexo 8 RMF 2026 (DOF 28/12/2025)', 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recibo_envios');
        Schema::dropIfExists('ajustes_anuales');
        Schema::dropIfExists('vacaciones');
        Schema::table('periodos_nomina', fn (Blueprint $t) => $t->dropColumn('clase'));
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('metodo_isr_extraordinario'));
        DB::table('conceptos_nomina')->whereIn('clave', ['P006', 'P007', 'D003', 'O001'])->delete();
        DB::table('tablas_fiscales')->where('tipo', 'isr_anual')->delete();
    }
};
