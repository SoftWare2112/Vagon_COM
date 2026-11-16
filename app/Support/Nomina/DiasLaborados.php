<?php

namespace App\Support\Nomina;

/**
 * v12 — Días del periodo que quedan FUERA de la relación laboral (lógica pura).
 *
 * Periodo completo trabajado = los días del periodo (15 en quincena aunque el mes tenga 31 o 28).
 * Si el trabajador ingresó o se dio de baja a la mitad, se pagan los días de calendario que sí estuvo
 * contratado, sin pasar de los días del periodo; el resto son "días fuera" (no se pagan ni cotizan al IMSS).
 */
final class DiasLaborados
{
    public static function fuera(string $inicio, string $fin, int $diasPeriodo, string $ingreso, ?string $baja = null): int
    {
        $desde = max($inicio, $ingreso);
        $hasta = $baja ? min($fin, $baja) : $fin;
        if ($desde === $inicio && $hasta === $fin) {
            return 0;
        }
        if ($desde > $hasta) {
            return $diasPeriodo;   // no estuvo contratado en ningún día del periodo
        }
        $trabajados = (int) (new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days + 1;

        return $diasPeriodo - min($diasPeriodo, $trabajados);
    }

    /** ¿El empleado debe aparecer en la nómina de ese periodo? (contratado al menos un día). */
    public static function participa(string $inicio, string $fin, string $ingreso, ?string $baja = null): bool
    {
        return $ingreso <= $fin && ($baja === null || $baja >= $inicio);
    }
}
