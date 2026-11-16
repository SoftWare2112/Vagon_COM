<?php

use App\Enums\Permiso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v12 — Nómina por periodo (al estilo CONTPAQi Nóminas).
 *
 *  - `nomina_incidencias`: lo que se captura en el periodo por empleado (faltas, retardos, domingos, montos capturados).
 *  - `nomina_recibos`: el recibo de cada empleado en el periodo. Al AUTORIZAR se congela: guarda la "fotografía"
 *    completa del cálculo (datos fiscales, salario, percepciones, deducciones, ISR, IMSS) y su huella SHA-256.
 *  - `nomina_recibo_lineas`: cada percepción/deducción del recibo, para reportes y acumulados.
 *  - `nomina_acumulados`: acumulado anual por empleado y concepto; se alimenta en cada autorización y se puede reconstruir.
 *  - `empleado_movimientos`: trayectoria del trabajador (alta, baja, reingreso, salario, contrato).
 *  - `empresas.timbrado_activo`: el timbrado del CFDI es opcional (RH decide si se usa).
 *  - `empleados.validaciones_omitidas`: un RFC/CURP/NSS/CLABE real que no pasa el dígito verificador, aceptado con motivo.
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            if (! Schema::hasColumn('empresas', 'timbrado_activo')) {
                $t->boolean('timbrado_activo')->default(false);
            }
        });
        Schema::table('empleados', function (Blueprint $t) {
            if (! Schema::hasColumn('empleados', 'validaciones_omitidas')) {
                $t->json('validaciones_omitidas')->nullable();   // {"rfc": {"motivo": "...", "por": 1, "fecha": "..."}}
            }
        });
        Schema::table('periodos_nomina', function (Blueprint $t) {
            foreach ([
                'calculado_at' => fn () => $t->timestamp('calculado_at')->nullable(),
                'autorizado_at' => fn () => $t->timestamp('autorizado_at')->nullable(),
                'autorizado_por' => fn () => $t->foreignId('autorizado_por')->nullable()->constrained('users')->nullOnDelete(),
                'notas' => fn () => $t->string('notas', 300)->nullable(),
            ] as $c => $crear) {
                if (! Schema::hasColumn('periodos_nomina', $c)) {
                    $crear();
                }
            }
        });

        if (! Schema::hasTable('nomina_incidencias')) {
            Schema::create('nomina_incidencias', function (Blueprint $t) {
                $t->id();
                $t->foreignId('periodo_id')->constrained('periodos_nomina')->cascadeOnDelete();
                $t->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
                $t->unsignedTinyInteger('faltas')->default(0);
                $t->unsignedTinyInteger('retardos')->default(0);
                $t->unsignedTinyInteger('domingos')->default(0);
                $t->json('capturados')->nullable();     // {"P006": 350.00, "D003": 200.00}
                $t->string('notas', 200)->nullable();
                $t->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->unique(['periodo_id', 'empleado_id']);
            });
        }

        if (! Schema::hasTable('nomina_recibos')) {
            Schema::create('nomina_recibos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
                $t->foreignId('periodo_id')->constrained('periodos_nomina')->restrictOnDelete();
                $t->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
                $t->unsignedSmallInteger('ejercicio');
                $t->string('estado', 12)->default('borrador');     // borrador | autorizado
                // Fotografía de los datos del trabajador en ese periodo
                $t->string('numero_empleado', 20);
                $t->string('nombre', 200);
                $t->string('rfc', 13)->nullable();
                $t->string('curp', 18)->nullable();
                $t->string('nss', 11)->nullable();
                $t->string('puesto', 120)->nullable();
                $t->decimal('salario_mensual', 12, 2);
                $t->decimal('salario_diario', 12, 2);
                $t->decimal('sdi', 12, 2);
                $t->decimal('sbc', 12, 2);
                $t->unsignedTinyInteger('dias_pagados');
                $t->unsignedTinyInteger('faltas')->default(0);
                $t->unsignedTinyInteger('retardos')->default(0);
                $t->unsignedTinyInteger('domingos')->default(0);
                // Totales
                $t->decimal('percepciones', 12, 2);
                $t->decimal('gravado', 12, 2);
                $t->decimal('exento', 12, 2);
                $t->decimal('deducciones', 12, 2);
                $t->decimal('isr_retenido', 12, 2);
                $t->decimal('subsidio_causado', 12, 2)->default(0);
                $t->decimal('subsidio_aplicado', 12, 2)->default(0);
                $t->decimal('imss', 12, 2);
                $t->decimal('neto', 12, 2);
                $t->json('detalle');                                // el cálculo completo, tal como se autorizó
                $t->char('huella', 64)->nullable();                 // SHA-256 del detalle al autorizar
                // CFDI (si el timbrado está activo)
                $t->string('uuid', 36)->nullable()->unique();
                $t->timestamp('timbrado_at')->nullable();
                $t->timestamps();
                $t->unique(['periodo_id', 'empleado_id']);
                $t->index(['empleado_id', 'ejercicio']);
            });
        }

        if (! Schema::hasTable('nomina_recibo_lineas')) {
            Schema::create('nomina_recibo_lineas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('recibo_id')->constrained('nomina_recibos')->cascadeOnDelete();
                $t->string('tipo', 12);              // percepcion | deduccion
                $t->string('clave', 10);
                $t->string('nombre', 80);
                $t->string('clave_sat', 5)->nullable();
                $t->decimal('importe', 12, 2);
                $t->decimal('gravado', 12, 2)->default(0);
                $t->decimal('exento', 12, 2)->default(0);
                $t->index(['clave', 'recibo_id']);
            });
        }

        if (! Schema::hasTable('nomina_acumulados')) {
            Schema::create('nomina_acumulados', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
                $t->unsignedSmallInteger('ejercicio');
                $t->string('tipo', 12);
                $t->string('clave', 10);
                $t->string('nombre', 80);
                $t->decimal('importe', 14, 2)->default(0);
                $t->decimal('gravado', 14, 2)->default(0);
                $t->decimal('exento', 14, 2)->default(0);
                $t->unsignedSmallInteger('periodos')->default(0);
                $t->timestamps();
                $t->unique(['empleado_id', 'ejercicio', 'clave']);
            });
        }

        if (! Schema::hasTable('empleado_movimientos')) {
            Schema::create('empleado_movimientos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
                $t->string('tipo', 12);              // alta | baja | reingreso | salario | contrato | puesto
                $t->date('fecha');
                $t->string('descripcion', 250);
                $t->json('datos')->nullable();
                $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['empleado_id', 'fecha']);
                $t->index(['tipo', 'fecha']);
            });
            $this->trayectoriaInicial();
        }

        $this->permisos();
    }

    /** Arma la trayectoria de los empleados capturados en la v11 a partir de su alta, salarios, contratos y baja. */
    private function trayectoriaInicial(): void
    {
        $ahora = now();
        foreach (DB::table('empleados')->whereNotNull('rfc')->get() as $e) {
            $filas = [['tipo' => 'alta', 'fecha' => $e->fecha_ingreso, 'descripcion' => 'Alta en la empresa']];
            $salarios = DB::table('empleado_salarios')->where('empleado_id', $e->id)->orderBy('desde')->orderBy('id')->get();
            foreach ($salarios->slice(1) as $s) {
                $filas[] = ['tipo' => 'salario', 'fecha' => $s->desde, 'descripcion' => 'Sueldo mensual $'.number_format((float) $s->salario_mensual, 2).($s->motivo ? " · {$s->motivo}" : '')];
            }
            $contratos = DB::table('empleado_contratos')->where('empleado_id', $e->id)->orderBy('inicio')->orderBy('id')->get();
            foreach ($contratos->slice(1) as $c) {
                $filas[] = ['tipo' => 'contrato', 'fecha' => $c->inicio, 'descripcion' => 'Contrato '.$c->tipo];
            }
            if ($e->estado === 'baja' && $e->fecha_baja) {
                $filas[] = ['tipo' => 'baja', 'fecha' => $e->fecha_baja, 'descripcion' => 'Baja'.($e->motivo_baja ? ": {$e->motivo_baja}" : '')];
            }
            DB::table('empleado_movimientos')->insert(array_map(fn ($f) => [...$f, 'empleado_id' => $e->id, 'created_at' => $ahora, 'updated_at' => $ahora], $filas));
        }
    }

    private function permisos(): void
    {
        if (! DB::table('roles')->exists()) {
            return;   // instalación nueva: lo hace RolesPermisosSeeder
        }
        $nuevos = [Permiso::NominaVer, Permiso::NominaCalcular, Permiso::NominaAutorizar, Permiso::IndicadoresVer];
        foreach ($nuevos as $p) {
            DB::table('permisos')->updateOrInsert(['nombre' => $p->value], ['descripcion' => $p->descripcion()]);
        }
        foreach (Permiso::porRol() as $rol => $permisos) {
            $rolId = DB::table('roles')->where('nombre', $rol)->value('id');
            foreach ($nuevos as $p) {
                if ($rolId && in_array($p->value, $permisos, true)) {
                    DB::table('permiso_rol')->insertOrIgnore(['rol_id' => $rolId, 'permiso_id' => DB::table('permisos')->where('nombre', $p->value)->value('id')]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('empleado_movimientos');
        Schema::dropIfExists('nomina_acumulados');
        Schema::dropIfExists('nomina_recibo_lineas');
        Schema::dropIfExists('nomina_recibos');
        Schema::dropIfExists('nomina_incidencias');
        Schema::table('periodos_nomina', function (Blueprint $t) {
            $t->dropConstrainedForeignId('autorizado_por');
            $t->dropColumn(['calculado_at', 'autorizado_at', 'notas']);
        });
        Schema::table('empleados', fn (Blueprint $t) => $t->dropColumn('validaciones_omitidas'));
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn('timbrado_activo'));
        DB::table('permisos')->whereIn('nombre', ['nomina.ver', 'nomina.calcular', 'nomina.autorizar', 'indicadores.ver'])->delete();
    }
};
