<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HojaPlantilla;
use App\Models\Libro;
use App\Services\Hojas\LibroService;
use App\Support\Hojas\AccesoLibros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v9 — Libros (trabajos personales con pestañas). Cada usuario ve y edita solo los suyos;
 * con `hojas.ver_todas` (Administrador) se ven y controlan los de todos.
 */
class LibroController extends Controller
{
    public function __construct(private readonly LibroService $servicio) {}

    /** ?q=texto &usuario=id (solo administrador) &todos=1 (administrador: de todos los usuarios) */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $verTodos = $u->tienePermiso('hojas.ver_todas') && ($request->boolean('todos') || $request->filled('usuario'));
        $libros = Libro::query()
            ->with('autor:id,name')->withCount('hojas')
            ->when(! $verTodos, fn ($q) => $q->where('user_id', $u->id))
            ->when($verTodos && $request->filled('usuario'), fn ($q) => $q->where('user_id', $request->integer('usuario')))
            ->when($request->filled('q'), fn ($q) => $q->where('nombre', 'like', '%'.addcslashes((string) $request->input('q'), '%_\\').'%'))
            ->orderByDesc('es_personal')->latest('updated_at')
            ->limit(500)->get();

        return response()->json($libros->map(fn (Libro $l) => [
            'id' => $l->id, 'nombre' => $l->nombre, 'origen' => $l->origen, 'es_personal' => $l->es_personal,
            'hojas' => $l->hojas_count, 'user_id' => $l->user_id, 'autor' => $l->autor?->name,
            'actualizado' => $l->updated_at?->format('d/m/Y H:i'), 'actualizado_iso' => $l->updated_at?->toIso8601String(),
        ]));
    }

    /** "Mi hoja" de Inicio (se crea la primera vez). */
    public function personal(Request $request): JsonResponse
    {
        return response()->json($this->servicio->detalle($this->servicio->personal($request->user())));
    }

    /** body: {nombre, plantilla_id?} */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'plantilla_id' => ['nullable', 'integer', 'exists:hoja_plantillas,id'],
        ]);
        $plantilla = isset($datos['plantilla_id']) ? HojaPlantilla::find($datos['plantilla_id']) : null;

        return response()->json($this->servicio->detalle($this->servicio->crear($datos['nombre'], $request->user(), $plantilla)), 201);
    }

    /** Importar cualquier Excel como libro. multipart: archivo, nombre? */
    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['bail', 'required', 'file', function (string $attr, $archivo, \Closure $fail) {
                if (strtolower($archivo->getClientOriginalExtension()) === 'pdf' || $archivo->getMimeType() === 'application/pdf') {
                    $fail('El PDF no tiene celdas que se puedan importar. Sube el archivo de Excel (.xlsx o .xls).');
                }
            }, 'mimes:xlsx,xls,ods', 'max:'.config('reportes.etl.max_kb', 10240)],
            'nombre' => ['nullable', 'string', 'max:120'],
        ], [
            'archivo.mimes' => 'Solo se pueden importar archivos de Excel (.xlsx, .xls) u hojas de cálculo .ods.',
            'archivo.max' => 'El archivo supera el tamaño máximo permitido ('.round(config('reportes.etl.max_kb', 10240) / 1024).' MB).',
        ]);
        $r = $this->servicio->importar($request->file('archivo'), $request->user(), $request->input('nombre'));

        return response()->json([
            ...$this->servicio->detalle($r['libro']),
            'importacion' => ['carga_id' => $r['carga']->id, 'avisos' => $r['avisos'], 'estadisticas' => $r['estadisticas']],
        ], 201);
    }

    public function show(Request $request, Libro $libro): JsonResponse
    {
        return response()->json($this->servicio->detalle(AccesoLibros::libro($request->user(), $libro)));
    }

    public function update(Request $request, Libro $libro): JsonResponse
    {
        AccesoLibros::libro($request->user(), $libro)->update($request->validate(['nombre' => ['required', 'string', 'max:120']]));

        return response()->json(['message' => 'Libro renombrado.']);
    }

    public function destroy(Request $request, Libro $libro): JsonResponse
    {
        $this->servicio->eliminar(AccesoLibros::libro($request->user(), $libro), $request->user());

        return response()->json(['message' => 'Libro eliminado.']);
    }

    /** Nueva pestaña en blanco. body: {nombre?} */
    public function agregarHoja(Request $request, Libro $libro): JsonResponse
    {
        AccesoLibros::libro($request->user(), $libro);
        $hoja = $this->servicio->agregarHoja($libro, $request->validate(['nombre' => ['nullable', 'string', 'max:60']])['nombre'] ?? null, $request->user());

        return response()->json([...$this->servicio->detalle($libro->fresh()), 'nueva' => $hoja->id], 201);
    }
}
