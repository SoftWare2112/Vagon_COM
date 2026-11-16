<?php

namespace App\Services\Publicaciones;

use App\Exceptions\ReglaNegocioException;
use App\Models\AuditLog;
use App\Models\Publicacion;
use App\Models\PublicacionAdjunto;
use App\Models\User;
use App\Support\Publicaciones\Audiencia;
use App\Support\Publicaciones\EnlaceVideo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * v10 — Publicaciones: crear, editar, publicar, archivar; adjuntos (documentos e imágenes), enlaces a video
 * v11: se retiró el tipo "trabajo de la plataforma" (dependía de las hojas de cálculo).
 */
class PublicacionService
{
    /** Documentos permitidos (extensión => tipos MIME reales aceptados). */
    public const DOCUMENTOS = [
        'pdf' => ['application/pdf'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'doc' => ['application/msword', 'application/octet-stream', 'application/CDFV2'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
    ];

    public const IMAGENES = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'gif' => ['image/gif'], 'webp' => ['image/webp']];

    public const MAX_KB_DOCUMENTO = 20480;

    public const MAX_KB_IMAGEN = 8192;

    public const MAX_ADJUNTOS = 20;

    private function disco(): Filesystem
    {
        return Storage::disk(config('reportes.etl.disco', 'local'));
    }

    // ───────────────────────── Crear y editar ─────────────────────────

    /** @param list<UploadedFile> $archivos */
    public function crear(array $d, array $archivos, User $u): Publicacion
    {
        $guardados = [];
        try {
            return DB::transaction(function () use ($d, $archivos, $u, &$guardados) {
                $p = new Publicacion(['user_id' => $u->id, 'tipo' => $d['tipo'], 'estado' => 'borrador']);
                $this->asignar($p, $d, $u, true);
                $p->save();
                $guardados = $this->guardarAdjuntos($p, $archivos);
                $this->validarContenido($p);
                if (($d['estado'] ?? 'borrador') === 'publicada') {
                    $p->update(['estado' => 'publicada', 'publicada_at' => now()]);
                }
                AuditLog::registrar('CREAR', 'Publicacion', $p->id, null, ['titulo' => $p->titulo, 'tipo' => $p->tipo, 'estado' => $p->estado]);

                return $p;
            });
        } catch (\Throwable $e) {
            foreach ($guardados as $r) {
                $this->disco()->delete($r);
            }
            throw $e;
        }
    }

    public function actualizar(Publicacion $p, array $d, User $u): Publicacion
    {
        $antes = $p->only(['titulo', 'estado', 'audiencia']);
        $this->asignar($p, $d, $u, false);
        $this->validarContenido($p);
        $p->save();
        AuditLog::registrar('EDITAR', 'Publicacion', $p->id, $antes, $p->only(['titulo', 'estado', 'audiencia']));

        return $p;
    }

    /** Copia los campos del formulario a la publicación (solo los que vienen). */
    private function asignar(Publicacion $p, array $d, User $u, bool $nueva): void
    {
        foreach (['cuerpo', 'categoria', 'caduca_at'] as $campo) {
            if (array_key_exists($campo, $d)) {
                $valor = is_string($d[$campo]) ? trim($d[$campo]) : $d[$campo];
                $p->{$campo} = $valor === '' ? null : $valor;
            }
        }
        foreach (['permite_descarga', 'permite_copia'] as $campo) {
            if (array_key_exists($campo, $d)) {
                $p->{$campo} = filter_var($d[$campo], FILTER_VALIDATE_BOOLEAN);   // "0"/"1" de un formulario
            }
        }
        if (array_key_exists('titulo', $d)) {
            $p->titulo = trim((string) $d['titulo']);
        }
        if (array_key_exists('etiquetas', $d)) {
            $p->etiquetas = array_values(array_unique(array_filter(array_map(fn ($e) => mb_substr(trim(mb_strtolower((string) $e)), 0, 30), (array) $d['etiquetas'])))) ?: null;
        }
        if (array_key_exists('audiencia', $d) || $nueva) {
            $aud = Audiencia::normalizar($d['audiencia'] ?? Audiencia::TODOS, (array) ($d['audiencia_ids'] ?? []));
            if ($aud === null) {
                throw new ReglaNegocioException('Elige al menos un rol o un usuario para la publicación, o publícala para todos.', 422, ['codigo' => 'AUDIENCIA_VACIA']);
            }
            [$p->audiencia, $p->audiencia_ids] = $aud;
        }
        if (array_key_exists('video_url', $d)) {
            $video = $d['video_url'] ? EnlaceVideo::analizar($d['video_url']) : null;
            if ($d['video_url'] && $video === null) {
                throw new ReglaNegocioException('El enlace del video no es válido. Copia la dirección completa que empieza con https://', 422, ['codigo' => 'VIDEO_INVALIDO']);
            }
            $p->video_url = $d['video_url'] ? trim($d['video_url']) : null;
            $p->video_proveedor = $video['proveedor'] ?? null;
        }
        if ($p->esModerador($u)) {
            foreach (['fijada', 'oficial'] as $campo) {
                if (array_key_exists($campo, $d)) {
                    $p->{$campo} = filter_var($d[$campo], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }
    }

    private function validarContenido(Publicacion $p): void
    {
        $falta = match ($p->tipo) {
            'texto' => ! trim((string) $p->cuerpo) ? 'Escribe el texto de la publicación.' : null,
            'video' => ! $p->video_url ? 'Pega el enlace del video.' : null,
            'documentos' => ! $p->adjuntos()->exists() ? 'Agrega al menos un documento.' : null,
            'imagenes' => ! $p->adjuntos()->exists() ? 'Agrega al menos una imagen.' : null,
            default => null,
        };
        if ($falta) {
            throw new ReglaNegocioException($falta, 422, ['codigo' => 'CONTENIDO_FALTANTE']);
        }
    }

    public function publicar(Publicacion $p): void
    {
        $this->validarContenido($p);
        $p->update(['estado' => 'publicada', 'publicada_at' => $p->publicada_at ?? now()]);
        AuditLog::registrar('PUBLICAR', 'Publicacion', $p->id);
    }

    public function archivar(Publicacion $p): void
    {
        $p->update(['estado' => 'archivada', 'fijada' => false]);
        AuditLog::registrar('ARCHIVAR', 'Publicacion', $p->id);
    }

    public function eliminar(Publicacion $p, User $u): void
    {
        $p->delete();   // borrado lógico; los archivos se conservan por si hay que recuperarla
        AuditLog::registrar('ELIMINAR', 'Publicacion', $p->id, ['titulo' => $p->titulo, 'autor' => $p->user_id], null, $u->id);
    }

    // ───────────────────────── Adjuntos ─────────────────────────

    /**
     * Revisa extensión, tamaño y el tipo REAL del contenido (no solo el nombre del archivo).
     * @param list<UploadedFile> $archivos
     * @return list<string>  rutas guardadas
     */
    public function guardarAdjuntos(Publicacion $p, array $archivos): array
    {
        if ($archivos === []) {
            return [];
        }
        $actuales = $p->adjuntos()->count();
        if ($actuales + count($archivos) > self::MAX_ADJUNTOS) {
            throw new ReglaNegocioException('Una publicación puede tener hasta '.self::MAX_ADJUNTOS.' archivos.', 422, ['codigo' => 'DEMASIADOS_ARCHIVOS']);
        }
        $permitidos = $p->tipo === 'imagenes' ? self::IMAGENES : ($p->tipo === 'documentos' ? self::DOCUMENTOS + self::IMAGENES : self::IMAGENES + self::DOCUMENTOS);
        $guardadas = [];
        foreach (array_values($archivos) as $i => $archivo) {
            $ext = strtolower($archivo->getClientOriginalExtension());
            $mime = (string) $archivo->getMimeType();   // detectado por el contenido
            $nombre = mb_substr($archivo->getClientOriginalName(), 0, 200);
            if (! isset($permitidos[$ext]) || ! in_array($mime, $permitidos[$ext], true)) {
                throw new ReglaNegocioException("«{$nombre}»: ese tipo de archivo no se puede publicar aquí.", 422, ['codigo' => 'ARCHIVO_NO_PERMITIDO']);
            }
            $esImagen = isset(self::IMAGENES[$ext]);
            $maxKb = $esImagen ? self::MAX_KB_IMAGEN : self::MAX_KB_DOCUMENTO;
            if ($archivo->getSize() > $maxKb * 1024) {
                throw new ReglaNegocioException("«{$nombre}» pesa más de ".round($maxKb / 1024).' MB.', 422, ['codigo' => 'ARCHIVO_GRANDE']);
            }
            $carpeta = "publicaciones/{$p->id}";
            $ruta = $archivo->storeAs($carpeta, Str::random(32).'.'.$ext, config('reportes.etl.disco', 'local'));
            $guardadas[] = $ruta;
            [$ancho, $alto] = $esImagen ? (@getimagesize($archivo->getRealPath()) ?: [null, null]) : [null, null];
            $mini = $esImagen ? $this->miniatura($archivo->getRealPath(), $carpeta) : null;
            if ($mini) {
                $guardadas[] = $mini;
            }
            PublicacionAdjunto::create([
                'publicacion_id' => $p->id, 'nombre' => $nombre, 'ruta' => $ruta, 'miniatura' => $mini, 'mime' => $esImagen ? $mime : (self::DOCUMENTOS[$ext][0]),
                'tamano' => $archivo->getSize(), 'ancho' => $ancho, 'alto' => $alto, 'orden' => $actuales + $i,
            ]);
        }

        return $guardadas;
    }

    public function quitarAdjunto(Publicacion $p, PublicacionAdjunto $a): void
    {
        abort_if($a->publicacion_id !== $p->id, 404);
        if (in_array($p->tipo, ['documentos', 'imagenes'], true) && $p->adjuntos()->count() <= 1 && $p->estado === 'publicada') {
            throw new ReglaNegocioException('La publicación debe conservar al menos un archivo.', 422, ['codigo' => 'ULTIMO_ARCHIVO']);
        }
        $this->disco()->delete(array_filter([$a->ruta, $a->miniatura]));
        $a->delete();
    }

    /** Miniatura de 480 px de ancho (si el servidor tiene GD). Las fotos grandes cargan rápido en el muro. */
    private function miniatura(string $origen, string $carpeta): ?string
    {
        if (! function_exists('imagecreatefromstring') || filesize($origen) > self::MAX_KB_IMAGEN * 1024) {
            return null;
        }
        $img = @imagecreatefromstring((string) file_get_contents($origen));
        if (! $img) {
            return null;
        }
        if (imagesx($img) <= 480) {
            imagedestroy($img);

            return null;   // ya es pequeña: se usa la original
        }
        $mini = imagescale($img, 480);
        ob_start();
        imagejpeg($mini, null, 82);
        $ruta = $carpeta.'/mini_'.Str::random(24).'.jpg';
        $this->disco()->put($ruta, (string) ob_get_clean());
        imagedestroy($img);
        imagedestroy($mini);

        return $ruta;
    }

    // ───────────────────────── Lecturas y favoritas ─────────────────────────

    public function marcarLeida(Publicacion $p, User $u): void
    {
        if ($p->estado === 'publicada' && $p->user_id !== $u->id) {
            DB::table('publicacion_lecturas')->insertOrIgnore(['publicacion_id' => $p->id, 'user_id' => $u->id, 'leida_at' => now()]);
        }
    }

    public function alternarFavorita(Publicacion $p, User $u): bool
    {
        $q = DB::table('publicacion_favoritas')->where(['publicacion_id' => $p->id, 'user_id' => $u->id]);
        if ($q->exists()) {
            $q->delete();

            return false;
        }
        DB::table('publicacion_favoritas')->insert(['publicacion_id' => $p->id, 'user_id' => $u->id]);

        return true;
    }

    // ───────────────────────── Respuesta ─────────────────────────

    public function aJson(Publicacion $p, User $u, bool $detalle = false): array
    {
        $p->loadMissing(['autor:id,name,rol_id', 'autor.rol:id,nombre', 'adjuntos']);
        $puedeEditar = $p->editablePor($u);
        $video = $p->video_url ? EnlaceVideo::analizar($p->video_url) : null;
        $cuerpo = (string) $p->cuerpo;

        $json = [
            'id' => $p->id, 'tipo' => $p->tipo, 'titulo' => $p->titulo,
            'cuerpo' => $detalle ? $cuerpo : (mb_strlen($cuerpo) > 280 ? rtrim(mb_substr($cuerpo, 0, 280)).'…' : $cuerpo),
            'categoria' => $p->categoria, 'etiquetas' => $p->etiquetas ?? [], 'estado' => $p->estado,
            'fijada' => $p->fijada, 'oficial' => $p->oficial, 'permite_descarga' => $p->permite_descarga, 'permite_copia' => $p->permite_copia,
            'video' => $video ? ['url' => $p->video_url, ...$video] : null,
            'autor' => ['id' => $p->autor?->id, 'nombre' => $p->autor?->name, 'rol' => $p->autor?->rol?->nombre],
            'publicada' => $p->publicada_at?->format('d/m/Y H:i'), 'publicada_iso' => ($p->publicada_at ?? $p->created_at)?->toIso8601String(),
            'caduca' => $p->caduca_at?->format('Y-m-d'),
            'adjuntos' => $p->adjuntos->map(fn (PublicacionAdjunto $a) => [
                'id' => $a->id, 'nombre' => $a->nombre, 'mime' => $a->mime, 'tamano' => $a->tamano, 'ancho' => $a->ancho, 'alto' => $a->alto,
                'es_imagen' => $a->esImagen(), 'url' => "/api/publicaciones/{$p->id}/adjuntos/{$a->id}",
                'miniatura' => "/api/publicaciones/{$p->id}/adjuntos/{$a->id}?mini=1",
            ])->values()->all(),
            'trabajo' => null,   // v11: el tipo "trabajo de la plataforma" se retiró junto con las hojas de cálculo
            'leida' => $p->user_id === $u->id || DB::table('publicacion_lecturas')->where(['publicacion_id' => $p->id, 'user_id' => $u->id])->exists(),
            'favorita' => DB::table('publicacion_favoritas')->where(['publicacion_id' => $p->id, 'user_id' => $u->id])->exists(),
            'puede_editar' => $puedeEditar,
        ];
        if ($puedeEditar) {
            $json['audiencia'] = $p->audiencia;
            $json['audiencia_ids'] = $p->audiencia_ids ?? [];
            $json['lecturas'] = DB::table('publicacion_lecturas')->where('publicacion_id', $p->id)->count();
        } else {
            $json['audiencia'] = $p->audiencia;   // solo el tipo; no la lista de destinatarios
        }
        return $json;
    }
}
