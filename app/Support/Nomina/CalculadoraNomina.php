<?php

namespace App\Support\Nomina;

/**
 * v11 — Cálculo de la nómina de UN trabajador en UN periodo (semanal, quincenal o mensual).
 *
 *  1. Percepciones según el catálogo de conceptos: sueldo, bonos con sus condiciones, prima dominical…
 *     Cada percepción se separa en parte GRAVADA y EXENTA (p. ej. prima dominical: exenta hasta 1 UMA por domingo).
 *  2. ISR: tarifa del periodo (art. 96 LISR) sobre lo gravado, menos el subsidio para el empleo
 *     (el subsidio solo reduce el ISR; desde 2024 el excedente ya no se entrega en efectivo).
 *  3. IMSS (cuotas obreras) sobre el Salario Base de Cotización = SDI, con tope de 25 UMA.
 *  4. Neto = percepciones − deducciones.
 * Los trabajadores con salario mínimo no tienen retención de ISR y la cuota obrera la paga el patrón.
 * Todo viene como datos (tarifas, UMA, catálogo); aquí no hay montos fijos de la ley. Lógica pura (sin Laravel).
 */
final class CalculadoraNomina
{
    public const DIAS_MES_ISR = 30.4;   // el SAT usa 30.4 días para proporcionar valores mensuales

