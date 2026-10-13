<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informes_exportados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('periodo_id')->nullable()->constrained('periodos')->nullOnDelete();
            $t->enum('formato', ['PDF', 'EXCEL']);
            $t->string('nombre');
            $t->string('ruta_archivo');
            $t->json('filtros')->nullable();
            $t->unsignedInteger('filas')->default(0);
            $t->timestamps();
        });

        // Solo inserción. SIN cascade: la auditoría nunca se borra.
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('entidad', 60);
            $t->unsignedBigInteger('entidad_id')->nullable();
            $t->string('evento', 30);   // CREAR, EDITAR, ELIMINAR, RESTAURAR, CARGA_ETL, EXPORTAR, LOGIN, LOGIN_FALLIDO, BLOQUEO...
            $t->json('antes')->nullable();
            $t->json('despues')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entidad', 'entidad_id']);
            $t->index(['evento', 'created_at']);
        });

        // Ajustes NO secretos (los secretos como SMTP van en .env)
        Schema::create('ajustes', function (Blueprint $t) {
            $t->string('clave', 80)->primary();
            $t->string('valor', 255);
            $t->string('descripcion')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ajustes');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('informes_exportados');
    }
};
