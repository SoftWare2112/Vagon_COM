<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InformeExportado;
use App\Models\Periodo;
use App\Repositories\FiltrosTabla;
use App\Services\Exportacion\ExportacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportacionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = InformeExportado::with('usuario:id,name')->latest()
            ->when(! $user->tienePermiso('usuarios.gestionar'), fn ($q) => $q->where('user_id', $user->id)); // cada quien ve los suyos; admin ve todo

        return response()->json($q->paginate(25)->through(fn ($i) => [
            'id' => $i->id, 'nombre' => $i->nombre, 'formato' => $i->formato, 'filas' => $i->filas,
            'usuario' => $i->usuario->name, 'fecha' => $i->created_at->format('d/m/Y H:i'),
        ]));
    }

    public function store(Request $request, ExportacionService $service): JsonResponse
    {
        $request->validate(['formato' => ['required', 'in:PDF,EXCEL'], 'periodo_id' => ['nullable', 'integer', 'exists:periodos,id']]);
        $periodo = $request->filled('periodo_id') ? Periodo::find($request->integer('periodo_id')) : Periodo::masReciente();
        abort_if(! $periodo, 422, 'No hay datos para exportar.');

        $informe = $service->generar($request->input('formato'), FiltrosTabla::desdeRequest($request, $periodo->id), $request->user());

        return response()->json(['id' => $informe->id, 'nombre' => $informe->nombre], 201);
    }

    /** Descarga autorizada desde storage PRIVADO (nunca una URL pública). */
    public function descargar(Request $request, InformeExportado $informe): StreamedResponse
    {
        $user = $request->user();
        abort_unless($informe->user_id === $user->id || $user->tienePermiso('usuarios.gestionar'), 403);

        return Storage::disk('local')->download($informe->ruta_archivo, $informe->nombre);
    }
}
