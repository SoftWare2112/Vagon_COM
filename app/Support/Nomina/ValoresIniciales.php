<?php

namespace App\Support\Nomina;

/**
 * v11 — Valores con los que arranca el sistema de nómina: catálogo de conceptos de la empresa y
 * tablas fiscales 2026. TODO es editable después desde Configuración de nómina; cada año se agregan
 * las tablas nuevas con su fecha de vigencia (no se cambia código).
 *
 * Fuentes 2026: Anexo 8 RMF 2026 (tarifas ISR), INEGI (UMA $117.31 desde el 1/feb/2026; enero usa la de 2025),
 * Decreto del subsidio para el empleo (15.02 % de la UMA mensual; ingresos hasta $11,492.66 al mes),
 * CONASAMI (salario mínimo general $315.04), Ley del Seguro Social (cuotas obreras).
 */
final class ValoresIniciales
{
    /** Conceptos de la empresa (percepciones y deducciones) con sus reglas. */
    public static function conceptos(): array
    {
        return [
            ['clave' => 'P001', 'nombre' => 'Sueldo', 'tipo' => 'percepcion', 'clave_sat' => '001', 'metodo' => 'sueldo',
                'monto' => 0, 'porcentaje' => 0, 'regla' => [], 'exencion' => ['tipo' => 'ninguna'], 'sistema' => true, 'orden' => 1,
                'descripcion' => 'Salario diario × días pagados del periodo (descuenta faltas).'],
            ['clave' => 'P002', 'nombre' => 'Bono de puntualidad', 'tipo' => 'percepcion', 'clave_sat' => '010', 'metodo' => 'fijo',
                'monto' => 500, 'porcentaje' => 0, 'regla' => ['solo_fin_de_mes' => true, 'sin_retardos' => true], 'exencion' => ['tipo' => 'ninguna'], 'orden' => 2,
                'descripcion' => 'Se paga en el periodo que cierra el mes (2.ª quincena), si no hubo retardos.'],
            ['clave' => 'P003', 'nombre' => 'Bono de 3 meses', 'tipo' => 'percepcion', 'clave_sat' => '038', 'metodo' => 'fijo',
                'monto' => 500, 'porcentaje' => 0, 'regla' => ['solo_fin_de_mes' => true, 'antiguedad_meses_min' => 3, 'frecuencia' => 'recurrente'],
                'exencion' => ['tipo' => 'ninguna'], 'orden' => 3,
                'descripcion' => 'Cada mes a partir de cumplir 3 meses en la empresa. Cambia la frecuencia a "pago único" si así se decide.'],
            ['clave' => 'P004', 'nombre' => 'Bono de asistencia', 'tipo' => 'percepcion', 'clave_sat' => '049', 'metodo' => 'fijo',
                'monto' => 0, 'porcentaje' => 0, 'regla' => ['solo_fin_de_mes' => true, 'sin_faltas' => true], 'exencion' => ['tipo' => 'ninguna'], 'orden' => 4,
                'descripcion' => 'Monto por definir. Se paga en el periodo que cierra el mes si no hubo faltas.'],
            ['clave' => 'P005', 'nombre' => 'Prima dominical', 'tipo' => 'percepcion', 'clave_sat' => '020', 'metodo' => 'por_evento',
                'monto' => 0, 'porcentaje' => 25, 'regla' => [], 'exencion' => ['tipo' => 'uma_por_evento', 'umas' => 1], 'orden' => 5,
                'descripcion' => '25 % del salario diario por cada domingo trabajado (art. 71 LFT). Exenta hasta 1 UMA por domingo.'],
            // v13: prima vacacional y aguinaldo (exenciones anuales del art. 93 fr. XIV LISR)
            ['clave' => 'P006', 'nombre' => 'Prima vacacional', 'tipo' => 'percepcion', 'clave_sat' => '021', 'metodo' => 'prima_vacacional',
                'monto' => 0, 'porcentaje' => 25, 'regla' => [], 'exencion' => ['tipo' => 'uma_anual', 'umas' => 15], 'sistema' => true, 'orden' => 6,
                'descripcion' => '% de prima vacacional (Configuración → Empresa) sobre los días de vacaciones que inician en el periodo. Exenta hasta 15 UMA al año.'],
            ['clave' => 'P007', 'nombre' => 'Aguinaldo', 'tipo' => 'percepcion', 'clave_sat' => '002', 'metodo' => 'aguinaldo',
                'monto' => 0, 'porcentaje' => 0, 'regla' => [], 'exencion' => ['tipo' => 'uma_anual', 'umas' => 30], 'sistema' => true, 'orden' => 7,
                'descripcion' => 'Se paga en la nómina especial de aguinaldo: días proporcionales × salario diario. Exento hasta 30 UMA al año.'],
            ['clave' => 'D001', 'nombre' => 'Seguro Social (IMSS)', 'tipo' => 'deduccion', 'clave_sat' => '001', 'metodo' => 'imss',
                'monto' => 0, 'porcentaje' => 0, 'regla' => [], 'exencion' => ['tipo' => 'ninguna'], 'sistema' => true, 'orden' => 20,
                'descripcion' => 'Cuotas obreras del IMSS sobre el salario base de cotización.'],
            ['clave' => 'D002', 'nombre' => 'ISR', 'tipo' => 'deduccion', 'clave_sat' => '002', 'metodo' => 'isr',
                'monto' => 0, 'porcentaje' => 0, 'regla' => [], 'exencion' => ['tipo' => 'ninguna'], 'sistema' => true, 'orden' => 21,
                'descripcion' => 'Impuesto Sobre la Renta retenido (tarifa art. 96 menos subsidio para el empleo).'],
            // v13: resultado del cálculo anual (art. 97 LISR); se llenan desde «Ajuste anual» → Aplicar
            ['clave' => 'D003', 'nombre' => 'ISR a cargo por ajuste anual', 'tipo' => 'deduccion', 'clave_sat' => '002', 'metodo' => 'captura',
                'monto' => 0, 'porcentaje' => 0, 'regla' => ['solo_ajuste' => true], 'exencion' => ['tipo' => 'ninguna'], 'sistema' => true, 'orden' => 22,
                'descripcion' => 'Diferencia a cargo del cálculo anual de ISR. Si se aplica en el año siguiente, el contador puede cambiar la clave SAT a 101.'],
            ['clave' => 'O001', 'nombre' => 'ISR a favor por ajuste anual', 'tipo' => 'otro_pago', 'clave_sat' => '004', 'metodo' => 'captura',
                'monto' => 0, 'porcentaje' => 0, 'regla' => ['solo_ajuste' => true], 'exencion' => ['tipo' => 'ninguna'], 'sistema' => true, 'orden' => 30,
                'descripcion' => 'Saldo a favor del cálculo anual que se devuelve al trabajador (otro pago 004: aplicación de saldo a favor por compensación anual).'],
        ];
    }

