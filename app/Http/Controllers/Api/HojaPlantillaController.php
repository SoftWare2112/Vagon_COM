<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HojaPlantilla;
use Illuminate\Http\JsonResponse;

/** v8 — Plantillas para crear hojas ya armadas (las predefinidas vienen con la plataforma). */
class HojaPlantillaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(HojaPlantilla::orderByDesc('sistema')->orderBy('nombre')->get()->map(fn (HojaPlantilla $p) => [
            'id' => $p->id, 'clave' => $p->clave, 'nombre' => $p->nombre, 'descripcion' => $p->descripcion, 'sistema' => $p->sistema,
            'columnas' => count($p->definicion['columnas'] ?? []), 'filas' => $p->definicion['filas'] ?? null,
        ]));
    }
}
