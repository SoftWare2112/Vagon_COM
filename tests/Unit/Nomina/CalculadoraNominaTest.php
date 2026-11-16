<?php

namespace Tests\Unit\Nomina;

use App\Support\Nomina\CalculadoraNomina;
use App\Support\Nomina\GeneradorPeriodos;
use App\Support\Nomina\Prestaciones;
use App\Support\Nomina\TarifaIsr;
use App\Support\Nomina\ValoresIniciales;
use PHPUnit\Framework\TestCase;

/**
 * v11: el motor de nómina reproduce los netos reales que dio la empresa (2026):
 *  - Generalista ($11,250 al mes): $5,100–$5,400 por quincena.
 *  - Supervisor de mesa de servicio (~$20,000 al mes): $8,200–$8,500 por quincena.
 */
class CalculadoraNominaTest extends TestCase
{
    private function calcular(float $mensual, array $extra = []): array
    {
        $tablas = ValoresIniciales::tablasFiscales();
        $fin = $extra['fin'] ?? '2026-11-15';

        return CalculadoraNomina::calcular([
            'salario_mensual' => $mensual, 'divisor' => 30, 'tipo_periodo' => 'quincenal', 'dias_periodo' => 15,
            'fecha_ingreso' => '2026-02-01', 'inicio' => '2026-11-01', 'fin' => $fin, 'es_fin_de_mes' => false,
            'conceptos' => ValoresIniciales::conceptos(),
            'parametros' => ValoresIniciales::parametros(ValoresIniciales::vigentesEn($tablas, $fin), 'quincenal'),
            ...$extra,
        ]);
    }

    public function test_generalista_primera_quincena(): void
    {
        $r = $this->calcular(11250);
        $this->assertSame(375.0, $r['salario_diario']);
        $this->assertSame(393.49, $r['sdi']);
        $this->assertSame(434.91, $r['isr']['isr_causado']);
        $this->assertSame(264.30, $r['isr']['subsidio_aplicado']);
        $this->assertSame(170.61, $r['isr']['isr_retenido']);
        $this->assertSame(142.67, round(array_sum(array_column($r['imss'], 'importe')), 2));
        $this->assertSame(5311.72, $r['totales']['neto']);
        $this->assertTrue($r['totales']['neto'] >= 5100 && $r['totales']['neto'] <= 5400);
    }

    public function test_generalista_segunda_quincena_con_bonos(): void
    {
        $r = $this->calcular(11250, ['inicio' => '2026-11-16', 'fin' => '2026-11-30', 'es_fin_de_mes' => true]);
        $this->assertSame(['P001', 'P002', 'P003'], array_column($r['percepciones'], 'clave'));   // P004 en $0 no aparece
        $this->assertSame(6625.0, $r['totales']['gravado']);
        $this->assertSame(0.0, $r['isr']['subsidio_aplicado']);    // pasa el límite proporcional del subsidio
        $this->assertSame(564.61, $r['isr']['isr_retenido']);
        $this->assertSame(5917.72, $r['totales']['neto']);
    }

    public function test_bono_de_tres_meses_y_puntualidad_respetan_sus_condiciones(): void
    {
        $nuevo = $this->calcular(11250, ['fecha_ingreso' => '2026-10-01', 'inicio' => '2026-11-16', 'fin' => '2026-11-30', 'es_fin_de_mes' => true, 'retardos' => 1]);
        $this->assertSame(['P001'], array_column($nuevo['percepciones'], 'clave'));   // 1 mes de antigüedad y con retardo
        $unico = ValoresIniciales::conceptos();
        $unico[2]['regla']['frecuencia'] = 'unico';
        $cumple = $this->calcular(11250, ['fecha_ingreso' => '2026-08-20', 'inicio' => '2026-11-16', 'fin' => '2026-11-30', 'es_fin_de_mes' => true, 'conceptos' => $unico]);
        $this->assertContains('P003', array_column($cumple['percepciones'], 'clave'));   // cumplió 3 meses el 20/nov
        $despues = $this->calcular(11250, ['fecha_ingreso' => '2026-07-20', 'inicio' => '2026-11-16', 'fin' => '2026-11-30', 'es_fin_de_mes' => true, 'conceptos' => $unico]);
        $this->assertNotContains('P003', array_column($despues['percepciones'], 'clave'));
    }

    public function test_supervisor(): void
    {
        $this->assertSame(8525.67, $this->calcular(20000)['totales']['neto']);
        $r = $this->calcular(19500);
        $this->assertSame(1150.86, $r['isr']['isr_retenido']);
        $this->assertSame(8336.34, $r['totales']['neto']);   // cuotas IMSS redondeadas por rama
    }

