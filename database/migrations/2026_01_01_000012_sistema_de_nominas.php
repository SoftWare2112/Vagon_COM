<?php

use App\Enums\Permiso;
use App\Support\Nomina\ValoresIniciales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v11 — La plataforma pasa a ser un SISTEMA DE NÓMINA.
 *
 *  - `empresas`: una sola (Vagón COM), pero cada tabla de nómina guarda su empresa para poder crecer.
 *  - `empleados`: se amplía al expediente completo (RFC, CURP, NSS, puesto, salario, contrato, jornada, banco).
 *  - `empleado_contratos` y `empleado_salarios`: historial de contratos (capacitación, prueba, temporal,
 *    indeterminado) y de cambios de sueldo.
 *  - `conceptos_nomina`: percepciones y deducciones con sus reglas (bonos, prima dominical, IMSS, ISR).
 *  - `tablas_fiscales`: tarifas ISR, UMA, subsidio, salario mínimo y cuotas IMSS POR FECHA DE VIGENCIA.
 *  - `periodos_nomina`: calendario semanal, quincenal o mensual.
 *  - Permisos nuevos y retiro de los permisos de incidencias, cargas y hojas de cálculo.
 * Las tablas de los módulos retirados NO se borran aquí (no se pierde nada al migrar);
 * `php artisan reportes:limpiar-datos-prueba` vacía los datos ficticios cuando lo decidas.
 * Idempotente: se puede volver a correr si una ejecución anterior quedó a medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('empresas')) {
            Schema::create('empresas', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 120);
                $t->string('razon_social', 200)->nullable();
                $t->string('rfc', 13)->nullable();
                $t->string('regimen_fiscal', 3)->nullable();          // catálogo SAT (601 General de Ley PM…)
                $t->string('registro_patronal', 11)->nullable();      // IMSS
                $t->string('codigo_postal', 5)->nullable();           // lugar de expedición del CFDI
                $t->string('estado', 40)->nullable();                 // para el impuesto sobre nómina estatal
                $t->string('periodicidad', 10)->default('quincenal'); // semanal | quincenal | mensual
                $t->decimal('divisor_salario_diario', 5, 2)->default(30); // salario diario = mensual / divisor
                $t->unsignedTinyInteger('dias_aguinaldo')->default(15);
                $t->decimal('prima_vacacional', 5, 2)->default(25);   // %
                $t->string('zona_salario_minimo', 15)->default('general'); // general | frontera_norte
                $t->timestamps();
            });
            DB::table('empresas')->insert(['nombre' => 'Vagón COM', 'created_at' => now(), 'updated_at' => now()]);
        }
        $empresaId = (int) DB::table('empresas')->orderBy('id')->value('id');

        Schema::table('empleados', function (Blueprint $t) {
            $nuevas = [
                'empresa_id' => fn () => $t->foreignId('empresa_id')->nullable()->after('id')->constrained('empresas')->restrictOnDelete(),
                'apellido_paterno' => fn () => $t->string('apellido_paterno', 60)->nullable()->after('nombre_completo'),
                'apellido_materno' => fn () => $t->string('apellido_materno', 60)->nullable()->after('apellido_paterno'),
                'nombres' => fn () => $t->string('nombres', 80)->nullable()->after('apellido_materno'),
                'rfc' => fn () => $t->string('rfc', 13)->nullable()->unique(),
                'curp' => fn () => $t->string('curp', 18)->nullable()->unique(),
                'nss' => fn () => $t->string('nss', 11)->nullable()->unique(),
                'codigo_postal' => fn () => $t->string('codigo_postal', 5)->nullable(),     // domicilio fiscal (CFDI 4.0)
                'email' => fn () => $t->string('email', 120)->nullable(),
                'telefono' => fn () => $t->string('telefono', 20)->nullable(),
                'puesto_id' => fn () => $t->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete(),
                'departamento' => fn () => $t->string('departamento', 80)->nullable(),
                'salario_mensual' => fn () => $t->decimal('salario_mensual', 12, 2)->nullable(),
                'periodicidad' => fn () => $t->string('periodicidad', 10)->default('quincenal'),
                'tipo_contrato' => fn () => $t->string('tipo_contrato', 15)->default('capacitacion'),
                'contrato_fin' => fn () => $t->date('contrato_fin')->nullable(),
                'jornada_horas' => fn () => $t->decimal('jornada_horas', 4, 1)->default(10),
                'dias_descanso' => fn () => $t->json('dias_descanso')->nullable(),   // 0 = domingo … 6 = sábado
                'banco' => fn () => $t->string('banco', 40)->nullable(),
                'clabe' => fn () => $t->string('clabe', 18)->nullable(),
                'estado' => fn () => $t->string('estado', 10)->default('activo'),        // activo | baja
                'fecha_baja' => fn () => $t->date('fecha_baja')->nullable(),
                'motivo_baja' => fn () => $t->string('motivo_baja', 200)->nullable(),
                'notas' => fn () => $t->text('notas')->nullable(),
            ];
            foreach ($nuevas as $columna => $crear) {
                if (! Schema::hasColumn('empleados', $columna)) {
                    $crear();
                }
            }
        });
        DB::table('empleados')->whereNull('empresa_id')->update(['empresa_id' => $empresaId]);

        if (! Schema::hasTable('empleado_contratos')) {
            Schema::create('empleado_contratos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
                $t->string('tipo', 15);            // capacitacion | prueba | temporal | indeterminado
                $t->date('inicio');
                $t->date('fin')->nullable();
                $t->string('notas', 200)->nullable();
                $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['empleado_id', 'inicio']);
            });
        }
        if (! Schema::hasTable('empleado_salarios')) {
            Schema::create('empleado_salarios', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empleado_id')->constrained('empleados')->cascadeOnDelete();
                $t->decimal('salario_mensual', 12, 2);
                $t->date('desde');
                $t->string('motivo', 200)->nullable();
                $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['empleado_id', 'desde']);
            });
        }

        if (! Schema::hasTable('conceptos_nomina')) {
            Schema::create('conceptos_nomina', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->string('clave', 10);
                $t->string('nombre', 80);
                $t->string('tipo', 12);             // percepcion | deduccion
                $t->string('clave_sat', 5)->nullable();
                $t->string('metodo', 15);           // sueldo | fijo | por_evento | captura | isr | imss
                $t->decimal('monto', 12, 2)->default(0);
                $t->decimal('porcentaje', 7, 4)->default(0);
                $t->json('regla')->nullable();      // condiciones: solo_fin_de_mes, antiguedad_meses_min, frecuencia, sin_faltas, sin_retardos
                $t->json('exencion')->nullable();   // ninguna | total | uma_por_evento | uma_tope (con número de UMA)
                $t->string('descripcion', 300)->nullable();
                $t->boolean('sistema')->default(false);   // los del sistema no se pueden borrar
                $t->boolean('activo')->default(true);
                $t->unsignedSmallInteger('orden')->default(0);
                $t->timestamps();
                $t->unique(['empresa_id', 'clave']);
            });
        }

        if (! Schema::hasTable('tablas_fiscales')) {
            Schema::create('tablas_fiscales', function (Blueprint $t) {
                $t->id();
                $t->string('tipo', 20);             // uma | salario_minimo | subsidio | imss_obrero | isr_semanal | isr_quincenal | isr_mensual
                $t->date('vigente_desde');
                $t->json('datos');
                $t->string('fuente', 200)->nullable();
                $t->timestamps();
                $t->unique(['tipo', 'vigente_desde']);
            });
        }

        if (! Schema::hasTable('periodos_nomina')) {
            Schema::create('periodos_nomina', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->string('tipo', 10);
                $t->unsignedSmallInteger('ejercicio');
                $t->unsignedSmallInteger('numero');
                $t->date('inicio');
                $t->date('fin');
                $t->date('fecha_pago');
                $t->unsignedTinyInteger('dias');
                $t->boolean('fin_de_mes')->default(false);
                $t->string('estado', 12)->default('abierto');   // abierto | calculado | autorizado | cerrado
                $t->timestamps();
                $t->unique(['empresa_id', 'tipo', 'ejercicio', 'numero']);
            });
        }

        $this->sembrar($empresaId);
        $this->permisos();
    }

    private function sembrar(int $empresaId): void
    {
        foreach (ValoresIniciales::conceptos() as $c) {
            if (DB::table('conceptos_nomina')->where(['empresa_id' => $empresaId, 'clave' => $c['clave']])->exists()) {
                continue;   // no se pisan los cambios que ya hizo la empresa
            }
            DB::table('conceptos_nomina')->insert([
                'empresa_id' => $empresaId, 'clave' => $c['clave'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'], 'clave_sat' => $c['clave_sat'],
                'metodo' => $c['metodo'], 'monto' => $c['monto'], 'porcentaje' => $c['porcentaje'], 'regla' => json_encode((object) $c['regla']),
                'exencion' => json_encode($c['exencion']), 'descripcion' => $c['descripcion'], 'sistema' => $c['sistema'] ?? false,
                'activo' => true, 'orden' => $c['orden'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (ValoresIniciales::tablasFiscales() as [$tipo, $desde, $datos]) {
            DB::table('tablas_fiscales')->insertOrIgnore(['tipo' => $tipo, 'vigente_desde' => $desde, 'datos' => json_encode($datos),
                'fuente' => 'Valores iniciales v11', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['Generalista', 'Supervisor de mesa de servicio'] as $p) {
            DB::table('puestos')->insertOrIgnore(['nombre' => $p, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function permisos(): void
    {
        // Se quitan los permisos de los módulos retirados (en cascada, de cada rol)
        DB::table('permisos')->whereIn('nombre', Permiso::RETIRADOS)->delete();
        if (! DB::table('roles')->exists()) {
            return;   // instalación nueva: lo hace RolesPermisosSeeder
        }
        $nuevos = [Permiso::EmpleadosVer, Permiso::EmpleadosGestionar, Permiso::NominaConfigurar];
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
        Schema::dropIfExists('periodos_nomina');
        Schema::dropIfExists('tablas_fiscales');
        Schema::dropIfExists('conceptos_nomina');
        Schema::dropIfExists('empleado_salarios');
        Schema::dropIfExists('empleado_contratos');
        Schema::table('empleados', function (Blueprint $t) {
            $t->dropConstrainedForeignId('empresa_id');
            $t->dropConstrainedForeignId('puesto_id');
            $t->dropUnique(['rfc']);
            $t->dropUnique(['curp']);
            $t->dropUnique(['nss']);
            $t->dropColumn(['apellido_paterno', 'apellido_materno', 'nombres', 'rfc', 'curp', 'nss', 'codigo_postal', 'email', 'telefono', 'departamento',
                'salario_mensual', 'periodicidad', 'tipo_contrato', 'contrato_fin', 'jornada_horas', 'dias_descanso', 'banco', 'clabe', 'estado',
                'fecha_baja', 'motivo_baja', 'notas']);
        });
        Schema::dropIfExists('empresas');
    }
};
