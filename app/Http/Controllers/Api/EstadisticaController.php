<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Periodo;
use App\Services\Estadisticas\EstadisticasService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/estadisticas?periodo_id= — datos de la pantalla "Gráficas" (v6). */
class EstadisticaController extends Controller
{
    public function index(Request $request, EstadisticasService $servicio): JsonResponse
    {
        $request->validate(['periodo_id' => ['nullable', 'integer', 'exists:periodos,id']]);
        $periodo = $request->filled('periodo_id') ? Periodo::find($request->integer('periodo_id')) : Periodo::masReciente();
        if (! $periodo) {
            return response()->json(['periodo' => null, 'kpis' => null, 'serie' => [], 'top_faltas' => [], 'por_puesto' => []]);
        }

        return response()->json($servicio->para($periodo));
    }
}
