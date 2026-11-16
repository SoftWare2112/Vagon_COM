<?php

namespace App\Support\Publicaciones;

/**
 * v10 — A quién va dirigida una publicación: a todos, a ciertos roles o a ciertos usuarios.
 * Lógica pura (sin Laravel); la consulta equivalente está en Publicacion::scopeVisiblesPara.
 */
final class Audiencia
{
    public const TODOS = 'todos';

    public const ROLES = 'roles';

    public const USUARIOS = 'usuarios';

    /**
     * @param  array{audiencia: string, audiencia_ids: ?array, estado: string, user_id: int, caduca_at?: ?\DateTimeInterface}  $p
     */
    public static function puedeVer(array $p, int $usuarioId, ?string $rol, bool $moderador, ?\DateTimeInterface $ahora = null): bool
    {
        if ($moderador || $p['user_id'] === $usuarioId) {
            return true;
        }
        if ($p['estado'] !== 'publicada') {
            return false;
        }
        $ahora ??= new \DateTimeImmutable;
        if (! empty($p['caduca_at']) && $p['caduca_at'] <= $ahora) {
            return false;
        }
        $ids = $p['audiencia_ids'] ?? [];

        return match ($p['audiencia']) {
            self::TODOS => true,
            self::ROLES => $rol !== null && in_array($rol, $ids, true),
            self::USUARIOS => in_array($usuarioId, array_map('intval', $ids), true),
            default => false,
        };
    }

    /**
     * Normaliza lo que manda el formulario. Sin destinatarios en "roles"/"usuarios" no tiene sentido.
     * @return array{0: string, 1: ?array}|null  null si no es válida
     */
    public static function normalizar(string $audiencia, array $ids): ?array
    {
        return match ($audiencia) {
            self::TODOS => [self::TODOS, null],
            self::ROLES => ($r = array_values(array_unique(array_filter(array_map('strval', $ids))))) ? [self::ROLES, $r] : null,
            self::USUARIOS => ($u = array_values(array_unique(array_filter(array_map('intval', $ids))))) ? [self::USUARIOS, $u] : null,
            default => null,
        };
    }
}
