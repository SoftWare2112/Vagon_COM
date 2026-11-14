<?php

namespace App\Support\Hojas;

use App\Models\Hoja;
use App\Models\Libro;
use App\Models\User;

/**
 * v9 — Espacio personal: cada libro es de su dueño. Solo él lo ve y lo edita;
 * quien tenga `hojas.ver_todas` (Administrador) ve y controla todos.
 * Para no revelar que un libro ajeno existe, se responde 404 (no 403).
 */
final class AccesoLibros
{
    public static function puede(User $u, Libro $libro): bool
    {
        return $libro->user_id === $u->id || $u->tienePermiso('hojas.ver_todas');
    }

    public static function libro(User $u, Libro $libro): Libro
    {
        abort_unless(self::puede($u, $libro), 404);

        return $libro;
    }

    public static function hoja(User $u, Hoja $hoja): Libro
    {
        $libro = $hoja->libro;
        abort_unless($libro && ! $libro->trashed() && self::puede($u, $libro), 404);

        return $libro;
    }
}
