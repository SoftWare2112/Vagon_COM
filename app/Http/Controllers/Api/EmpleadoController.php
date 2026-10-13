<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CrearEmpleadoRequest;
use App\Models\Periodo;
use App\Services\Empleados\EmpleadoService;
use Illuminate\Http\JsonResponse;

class EmpleadoController extends Controller
{
    /** Alta manual: crea (o reutiliza) al empleado y su fila en la quincena. Número MSI automático. */
    public function store(CrearEmpleadoRequest $request, EmpleadoService $service): JsonResponse
    {
        $datos = $request->datos();
        $periodo = isset($datos['periodo_id']) ? Periodo::findOrFail($datos['periodo_id']) : Periodo::masReciente();
        abort_if(! $periodo, 422, 'Primero carga un archivo para crear la quincena.');

        $registro = $service->crear($datos, $periodo, $request->user());

        return response()->json(['id' => $registro->id, 'numero_empleado' => $registro->empleado->numero_empleado], 201);
    }
}