    /** Tablas fiscales: [tipo, vigente_desde, datos]. */
    public static function tablasFiscales(): array
    {
        $imss = [
            'em_excedente' => ['nombre' => 'Enfermedades y maternidad (excedente de 3 UMA)', 'tasa' => 0.40, 'sobre' => 'excedente_3uma'],
            'em_dinero' => ['nombre' => 'Enfermedades y maternidad (prestaciones en dinero)', 'tasa' => 0.25],
            'em_pensionados' => ['nombre' => 'Gastos médicos de pensionados', 'tasa' => 0.375],
            'invalidez_vida' => ['nombre' => 'Invalidez y vida', 'tasa' => 0.625],
            'cesantia_vejez' => ['nombre' => 'Cesantía en edad avanzada y vejez', 'tasa' => 1.125],
        ];

        return [
            ['uma', '2025-02-01', ['diaria' => 113.14, 'mensual' => 3439.46, 'anual' => 41273.52]],
            ['uma', '2026-02-01', ['diaria' => 117.31, 'mensual' => 3566.22, 'anual' => 42794.64]],
            ['salario_minimo', '2026-01-01', ['general' => 315.04, 'frontera_norte' => 440.87]],
            ['subsidio', '2026-01-01', ['porcentaje' => 15.59, 'uma_mensual' => 3439.46, 'limite_mensual' => 11492.66]],   // enero: UMA 2025
            ['subsidio', '2026-02-01', ['porcentaje' => 15.02, 'uma_mensual' => 3566.22, 'limite_mensual' => 11492.66]],
            ['imss_obrero', '2026-01-01', $imss],
            ['isr_semanal', '2026-01-01', ['renglones' => [
                [0.01, 0, 1.92], [194.47, 3.71, 6.40], [1650.68, 96.95, 10.88], [2900.88, 232.96, 16.00], [3372.12, 308.35, 17.92],
                [4037.33, 427.56, 21.36], [8142.76, 1304.45, 23.52], [12834.09, 2407.86, 30.00], [24502.46, 5908.35, 32.00],
                [32669.92, 8521.94, 34.00], [98009.67, 30737.49, 35.00]]]],
            ['isr_quincenal', '2026-01-01', ['renglones' => [
                [0.01, 0, 1.92], [416.71, 7.95, 6.40], [3537.16, 207.75, 10.88], [6216.16, 499.20, 16.00], [7225.96, 660.75, 17.92],
                [8651.41, 916.20, 21.36], [17448.76, 2795.25, 23.52], [27501.61, 5159.70, 30.00], [52505.26, 12660.75, 32.00],
                [70006.96, 18261.30, 34.00], [210020.71, 65866.05, 35.00]]]],
            ['isr_mensual', '2026-01-01', ['renglones' => [
                [0.01, 0, 1.92], [844.60, 16.22, 6.40], [7168.52, 420.95, 10.88], [12598.03, 1011.68, 16.00], [14644.65, 1339.14, 17.92],
                [17533.65, 1856.84, 21.36], [35362.84, 5665.16, 23.52], [55736.69, 10457.09, 30.00], [106410.51, 25659.23, 32.00],
                [141880.67, 37009.69, 34.00], [425642.00, 133488.54, 35.00]]]],
            // v13: tarifa anual (art. 152 LISR) para el cálculo anual del art. 97. Anexo 8 RMF 2026, DOF 28/12/2025
            ['isr_anual', '2026-01-01', ['renglones' => [
                [0.01, 0, 1.92], [10135.12, 194.59, 6.40], [86022.12, 5051.37, 10.88], [151176.20, 12140.13, 16.00], [175735.67, 16069.64, 17.92],
                [210403.70, 22282.14, 21.36], [424353.98, 67981.92, 23.52], [668840.15, 125485.07, 30.00], [1276925.99, 307910.81, 32.00],
                [1702567.98, 444116.23, 34.00], [5107703.93, 1601862.46, 35.00]]]],
        ];
    }

