<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ajuste;
use App\Models\Periodo;
use App\Models\Puesto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    public function puestos(): JsonResponse
    {
        return response()->json(Puesto::orderBy('nombre')->get(['id', 'nombre']));
    }

    public function periodos(): JsonResponse
    {
        return response()->json(Periodo::orderByDesc('fecha_inicio')->get()->map(fn ($p) => [
            'id' => $p->id, 'etiqueta' => $p->nombre(), 'ejercicio' => $p->ejercicio, 'numero' => $p->numero,
        ]));
    }

    public function ajustes(): JsonResponse
    {
        return response()->json(collect(Ajuste::PERMITIDOS)->mapWithKeys(fn ($v, $k) => [$k => Ajuste::valor($k)]));
    }

    public function guardarAjustes(Request $request): JsonResponse
    {
        $reglas = collect(Ajuste::PERMITIDOS)->mapWithKeys(fn ($v, $k) => [$k => ['sometimes', ...explode('|', $v[0])]])->all();
        foreach ($request->validate($reglas) as $clave => $valor) {
            Ajuste::guardar($clave, (string) $valor);
        }

        return $this->ajustes();
    }
}
