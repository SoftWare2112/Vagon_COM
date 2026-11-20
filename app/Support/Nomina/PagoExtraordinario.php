<?php

namespace App\Support\Nomina;

/**
 * v13 — Nómina especial de AGUINALDO (lógica pura).
 *
 *  Días  = días de aguinaldo de la empresa × días del año contratado / 365 (art. 87 LFT: mínimo 15, proporcional).
 *  Monto = salario diario vigente × días.
 *  Exento de ISR hasta 30 UMA en el año (art. 93 fr. XIV LISR); el resto es gravado.
 *  No genera cuotas del IMSS: el aguinaldo ya está integrado en el SDI de cada quincena.
 *
 * ISR — dos métodos legales, el patrón elige (Configuración → Empresa); siempre se calculan los dos para comparar:
 *  - art. 96 LISR:   ISR = tarifa mensual(ingreso ordinario + aguinaldo gravado) − tarifa mensual(ingreso ordinario)
 *  - art. 174 RLISR: 1) gravado ÷ 365 × 30.4   2) ISR(ordinario + 1) − ISR(ordinario)   3) tasa = 2 ÷ 1
 *                    4) ISR a retener = gravado × tasa
 *  "Ingreso ordinario" mensual = salario diario × 30.4. No se aplica subsidio para el empleo en pagos extraordinarios.
 */
final class PagoExtraordinario
{
    /**
     * @param array{
     *   salario_mensual: float, divisor: float, fecha_ingreso: string, ejercicio: int, dias_aguinaldo: int,
     *   metodo_isr: 'art96'|'art174', exento_acumulado?: float, concepto: array, deduccion_isr: array, parametros: array
     * } $e
     */
    public static function aguinaldo(array $e): array
    {
        $p = $e['parametros'];
        $uma = (float) $p['uma'];
        $sd = (float) $e['salario_mensual'] / (float) $e['divisor'];
        $dias = Prestaciones::diasAguinaldoProporcionales(new \DateTimeImmutable($e['fecha_ingreso']), (int) $e['ejercicio'], (int) $e['dias_aguinaldo']);
        $importe = round($sd * $dias, 2);
        $c = $e['concepto'];
        $exento = CalculadoraNomina::exento($c, $importe, 1, $uma, (float) ($e['exento_acumulado'] ?? 0));
        $gravado = round($importe - $exento, 2);

        $ordinario = round($sd * CalculadoraNomina::DIAS_MES_ISR, 2);
        $metodos = self::isr($gravado, $ordinario, $p['isr_mensual']);
        $metodo = $e['metodo_isr'] === 'art96' ? 'art96' : 'art174';
        $isr = $metodos[$metodo]['isr'];
        $avisos = [];
        if ($exento >= $importe) {
            $avisos[] = 'El aguinaldo está exento por completo (no rebasa 30 UMA): no se retiene ISR.';
        }
        $completo = $dias >= (float) $e['dias_aguinaldo'] - 0.0001;
        $anio = (int) $e['ejercicio'];
        $detalle = number_format($dias, 2).' días × $'.number_format($sd, 2).($completo ? '' : " (proporcional: ingresó en {$anio})");

        $deducciones = $isr > 0 ? [['clave' => $e['deduccion_isr']['clave'], 'nombre' => $e['deduccion_isr']['nombre'], 'clave_sat' => $e['deduccion_isr']['clave_sat'] ?? '002', 'importe' => $isr]] : [];

        return [
            'tipo_calculo' => 'aguinaldo',
            'salario_diario' => round($sd, 2), 'sdi' => 0.0, 'sbc' => 0.0, 'dias_pagados' => (int) round($dias), 'dias_periodo' => (int) $e['dias_aguinaldo'],
            'dias_aguinaldo' => round($dias, 4),
            'antiguedad' => null,
            'percepciones' => [['clave' => $c['clave'], 'nombre' => $c['nombre'], 'clave_sat' => $c['clave_sat'] ?? '002', 'importe' => $importe,
                'gravado' => $gravado, 'exento' => $exento, 'detalle' => $detalle]],
            'deducciones' => $deducciones,
            'otros_pagos' => [],
            'isr' => [
                'metodo' => $metodo, 'base' => $gravado, 'ingreso_ordinario' => $ordinario,
                'exento_tope' => round(30 * $uma, 2), 'comparativa' => $metodos,
                // mismas llaves que el cálculo ordinario, para el recibo
                'limite_inferior' => 0.0, 'excedente' => 0.0, 'porcentaje' => $metodos[$metodo]['tasa'] ?? 0.0, 'impuesto_marginal' => 0.0, 'cuota_fija' => 0.0,
                'isr' => $isr, 'isr_causado' => $isr, 'subsidio_causado' => 0.0, 'subsidio_aplicado' => 0.0, 'isr_retenido' => $isr,
            ],
            'imss' => [],
            'totales' => ['percepciones' => $importe, 'gravado' => $gravado, 'exento' => $exento, 'deducciones' => $isr, 'otros_pagos' => 0.0,
                'neto' => round($importe - $isr, 2)],
            'avisos' => $avisos,
        ];
    }

    /** @return array{art96: array, art174: array} ISR del pago extraordinario por cada método. */
    public static function isr(float $gravado, float $ordinario, array $tarifaMensual): array
    {
        $base = TarifaIsr::aplicar($ordinario, $tarifaMensual)['isr'];
        $art96 = round(TarifaIsr::aplicar($ordinario + $gravado, $tarifaMensual)['isr'] - $base, 2);

        $mensual = round($gravado / 365 * CalculadoraNomina::DIAS_MES_ISR, 2);
        $diferencia = round(TarifaIsr::aplicar($ordinario + $mensual, $tarifaMensual)['isr'] - $base, 2);
        $tasa = $mensual > 0 ? $diferencia / $mensual * 100 : 0.0;
        $art174 = round($gravado * $tasa / 100, 2);

        return [
            'art96' => ['isr' => max(0.0, $art96), 'tasa' => $gravado > 0 ? round($art96 / $gravado * 100, 4) : 0.0,
                'pasos' => ['ingreso_ordinario' => $ordinario, 'isr_ordinario' => $base, 'isr_con_pago' => round($base + $art96, 2)]],
            'art174' => ['isr' => max(0.0, $art174), 'tasa' => round($tasa, 4),
                'pasos' => ['mensualizado' => $mensual, 'isr_ordinario' => $base, 'isr_con_mensualizado' => round($base + $diferencia, 2), 'diferencia' => $diferencia]],
        ];
    }
}
