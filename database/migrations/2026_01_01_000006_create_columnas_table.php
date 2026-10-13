<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Catálogo de columnas del reporte (las del sistema + las que RH agregue).
 * El ORDEN de la tabla, del Excel exportado y del PDF sale de aquí.
 * Insertar una columna en una posición = cambiar `orden`; los datos ya guardados
 * no se tocan porque se guardan por `clave`, nunca por posición.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('columnas', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 60)->unique();                   // nombre_completo, bono_asistencia, extra_bono_transporte...
            $t->string('etiqueta', 100);                         // encabezado que se muestra / exporta
            $t->enum('tipo', ['texto', 'texto_largo', 'entero', 'decimal', 'dinero', 'fecha', 'booleano']);
            $t->enum('origen', ['sistema', 'personalizada', 'domingos', 'periodo']);
            $t->unsignedInteger('orden');                        // posición (1, 2, 3...)
            $t->json('alias')->nullable();                       // encabezados aceptados en el Excel
            $t->boolean('obligatoria')->default(false);          // si falta en el Excel -> error
            $t->boolean('editable')->default(true);
            $t->boolean('visible')->default(true);
            $t->boolean('activa')->default(true);                // "eliminar" una personalizada = desactivarla (sus datos se conservan)
            $t->timestamps();
            $t->index(['activa', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('columnas');
    }
};
