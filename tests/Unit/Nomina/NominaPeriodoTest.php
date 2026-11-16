<?php

namespace Tests\Unit\Nomina;

use App\Support\Nomina\CalculadoraNomina;
use App\Support\Nomina\DiasLaborados;
use App\Support\Nomina\Huella;
use App\Support\Nomina\ValidadorFiscal;
use App\Support\Nomina\ValoresIniciales;
use PHPUnit\Framework\TestCase;

/** v12: días pagados al entrar o salir a mitad de periodo, huella de los recibos autorizados y validación omitible. */
class NominaPeriodoTest extends TestCase
{
    public function test_periodo_completo_no_tiene_dias_fuera(): void
    {
        $this->assertSame(0, DiasLaborados::fuera('2026-11-16', '2026-11-30', 15, '2025-06-02'));
        $this->assertSame(0, DiasLaborados::fuera('2026-12-16', '2026-12-31', 15, '2025-06-02'));   // 16 días de calendario, se pagan 15
    }

    public function test_ingreso_a_mitad_de_quincena(): void
    {
        $this->assertSame(8, DiasLaborados::fuera('2026-11-16', '2026-11-30', 15, '2026-11-24'));   // trabaja del 24 al 30 = 7 días
        $this->assertSame(7, DiasLaborados::fuera('2026-12-16', '2026-12-31', 15, '2026-12-24'));   // del 24 al 31 = 8 días
        $this->assertSame(15, DiasLaborados::fuera('2026-11-16', '2026-11-30', 15, '2026-12-01'));  // aún no ingresaba
    }

    public function test_baja_a_mitad_de_quincena(): void
    {
        $this->assertSame(10, DiasLaborados::fuera('2026-11-01', '2026-11-15', 15, '2025-01-01', '2026-11-05'));
        $this->assertTrue(DiasLaborados::participa('2026-11-01', '2026-11-15', '2025-01-01', '2026-11-05'));
        $this->assertFalse(DiasLaborados::participa('2026-11-16', '2026-11-30', '2025-01-01', '2026-11-05'));
    }

    public function test_el_calculo_paga_solo_los_dias_contratados(): void
    {
        $tablas = ValoresIniciales::tablasFiscales();
        $r = CalculadoraNomina::calcular([
            'salario_mensual' => 11250, 'divisor' => 30, 'tipo_periodo' => 'quincenal', 'dias_periodo' => 15, 'dias_fuera' => 8,
            'fecha_ingreso' => '2026-11-24', 'inicio' => '2026-11-16', 'fin' => '2026-11-30', 'es_fin_de_mes' => true,
            'conceptos' => ValoresIniciales::conceptos(),
            'parametros' => ValoresIniciales::parametros(ValoresIniciales::vigentesEn($tablas, '2026-11-30'), 'quincenal'),
        ]);
        $this->assertSame(7, $r['dias_pagados']);
        $this->assertSame(2625.0, $r['percepciones'][0]['importe']);   // 7 × $375
        $this->assertSame('P001', $r['percepciones'][0]['clave']);
    }

    public function test_la_huella_no_depende_del_orden_ni_de_enteros_contra_decimales(): void
    {
        $a = Huella::de(['neto' => 5311.72, 'dias' => 15, 'detalle' => ['b' => 500, 'a' => [1, 2]]]);
        $b = Huella::de(['detalle' => ['a' => [1.0, 2.0], 'b' => 500.0], 'dias' => 15.0, 'neto' => 5311.720]);
        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
        $this->assertNotSame($a, Huella::de(['neto' => 5311.73, 'dias' => 15, 'detalle' => ['b' => 500, 'a' => [1, 2]]]));
    }

    public function test_solo_el_digito_verificador_se_puede_omitir(): void
    {
        $this->assertTrue(ValidadorFiscal::esErrorDeDigito(ValidadorFiscal::rfc('HEGG560427AB4')));
        $this->assertTrue(ValidadorFiscal::esErrorDeDigito(ValidadorFiscal::nss('12345678901')));
        $this->assertFalse(ValidadorFiscal::esErrorDeDigito(ValidadorFiscal::nss('123')));          // formato: nunca se omite
        $this->assertFalse(ValidadorFiscal::esErrorDeDigito(ValidadorFiscal::rfc('HEGG561327AB3')));  // fecha inválida
        $this->assertFalse(ValidadorFiscal::esErrorDeDigito(null));
    }
}
