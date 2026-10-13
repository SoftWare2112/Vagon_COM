<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contadores atómicos (número de empleado MSI000001...). Se leen con SELECT ... FOR UPDATE.
        Schema::create('secuencias', function (Blueprint $t) {
            $t->string('clave', 50)->primary();
            $t->unsignedInteger('valor')->default(0);
        });

        Schema::create('puestos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 100)->unique();
            $t->timestamps();
        });

        // Datos ESTABLES de la persona. No se borran: lo que se elimina/restaura es su
        // registro de una quincena concreta (tabla registros), como pidió RH.
        // El Puesto NO va aquí: puede cambiar entre quincenas y se guarda en cada registro.
        Schema::create('empleados', function (Blueprint $t) {
            $t->id();
            $t->string('numero_empleado', 12)->unique();            // MSI000001 (inmutable)
            $t->string('nombre_completo', 150);
            $t->string('nombre_normalizado', 150);                  // sin acentos, minúsculas: clave de cruce con el Excel
            $t->date('fecha_ingreso');
            $t->enum('origen', ['excel', 'manual'])->default('excel');
            $t->timestamps();

            // Mismo nombre + misma fecha de ingreso = misma persona.
            $t->unique(['nombre_normalizado', 'fecha_ingreso'], 'empleados_clave_natural_unique');
            $t->index('fecha_ingreso');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empleados');
        Schema::dropIfExists('puestos');
        Schema::dropIfExists('secuencias');
    }
};
