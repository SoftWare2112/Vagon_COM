<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quincena de nómina: "12 de agosto al 27 de agosto"
        Schema::create('periodos', function (Blueprint $t) {
            $t->id();
            $t->date('fecha_inicio');
            $t->date('fecha_fin');
            $t->string('etiqueta', 100);                         // encabezado tal como viene en el Excel
            $t->timestamps();
            $t->unique(['fecha_inicio', 'fecha_fin']);
        });

        Schema::create('cargas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('periodo_id')->nullable()->constrained('periodos')->nullOnDelete();
            $t->string('nombre_original');
            $t->string('ruta_archivo');                          // storage privado
            $t->char('sha256', 64)->index();                     // hash del archivo
            $t->char('contenido_hash', 64)->nullable()->index(); // hash de los datos normalizados
            // Se llenan solo al APLICAR. UNIQUE => la BD impide aplicar dos veces el mismo archivo.
            $t->char('sha256_aplicado', 64)->nullable()->unique();
            $t->char('contenido_hash_aplicado', 64)->nullable()->unique();
            $t->enum('modo', ['REEMPLAZAR', 'MANTENER'])->nullable();
            $t->enum('estatus', ['CON_ERRORES', 'LISTA', 'APLICADA', 'FALLIDA', 'DESCARTADA'])->default('LISTA');
            $t->json('encabezados')->nullable();                 // mapa columna Excel -> campo, columnas ausentes, periodo
            $t->unsignedInteger('filas_total')->default(0);
            $t->unsignedInteger('num_errores')->default(0);
            $t->unsignedInteger('num_advertencias')->default(0);
            $t->json('resumen')->nullable();
            $t->timestamp('aplicada_at')->nullable();
            $t->timestamps();
        });

        // Staging: filas normalizadas esperando a que el usuario elija el modo
        Schema::create('carga_filas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carga_id')->constrained('cargas')->cascadeOnDelete();
            $t->unsignedInteger('fila_excel');
            $t->string('nombre_normalizado', 150);
            $t->date('fecha_ingreso')->nullable();
            $t->json('datos');
            $t->index(['carga_id', 'nombre_normalizado']);
        });

        Schema::create('carga_mensajes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carga_id')->constrained('cargas')->cascadeOnDelete();
            $t->enum('nivel', ['ERROR', 'ADVERTENCIA']);
            $t->unsignedInteger('fila')->nullable();
            $t->string('columna', 60)->nullable();
            $t->string('celda', 10)->nullable();                 // "G14"
            $t->string('codigo', 40);
            $t->string('mensaje', 500);
            $t->string('valor_recibido', 255)->nullable();
        });

        // Una FILA del reporte: un empleado en una quincena. Es lo que se elimina,
        // se ve en Histórico y se restaura a SU quincena.
        Schema::create('registros', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
            $t->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $t->foreignId('carga_id')->nullable()->constrained('cargas')->nullOnDelete(); // null = capturado a mano
            $t->foreignId('puesto_id')->nullable()->constrained('puestos')->nullOnDelete(); // puesto EN ESA quincena (historial)
            $t->decimal('prima_vacacional', 10, 2)->nullable();
            $t->decimal('bono_3_meses', 10, 2)->nullable();
            $t->decimal('bono_puntualidad', 10, 2)->nullable();
            $t->decimal('bono_asistencia', 10, 2)->nullable();
            $t->unsignedTinyInteger('num_faltas')->default(0);
            $t->text('detalle')->nullable();
            $t->boolean('acredita_bono')->nullable();
            $t->decimal('programado_domingo', 4, 2)->nullable();  // se lee tal cual; no se calcula
            $t->string('valor_periodo', 100)->nullable();         // columna "12 de agosto al 27 de agosto" (texto)
            $t->json('valores_extra')->nullable();                // columnas agregadas por RH: {"clave": valor}
            $t->json('campos_editados')->nullable();              // ["num_faltas","domingo:2026-08-16","extra:x"] -> modo MANTENER
            $t->unsignedInteger('version')->default(1);           // bloqueo optimista
            $t->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('conservar_en_cargas')->default(false);  // true al restaurar desde Histórico
            $t->string('motivo_baja', 40)->nullable();            // MANUAL | REEMPLAZO_CARGA
            $t->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();

            // Incluye eliminados a propósito: restaurar = reutilizar la misma fila, nunca duplicar.
            $t->unique(['empleado_id', 'periodo_id']);
            $t->index(['periodo_id', 'deleted_at', 'num_faltas']);
            $t->index(['periodo_id', 'puesto_id']);
        });

        // Columnas dinámicas "Domingo 16", "Domingo 23", ... (2 o 3 por quincena)
        Schema::create('registro_domingos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('registro_id')->constrained('registros')->cascadeOnDelete();
            $t->date('fecha');
            $t->string('etiqueta', 30);                          // "Domingo 16"
            $t->decimal('cantidad', 4, 2)->nullable();           // se lee tal cual; puede venir vacía
            $t->unique(['registro_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_domingos');
        Schema::dropIfExists('registros');
        Schema::dropIfExists('carga_mensajes');
        Schema::dropIfExists('carga_filas');
        Schema::dropIfExists('cargas');
        Schema::dropIfExists('periodos');
    }
};
