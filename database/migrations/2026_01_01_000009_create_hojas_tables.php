<?php

use App\Enums\Permiso;
use App\Support\Hojas\PlantillasPredefinidas;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v8 — Módulo Hojas: hojas de cálculo creadas en la plataforma (desde cero o desde plantilla).
 *
 *  - Las filas y columnas tienen un ID FIJO y un `orden`: insertar una fila no mueve ninguna celda
 *    ni rompe fórmulas (las fórmulas apuntan a ids, no a posiciones).
 *  - `hoja_celdas` guarda SOLO las celdas con contenido o formato, con su tipo explícito y un campo
 *    por clase de valor. Una fórmula guarda su texto y su último resultado (lo calcula el navegador).
 *  - `hoja_estilos` guarda cada combinación de formato una sola vez; las celdas apuntan a ella.
 *  - `version` (hoja y celda) permite que varias personas editen sin pisarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hoja_plantillas', function (Blueprint $t) {
            $t->id();
            $t->string('clave', 60)->unique();
            $t->string('nombre', 120);
            $t->string('descripcion', 255)->nullable();
            $t->json('definicion');                       // columnas, filas, celdas y estilos
            $t->boolean('sistema')->default(false);       // predefinida (no se borra)
            $t->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('hojas', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 120);
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();   // quien la creó
            $t->foreignId('plantilla_id')->nullable()->constrained('hoja_plantillas')->nullOnDelete();
            $t->unsignedBigInteger('version')->default(1);
            $t->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('hoja_columnas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hoja_id')->constrained('hojas')->cascadeOnDelete();
            $t->unsignedInteger('orden');
            $t->unsignedSmallInteger('ancho')->default(100);   // píxeles
            $t->index(['hoja_id', 'orden']);
        });

        Schema::create('hoja_filas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hoja_id')->constrained('hojas')->cascadeOnDelete();
            $t->unsignedInteger('orden');
            $t->unsignedSmallInteger('alto')->default(28);
            $t->index(['hoja_id', 'orden']);
        });

        Schema::create('hoja_estilos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hoja_id')->constrained('hojas')->cascadeOnDelete();
            $t->char('huella', 40);                       // sha1 de la definición normalizada
            $t->json('definicion');                       // {n, i, s, c, f, a, w, fmt}
            $t->unique(['hoja_id', 'huella']);
        });

        Schema::create('hoja_celdas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hoja_id')->constrained('hojas')->cascadeOnDelete();
            $t->foreignId('fila_id')->constrained('hoja_filas')->cascadeOnDelete();
            $t->foreignId('columna_id')->constrained('hoja_columnas')->cascadeOnDelete();
            // vacio (solo formato) | texto | numero | fecha | fecha_hora | hora | logico | formula
            $t->string('tipo', 12);
            $t->text('valor_texto')->nullable();          // texto, hora (HH:MM:SS) o código de error (#DIV/0!)
            $t->decimal('valor_numero', 20, 6)->nullable();
            $t->dateTime('valor_fecha')->nullable();      // fecha y fecha_hora
            $t->boolean('valor_logico')->nullable();
            $t->text('formula')->nullable();              // "=[f12c3]*[f12c4]" (ids, no posiciones)
            $t->string('resultado_tipo', 12)->nullable(); // tipo del último resultado de la fórmula
            $t->foreignId('estilo_id')->nullable()->constrained('hoja_estilos')->nullOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['fila_id', 'columna_id']);         // nunca dos celdas en el mismo lugar
            $t->index('hoja_id');
        });

        $this->sembrarPlantillas();
        $this->sembrarPermisos();
    }

    /** Plantillas predefinidas (idempotente: también corre en instalaciones existentes). */
    private function sembrarPlantillas(): void
    {
        foreach (PlantillasPredefinidas::todas() as $p) {
            DB::table('hoja_plantillas')->updateOrInsert(['clave' => $p['clave']], [
                'nombre' => $p['nombre'], 'descripcion' => $p['descripcion'], 'definicion' => json_encode($p['definicion']),
                'sistema' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Permisos nuevos con la matriz inicial (en una instalación nueva el seeder hace lo mismo). */
    private function sembrarPermisos(): void
    {
        if (! Schema::hasTable('permisos') || ! DB::table('roles')->exists()) {
            return;   // instalación nueva: lo hace RolesPermisosSeeder
        }
        $nuevos = [Permiso::HojasVer, Permiso::HojasCrear, Permiso::HojasEditar, Permiso::PlantillasGestionar];
        foreach ($nuevos as $p) {
            DB::table('permisos')->updateOrInsert(['nombre' => $p->value], []);
        }
        foreach (Permiso::porRol() as $rol => $permisos) {
            $rolId = DB::table('roles')->where('nombre', $rol)->value('id');
            if (! $rolId) {
                continue;
            }
            foreach ($nuevos as $p) {
                if (in_array($p->value, $permisos, true)) {
                    $permisoId = DB::table('permisos')->where('nombre', $p->value)->value('id');
                    DB::table('permiso_rol')->insertOrIgnore(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hoja_celdas');
        Schema::dropIfExists('hoja_estilos');
        Schema::dropIfExists('hoja_filas');
        Schema::dropIfExists('hoja_columnas');
        Schema::dropIfExists('hojas');
        Schema::dropIfExists('hoja_plantillas');
        DB::table('permisos')->whereIn('nombre', ['hojas.ver', 'hojas.crear', 'hojas.editar', 'plantillas.gestionar'])->delete();
    }
};