    /** Parámetros que espera CalculadoraNomina, a partir de las tablas vigentes en una fecha. */
    public static function parametros(array $vigentes, string $tipoPeriodo): array
    {
        return [
            'uma' => (float) $vigentes['uma']['diaria'],
            'salario_minimo' => (float) ($vigentes['salario_minimo']['general'] ?? 0),
            'subsidio' => $vigentes['subsidio'] ?? null,
            'imss' => $vigentes['imss_obrero'],
            'isr' => $vigentes['isr_'.($tipoPeriodo === 'especial' ? 'mensual' : $tipoPeriodo)]['renglones'],
            'isr_mensual' => $vigentes['isr_mensual']['renglones'],   // v13: pagos extraordinarios (art. 96 / art. 174 RLISR)
        ];
    }

    /** Las tablas vigentes en una fecha (la más reciente con vigente_desde <= fecha, por tipo). */
    public static function vigentesEn(array $tablas, string $fecha): array
    {
        $out = [];
        foreach ($tablas as [$tipo, $desde, $datos]) {
            if ($desde <= $fecha && (! isset($out[$tipo]) || $out[$tipo][0] < $desde)) {
                $out[$tipo] = [$desde, $datos];
            }
        }

        return array_map(fn ($x) => $x[1], $out);
    }
}