    /**
     * @param array{
     *   salario_mensual: float, divisor: float, tipo_periodo: string, dias_periodo: int,
     *   fecha_ingreso: string, inicio: string, fin: string, es_fin_de_mes: bool,
     *   faltas?: int, retardos?: int, domingos?: int, capturados?: array<string, float>, dias_fuera?: int,
     *   conceptos: list<array>, parametros: array
     * } $e
     */
    public static function calcular(array $e): array
    {
        $p = $e['parametros'];
        $uma = (float) $p['uma'];
        $diasPeriodo = (int) $e['dias_periodo'];
        // v12: días del periodo fuera de la relación laboral (ingresó o se dio de baja a la mitad del periodo): no se pagan ni cotizan
        $fuera = min($diasPeriodo, max(0, (int) ($e['dias_fuera'] ?? 0)));
        $dias = $diasPeriodo - $fuera;
        $faltas = min($dias, max(0, (int) ($e['faltas'] ?? 0)));
        $retardos = max(0, (int) ($e['retardos'] ?? 0));
        $domingos = max(0, (int) ($e['domingos'] ?? 0));
        // Se calcula con el salario diario SIN redondear (p. ej. 20,000 / 30 = 666.666…) para que 15 días den exactamente la mitad del sueldo
        $sd = (float) $e['salario_mensual'] / (float) $e['divisor'];
        $ingreso = new \DateTimeImmutable($e['fecha_ingreso']);
        $inicio = new \DateTimeImmutable($e['inicio']);
        $fin = new \DateTimeImmutable($e['fin']);
        $mesesAntiguedad = Prestaciones::antiguedadMeses($ingreso, $fin);
        $aniosAntiguedad = Prestaciones::antiguedadAnios($ingreso, $fin);
        $salarioMinimo = (float) ($p['salario_minimo'] ?? 0) > 0 && round($sd, 2) <= (float) $p['salario_minimo'];
        $avisos = [];

        // ── 1. Percepciones ──
        $percepciones = [];
        foreach ($e['conceptos'] as $c) {
            if (($c['tipo'] ?? '') !== 'percepcion' || ! ($c['activo'] ?? true)) {
                continue;
            }
            [$importe, $eventos, $motivo] = self::importe($c, $sd, $dias, $faltas, $retardos, $domingos, $mesesAntiguedad, $ingreso, $inicio, $fin, (bool) $e['es_fin_de_mes'], $e['capturados'] ?? []);
            if ($importe <= 0) {
                continue;
            }
            $exento = self::exento($c, $importe, $eventos, $uma);
            $percepciones[] = [
                'clave' => $c['clave'], 'nombre' => $c['nombre'], 'clave_sat' => $c['clave_sat'] ?? null,
                'importe' => $importe, 'gravado' => round($importe - $exento, 2), 'exento' => $exento, 'detalle' => $motivo,
            ];
        }
        $totalPercepciones = round(array_sum(array_column($percepciones, 'importe')), 2);
        $gravado = round(array_sum(array_column($percepciones, 'gravado')), 2);

        // ── 2. ISR y subsidio para el empleo ──
        $tarifa = TarifaIsr::aplicar($gravado, $p['isr']);
        $proporcion = $e['tipo_periodo'] === 'mensual' ? 1.0 : $diasPeriodo / self::DIAS_MES_ISR;
        $sub = $p['subsidio'] ?? null;
        $subsidio = 0.0;
        if ($sub && $gravado > 0 && $gravado <= round((float) $sub['limite_mensual'] * $proporcion, 2)) {
            $subsidio = round((float) $sub['porcentaje'] / 100 * (float) $sub['uma_mensual'] * $proporcion, 2);
        }
        $isrCausado = $salarioMinimo ? 0.0 : $tarifa['isr'];
        $subsidioAplicado = min($subsidio, $isrCausado);
        $isrRetenido = round($isrCausado - $subsidioAplicado, 2);
        if ($salarioMinimo) {
            $avisos[] = 'Salario mínimo: no se retiene ISR y la cuota obrera del IMSS la cubre el patrón.';
        }

        // ── 3. IMSS (cuotas obreras) ──
        $sdi = Prestaciones::sdi($sd, Prestaciones::factorIntegracion($aniosAntiguedad));
        $sbc = min($sdi, round(25 * $uma, 2));
        $diasCotizados = $dias - $faltas;
        $imss = self::imss($sbc, $diasCotizados, $uma, $p['imss']);
        if ($salarioMinimo) {
            $imss = array_map(fn ($r) => [...$r, 'importe' => 0.0], $imss);
        }
        $totalImss = round(array_sum(array_column($imss, 'importe')), 2);

        // ── 4. Deducciones y neto ──
        $deducciones = [];
        foreach ($e['conceptos'] as $c) {
            if (($c['tipo'] ?? '') !== 'deduccion' || ! ($c['activo'] ?? true)) {
                continue;
            }
            $importe = match ($c['metodo']) {
                'isr' => $isrRetenido,
                'imss' => $totalImss,
                'fijo' => round((float) $c['monto'], 2),
                'captura' => round((float) ($e['capturados'][$c['clave']] ?? 0), 2),
                default => 0.0,
            };
            if ($importe > 0) {
                $deducciones[] = ['clave' => $c['clave'], 'nombre' => $c['nombre'], 'clave_sat' => $c['clave_sat'] ?? null, 'importe' => $importe];
            }
        }
        $totalDeducciones = round(array_sum(array_column($deducciones, 'importe')), 2);
        $neto = round($totalPercepciones - $totalDeducciones, 2);
        if ($neto < 0) {
            $avisos[] = 'Las deducciones superan a las percepciones: revisa las faltas y los descuentos capturados.';
        }

        return [
            'salario_diario' => round($sd, 2), 'sdi' => $sdi, 'sbc' => $sbc, 'dias_pagados' => $dias - $faltas, 'dias_periodo' => $diasPeriodo, 'dias_fuera' => $fuera,
            'antiguedad' => ['anios' => $aniosAntiguedad, 'meses' => $mesesAntiguedad],
            'percepciones' => $percepciones, 'deducciones' => $deducciones,
            'otros_pagos' => $subsidioAplicado > 0 ? [['clave' => 'SUBSIDIO', 'nombre' => 'Subsidio para el empleo (aplicado al ISR)', 'clave_sat' => '002', 'subsidio_causado' => $subsidio, 'importe' => 0.0]] : [],
            'isr' => [...$tarifa, 'isr_causado' => $isrCausado, 'subsidio_causado' => $subsidio, 'subsidio_aplicado' => $subsidioAplicado, 'isr_retenido' => $isrRetenido],
            'imss' => $imss,
            'totales' => ['percepciones' => $totalPercepciones, 'gravado' => $gravado, 'exento' => round($totalPercepciones - $gravado, 2),
                'deducciones' => $totalDeducciones, 'neto' => $neto],
            'avisos' => $avisos,
        ];
    }

