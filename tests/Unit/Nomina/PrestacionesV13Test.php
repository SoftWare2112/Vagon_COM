<?php

namespace Tests\Unit\Nomina;

use App\Support\Nomina\AjusteAnual;
use App\Support\Nomina\CalculadoraNomina;
use App\Support\Nomina\ImporteConLetra;
use App\Support\Nomina\PagoExtraordinario;
use App\Support\Nomina\ReciboHtml;
use App\Support\Nomina\Vacaciones;
use App\Support\Nomina\ValoresIniciales;
use PHPUnit\Framework\TestCase;

/** v13: prima vacacional, aguinaldo (art. 96 y art. 174 RLISR), cálculo anual (art. 97), saldo de vacaciones y recibo PDF. */
class PrestacionesV13Test extends TestCase
{
    private function parametros(string $fecha = '2026-12-15', string $tipo = 'quincenal'): array
    {
        return ValoresIniciales::parametros(ValoresIniciales::vigentesEn(ValoresIniciales::tablasFiscales(), $fecha), $tipo);
    }

    private function concepto(string $clave): array
    {
        return array_values(array_filter(ValoresIniciales::conceptos(), fn ($c) => $c['clave'] === $clave))[0];
    }

    private function quincena(array $extra = []): array
    {
        return CalculadoraNomina::calcular([...[
            'salario_mensual' => 11250, 'divisor' => 30, 'tipo_periodo' => 'quincenal', 'dias_periodo' => 15, 'fecha_ingreso' => '2026-02-01',
            'inicio' => '2026-11-01', 'fin' => '2026-11-15', 'es_fin_de_mes' => false, 'conceptos' => ValoresIniciales::conceptos(),
            'parametros' => $this->parametros('2026-11-15'),
        ], ...$extra]);
    }

    public function test_prima_vacacional_exenta_hasta_15_uma_al_anio(): void
    {
        $r = $this->quincena(['vacaciones' => 6, 'prima_vacacional' => 25]);
        $pv = array_values(array_filter($r['percepciones'], fn ($l) => $l['clave'] === 'P006'))[0];
        $this->assertSame(562.5, $pv['importe']);        // 6 días × $375 × 25 %
        $this->assertSame(562.5, $pv['exento']);         // 15 UMA = $1,759.65
        $this->assertSame('021', $pv['clave_sat']);

        $r2 = $this->quincena(['vacaciones' => 6, 'prima_vacacional' => 25, 'exento_acumulado' => ['P006' => 1500]]);
        $pv2 = array_values(array_filter($r2['percepciones'], fn ($l) => $l['clave'] === 'P006'))[0];
        $this->assertSame(259.65, $pv2['exento']);       // solo quedaban $259.65 de exención en el año
        $this->assertSame(302.85, $pv2['gravado']);
        $this->assertCount(0, array_filter($this->quincena()['percepciones'], fn ($l) => $l['clave'] === 'P006'));
    }

    public function test_otro_pago_capturado_se_suma_al_neto(): void
    {
        $base = $this->quincena();
        $r = $this->quincena(['capturados' => ['O001' => 300.5]]);
        $this->assertSame(300.5, $r['totales']['otros_pagos']);
        $this->assertSame(round($base['totales']['neto'] + 300.5, 2), $r['totales']['neto']);
        $this->assertSame($base['totales']['gravado'], $r['totales']['gravado']);   // no es ingreso gravable
    }

    public function test_aguinaldo_con_los_dos_metodos_de_isr(): void
    {
        $datos = ['salario_mensual' => 11250, 'divisor' => 30, 'fecha_ingreso' => '2025-06-02', 'ejercicio' => 2026, 'dias_aguinaldo' => 15,
            'concepto' => $this->concepto('P007'), 'deduccion_isr' => $this->concepto('D002'), 'parametros' => $this->parametros()];
        $r = PagoExtraordinario::aguinaldo([...$datos, 'metodo_isr' => 'art174']);
        $l = $r['percepciones'][0];
        $this->assertSame(5625.0, $l['importe']);        // 15 días × $375
        $this->assertSame(3519.3, $l['exento']);         // 30 UMA × $117.31
        $this->assertSame(2105.7, $l['gravado']);
        $this->assertSame([], $r['imss']);               // el aguinaldo no paga cuotas obreras
        $this->assertSame(275.57, $r['isr']['comparativa']['art96']['isr']);    // ISR(11,400 + 2,105.70) − ISR(11,400)
        $this->assertSame(175.38, $r['isr']['comparativa']['art174']['pasos']['mensualizado']);
        $this->assertSame(229.08, $r['isr']['comparativa']['art174']['isr']);   // tasa 19.08 ÷ 175.38 = 10.879 %
        $this->assertSame(229.08, $r['isr']['isr_retenido']);
        $this->assertSame(5395.92, $r['totales']['neto']);
        $this->assertSame(275.57, PagoExtraordinario::aguinaldo([...$datos, 'metodo_isr' => 'art96'])['isr']['isr_retenido']);
    }

    public function test_aguinaldo_proporcional_y_exento(): void
    {
        $r = PagoExtraordinario::aguinaldo(['salario_mensual' => 11250, 'divisor' => 30, 'fecha_ingreso' => '2026-09-01', 'ejercicio' => 2026, 'dias_aguinaldo' => 15,
            'metodo_isr' => 'art174', 'concepto' => $this->concepto('P007'), 'deduccion_isr' => $this->concepto('D002'), 'parametros' => $this->parametros()]);
        $this->assertSame(5.0137, $r['dias_aguinaldo']);  // 15 × 122 días (1 sep–31 dic) / 365
        $this->assertSame(1880.14, $r['totales']['percepciones']);
        $this->assertSame(0.0, $r['isr']['isr_retenido']);   // todo cabe en la exención de 30 UMA
        $this->assertSame([], $r['deducciones']);
    }

