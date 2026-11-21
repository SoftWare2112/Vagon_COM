<?php

use App\Enums\Permiso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v14 — Timbrado del CFDI 4.0 con Complemento de Nómina 1.2 (opcional) e importación de XML.
 *
 *  - `empresas`: datos del complemento (entidad federativa, clase de riesgo, tipo de jornada, serie, CURP del patrón
 *    persona física), el proveedor de timbrado (PAC simulado o SW Sapien, en pruebas o producción) y el CSD
 *    (rutas de los archivos .cer/.key en el disco privado, contraseña cifrada, número y vigencia del certificado).
 *  - `nomina_recibos`: modo del timbre (simulado | pruebas | produccion | importado), ruta del XML y total del CFDI.
 *  - `cfdi_eventos`: bitácora de timbrados, errores del PAC, cancelaciones e importaciones.
 *  - Permiso `nomina.timbrar`.
 * Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $t) {
            $columnas = [
                'curp' => fn () => $t->string('curp', 18)->nullable(),
                'clave_ent_fed' => fn () => $t->string('clave_ent_fed', 3)->nullable(),
                'riesgo_puesto' => fn () => $t->string('riesgo_puesto', 1)->default('1'),
                'tipo_jornada' => fn () => $t->string('tipo_jornada', 2)->default('01'),
                'serie_cfdi' => fn () => $t->string('serie_cfdi', 10)->default('N'),
                'pac' => fn () => $t->string('pac', 12)->default('simulado'),          // simulado | sw
                'pac_modo' => fn () => $t->string('pac_modo', 12)->default('pruebas'), // pruebas | produccion
                'pac_usuario' => fn () => $t->string('pac_usuario', 150)->nullable(),
                'pac_password' => fn () => $t->text('pac_password')->nullable(),       // cifrada (APP_KEY)
                'csd_cer_path' => fn () => $t->string('csd_cer_path', 255)->nullable(),
                'csd_key_path' => fn () => $t->string('csd_key_path', 255)->nullable(),
                'csd_password' => fn () => $t->text('csd_password')->nullable(),       // cifrada (APP_KEY)
                'csd_no_certificado' => fn () => $t->string('csd_no_certificado', 20)->nullable(),
                'csd_rfc' => fn () => $t->string('csd_rfc', 13)->nullable(),
                'csd_vigente_desde' => fn () => $t->dateTime('csd_vigente_desde')->nullable(),
                'csd_vigente_hasta' => fn () => $t->dateTime('csd_vigente_hasta')->nullable(),
            ];
            foreach ($columnas as $c => $crear) {
                if (! Schema::hasColumn('empresas', $c)) {
                    $crear();
                }
            }
        });

        Schema::table('nomina_recibos', function (Blueprint $t) {
            foreach ([
                'cfdi_modo' => fn () => $t->string('cfdi_modo', 12)->nullable(),     // simulado | pruebas | produccion | importado
                'cfdi_xml_path' => fn () => $t->string('cfdi_xml_path', 255)->nullable(),
                'cfdi_total' => fn () => $t->decimal('cfdi_total', 12, 2)->nullable(),
            ] as $c => $crear) {
                if (! Schema::hasColumn('nomina_recibos', $c)) {
                    $crear();
                }
            }
        });

        if (! Schema::hasTable('cfdi_eventos')) {
            Schema::create('cfdi_eventos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
                $t->foreignId('recibo_id')->nullable()->constrained('nomina_recibos')->nullOnDelete();
                $t->string('tipo', 14);                  // timbrado | error | cancelacion | importacion | csd | prueba
                $t->string('uuid', 36)->nullable();
                $t->string('modo', 12)->nullable();
                $t->string('mensaje', 500);
                $t->json('datos')->nullable();
                $t->string('xml_path', 255)->nullable();
                $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamps();
                $t->index(['recibo_id', 'created_at']);
                $t->index(['uuid']);
                $t->index(['tipo', 'created_at']);
            });
        }

        if (DB::table('roles')->exists()) {
            DB::table('permisos')->updateOrInsert(['nombre' => Permiso::NominaTimbrar->value], ['descripcion' => Permiso::NominaTimbrar->descripcion()]);
            $permisoId = DB::table('permisos')->where('nombre', Permiso::NominaTimbrar->value)->value('id');
            foreach (Permiso::porRol() as $rol => $permisos) {
                $rolId = DB::table('roles')->where('nombre', $rol)->value('id');
                if ($rolId && in_array(Permiso::NominaTimbrar->value, $permisos, true)) {
                    DB::table('permiso_rol')->insertOrIgnore(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cfdi_eventos');
        Schema::table('nomina_recibos', fn (Blueprint $t) => $t->dropColumn(['cfdi_modo', 'cfdi_xml_path', 'cfdi_total']));
        Schema::table('empresas', fn (Blueprint $t) => $t->dropColumn(['curp', 'clave_ent_fed', 'riesgo_puesto', 'tipo_jornada', 'serie_cfdi', 'pac', 'pac_modo',
            'pac_usuario', 'pac_password', 'csd_cer_path', 'csd_key_path', 'csd_password', 'csd_no_certificado', 'csd_rfc', 'csd_vigente_desde', 'csd_vigente_hasta']));
        DB::table('permisos')->where('nombre', 'nomina.timbrar')->delete();
    }
};
