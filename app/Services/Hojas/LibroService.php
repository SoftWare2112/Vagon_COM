<?php

namespace App\Services\Hojas;

use App\Enums\EstatusCarga;
use App\Exceptions\ReglaNegocioException;
use App\Models\AuditLog;
use App\Models\Carga;
use App\Models\Hoja;
use App\Models\HojaPlantilla;
use App\Models\Libro;
use App\Models\User;
use App\Support\Hojas\Importacion\TraductorExcel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * v9 — Libros: el trabajo personal de cada usuario (una o más pestañas).
 *  - Crear en blanco o desde plantilla, "Mi hoja" de Inicio, agregar/renombrar/borrar pestañas.
 *  - Importar CUALQUIER Excel como libro: LectorExcel (lee) -> TraductorExcel (convierte, lógica pura)
 *    -> HojaService (guarda). Queda registrado en el historial de importaciones (cargas, tipo libro).
 */
class LibroService
{
    public function __construct(
        private readonly HojaService $hojas,
        private readonly LectorExcel $lector,
    ) {}

    public function crear(string $nombre, User $usuario, ?HojaPlantilla $plantilla = null): Libro
    {
        return DB::transaction(function () use ($nombre, $usuario, $plantilla) {
            $libro = Libro::create(['nombre' => $nombre, 'user_id' => $usuario->id, 'origen' => $plantilla ? 'plantilla' : 'blanco']);
            $this->hojas->crear($libro, 'Hoja1', $usuario, $plantilla);
            AuditLog::registrar('CREAR', 'Libro', $libro->id, null, ['nombre' => $nombre, 'plantilla' => $plantilla?->clave]);

            return $libro;
        });
    }

    /** "Mi hoja": el libro personal que aparece en Inicio. Se crea la primera vez. */
    public function personal(User $usuario): Libro
    {
        return DB::transaction(function () use ($usuario) {
            $libro = Libro::where('user_id', $usuario->id)->where('es_personal', true)->lockForUpdate()->first();
            if ($libro) {
                return $libro;
            }
            $libro = Libro::create(['nombre' => 'Mi hoja', 'user_id' => $usuario->id, 'origen' => 'personal', 'es_personal' => true]);
            $this->hojas->crear($libro, 'Hoja1', $usuario);

            return $libro;
        });
    }

    public function agregarHoja(Libro $libro, ?string $nombre, User $usuario): Hoja
    {
        return DB::transaction(function () use ($libro, $nombre, $usuario) {
            Libro::whereKey($libro->id)->lockForUpdate()->first();
            abort_if($libro->hojas()->count() >= LectorExcel::MAX_PESTANAS * 2, 422, 'El libro ya tiene el máximo de pestañas.');
            $orden = (int) $libro->hojas()->max('orden') + 1;
            $nombre = $this->nombreLibre($libro, $nombre ?: 'Hoja'.$orden);
            $libro->touch();

            return $this->hojas->crear($libro, $nombre, $usuario, null, $orden);
        });
    }

    public function renombrarHoja(Hoja $hoja, string $nombre): void
    {
        $nombre = trim($nombre);
        abort_if($hoja->libro->hojas()->where('id', '!=', $hoja->id)->where('nombre', $nombre)->exists(), 422, "Ya hay una pestaña llamada «{$nombre}».");
        $hoja->update(['nombre' => $nombre]);
    }

    public function eliminarHoja(Hoja $hoja, User $usuario): void
    {
        $libro = $hoja->libro;
        abort_if($libro->hojas()->count() <= 1, 422, 'El libro debe conservar al menos una pestaña.');
        DB::transaction(function () use ($hoja, $libro) {
            $this->borrarImagenes($hoja);
            $hoja->forceDelete();   // filas, columnas, celdas, combinadas e imágenes se borran en cascada
            $libro->touch();
        });
        AuditLog::registrar('ELIMINAR', 'Hoja', $hoja->id, ['libro' => $libro->id, 'nombre' => $hoja->nombre], null, $usuario->id);
    }

    public function eliminar(Libro $libro, User $usuario): void
    {
        $libro->delete();   // borrado lógico: se puede recuperar desde la base de datos si fue un error
        AuditLog::registrar('ELIMINAR', 'Libro', $libro->id, ['nombre' => $libro->nombre, 'dueno' => $libro->user_id], null, $usuario->id);
    }

    /** Lo que el editor necesita para mostrar las pestañas. */
    public function detalle(Libro $libro): array
    {
        $libro->loadMissing(['autor:id,name', 'hojas:id,libro_id,nombre,orden']);

        return [
            'libro' => [
                'id' => $libro->id, 'nombre' => $libro->nombre, 'origen' => $libro->origen, 'es_personal' => $libro->es_personal,
                'user_id' => $libro->user_id, 'autor' => $libro->autor?->name,
                'actualizado' => $libro->updated_at?->format('d/m/Y H:i'),
            ],
            'hojas' => $libro->hojas->map(fn (Hoja $h) => ['id' => $h->id, 'nombre' => $h->nombre, 'orden' => $h->orden])->values()->all(),
        ];
    }