    public function test_prima_dominical_exenta_hasta_una_uma_por_domingo(): void
    {
        $gen = $this->calcular(11250, ['domingos' => 2]);
        $prima = $gen['percepciones'][1];
        $this->assertSame(['P005', 187.5, 0.0, 187.5], [$prima['clave'], $prima['importe'], $prima['gravado'], $prima['exento']]);
        $sup = $this->calcular(20000, ['domingos' => 1]);
        $prima = $sup['percepciones'][1];
        $this->assertSame([166.67, 117.31, 49.36], [$prima['importe'], $prima['exento'], $prima['gravado']]);
    }

    public function test_el_sdi_sube_al_cumplir_un_anio(): void
    {
        $this->assertSame(394.01, $this->calcular(11250, ['fecha_ingreso' => '2025-03-01'])['sdi']);
    }

    public function test_faltas_descuentan_sueldo_y_dias_cotizados(): void
    {
        $r = $this->calcular(11250, ['faltas' => 2]);
        $this->assertSame(4875.0, $r['percepciones'][0]['importe']);
        $this->assertSame(13, $r['dias_pagados']);
        $this->assertSame(123.65, round(array_sum(array_column($r['imss'], 'importe')), 2));
    }

    public function test_salario_minimo_no_retiene_isr_ni_cuota_obrera(): void
    {
        $r = $this->calcular(315.04 * 30);
        $this->assertSame(0.0, $r['isr']['isr_retenido']);
        $this->assertSame(0.0, $r['totales']['deducciones']);
        $this->assertCount(1, $r['avisos']);
    }

    public function test_enero_usa_uma_y_subsidio_de_2025(): void
    {
        $r = $this->calcular(11250, ['inicio' => '2026-01-01', 'fin' => '2026-01-15']);
        $this->assertSame(264.58, $r['isr']['subsidio_causado']);   // 15.59 % × 3,439.46 / 30.4 × 15
    }

    public function test_tarifas_mensual_y_semanal(): void
    {
        $t = ValoresIniciales::vigentesEn(ValoresIniciales::tablasFiscales(), '2026-06-01');
        $this->assertSame(1856.85, TarifaIsr::aplicar(17533.64, $t['isr_mensual']['renglones'])['isr']);
        $this->assertSame(202.96, TarifaIsr::aplicar(2625, $t['isr_semanal']['renglones'])['isr']);
        $this->assertSame(0.0, TarifaIsr::aplicar(0, $t['isr_mensual']['renglones'])['isr']);
    }

    public function test_prestaciones_por_antiguedad(): void
    {
        $this->assertSame([0, 12, 14, 16, 18, 20, 22, 22, 24, 26], array_map([Prestaciones::class, 'diasVacaciones'], [0, 1, 2, 3, 4, 5, 6, 10, 11, 16]));
        $this->assertSame(1.049315, Prestaciones::factorIntegracion(0));
        $this->assertSame(1.050685, Prestaciones::factorIntegracion(1));   // 2.º año: 14 días de vacaciones
        $this->assertSame(2, Prestaciones::antiguedadMeses(new \DateTimeImmutable('2026-09-15'), new \DateTimeImmutable('2026-11-30')));
        $this->assertSame(2.5068, Prestaciones::diasAguinaldoProporcionales(new \DateTimeImmutable('2026-11-01'), 2026));
    }

    public function test_calendario_de_periodos(): void
    {
        $q = GeneradorPeriodos::generar('quincenal', 2026);
        $this->assertCount(24, $q);
        $this->assertSame(['2026-02-16', '2026-02-28', true, 15], [$q[3]['inicio'], $q[3]['fin'], $q[3]['fin_de_mes'], $q[3]['dias']]);
        $this->assertFalse($q[0]['fin_de_mes']);
        $m = GeneradorPeriodos::generar('mensual', 2026);
        $this->assertSame(['2026-12-01', '2026-12-31'], [$m[11]['inicio'], $m[11]['fin']]);
        $s = GeneradorPeriodos::generar('semanal', 2026);
        $this->assertSame('2025-12-29', $s[0]['inicio']);   // lunes de la semana del 1 de enero
        $this->assertCount(53, $s);
        $finesDeMes = array_values(array_filter($s, fn ($p) => $p['fin_de_mes']));
        $this->assertCount(12, $finesDeMes);
        $this->assertSame('2026-01-25', $finesDeMes[0]['fin']);   // última semana que termina en enero
    }
}