    public function test_calculo_anual_a_cargo_a_favor_y_exclusiones(): void
    {
        $tarifa = ValoresIniciales::vigentesEn(ValoresIniciales::tablasFiscales(), '2026-12-31')['isr_anual']['renglones'];
        $base = ['ejercicio' => 2026, 'fecha_ingreso' => '2025-06-02', 'ingresos_totales' => 160000, 'gravado' => 145000,
            'isr_retenido' => 9000, 'subsidio_aplicado' => 1500, 'tarifa_anual' => $tarifa];
        $r = AjusteAnual::calcular($base);
        $this->assertTrue($r['aplica']);
        $this->assertSame(11468.16, $r['isr_anual']);     // 5,051.37 + (145,000 − 86,022.12) × 10.88 %
        $this->assertSame(9968.16, $r['isr_despues_subsidio']);
        $this->assertSame(968.16, $r['diferencia']);
        $this->assertSame('a_cargo', $r['resultado']);

        $this->assertSame('a_favor', AjusteAnual::calcular([...$base, 'isr_retenido' => 10500])['resultado']);
        $this->assertSame(0.0, AjusteAnual::calcular([...$base, 'gravado' => 50000, 'isr_retenido' => 0, 'subsidio_aplicado' => 9000])['isr_despues_subsidio']);   // el subsidio no se devuelve

        $this->assertFalse(AjusteAnual::calcular([...$base, 'fecha_ingreso' => '2026-02-01'])['aplica']);
        $this->assertFalse(AjusteAnual::calcular([...$base, 'fecha_baja' => '2026-11-20'])['aplica']);
        $this->assertTrue(AjusteAnual::calcular([...$base, 'fecha_baja' => '2026-12-05'])['aplica']);
        $this->assertFalse(AjusteAnual::calcular([...$base, 'ingresos_totales' => 400000.01])['aplica']);
        $this->assertFalse(AjusteAnual::calcular([...$base, 'declara_por_su_cuenta' => true])['aplica']);
    }

    public function test_saldo_de_vacaciones_por_anio_de_servicio(): void
    {
        $s = Vacaciones::saldo('2024-03-02', '2026-11-17', [1 => 12, 2 => 4]);
        $this->assertCount(2, $s['anios']);
        $this->assertSame('agotado', $s['anios'][0]['estado']);
        $this->assertSame(14, $s['anios'][1]['derecho']);
        $this->assertSame(10, $s['anios'][1]['pendientes']);
        $this->assertSame('por_vencer', $s['anios'][1]['estado']);   // más de 6 meses desde el aniversario
        $this->assertSame(10, $s['disponibles']);
        $this->assertSame(['anio' => 3, 'desde' => '2027-03-02', 'derecho' => 16], $s['proximo']);
        $this->assertSame(2, Vacaciones::anioACargar($s, 5));
        $this->assertNull(Vacaciones::anioACargar($s, 11));
        $this->assertSame(0, Vacaciones::saldo('2026-03-02', '2026-11-17')['disponibles']);   // aún no cumple un año
    }

    public function test_importe_con_letra(): void
    {
        $this->assertSame('CINCO MIL TRESCIENTOS ONCE PESOS 72/100 M.N.', ImporteConLetra::pesos(5311.72));
        $this->assertSame('UN MILLÓN DE PESOS 00/100 M.N.', ImporteConLetra::pesos(1000000));
        $this->assertSame('VEINTIÚN MIL CIENTO UN PESOS 50/100 M.N.', ImporteConLetra::pesos(21101.5));
        $this->assertSame('CIEN PESOS 00/100 M.N.', ImporteConLetra::pesos(100));
        $this->assertSame('UN PESO 00/100 M.N.', ImporteConLetra::pesos(1));
        $this->assertSame('OCHO MIL QUINIENTOS VEINTICINCO PESOS 67/100 M.N.', ImporteConLetra::pesos(8525.67));
    }

    public function test_recibo_html_dice_si_no_es_cfdi(): void
    {
        $calculo = $this->quincena();
        $recibo = ['id' => 7, 'estado' => 'autorizado', 'nombre' => 'GÓMEZ <b>X</b>', 'numero_empleado' => 'VC0001', 'rfc' => 'HEGG560427AB3', 'curp' => null,
            'nss' => '12345678903', 'puesto' => 'Generalista', 'salario_diario' => 375, 'sdi' => 393.49, 'dias_pagados' => 15, 'faltas' => 0, 'domingos' => 0,
            'periodo' => ['etiqueta' => 'Quincena 21/2026', 'inicio' => '2026-11-01', 'fin' => '2026-11-15', 'fecha_pago' => '2026-11-15', 'dias' => 15],
            'calculo' => $calculo, 'cfdi' => ['uuid' => null, 'timbrado_at' => null]];
        $html = ReciboHtml::documento([$recibo, $recibo], ['nombre' => 'Vagón COM', 'razon_social' => null, 'rfc' => 'VCO010101AB1']);
        $this->assertStringContainsString('no es un Comprobante Fiscal Digital', $html);
        $this->assertStringContainsString('GÓMEZ &lt;b&gt;X&lt;/b&gt;', $html);   // escapado
        $this->assertStringContainsString(ImporteConLetra::pesos($calculo['totales']['neto']), $html);
        $this->assertSame(1, substr_count($html, 'class="salto"'));   // dos recibos, un salto de página
    }
}
