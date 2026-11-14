<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hoja;
use App\Models\HojaColumna;
use App\Models\HojaFila;
use App\Models\HojaCombinada;
use App\Models\HojaImagen;
use App\Services\Hojas\HojaService;
use App\Services\Hojas\LibroService;
use App\Support\Hojas\AccesoLibros;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v8 — Hojas de cálculo (cada hoja es una pestaña de un libro). Controlador delgado: valida, delega y responde.
 * v9: espacio personal. Antes de cualquier acción se comprueba que la hoja sea del usuario
 * (o que tenga hojas.ver_todas); si no, 404. Editar exige además hojas.editar (en la ruta).
 */
class HojaController extends Controller
{
    public function __construct(
        private readonly HojaService $servicio,
        private readonly LibroService $libros,
    ) {}

    public function show(Request $request, Hoja $hoja): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);

        return response()->json($this->servicio->datos($hoja));
    }

    /** Renombrar la pestaña. */
    public function update(Request $request, Hoja $hoja): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $this->libros->renombrarHoja($hoja, $request->validate(['nombre' => ['required', 'string', 'max:60']])['nombre']);

        return response()->json(['message' => 'Pestaña renombrada.']);
    }

    /** Eliminar la pestaña (el libro conserva al menos una). */
    public function destroy(Request $request, Hoja $hoja): JsonResponse
    {
        $libro = AccesoLibros::hoja($request->user(), $hoja);
        $this->libros->eliminarHoja($hoja, $request->user());

        return response()->json($this->libros->detalle($libro->fresh()));
    }

    /**
     * Lote de cambios de celdas y formato (el editor los junta y los manda cada momento).
     * body: {cambios: [{f, c, t, v?, fx?, rt?, ver?}], formatos: [{celdas: [[f, c]], estilo: {...}}]}
     */
    public function celdas(Request $request, Hoja $hoja): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $datos = $request->validate([
            'cambios' => ['present', 'array', 'max:5000'],
            'cambios.*.f' => ['required', 'integer'],
            'cambios.*.c' => ['required', 'integer'],
            'cambios.*.t' => ['required', 'string'],
            'cambios.*.ver' => ['sometimes', 'integer', 'min:0'],
            'formatos' => ['sometimes', 'array', 'max:50'],
            'formatos.*.celdas' => ['required', 'array', 'max:10000'],
            'formatos.*.estilo' => ['present', 'array'],
        ]);

        return response()->json($this->servicio->guardar($hoja, $request->input('cambios', []), $datos['formatos'] ?? [], $request->user()));
    }

    /** body: {despues_de?: fila_id, al_inicio?: bool, cantidad?: 1-500} (sin despues_de = al final) */
    public function insertarFilas(Request $request, Hoja $hoja): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $d = $this->validarInsercion($request, 'hoja_filas');
        $this->servicio->insertarFilas($hoja, $d['posicion'], $d['cantidad'] ?? 1, $request->user());

        return response()->json($this->servicio->datos($hoja->fresh()));
    }

    public function insertarColumnas(Request $request, Hoja $hoja): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $d = $this->validarInsercion($request, 'hoja_columnas');
        $this->servicio->insertarColumnas($hoja, $d['posicion'], $d['cantidad'] ?? 1, $request->user());

        return response()->json($this->servicio->datos($hoja->fresh()));
    }

    public function eliminarFila(Request $request, Hoja $hoja, HojaFila $fila): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $this->servicio->eliminarFila($hoja, $fila, $request->user());

        return response()->json($this->servicio->datos($hoja->fresh()));
    }

    public function eliminarColumna(Request $request, Hoja $hoja, HojaColumna $columna): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $this->servicio->eliminarColumna($hoja, $columna, $request->user());

        return response()->json($this->servicio->datos($hoja->fresh()));
    }

    public function anchoColumna(Request $request, Hoja $hoja, HojaColumna $columna): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $this->servicio->anchoColumna($hoja, $columna, (int) $request->validate(['ancho' => ['required', 'integer']])['ancho']);

        return response()->json(['message' => 'ok']);
    }

    /** v9: combinar celdas. body: {f, c, f2, c2} (ids de las esquinas) */
    public function combinar(Request $request, Hoja $hoja): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $d = $request->validate(['f' => ['required', 'integer'], 'c' => ['required', 'integer'], 'f2' => ['required', 'integer'], 'c2' => ['required', 'integer']]);
        $this->servicio->combinar($hoja, $d['f'], $d['c'], $d['f2'], $d['c2'], $request->user());

        return response()->json($this->servicio->datos($hoja->fresh()));
    }

    public function separar(Request $request, Hoja $hoja, HojaCombinada $combinada): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $this->servicio->separar($hoja, $combinada, $request->user());

        return response()->json($this->servicio->datos($hoja->fresh()));
    }

    /** v9: la imagen se entrega solo a quien puede ver la hoja (nunca con un enlace público). */
    public function imagen(Request $request, Hoja $hoja, HojaImagen $imagen): StreamedResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        abort_if($imagen->hoja_id !== $hoja->id, 404);

        return Storage::disk(config('reportes.etl.disco', 'local'))->response($imagen->ruta, $imagen->nombre, [
            'Content-Type' => $imagen->mime, 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function eliminarImagen(Request $request, Hoja $hoja, HojaImagen $imagen): JsonResponse
    {
        AccesoLibros::hoja($request->user(), $hoja);
        $this->servicio->eliminarImagen($hoja, $imagen);

        return response()->json(['message' => 'Imagen eliminada.']);
    }

    private function validarInsercion(Request $request, string $tabla): array
    {
        $d = $request->validate([
            'despues_de' => ['nullable', 'integer', "exists:{$tabla},id"],
            'al_inicio' => ['sometimes', 'boolean'],
            'cantidad' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        return [...$d, 'posicion' => ! empty($d['al_inicio']) ? 0 : ($d['despues_de'] ?? null)];
    }
}