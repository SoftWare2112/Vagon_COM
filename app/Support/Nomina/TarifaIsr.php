<?php

namespace App\Support\Nomina;

/**
 * v11 — Aplica una tarifa del art. 96 LISR (Anexo 8 RMF): se ubica el renglón, al excedente sobre el límite
 * inferior se le aplica el porcentaje y se suma la cuota fija. Las tarifas vienen de `tablas_fiscales`.
 * Lógica pura (sin Laravel).
 */
final class TarifaIsr
{
    /**
     * @param list<array{0: float, 1: float, 2: float}> $renglones  [límite inferior, cuota fija, % sobre excedente]
     * @return array{base: float, limite_inferior: float, excedente: float, porcentaje: float, impuesto_marginal: float, cuota_fija: float, isr: float}
     */
    public static function aplicar(float $base, array $renglones): array
    {
        $fila = [0.0, 0.0, 0.0];
        foreach ($renglones as $r) {
            if ($base >= (float) $r[0]) {
                $fila = $r;
            }
        }
        $excedente = $base > 0 ? round($base - (float) $fila[0], 2) : 0.0;
        $marginal = round($excedente * (float) $fila[2] / 100, 2);

        return [
            'base' => round($base, 2), 'limite_inferior' => (float) $fila[0], 'excedente' => $excedente, 'porcentaje' => (float) $fila[2],
            'impuesto_marginal' => $marginal, 'cuota_fija' => (float) $fila[1], 'isr' => $base > 0 ? round($marginal + (float) $fila[1], 2) : 0.0,
        ];
    }
}
