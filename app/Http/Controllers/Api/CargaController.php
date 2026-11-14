<?php

namespace App\Http\Controllers\Api;

use App\Enums\EstatusCarga;
use App\Enums\ModoSincronizacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\SubirCargaRequest;
use App\Http\Resources\CargaResource;
use App\Models\AuditLog;
use App\Models\Carga;
use App\Models\User;
use App\Services\Etl\CargaService;
use App\Services\Etl\ReporteErroresExcel;
use App\Services\Hojas\LibroService;
use App\Support\Hojas\AccesoLibros;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CargaController extends Controller
{
    public function __construct(private readonly CargaService $service) {}

    /**
     * v9 — Historial de importaciones (pantalla Informes).
     * Visibilidad: las de nómina son de todo el equipo (cargas.ver); las de libros, solo de su dueño
     * (el Administrador, con informes.limpiar u hojas.ver_todas, ve todas).
     * ?q= &tipo=nomina|libro &estatus= &desde=Y-m-d &hasta=Y-m-d &usuario= &orden=reciente|antiguo|nombre|tamano &papelera=1
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $u = $request->user();
        abort_unless($u->tienePermiso('cargas.ver') || $u->tienePermiso('hojas.crear'), 403);
        $request->validate([
            'tipo' => ['nullable', Rule::in([Carga::NOMINA, Carga::LIBRO])],
            'desde' => ['nullable', 'date_format:Y-m-d'], 'hasta' => ['nullable', 'date_format:Y-m-d'],
            'orden' => ['nullable', Rule::in(['reciente', 'antiguo', 'nombre', 'tamano'])],
        ]);

        $q = $this->visibles($u)->with(['usuario', 'libro:id,nombre,deleted_at'])
            ->when($request->boolean('papelera'), fn ($q) => $q->onlyTrashed())
            ->when($request->filled('q'), fn ($q) => $q->where('nombre_original', 'like', '%'.addcslashes((string) $request->input('q'), '%_\\').'%'))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))
            ->when($request->filled('estatus'), fn ($q) => $q->where('estatus', $request->input('estatus')))
            ->when($request->filled('desde'), fn ($q) => $q->where('created_at', '>=', $request->input('desde').' 00:00:00'))
            ->when($request->filled('hasta'), fn ($q) => $q->where('created_at', '<=', $request->input('hasta').' 23:59:59'))
            ->when($request->filled('usuario') && $this->esAdministrador($u), fn ($q) => $q->where('user_id', $request->integer('usuario')));

        match ($request->input('orden', 'reciente')) {
            'antiguo' => $q->oldest(),
            'nombre' => $q->orderBy('nombre_original')->latest(),
            'tamano' => $q->orderByDesc('tamano')->latest(),
            default => $q->latest(),
        };

        return CargaResource::collection($q->paginate(30));
    }

    /**
     * v9 — Quitar una importación del historial (papelera de N días). NO borra datos de nómina.
     * ?con_libro=1 también elimina el libro que creó (importaciones como hoja).
     */
    public function eliminar(Request $request, Carga $carga, LibroService $libros): JsonResponse
    {
        $u = $request->user();
        $this->exigirVisible($u, $carga);
        abort_unless($carga->user_id === $u->id || $u->tienePermiso('informes.limpiar'), 403, 'Solo quien hizo la importación o un administrador puede quitarla del historial.');

        DB::transaction(function () use ($request, $carga, $libros, $u) {
            if ($request->boolean('con_libro') && $carga->libro && ! $carga->libro->trashed() && AccesoLibros::puede($u, $carga->libro)) {
                $libros->eliminar($carga->libro, $u);
            }
            $carga->update(['eliminado_por' => $u->id]);
            $carga->delete();
        });
        AuditLog::registrar('HISTORIAL_QUITAR', 'Carga', $carga->id, ['archivo' => $carga->nombre_original, 'tipo' => $carga->tipo]);

        return response()->json(['message' => 'Se quitó del historial. Puedes recuperarla desde la papelera durante '.config('reportes.libros.dias_papelera', 30).' días.']);
    }

    public function restaurar(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        $carga = $this->visibles($u)->onlyTrashed()->findOrFail($id);
        abort_unless($carga->user_id === $u->id || $u->tienePermiso('informes.limpiar'), 403);
        $carga->restore();
        $carga->update(['eliminado_por' => null]);
        AuditLog::registrar('HISTORIAL_RESTAURAR', 'Carga', $carga->id);

        return response()->json(['message' => 'Importación recuperada en el historial.']);
    }

    /** v9 — Limpiar el historial. body: {alcance: mias|todas, tipo?: nomina|libro}. "todas" exige informes.limpiar. */
    public function limpiar(Request $request): JsonResponse
    {
        $u = $request->user();
        $d = $request->validate([
            'alcance' => ['required', Rule::in(['mias', 'todas'])],
            'tipo' => ['nullable', Rule::in([Carga::NOMINA, Carga::LIBRO])],
        ]);
        abort_if($d['alcance'] === 'todas' && ! $u->tienePermiso('informes.limpiar'), 403, 'Solo un administrador puede limpiar el historial de todos.');

        $q = $this->visibles($u)
            ->where('estatus', '!=', EstatusCarga::Lista->value)   // una carga a medio revisar no se toca
            ->when($d['alcance'] === 'mias', fn ($q) => $q->where('user_id', $u->id))
            ->when($d['tipo'] ?? null, fn ($q, $t) => $q->where('tipo', $t));
        $n = 0;
        $q->chunkById(200, function ($lote) use (&$n, $u) {
            foreach ($lote as $c) {
                $c->update(['eliminado_por' => $u->id]);
                $c->delete();
                $n++;
            }
        });
        AuditLog::registrar('HISTORIAL_LIMPIAR', 'Carga', null, null, ['alcance' => $d['alcance'], 'tipo' => $d['tipo'] ?? null, 'cantidad' => $n]);

        return response()->json(['message' => $n === 0 ? 'No había importaciones para quitar.' : "Se quitaron {$n} importación(es) del historial.", 'cantidad' => $n]);
    }

    /** Importaciones que este usuario puede ver en el historial. */
    private function visibles(User $u): Builder
    {
        $nomina = $u->tienePermiso('cargas.ver');
        if ($this->esAdministrador($u)) {
            return Carga::query()->when(! $nomina, fn ($q) => $q->where('tipo', Carga::LIBRO));
        }

        return Carga::query()->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('tipo', Carga::LIBRO)->where('user_id', $u->id))
            ->when($nomina, fn ($q) => $q->orWhere('tipo', Carga::NOMINA)));
    }

    private function exigirVisible(User $u, Carga $carga): void
    {
        abort_unless($this->visibles($u)->whereKey($carga->id)->exists(), 404);
    }

    private function esAdministrador(User $u): bool
    {
        return $u->tienePermiso('informes.limpiar') || $u->tienePermiso('hojas.ver_todas');
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

    /** v7: el archivo original con las celdas con problema marcadas y una hoja con la lista. */
    public function errores(Carga $carga, ReporteErroresExcel $reporte): BinaryFileResponse
    {
        abort_if($carga->num_errores + $carga->num_advertencias === 0, 404, 'Esta carga no tiene errores ni avisos.');
        $nombre = 'errores_'.pathinfo($carga->nombre_original, PATHINFO_FILENAME).'.xlsx';

        return response()->download($reporte->generar($carga), $nombre)->deleteFileAfterSend();
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
