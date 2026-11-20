<?php

namespace App\Support\Nomina;

/**
 * v13 — Saldo de vacaciones por año de servicio (lógica pura).
 *
 * Cada aniversario da derecho a los días de la tabla del art. 76 LFT (reforma 2023: 12, 14, 16, 18, 20, 22…).
 * Se deben disfrutar dentro de los 6 meses siguientes (art. 81) y la acción prescribe al año (art. 516):
 * el sistema marca «por vencer» un saldo de más de 6 meses y «vencido» uno de más de 18 meses, sin borrarlo.
 */
final class Vacaciones
{
    /**
     * @param array<int, int> $tomadas  días tomados por año de servicio [1 => 5, 2 => 0, …]
     * @return array{anios: list<array>, disponibles: int, proximo: array}
     */
    public static function saldo(string $ingreso, string $fecha, array $tomadas = []): array
    {
        $alta = new \DateTimeImmutable($ingreso);
        $hoy = new \DateTimeImmutable($fecha);
        $cumplidos = Prestaciones::antiguedadAnios($alta, $hoy);
        $anios = [];
        $disponibles = 0;
        for ($n = 1; $n <= $cumplidos; $n++) {
            $desde = $alta->modify("+{$n} years");
            $derecho = Prestaciones::diasVacaciones($n);
            $usados = (int) ($tomadas[$n] ?? 0);
            $pendientes = max(0, $derecho - $usados);
            $meses = Prestaciones::antiguedadMeses($desde, $hoy);
            $estado = $pendientes === 0 ? 'agotado' : ($meses >= 18 ? 'vencido' : ($meses >= 6 ? 'por_vencer' : 'vigente'));
            if ($estado !== 'vencido') {
                $disponibles += $pendientes;
            }
            $anios[] = ['anio' => $n, 'desde' => $desde->format('Y-m-d'), 'derecho' => $derecho, 'tomados' => $usados,
                'pendientes' => $pendientes, 'estado' => $estado];
        }
        $siguiente = $alta->modify('+'.($cumplidos + 1).' years');

        return ['anios' => $anios, 'disponibles' => $disponibles,
            'proximo' => ['anio' => $cumplidos + 1, 'desde' => $siguiente->format('Y-m-d'), 'derecho' => Prestaciones::diasVacaciones($cumplidos + 1)]];
    }

    /** Año de servicio al que conviene cargar unas vacaciones: el más antiguo con saldo y sin vencer. */
    public static function anioACargar(array $saldo, int $dias): ?int
    {
        foreach ($saldo['anios'] as $a) {
            if ($a['estado'] !== 'vencido' && $a['pendientes'] >= $dias) {
                return $a['anio'];
            }
        }

        return null;
    }
}
