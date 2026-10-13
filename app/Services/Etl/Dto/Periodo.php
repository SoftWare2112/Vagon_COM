<?php

namespace App\Services\Etl\Dto;

use DateTimeImmutable;

final class Periodo
{
    public function __construct(
        public readonly DateTimeImmutable $inicio,
        public readonly DateTimeImmutable $fin,
        public readonly string $etiqueta,
        public readonly ?int $anioEncabezado = null,   // año escrito en el encabezado (si venía)
    ) {}

    /** Fecha dentro del periodo cuyo día del mes es $dia (p. ej. 16 -> 2026-08-16). */
    public function fechaDelDia(int $dia): ?DateTimeImmutable
    {
        for ($d = $this->inicio; $d <= $this->fin; $d = $d->modify('+1 day')) {
            if ((int) $d->format('j') === $dia) {
                return $d;
            }
        }

        return null;
    }
}
