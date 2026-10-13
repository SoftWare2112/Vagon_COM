<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Periodo;
use App\Models\Registro;
use App\Repositories\Contracts\RegistroRepository;
use App\Repositories\FiltrosTabla;
use App\Services\Registros\PresentadorFila;
use App\Services\Registros\RegistroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador delgado: valida, delega y responde.
 * La tabla es GENÉRICA: el servidor manda las columnas (en orden) y cada fila como {clave: valor},
 * así una columna nueva aparece sola en la web, el Excel y el PDF.
 */
class RegistroController extends Controller
{
    public function __construct(
        private readonly RegistroRepository $repo,
        private readonly PresentadorFila $presentador,
    ) {}

    /** GET /api/registros?periodo_id=&nombre=&puesto=&ingreso_desde=dd/mm/aaaa&ingreso_hasta=&faltas=&orden=&direccion= */
    public function index(Request $request): JsonResponse
    {
        $periodo = $request->filled('periodo_id') ? Periodo::findOrFail($request->integer('periodo_id')) : Periodo::masReciente();
        if (! $periodo) {
            return response()->json(['data' => [], 'columnas' => [], 'periodo' => null, 'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1, 'per_page' => 50]]);
        }

        $columnas = $this->presentador->columnas($periodo);
        $pagina = $this->repo->tabla(FiltrosTabla::desdeRequest($request, $periodo->id));

        return response()->json([
            'periodo' => ['id' => $periodo->id, 'etiqueta' => $periodo->etiqueta,
                'inicio' => $periodo->fecha_inicio->format('Y-m-d'), 'fin' => $periodo->fecha_fin->format('Y-m-d')],
            'columnas' => $columnas,
            'data' => collect($pagina->items())->map(fn (Registro $r) => $this->fila($r, $columnas))->values(),
            'meta' => ['total' => $pagina->total(), 'current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'per_page' => $pagina->perPage()],
        ]);
    }

    /** "Vista en resumen": la misma fila, con la misma estructura, en un modal. */
    public function resumen(Registro $registro): JsonResponse
    {
        $registro->load(['empleado', 'puesto', 'domingos', 'periodo']);
        $columnas = $this->presentador->columnas($registro->periodo);

        return response()->json([
            ...$this->fila($registro, $columnas),
            'columnas' => $columnas,
            'periodo' => $registro->periodo->etiqueta,
            'faltas_mes' => $this->repo->faltasDelMes($registro),
        ]);
    }

    /** PATCH /api/registros/{registro}  body: {version, valores: {clave: valor}} */
    public function actualizar(Request $request, Registro $registro, RegistroService $service): JsonResponse
    {
        $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'valores' => ['required', 'array', 'max:100'],
        ]);
        $reg = $service->guardar($registro, $request->input('valores'), $request->integer('version'), $request->user());

        return response()->json($this->fila($reg, $this->presentador->columnas($reg->periodo)));
    }

    /** Quita la fila de la vista (soft delete) -> Histórico. */
    public function destroy(Request $request, Registro $registro, RegistroService $service): JsonResponse
    {
        $service->eliminar($registro, $request->user());

        return response()->json(['message' => "{$registro->empleado->nombre_completo} se movió al Histórico de {$registro->periodo->etiqueta}."]);
    }

    public function fila(Registro $r, array $columnas): array
    {
        return [
            'id' => $r->id,
            'empleado_id' => $r->empleado_id,
            'numero_empleado' => $r->empleado?->numero_empleado,
            'version' => $r->version,
            'origen' => $r->carga_id ? 'excel' : 'manual',
            'campos_editados' => $r->campos_editados ?? [],
            'valores' => $this->presentador->valores($r, $columnas),
        ];
    }
}
