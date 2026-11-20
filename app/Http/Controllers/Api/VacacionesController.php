<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\Vacacion;
use App\Services\Nomina\VacacionesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** v13 — Vacaciones del empleado: saldo por año de servicio, registrar y cancelar. */
class VacacionesController extends Controller
{
    public function __construct(private readonly VacacionesService $vacaciones) {}

    public function index(Empleado $empleado): JsonResponse
    {
        return response()->json($this->vacaciones->resumen($empleado));
    }

    public function store(Request $request, Empleado $empleado): JsonResponse
    {
        $d = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'dias' => ['required', 'integer', 'between:1,60'],
            'anio_servicio' => ['nullable', 'integer', 'min:1'],
            'notas' => ['nullable', 'string', 'max:200'],
        ]);
        $this->vacaciones->registrar($empleado, $d, $request->user());

        return response()->json($this->vacaciones->resumen($empleado), 201);
    }

    public function destroy(Request $request, Vacacion $vacacion): JsonResponse
    {
        $empleado = $vacacion->empleado;
        $this->vacaciones->cancelar($vacacion, $request->user());

        return response()->json($this->vacaciones->resumen($empleado));
    }
}
