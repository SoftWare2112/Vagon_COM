<?php

use App\Enums\Permiso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v10 — Publicaciones: un canal para compartir con los demás usuarios textos, documentos, imágenes,
 * enlaces a videos y trabajos de la plataforma (una copia de solo lectura de un libro).
 *
 *  - `publicaciones`: autor, tipo, título, texto, audiencia (todos / roles / usuarios), estado
 *    (borrador / publicada / archivada), fijada, oficial, opciones de descarga y copia, caducidad.
 *  - `publicacion_adjuntos`: archivos (documentos e imágenes) en el disco privado; aquí solo su ruta.
 *  - `publicacion_versiones`: la copia congelada del libro publicado (lo que el autor siga editando
 *    en su espacio no se ve hasta que actualiza la publicación).
 *  - `publicacion_lecturas` (quién la vio y cuándo) y `publicacion_favoritas`.
 * Idempotente: se puede volver a correr si una ejecución anterior quedó a medias.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('publicaciones')) {
            Schema::create('publicaciones', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $t->string('tipo', 12);                        // texto | documentos | imagenes | video | trabajo
                $t->string('titulo', 160);
                $t->text('cuerpo')->nullable();                // texto con formato sencillo (**negrita**, listas, enlaces)
                $t->string('categoria', 60)->nullable();
                $t->json('etiquetas')->nullable();
                $t->string('audiencia', 10)->default('todos'); // todos | roles | usuarios
                $t->json('audiencia_ids')->nullable();         // nombres de rol o ids de usuario
                $t->string('estado', 10)->default('borrador'); // borrador | publicada | archivada
                $t->boolean('fijada')->default(false);
                $t->boolean('oficial')->default(false);        // marcada por un moderador como contenido oficial
                $t->boolean('permite_descarga')->default(true);
                $t->boolean('permite_copia')->default(true);   // trabajos: "Hacer una copia en mi espacio"
                $t->string('video_url', 500)->nullable();
                $t->string('video_proveedor', 20)->nullable();
                $t->foreignId('libro_id')->nullable()->constrained('libros')->nullOnDelete();   // libro de origen (trabajos)
                $t->timestamp('publicada_at')->nullable();
                $t->timestamp('caduca_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['estado', 'publicada_at']);
                $t->index(['user_id', 'estado']);
            });
        }

        if (! Schema::hasTable('publicacion_adjuntos')) {
            Schema::create('publicacion_adjuntos', function (Blueprint $t) {
                $t->id();
                $t->foreignId('publicacion_id')->constrained('publicaciones')->cascadeOnDelete();
                $t->string('nombre', 200);
                $t->string('ruta');
                $t->string('miniatura')->nullable();
                $t->string('mime', 120);
                $t->unsignedInteger('tamano');
                $t->unsignedSmallInteger('ancho')->nullable();
                $t->unsignedSmallInteger('alto')->nullable();
                $t->unsignedSmallInteger('orden')->default(0);
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('publicacion_versiones')) {
            Schema::create('publicacion_versiones', function (Blueprint $t) {
                $t->id();
                $t->foreignId('publicacion_id')->constrained('publicaciones')->cascadeOnDelete();
                $t->unsignedSmallInteger('numero');
                $t->string('libro_nombre', 120);
                $t->json('hojas');          // nombres de las pestañas (para el muro, sin cargar todo el contenido)
                $t->json('contenido');      // [{nombre, columnas, filas, estilos, celdas, combinadas, imagenes}]
                $t->foreignId('creada_por')->nullable()->constrained('users')->nullOnDelete();
                $t->timestamp('created_at')->nullable();
                $t->unique(['publicacion_id', 'numero']);
            });
        }

        if (! Schema::hasTable('publicacion_lecturas')) {
            Schema::create('publicacion_lecturas', function (Blueprint $t) {
                $t->foreignId('publicacion_id')->constrained('publicaciones')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->timestamp('leida_at');
                $t->primary(['publicacion_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('publicacion_favoritas')) {
            Schema::create('publicacion_favoritas', function (Blueprint $t) {
                $t->foreignId('publicacion_id')->constrained('publicaciones')->cascadeOnDelete();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->primary(['publicacion_id', 'user_id']);
            });
        }

        $this->sembrarPermisos();
    }

    private function sembrarPermisos(): void
    {
        if (! Schema::hasTable('permisos') || ! DB::table('roles')->exists()) {
            return;   // instalación nueva: lo hace RolesPermisosSeeder
        }
        $nuevos = [Permiso::PublicacionesVer, Permiso::PublicacionesCrear, Permiso::PublicacionesModerar];
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

    public function down(): void
    {
        Schema::dropIfExists('publicacion_favoritas');
        Schema::dropIfExists('publicacion_lecturas');
        Schema::dropIfExists('publicacion_versiones');
        Schema::dropIfExists('publicacion_adjuntos');
        Schema::dropIfExists('publicaciones');
        DB::table('permisos')->whereIn('nombre', ['publicaciones.ver', 'publicaciones.crear', 'publicaciones.moderar'])->delete();
    }
};
