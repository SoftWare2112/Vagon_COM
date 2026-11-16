<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Publicacion;
use App\Models\PublicacionAdjunto;
use App\Models\Rol;
use App\Models\User;
use App\Services\Publicaciones\PublicacionService;
use App\Support\Publicaciones\Audiencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v10 — Publicaciones. Cada quien ve lo publicado para todos, para su rol o para su usuario, además de lo suyo.
 * Editar: el autor (publicaciones.crear) o un moderador (publicaciones.moderar). Lo que no se puede ver responde 404.
 */
class PublicacionController extends Controller
{
    public function __construct(private readonly PublicacionService $servicio) {}

    /** ?q &tipo &categoria &etiqueta &vista=todas|mias|favoritas|borradores|archivadas &page */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $request->validate([
            'tipo' => ['nullable', Rule::in(Publicacion::TIPOS)],
            'vista' => ['nullable', Rule::in(['todas', 'mias', 'favoritas', 'borradores', 'archivadas'])],
        ]);
        $vista = $request->input('vista', 'todas');
        $moderador = $u->tienePermiso('publicaciones.moderar');

        $q = Publicacion::visiblesPara($u)
            ->when($request->filled('q'), function ($q) use ($request) {
                $t = '%'.addcslashes((string) $request->input('q'), '%_\\').'%';
                $q->where(fn ($q) => $q->where('titulo', 'like', $t)->orWhere('cuerpo', 'like', $t));
            })
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->input('tipo')))
            ->when($request->filled('categoria'), fn ($q) => $q->where('categoria', $request->input('categoria')))
            ->when($request->filled('etiqueta'), fn ($q) => $q->whereJsonContains('etiquetas', mb_strtolower((string) $request->input('etiqueta'))));

        match ($vista) {
            'mias' => $q->where('user_id', $u->id),
            'borradores' => $q->where('estado', 'borrador')->when(! $moderador, fn ($q) => $q->where('user_id', $u->id)),
            'archivadas' => $q->where('estado', 'archivada')->when(! $moderador, fn ($q) => $q->where('user_id', $u->id)),
            'favoritas' => $q->whereIn('id', DB::table('publicacion_favoritas')->where('user_id', $u->id)->select('publicacion_id')),
            default => $q->where('estado', 'publicada'),
        };

        $pagina = $q->orderByDesc('fijada')->orderByRaw('COALESCE(publicada_at, created_at) DESC')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => collect($pagina->items())->map(fn (Publicacion $p) => $this->servicio->aJson($p, $u))->all(),
            'meta' => ['current_page' => $pagina->currentPage(), 'last_page' => $pagina->lastPage(), 'total' => $pagina->total(), 'per_page' => $pagina->perPage()],
        ]);
    }

    /** Cuántas publicaciones nuevas (no leídas) tiene el usuario: el número del menú. */
    public function sinLeer(Request $request): JsonResponse
    {
        $u = $request->user();
        $n = Publicacion::visiblesPara($u)->where('estado', 'publicada')->where('user_id', '!=', $u->id)
            ->whereNotIn('id', DB::table('publicacion_lecturas')->where('user_id', $u->id)->select('publicacion_id'))
            ->when($u->tienePermiso('publicaciones.moderar'), fn ($q) => $q->where(fn ($q) => $q->whereNull('caduca_at')->orWhere('caduca_at', '>', now())))
            ->count();

        return response()->json(['total' => $n]);
    }

    /** Categorías usadas (para el filtro y para sugerir al publicar). */
    public function categorias(Request $request): JsonResponse
    {
        return response()->json(Publicacion::visiblesPara($request->user())->where('estado', 'publicada')->whereNotNull('categoria')
            ->select('categoria', DB::raw('COUNT(*) as total'))->groupBy('categoria')->orderBy('categoria')->get());
    }

    /** Roles y usuarios a los que se puede dirigir una publicación. */
    public function audiencias(): JsonResponse
    {
        return response()->json([
            'roles' => Rol::orderBy('nombre')->get(['nombre', 'descripcion']),
            'usuarios' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validar($request, true);
        $p = $this->servicio->crear($d, $request->file('adjuntos', []), $request->user());

        return response()->json($this->servicio->aJson($p->fresh(), $request->user(), true), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $p = $this->visible($request, $id);
        $this->servicio->marcarLeida($p, $request->user());

        return response()->json($this->servicio->aJson($p, $request->user(), true));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $p = $this->editable($request, $id);

        return response()->json($this->servicio->aJson($this->servicio->actualizar($p, $this->validar($request, false), $request->user())->fresh(), $request->user(), true));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->servicio->eliminar($this->editable($request, $id), $request->user());

        return response()->json(['message' => 'Publicación eliminada.']);
    }

    public function publicar(Request $request, int $id): JsonResponse
    {
        $this->servicio->publicar($p = $this->editable($request, $id));

        return response()->json($this->servicio->aJson($p->fresh(), $request->user(), true));
    }

    public function archivar(Request $request, int $id): JsonResponse
    {
        $this->servicio->archivar($p = $this->editable($request, $id));

        return response()->json($this->servicio->aJson($p->fresh(), $request->user(), true));
    }

    /** Moderación: fijar arriba del muro o marcar como oficial. body: {fijada?, oficial?} */
    public function moderar(Request $request, int $id): JsonResponse
    {
        $p = $this->visible($request, $id);
        $d = $request->validate(['fijada' => ['sometimes', 'boolean'], 'oficial' => ['sometimes', 'boolean']]);
        $p->update($d);

        return response()->json($this->servicio->aJson($p->fresh(), $request->user(), true));
    }

    public function favorita(Request $request, int $id): JsonResponse
    {
        return response()->json(['favorita' => $this->servicio->alternarFavorita($this->visible($request, $id), $request->user())]);
    }

    public function agregarAdjuntos(Request $request, int $id): JsonResponse
    {
        $p = $this->editable($request, $id);
        $request->validate(['adjuntos' => ['required', 'array', 'max:'.PublicacionService::MAX_ADJUNTOS], 'adjuntos.*' => ['file']]);
        $this->servicio->guardarAdjuntos($p, $request->file('adjuntos', []));

        return response()->json($this->servicio->aJson($p->fresh(), $request->user(), true));
    }

    public function quitarAdjunto(Request $request, int $id, PublicacionAdjunto $adjunto): JsonResponse
    {
        $p = $this->editable($request, $id);
        $this->servicio->quitarAdjunto($p, $adjunto);

        return response()->json($this->servicio->aJson($p->fresh(), $request->user(), true));
    }

    /** Archivo adjunto con la sesión. ?mini=1 miniatura · ?descargar=1 como descarga (si el autor lo permite). */
    public function adjunto(Request $request, int $id, PublicacionAdjunto $adjunto): StreamedResponse
    {
        $p = $this->visible($request, $id);
        abort_if($adjunto->publicacion_id !== $p->id, 404);
        $descargar = $request->boolean('descargar');
        $vistaPrevia = $adjunto->esImagen() || $adjunto->mime === 'application/pdf';
        abort_if(($descargar || ! $vistaPrevia) && ! $p->permite_descarga && ! $p->editablePor($request->user()), 403, 'El autor no permitió descargar este archivo.');

        $ruta = $request->boolean('mini') && $adjunto->miniatura ? $adjunto->miniatura : $adjunto->ruta;
        $disco = Storage::disk(config('reportes.etl.disco', 'local'));
        abort_unless($disco->exists($ruta), 404);
        $cabeceras = ['Content-Type' => $ruta === $adjunto->miniatura ? 'image/jpeg' : $adjunto->mime, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff'];

        return $descargar ? $disco->download($ruta, $adjunto->nombre, $cabeceras) : $disco->response($ruta, $adjunto->nombre, $cabeceras);
    }

    /** Quién la ha visto (autor o moderador). */
    public function lecturas(Request $request, int $id): JsonResponse
    {
        $p = $this->editable($request, $id);

        return response()->json($p->lectores()->orderByPivot('leida_at', 'desc')->get(['users.id', 'users.name'])
            ->map(fn (User $l) => ['id' => $l->id, 'nombre' => $l->name, 'leida' => \Illuminate\Support\Carbon::parse($l->pivot->leida_at)->format('d/m/Y H:i')]));
    }

    // ───────────────────────── Auxiliares ─────────────────────────

    private function visible(Request $request, int $id): Publicacion
    {
        return Publicacion::visiblesPara($request->user())->findOrFail($id);
    }

    private function editable(Request $request, int $id): Publicacion
    {
        $p = $this->visible($request, $id);
        abort_unless($p->editablePor($request->user()), 403, 'Solo el autor o un moderador puede cambiar esta publicación.');

        return $p;
    }

    private function validar(Request $request, bool $nueva): array
    {
        $req = $nueva ? 'required' : 'sometimes';
        $d = $request->validate([
            'tipo' => [$nueva ? 'required' : 'prohibited', Rule::in(Publicacion::TIPOS)],
            'titulo' => [$req, 'string', 'min:3', 'max:160'],
            'cuerpo' => ['nullable', 'string', 'max:20000'],
            'categoria' => ['nullable', 'string', 'max:60'],
            'etiquetas' => ['nullable', 'array', 'max:10'],
            'etiquetas.*' => ['string', 'max:30'],
            'audiencia' => [$nueva ? 'nullable' : 'sometimes', Rule::in([Audiencia::TODOS, Audiencia::ROLES, Audiencia::USUARIOS])],
            'audiencia_ids' => ['nullable', 'array', 'max:500'],
            'estado' => ['nullable', Rule::in(['borrador', 'publicada'])],
            'fijada' => ['sometimes', 'boolean'],
            'oficial' => ['sometimes', 'boolean'],
            'permite_descarga' => ['sometimes', 'boolean'],
            'permite_copia' => ['sometimes', 'boolean'],
            'video_url' => ['nullable', 'string', 'max:500'],
            'caduca_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'adjuntos' => ['nullable', 'array', 'max:'.PublicacionService::MAX_ADJUNTOS],
            'adjuntos.*' => ['file'],
        ], [
            'titulo.min' => 'El título debe tener al menos 3 letras.',
            'caduca_at.after_or_equal' => 'La fecha de caducidad no puede ser anterior a hoy.',
        ]);
        if (! empty($d['caduca_at'])) {
            $d['caduca_at'] = $d['caduca_at'].' 23:59:59';   // vigente todo ese día
        }
        unset($d['adjuntos']);

        return $d;
    }
}
