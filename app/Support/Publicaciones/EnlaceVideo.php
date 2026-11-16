<?php

namespace App\Support\Publicaciones;

/**
 * v10 — Los videos solo se publican como ENLACE (no se suben al servidor).
 * Reconoce YouTube, Vimeo, Google Drive, Microsoft Stream/SharePoint/OneDrive y Teams; de los primeros
 * tres sabe armar el reproductor incrustado. Cualquier otro https:// se muestra como enlace.
 * Lógica pura (sin Laravel).
 */
final class EnlaceVideo
{
    /** @return array{proveedor: string, id: ?string, incrustar: ?string}|null  null si no es un enlace https válido */
    public static function analizar(string $url): ?array
    {
        $url = trim($url);
        if (mb_strlen($url) > 500 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return null;
        }
        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $p['host']));
        $ruta = $p['path'] ?? '';
        parse_str($p['query'] ?? '', $q);

        // YouTube: watch?v=ID, youtu.be/ID, /shorts/ID, /embed/ID, /live/ID
        if (in_array($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
            $id = $host === 'youtu.be' ? ltrim($ruta, '/') : ($q['v'] ?? null);
            if (! $id && preg_match('#^/(shorts|embed|live)/([^/?]+)#', $ruta, $m)) {
                $id = $m[2];
            }
            if ($id && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
                return ['proveedor' => 'youtube', 'id' => $id, 'incrustar' => "https://www.youtube-nocookie.com/embed/{$id}"];
            }

            return null;
        }
        if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
            if (preg_match('#/(?:video/)?(\d{6,12})(?:/|$)#', $ruta, $m)) {
                return ['proveedor' => 'vimeo', 'id' => $m[1], 'incrustar' => "https://player.vimeo.com/video/{$m[1]}"];
            }

            return null;
        }
        if ($host === 'drive.google.com' && preg_match('#/file/d/([A-Za-z0-9_-]{10,})#', $ruta, $m)) {
            return ['proveedor' => 'drive', 'id' => $m[1], 'incrustar' => "https://drive.google.com/file/d/{$m[1]}/preview"];
        }
        if (str_ends_with($host, '.sharepoint.com') || str_ends_with($host, 'microsoftstream.com') || $host === '1drv.ms' || $host === 'onedrive.live.com') {
            return ['proveedor' => 'microsoft', 'id' => null, 'incrustar' => null];   // requiere iniciar sesión en Microsoft: se abre aparte
        }
        if (str_ends_with($host, 'teams.microsoft.com')) {
            return ['proveedor' => 'teams', 'id' => null, 'incrustar' => null];
        }

        return ['proveedor' => 'otro', 'id' => null, 'incrustar' => null];
    }
}
