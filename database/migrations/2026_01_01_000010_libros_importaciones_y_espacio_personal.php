<?php

use App\Enums\Permiso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v9 — Libros (varias pestañas), importación de cualquier Excel, espacio personal e historial de importaciones.
 *
 *  - `libros`: el "trabajo" de un usuario. Cada libro tiene una o más hojas (pestañas) y un DUEÑO:
 *    solo él lo ve y lo edita; quien tenga `hojas.ver_todas` (Administrador) ve y controla todos.
 *  - `hoja_combinadas` y `hoja_imagenes`: celdas combinadas e imágenes (vienen de un Excel importado o
 *    se crean en el editor). Apuntan a ids de fila/columna: si se borra la fila, se borran en cascada.
 *  - `cargas` pasa a ser el historial de TODAS las importaciones: `tipo` = nomina | libro.
 *    Se puede quitar del historial (borrado lógico, papelera de 30 días) sin tocar los datos de nómina.
 *
 * Idempotente: se puede volver a correr si una ejecución anterior quedó a medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('libros')) {
            Schema::create('libros', function (Blueprint $t) {
                $t->id();
                $t->string('nombre', 120);
                $t->foreignId('user_id')->constrained('users')->restrictOnDelete();   // dueño
                $t->string('origen', 12)->default('blanco');   // blanco | plantilla | importado | personal
                $t->boolean('es_personal')->default(false);     // "Mi hoja" de la pantalla de Inicio
                $t->foreignId('carga_id')->nullable();           // importación que lo creó (FK abajo)
                $t->timestamps();
                $t->softDeletes();
                $t->index(['user_id', 'updated_at']);
            });
        }

        Schema::table('hojas', function (Blueprint $t) {
            if (! Schema::hasColumn('hojas', 'libro_id')) {
                $t->foreignId('libro_id')->nullable()->after('id')->constrained('libros')->cascadeOnDelete();
            }
            if (! Schema::hasColumn('hojas', 'orden')) {
                $t->unsignedSmallInteger('orden')->default(1)->after('libro_id');   // posición de la pestaña
            }
        });

        if (! Schema::hasTable('hoja_combinadas')) {
            Schema::create('hoja_combinadas', function (Blueprint $t) {
                $t->id();
                $t->foreignId('hoja_id')->constrained('hojas')->cascadeOnDelete();
                $t->foreignId('fila_id')->constrained('hoja_filas')->cascadeOnDelete();         // esquina superior izquierda
                $t->foreignId('columna_id')->constrained('hoja_columnas')->cascadeOnDelete();
                $t->foreignId('fila_fin_id')->constrained('hoja_filas')->cascadeOnDelete();     // esquina inferior derecha
                $t->foreignId('columna_fin_id')->constrained('hoja_columnas')->cascadeOnDelete();
                $t->index('hoja_id');
            });
        }

        if (! Schema::hasTable('hoja_imagenes')) {
            Schema::create('hoja_imagenes', function (Blueprint $t) {
                $t->id();
                $t->foreignId('hoja_id')->constrained('hojas')->cascadeOnDelete();
                $t->foreignId('fila_id')->constrained('hoja_filas')->cascadeOnDelete();         // celda donde está anclada
                $t->foreignId('columna_id')->constrained('hoja_columnas')->cascadeOnDelete();
                $t->unsignedSmallInteger('desplaz_x')->default(0);
                $t->unsignedSmallInteger('desplaz_y')->default(0);
                $t->unsignedSmallInteger('ancho');
                $t->unsignedSmallInteger('alto');
                $t->string('ruta');                       // disco local privado; nunca pública
                $t->string('mime', 40);
                $t->string('nombre', 120)->nullable();
                $t->timestamps();
                $t->index('hoja_id');
            });
        }

        Schema::table('cargas', function (Blueprint $t) {
            if (! Schema::hasColumn('cargas', 'tipo')) {
                $t->string('tipo', 10)->default('nomina')->after('id');   // nomina | libro
            }
            if (! Schema::hasColumn('cargas', 'libro_id')) {
                $t->foreignId('libro_id')->nullable()->after('periodo_id')->constrained('libros')->nullOnDelete();
            }
            if (! Schema::hasColumn('cargas', 'tamano')) {
                $t->unsignedInteger('tamano')->nullable()->after('nombre_original');   // bytes
            }
            if (! Schema::hasColumn('cargas', 'deleted_at')) {
                $t->softDeletes();
                $t->foreignId('eliminado_por')->nullable()->constrained('users')->nullOnDelete();
            }
        });
        if (! Schema::hasIndex('cargas', ['tipo', 'created_at'])) {
            Schema::table('cargas', fn (Blueprint $t) => $t->index(['tipo', 'created_at']));
        }
        if (! $this->tieneFk('libros', 'libros_carga_id_foreign')) {
            Schema::table('libros', fn (Blueprint $t) => $t->foreign('carga_id')->references('id')->on('cargas')->nullOnDelete());
        }

        $this->hojasExistentesALibros();
        $this->sembrarPermisos();
    }

    /** Las hojas creadas en la v8 pasan a ser libros de una pestaña, del mismo dueño. */
    private function hojasExistentesALibros(): void
    {
        foreach (DB::table('hojas')->whereNull('libro_id')->get() as $h) {
            $libroId = DB::table('libros')->insertGetId([
                'nombre' => $h->nombre, 'user_id' => $h->user_id, 'origen' => $h->plantilla_id ? 'plantilla' : 'blanco',
                'es_personal' => false, 'created_at' => $h->created_at, 'updated_at' => $h->updated_at, 'deleted_at' => $h->deleted_at,
            ]);
            DB::table('hojas')->where('id', $h->id)->update(['libro_id' => $libroId, 'orden' => 1, 'nombre' => 'Hoja1']);
        }
    }

    private function sembrarPermisos(): void
    {
        if (! Schema::hasTable('permisos') || ! DB::table('roles')->exists()) {
            return;   // instalación nueva: lo hace RolesPermisosSeeder
        }
        $nuevos = [Permiso::HojasVerTodas, Permiso::InformesLimpiar];
        foreach ($nuevos as $p) {
            DB::table('permisos')->updateOrInsert(['nombre' => $p->value], ['descripcion' => $p->descripcion()]);
        }
        foreach (Permiso::porRol() as $rol => $permisos) {
            $rolId = DB::table('roles')->where('nombre', $rol)->value('id');
            foreach ($nuevos as $p) {
                if ($rolId && in_array($p->value, $permisos, true)) {
                    $permisoId = DB::table('permisos')->where('nombre', $p->value)->value('id');
                    DB::table('permiso_rol')->insertOrIgnore(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
                }
            }
        }
    }

    private function tieneFk(string $tabla, string $nombre): bool
    {
        return collect(Schema::getForeignKeys($tabla))->contains(fn ($fk) => $fk['name'] === $nombre);
    }

    public function down(): void
    {
        Schema::table('libros', fn (Blueprint $t) => $t->dropForeign(['carga_id']));
        Schema::table('cargas', function (Blueprint $t) {
            $t->dropIndex(['tipo', 'created_at']);
            $t->dropConstrainedForeignId('libro_id');
            $t->dropConstrainedForeignId('eliminado_por');
            $t->dropSoftDeletes();
            $t->dropColumn(['tipo', 'tamano']);
        });
        Schema::dropIfExists('hoja_imagenes');
        Schema::dropIfExists('hoja_combinadas');
        Schema::table('hojas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('libro_id');
            $t->dropColumn('orden');
        });
        Schema::dropIfExists('libros');
        DB::table('permisos')->whereIn('nombre', ['hojas.ver_todas', 'informes.limpiar'])->delete();
    }
};
