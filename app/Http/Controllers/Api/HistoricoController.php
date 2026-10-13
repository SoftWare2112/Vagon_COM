<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Registro;
use App\Services\Registros\PresentadorFila;
use App\Services\Registros\RegistroService;
use App\Support\Texto;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Filas eliminadas de la vista pero conservadas en la BD (permanente).
 * Consultar NO restaura: se ve la fila tal como estaba en SU quincena.
 * Restaurar la regresa a esa misma quincena (no a la última carga).
 */
class HistoricoController extends Controller
{
    public function index(Request $request, PresentadorFila $presentador): JsonResponse
    {
        $request->validate([
            'desde' => ['nullable', 'date_format:d/m/Y'],
            'hasta' => ['nullable', 'date_format:d/m/Y'],
            'usuario_id' => ['nullable', 'integer'],
            'periodo_id' => ['nullable', 'integer'],
            'nombre' => ['nullable', 'string', 'max:100'],
        ]);

        $q = Registro::onlyTrashed()->with(['empleado', 'puesto', 'periodo', 'domingos', 'eliminadoPor:id,name'])
            ->when($request->filled('nombre'), fn ($q) => $q->whereHas('empleado', fn ($e) => $e->where('nombre_normalizado', 'like', '%'.Texto::normalizar($request->input('nombre')).'%')))
            ->when($request->filled('periodo_id'), fn ($q) => $q->where('periodo_id', $request->integer('periodo_id')))
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('deleted_by', $request->integer('usuario_id')))
            ->when($request->filled('desde'), fn ($q) => $q->where('deleted_at', '>=', Carbon::createFromFormat('!d/m/Y', $request->input('desde'))))
            ->when($request->filled('hasta'), fn ($q) => $q->where('deleted_at', '<', Carbon::createFromFormat('!d/m/Y', $request->input('hasta'))->addDay()))
            ->orderByDesc('deleted_at');

        $columnasPorPeriodo = [];

        return response()->json($q->paginate(50)->through(function (Registro $r) use ($presentador, &$columnasPorPeriodo) {
            $cols = $columnasPorPeriodo[$r->periodo_id] ??= $presentador->columnas($r->periodo);

            return [
                'id' => $r->id,
                'numero_empleado' => $r->empleado->numero_empleado,
                'nombre_completo' => $r->empleado->nombre_completo,
                'periodo' => $r->periodo->etiqueta.' '.$r->periodo->fecha_fin->format('Y'),
                'eliminado' => $r->deleted_at->format('d/m/Y H:i'),
                'eliminado_por' => $r->eliminadoPor?->name ?? 'Sistema',
                'motivo' => $r->motivo_baja === 'REEMPLAZO_CARGA' ? 'No venía en una carga (Reemplazar)' : 'Eliminado manualmente',
                'columnas' => $cols,                               // para ver la fila tal como estaba
                'valores' => $presentador->valores($r, $cols),
            ];
        }));
    }

    public function restaurar(int $id, RegistroService $service): JsonResponse
    {
        $registro = Registro::onlyTrashed()->with(['empleado', 'periodo'])->findOrFail($id);
        $service->restaurar($registro);

        return response()->json(['message' => "{$registro->empleado->nombre_completo} se restauró en {$registro->periodo->etiqueta} y se conservará en futuras cargas de esa quincena."]);
    }
}
