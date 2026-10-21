<?php

use App\Support\CatalogoColumnas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v6:
 *  - registros.numero_nomina: la columna "#" del Excel (200, 201, ...). Es POR QUINCENA, como el
 *    puesto; no identifica al empleado (para eso está empleados.numero_empleado, MSI000001).
 *  - periodos.formato: cómo venía el archivo de RH (título y encabezados con su texto original,
 *    en su orden) para exportar e imprimir con la misma estructura.
 *  - En una instalación existente agrega la columna "Número de nómina" al catálogo, en primer lugar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registros', function (Blueprint $t) {
            $t->unsignedInteger('numero_nomina')->nullable()->after('puesto_id');
            $t->index(['periodo_id', 'numero_nomina']);
        });

        Schema::table('periodos', function (Blueprint $t) {
            $t->json('formato')->nullable()->after('tipo');
        });

        // Instalación nueva: el seeder crea el catálogo completo. Existente: se inserta al inicio.
        if (DB::table('columnas')->exists() && ! DB::table('columnas')->where('clave', 'numero_nomina')->exists()) {
            $def = CatalogoColumnas::SISTEMA[0];
            DB::table('columnas')->increment('orden');
            DB::table('columnas')->insert([
                'clave' => $def['clave'], 'etiqueta' => $def['etiqueta'], 'tipo' => $def['tipo'], 'origen' => 'sistema',
                'orden' => 1, 'alias' => json_encode($def['alias']), 'obligatoria' => false, 'editable' => true,
                'visible' => true, 'activa' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('columnas')->where('clave', 'numero_nomina')->delete();
        Schema::table('periodos', fn (Blueprint $t) => $t->dropColumn('formato'));
        Schema::table('registros', function (Blueprint $t) {
            $t->dropIndex(['periodo_id', 'numero_nomina']);
            $t->dropColumn('numero_nomina');
        });
    }
};
