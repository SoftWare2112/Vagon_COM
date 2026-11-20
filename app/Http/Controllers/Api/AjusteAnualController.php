<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AjusteAnualIsr;
use App\Models\Empresa;
use App\Models\PeriodoNomina;
use App\Services\Nomina\AjusteAnualService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** v13 — Cálculo anual del ISR (art. 97 LISR): consultar, calcular, marcar «declara por su cuenta», aplicar y quitar. */
class AjusteAnualController extends Controller
{
    public function __construct(private readonly AjusteAnualService $ajuste) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100']]);

        return response()->json($this->ajuste->listar(Empresa::actual(), (int) $d['ejercicio']));
    }

    public function calcular(Request $request): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100']]);

        return response()->json($this->ajuste->calcular(Empresa::actual(), (int) $d['ejercicio'], $request->user()));
    }

    public function declaracion(Request $request, AjusteAnualIsr $ajuste): JsonResponse
    {
        $d = $request->validate(['declara_por_su_cuenta' => ['required', 'boolean']]);

        return response()->json($this->ajuste->marcarDeclaracion($ajuste, (bool) $d['declara_por_su_cuenta'], $request->user()));
    }

    public function aplicar(Request $request): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100'], 'periodo_id' => ['required', 'integer', 'exists:periodos_nomina,id']]);
        $r = $this->ajuste->aplicar(Empresa::actual(), (int) $d['ejercicio'], PeriodoNomina::findOrFail($d['periodo_id']), $request->user());

        return response()->json([...$r, 'ajuste' => $this->ajuste->listar(Empresa::actual(), (int) $d['ejercicio'])]);
    }

    public function quitar(Request $request): JsonResponse
    {
        $d = $request->validate(['ejercicio' => ['required', 'integer', 'between:2000,2100']]);
        $this->ajuste->quitar(Empresa::actual(), (int) $d['ejercicio'], $request->user());

        return response()->json($this->ajuste->listar(Empresa::actual(), (int) $d['ejercicio']));
    }
}
