<?php

namespace App\Support\Nomina;

/**
 * v13 — Cálculo anual del ISR de un trabajador (art. 97 LISR). Lógica pura.
 *
 * El patrón NO hace el cálculo anual cuando el trabajador:
 *   a) inició a prestar servicios después del 1 de enero del ejercicio o dejó de prestarlos antes del 1 de diciembre;
 *   b) obtuvo ingresos anuales por salarios de más de $400,000;
 *   c) comunicó por escrito que presentará su declaración anual por su cuenta.
 *
 * Cálculo: tarifa anual (art. 152) sobre el total GRAVADO del año → ISR anual;
 *   se disminuye primero con el subsidio para el empleo aplicado en el año (el excedente no se entrega)
 *   y después con las retenciones del año. Positivo = a cargo (se retiene en diciembre o después);
 *   negativo = a favor (se devuelve / compensa a más tardar en el año siguiente).
 */
final class AjusteAnual
{
    public const LIMITE_INGRESOS = 400000.00;

    /**
     * @param array{
     *   ejercicio: int, fecha_ingreso: string, fecha_baja?: ?string, declara_por_su_cuenta?: bool,
     *   ingresos_totales: float, gravado: float, isr_retenido: float, subsidio_aplicado: float, tarifa_anual: array
     * } $d
     */
    public static function calcular(array $d): array
    {
        $anio = (int) $d['ejercicio'];
        $motivo = match (true) {
            ! empty($d['declara_por_su_cuenta']) => 'Comunicó por escrito que presentará su declaración anual',
            $d['fecha_ingreso'] > "{$anio}-01-01" => 'Ingresó después del 1 de enero de '.$anio,
            ! empty($d['fecha_baja']) && $d['fecha_baja'] < "{$anio}-12-01" => 'Dejó de trabajar antes del 1 de diciembre',
            (float) $d['ingresos_totales'] > self::LIMITE_INGRESOS => 'Sus ingresos del año rebasan $400,000',
            default => null,
        };

        $tarifa = TarifaIsr::aplicar((float) $d['gravado'], $d['tarifa_anual']);
        $isrAnual = $tarifa['isr'];
        $despuesSubsidio = round(max(0.0, $isrAnual - (float) $d['subsidio_aplicado']), 2);
        $diferencia = round($despuesSubsidio - (float) $d['isr_retenido'], 2);

        return [
            'aplica' => $motivo === null,
            'motivo' => $motivo,
            'ingresos_totales' => round((float) $d['ingresos_totales'], 2),
            'gravado' => round((float) $d['gravado'], 2),
            'tarifa' => $tarifa,
            'isr_anual' => $isrAnual,
            'subsidio_aplicado' => round((float) $d['subsidio_aplicado'], 2),
            'isr_despues_subsidio' => $despuesSubsidio,
            'isr_retenido' => round((float) $d['isr_retenido'], 2),
            'diferencia' => $diferencia,
            'resultado' => abs($diferencia) < 0.005 ? 'cero' : ($diferencia > 0 ? 'a_cargo' : 'a_favor'),
        ];
    }
}
