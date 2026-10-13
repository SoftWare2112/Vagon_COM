<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 50)->unique();          // admin_rh, supervisor, lector
            $t->string('descripcion')->nullable();
            $t->timestamps();
        });

        Schema::create('permisos', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 80)->unique();          // registros.ver, cargas.subir, ...
            $t->string('descripcion')->nullable();
        });

        Schema::create('permiso_rol', function (Blueprint $t) {
            $t->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $t->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();
            $t->primary(['rol_id', 'permiso_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permiso_rol');
        Schema::dropIfExists('permisos');
        Schema::dropIfExists('roles');
    }
};