    // ───────────────────────── Importar un Excel ─────────────────────────

    /** @return array{libro: Libro, carga: Carga, avisos: list<string>, estadisticas: array} */
    public function importar(UploadedFile $archivo, User $usuario, ?string $nombre = null): array
    {
        $leido = $this->lector->leer($archivo->getRealPath());
        $avisos = $leido['avisos'];
        $traducidas = [];
        $total = ['pestanas' => 0, 'celdas' => 0, 'formulas' => 0, 'formulas_a_valor' => 0, 'combinadas' => 0, 'imagenes' => 0];
        foreach ($leido['pestanas'] as $p) {
            $t = TraductorExcel::traducir($p);
            $traducidas[] = ['nombre' => $p['nombre'], ...$t];
            $avisos = [...$avisos, ...$t['avisos']];
            $total['pestanas']++;
            foreach ($t['estadisticas'] as $k => $v) {
                $total[$k] += $v;
            }
        }

        $disco = Storage::disk(config('reportes.etl.disco', 'local'));
        $original = mb_substr($archivo->getClientOriginalName(), 0, 255);
        $nombre = trim((string) $nombre) ?: mb_substr(pathinfo($original, PATHINFO_FILENAME), 0, 120);
        $ruta = $archivo->store(config('reportes.etl.carpeta', 'cargas'), config('reportes.etl.disco', 'local'));
        $guardadas = [];
        $carga = null;

        try {
            $libro = DB::transaction(function () use ($traducidas, $usuario, $nombre, $original, $archivo, $ruta, $total, $avisos, $disco, &$guardadas, &$carga) {
                $carga = Carga::create([
                    'tipo' => Carga::LIBRO, 'user_id' => $usuario->id, 'nombre_original' => $original, 'tamano' => $archivo->getSize() ?: null,
                    'ruta_archivo' => $ruta, 'sha256' => hash_file('sha256', $archivo->getRealPath()), 'estatus' => EstatusCarga::Aplicada,
                    'encabezados' => ['hojas' => array_column($traducidas, 'nombre')],
                    'filas_total' => $total['celdas'], 'num_advertencias' => count($avisos),
                    'resumen' => [...$total, 'avisos' => $avisos], 'aplicada_at' => now(),
                ]);
                $libro = Libro::create(['nombre' => $nombre, 'user_id' => $usuario->id, 'origen' => 'importado', 'carga_id' => $carga->id]);
                $carga->update(['libro_id' => $libro->id]);

                $usados = [];
                foreach ($traducidas as $i => $t) {
                    $def = $t['definicion'];
                    $carpeta = config('reportes.libros.carpeta_imagenes', 'hojas').'/'.$libro->id;
                    foreach ($def['imagenes'] as $k => [$f, $c, $img]) {
                        $archivoImg = $carpeta.'/'.Str::random(24).'.'.(['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'][$img['mime']] ?? 'bin');
                        $disco->put($archivoImg, $img['contenido']);
                        $guardadas[] = $archivoImg;
                        unset($img['contenido']);
                        $def['imagenes'][$k] = [$f, $c, [...$img, 'ruta' => $archivoImg]];
                    }
                    $nombreHoja = $this->nombreUnico($t['nombre'] ?: 'Hoja'.($i + 1), $usados);
                    $usados[] = $nombreHoja;
                    $this->hojas->crearDesdeDefinicion($libro, $nombreHoja, $i + 1, $def, $usuario);
                }

                return $libro;
            });
        } catch (Throwable $e) {
            foreach ($guardadas as $g) {
                $disco->delete($g);
            }
            $disco->delete($ruta);
            throw $e instanceof ReglaNegocioException ? $e : new ReglaNegocioException('No se pudo importar el archivo: '.$e->getMessage(), 422, ['codigo' => 'IMPORTACION_FALLIDA']);
        }

        AuditLog::registrar('IMPORTAR', 'Libro', $libro->id, null, ['archivo' => $original, ...$total]);

        return ['libro' => $libro, 'carga' => $carga, 'avisos' => $avisos, 'estadisticas' => $total];
    }

    private function borrarImagenes(Hoja $hoja): void
    {
        $disco = Storage::disk(config('reportes.etl.disco', 'local'));
        foreach ($hoja->imagenes as $img) {
            $disco->delete($img->ruta);
        }
    }

    private function nombreLibre(Libro $libro, string $base): string
    {
        return $this->nombreUnico($base, $libro->hojas()->pluck('nombre')->all());
    }

    /** "Hoja1", "Hoja1 (2)", … */
    private function nombreUnico(string $base, array $usados): string
    {
        $base = mb_substr(trim($base), 0, 60) ?: 'Hoja';
        $nombre = $base;
        for ($n = 2; in_array($nombre, $usados, true); $n++) {
            $nombre = "{$base} ({$n})";
        }

        return $nombre;
    }
}
