<?php

namespace App\Http\Controllers\Api;

use App\Enums\ModoSincronizacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubirCargaRequest;
use App\Http\Resources\CargaResource;
use App\Models\Carga;
use App\Services\Etl\CargaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CargaController extends Controller
{
    public function __construct(private readonly CargaService $service) {}

    /** Historial de ingestas (pantalla Informes). */
    public function index(): AnonymousResourceCollection
    {
        return CargaResource::collection(Carga::with('usuario')->latest()->paginate(25));
    }

    /** Paso 1-4: subir, extraer, transformar, validar -> errores o vista previa. */
    public function store(SubirCargaRequest $request): JsonResponse
    {
        $carga = $this->service->subir(
            $request->file('archivo'),
            $request->user(),
            $request->integer('anio', (int) now()->format('Y')),
            $request->periodoManual(),
            $request->input('hoja'),   // libros con varias hojas: la que eligió el usuario
        );

        return $this->detalle($carga)->setStatusCode(201);
    }

    public function show(Carga $carga): JsonResponse
    {
        return $this->detalle($carga);
    }

    /** Paso 5: impacto de cada modo antes de aplicar. */
    public function previsualizar(Carga $carga): JsonResponse
    {
        return response()->json($this->service->previsualizar($carga));
    }

    /** Paso 6-7: elegir modo y aplicar (con `confirmar: true` si hay advertencias que lo piden). */
    public function aplicar(Request $request, Carga $carga): JsonResponse
    {
        $request->validate([
            'modo' => ['required', Rule::enum(ModoSincronizacion::class)],
            'confirmar' => ['sometimes', 'boolean'],   // RH revisó las advertencias marcadas
        ]);
        $carga = $this->service->aplicar($carga, ModoSincronizacion::from($request->input('modo')), $request->user(), $request->boolean('confirmar'));

        return response()->json(['message' => 'Carga aplicada correctamente.', 'resumen' => $carga->resumen]);
    }

    public function descartar(Carga $carga): JsonResponse
    {
        $this->service->descartar($carga);

        return response()->json(['message' => 'Carga descartada.']);
    }

    private function detalle(Carga $carga): JsonResponse
    {
        return (new CargaResource($carga->load(['usuario', 'mensajes'])))->response();
    }
}