    /** @return array{0: float, 1: int, 2: string}  [importe, eventos (domingos), explicación] */
    private static function importe(array $c, float $sd, int $dias, int $faltas, int $retardos, int $domingos, int $meses,
        \DateTimeImmutable $ingreso, \DateTimeImmutable $inicio, \DateTimeImmutable $fin, bool $finDeMes, array $capturados): array
    {
        $r = $c['regla'] ?? [];
        // Condiciones del concepto
        if (! empty($r['solo_fin_de_mes']) && ! $finDeMes) {
            return [0.0, 0, 'Se paga en el periodo que cierra el mes'];
        }
        if (! empty($r['sin_faltas']) && $faltas > 0) {
            return [0.0, 0, 'Tuvo faltas'];
        }
        if (! empty($r['sin_retardos']) && $retardos > 0) {
            return [0.0, 0, 'Tuvo retardos'];
        }
        if (! empty($r['antiguedad_meses_min'])) {
            $min = (int) $r['antiguedad_meses_min'];
            if ($meses < $min) {
                return [0.0, 0, "Aún no cumple {$min} meses"];
            }
            if (($r['frecuencia'] ?? 'recurrente') === 'unico') {
                $cumple = $ingreso->modify("+{$min} months");
                if ($cumple < $inicio || $cumple > $fin) {
                    return [0.0, 0, 'Pago único: ya se pagó en el periodo en que cumplió'];
                }
            }
        }

        return match ($c['metodo']) {
            'sueldo' => [round($sd * ($dias - $faltas), 2), 0, ($dias - $faltas).' días × $'.number_format($sd, 2)],
            'fijo' => [round((float) $c['monto'], 2), 1, 'Monto fijo'],
            'por_evento' => [round($domingos * $sd * (float) $c['porcentaje'] / 100, 2), $domingos, "{$domingos} domingo(s) × {$c['porcentaje']}% del salario diario"],
            'captura' => [round((float) ($capturados[$c['clave']] ?? 0), 2), 1, 'Capturado en el periodo'],
            default => [0.0, 0, ''],
        };
    }

    /** Parte exenta de ISR de una percepción. */
    private static function exento(array $c, float $importe, int $eventos, float $uma): float
    {
        $x = $c['exencion'] ?? ['tipo' => 'ninguna'];

        return round(match ($x['tipo'] ?? 'ninguna') {
            'total' => $importe,
            'uma_por_evento' => min($importe, (float) $x['umas'] * $uma * max(1, $eventos)),
            'uma_tope' => min($importe, (float) $x['umas'] * $uma),   // tope anual: el acumulado del año se controla en la nómina (v12)
            default => 0.0,
        }, 2);
    }

    /** Cuotas obreras del IMSS por rama. Excedente de Enfermedades y Maternidad: solo sobre lo que pasa de 3 UMA. */
    private static function imss(float $sbc, int $dias, float $uma, array $tasas): array
    {
        $out = [];
        foreach ($tasas as $clave => $t) {
            $base = ($t['sobre'] ?? 'sbc') === 'excedente_3uma' ? max(0, $sbc - 3 * $uma) : $sbc;
            $out[] = ['clave' => $clave, 'nombre' => $t['nombre'], 'tasa' => (float) $t['tasa'], 'base_diaria' => round($base, 2),
                'importe' => round($base * (float) $t['tasa'] / 100 * max(0, $dias), 2)];
        }

        return $out;
    }
}
