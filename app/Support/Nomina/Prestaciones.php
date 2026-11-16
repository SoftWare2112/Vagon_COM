<?php

namespace App\Support\Nomina;

/**
 * v11 — Prestaciones de ley que dependen de la antigüedad (Ley Federal del Trabajo, reforma de 2023):
 * días de vacaciones, factor de integración y Salario Diario Integrado (SDI) para el IMSS.
 * La antigüedad cuenta desde la fecha de ingreso, incluida la capacitación inicial.
 * Lógica pura (sin Laravel).
 */
final class Prestaciones
{
    /** Años cumplidos de servicio a una fecha. */
    public static function antiguedadAnios(\DateTimeInterface $ingreso, \DateTimeInterface $fecha): int
    {
        return $fecha < $ingreso ? 0 : $ingreso->diff($fecha)->y;
    }

    /** Meses cumplidos (para el bono de 3 meses). */
    public static function antiguedadMeses(\DateTimeInterface $ingreso, \DateTimeInterface $fecha): int
    {
        if ($fecha < $ingreso) {
            return 0;
        }
        $d = $ingreso->diff($fecha);

        return $d->y * 12 + $d->m;
    }

    /** Días de vacaciones según el año de servicio que se cumple (art. 76 LFT, desde 2023). */
    public static function diasVacaciones(int $anios): int
    {
        return match (true) {
            $anios <= 0 => 0,
            $anios <= 5 => 12 + ($anios - 1) * 2,            // 12, 14, 16, 18, 20
            default => 22 + intdiv($anios - 6, 5) * 2,        // 6-10: 22, 11-15: 24, 16-20: 26 …
        };
    }

    /**
     * Factor de integración = (365 + días de aguinaldo + días de vacaciones × prima vacacional) / 365.
     * En el primer año se usan las vacaciones del primer aniversario (12 días).
     */
    public static function factorIntegracion(int $anios, int $diasAguinaldo = 15, float $primaVacacional = 0.25): float
    {
        return round((365 + $diasAguinaldo + self::diasVacaciones(max(1, $anios + 1)) * $primaVacacional) / 365, 6);
    }

    public static function sdi(float $salarioDiario, float $factor): float
    {
        return round($salarioDiario * $factor, 2);
    }

    /** Aguinaldo proporcional: días de aguinaldo × días trabajados en el año / 365. */
    public static function diasAguinaldoProporcionales(\DateTimeInterface $ingreso, int $ejercicio, int $diasAguinaldo = 15): float
    {
        $inicio = new \DateTimeImmutable("{$ejercicio}-01-01");
        $fin = new \DateTimeImmutable("{$ejercicio}-12-31");
        $desde = $ingreso > $inicio ? \DateTimeImmutable::createFromInterface($ingreso) : $inicio;
        if ($desde > $fin) {
            return 0.0;
        }
        $dias = $desde->diff($fin)->days + 1;

        return round($diasAguinaldo * min(365, $dias) / 365, 4);
    }
}
