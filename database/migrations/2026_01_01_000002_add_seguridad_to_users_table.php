<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Extiende la tabla `users` que trae Laravel por defecto
 * (0001_01_01_000000_create_users_table también crea password_reset_tokens y sessions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // nullable solo para que la migración corra también en SQLite (pruebas).
            // Un usuario sin rol no tiene ningún permiso (deny by default).
            $t->foreignId('rol_id')->nullable()->after('id')->constrained('roles')->restrictOnDelete();
            $t->boolean('is_active')->default(true)->after('password');
            $t->unsignedTinyInteger('failed_attempts')->default(0)->after('is_active');
            $t->timestamp('locked_until')->nullable()->after('failed_attempts');
            $t->boolean('must_change_password')->default(true)->after('locked_until');
            $t->timestamp('last_login_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('rol_id');
            $t->dropColumn(['is_active', 'failed_attempts', 'locked_until', 'must_change_password', 'last_login_at']);
        });
    }
};
