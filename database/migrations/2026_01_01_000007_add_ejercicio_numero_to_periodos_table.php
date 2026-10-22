<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v5: la quincena se identifica por Ejercicio + número de periodo (bloque "Ejercicio: 2026 /
 * Periodo: 17" del Excel). El encabezado "1 de octubre al 13 de octubre" queda como etiqueta.
 * Las columnas son opcionales: archivos sin ese bloque siguen funcionando con las fechas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periodos', function (Blueprint $t) {
            $t->unsignedSmallInteger('ejercicio')->nullable()->after('etiqueta');   // 2026
            $t->unsignedTinyInteger('numero')->nullable()->after('ejercicio');      // 17
            $t->string('tipo', 20)->nullable()->after('numero');                    // Quincenal
            $t->unique(['ejercicio', 'numero']);   // MySQL permite varios NULL: periodos sin número no chocan
        });
    }

    public function down(): void
    {
        Schema::table('periodos', function (Blueprint $t) {
            $t->dropUnique(['ejercicio', 'numero']);
            $t->dropColumn(['ejercicio', 'numero', 'tipo']);
        });
    }
};
