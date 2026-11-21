<?php

namespace Tests\Unit\Cfdi;

use App\Services\Cfdi\PacSimulado;
use App\Support\Cfdi\CadenaOriginal;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\Csd;
use App\Support\Cfdi\DatosCfdiNomina;
use App\Support\Cfdi\LectorCfdi;
use App\Support\Cfdi\NominaXml;
use PHPUnit\Framework\TestCase;

/**
 * v14 — CFDI 4.0 + Complemento de Nómina 1.2 (clases puras).
 * Usa el CSD de pruebas que publica el SAT (EKU9003173C9, contraseña 12345678a, vigente hasta mayo de 2027).
 * Las cadenas esperadas de tests/fixtures/cfdi/*.cadena.txt se generaron con el XSLT OFICIAL del SAT
 * (cadenaoriginal_4_0.xslt + nomina12.xslt): el generador en PHP debe dar exactamente lo mismo.
 */
class CfdiNominaTest extends TestCase
{
    private function fixture(string $f): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/'.$f);
    }

    private function csd(): Csd
    {
        return Csd::desdeArchivos($this->fixture('csd/EKU9003173C9.cer'), $this->fixture('csd/EKU9003173C9.key'), '12345678a');
    }

    /** Recibo verificado de la v12: Generalista, quincena 1, sueldo 5,625, IMSS 142.67, ISR 170.61, subsidio 264.30, neto 5,311.72. */
    private function contexto(array $cambios = []): array
    {
        $ctx = [
            'fecha' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->modify('-1 minute')->format('Y-m-d\TH:i:s'),
            'empresa' => ['rfc' => 'EKU9003173C9', 'razon_social' => 'Escuela Kemper Urgate', 'regimen_fiscal' => '601', 'codigo_postal' => '42501',
                'registro_patronal' => 'B5510768108', 'clave_ent_fed' => 'CMX', 'riesgo_puesto' => '1', 'tipo_jornada' => '01', 'serie_cfdi' => 'N'],
            'empleado' => ['rfc' => 'XOJI740919U48', 'curp' => 'XOJI740919HDFXXX06', 'nss' => '12345678901', 'codigo_postal' => '76028',
                'nombre' => 'Ingrid  Xodar Jiménez', 'numero_empleado' => 'MSI000001', 'fecha_ingreso' => '2025-03-01', 'tipo_contrato' => 'indeterminado',
                'departamento' => 'Operaciones', 'puesto' => 'Generalista', 'clabe' => '002180700000000004'],
            'periodo' => ['tipo' => 'quincenal', 'inicio' => '2026-01-01', 'fin' => '2026-01-15', 'fecha_pago' => '2026-01-15'],
            'recibo' => ['id' => 101, 'dias_pagados' => 15, 'sbc' => 393.49, 'sdi' => 393.49, 'subsidio_causado' => 264.30, 'neto' => 5311.72, 'detalle' => [
                'percepciones' => [['clave' => 'P001', 'nombre' => 'Sueldo', 'clave_sat' => '001', 'importe' => 5625, 'gravado' => 5625, 'exento' => 0],
                    ['clave' => 'P002', 'nombre' => 'Bono de puntualidad', 'clave_sat' => '010', 'importe' => 0, 'gravado' => 0, 'exento' => 0]],
                'deducciones' => [['clave' => 'D001', 'nombre' => 'Seguro Social (IMSS)', 'clave_sat' => '001', 'importe' => 142.67],
                    ['clave' => 'D002', 'nombre' => 'ISR', 'clave_sat' => '002', 'importe' => 170.61]],
                'otros_pagos' => [['clave' => 'SUBSIDIO', 'nombre' => 'Subsidio', 'clave_sat' => '002', 'importe' => 0, 'subsidio_causado' => 264.30]]]],
        ];

        return array_replace_recursive($ctx, $cambios);
    }

    public function test_csd_de_pruebas_del_sat(): void
    {
        $csd = $this->csd();
        $this->assertSame('30001000000500003416', $csd->noCertificado());
        $this->assertSame('EKU9003173C9', $csd->rfc());
        $this->assertFalse($csd->esEfirma());
        $this->assertTrue($csd->tieneLlave());
        $sello = $csd->sellar('||prueba||');
        $this->assertTrue($csd->verificar('||prueba||', $sello));
        $this->assertFalse($csd->verificar('||otra||', $sello));
    }

    public function test_contrasena_incorrecta_del_csd(): void
    {
        $this->expectException(CfdiException::class);
        Csd::desdeArchivos($this->fixture('csd/EKU9003173C9.cer'), $this->fixture('csd/EKU9003173C9.key'), 'equivocada');
    }

    public function test_cadena_original_igual_al_xslt_del_sat(): void
    {
        foreach (['nomina', 'aguinaldo', 'ajuste'] as $caso) {
            $this->assertSame(trim($this->fixture("cfdi/{$caso}.cadena.txt")), CadenaOriginal::de($this->fixture("cfdi/{$caso}.xml")), $caso);
        }
    }

    public function test_xml_de_nomina_ordinaria(): void
    {
        $r = DatosCfdiNomina::desde($this->contexto());
        $this->assertSame([], $r['errores']);
        $xml = NominaXml::sellar(NominaXml::armar($r['datos']), $this->csd());
        $c = LectorCfdi::leer($xml);

        $this->assertSame('N', $c['tipo_comprobante']);
        $this->assertSame(5625.0, $c['subtotal']);
        $this->assertSame(313.28, $c['descuento']);
        $this->assertSame(5311.72, $c['total']);           // = neto del recibo autorizado
        $this->assertSame('INGRID XODAR JIMÉNEZ', $c['receptor']['nombre']);
        $this->assertSame('O', $c['nomina']['tipo']);
        $this->assertSame('04', $c['nomina']['periodicidad']);
        $this->assertCount(1, $c['nomina']['percepciones']);   // el bono en cero no se emite
        $this->assertStringContainsString('<nomina12:SubsidioAlEmpleo SubsidioCausado="264.30"/>', $xml);
        $this->assertStringContainsString('TipoOtroPago="002" Clave="SUBSIDIO" Concepto="Subsidio para el empleo" Importe="0.00"', $xml);
        $this->assertStringContainsString('Antigüedad="P45W"', $xml);
        $this->assertStringContainsString('UsoCFDI="CN01"', $xml);
        $this->assertFalse(str_contains($xml, 'FormaPago'));   // no aplica al tipo N
        $this->assertTrue($this->csd()->verificar(CadenaOriginal::de($xml), $c['sello']));
    }

    public function test_aguinaldo_es_nomina_extraordinaria(): void
    {
        $r = DatosCfdiNomina::desde($this->contexto([
            'periodo' => ['tipo' => 'especial', 'inicio' => '2026-01-01', 'fin' => '2026-12-31', 'fecha_pago' => '2026-12-18'],
            'recibo' => ['neto' => 5395.92, 'subsidio_causado' => 0, 'detalle' => [
                'percepciones' => [['clave' => 'P007', 'nombre' => 'Aguinaldo', 'clave_sat' => '002', 'importe' => 5625, 'gravado' => 2105.70, 'exento' => 3519.30],
                    ['clave' => 'X', 'nombre' => 'X', 'clave_sat' => '001', 'importe' => 0, 'gravado' => 0, 'exento' => 0]],
                'deducciones' => [['clave' => 'D002', 'nombre' => 'ISR', 'clave_sat' => '002', 'importe' => 229.08],
                    ['clave' => 'D001', 'nombre' => 'IMSS', 'clave_sat' => '001', 'importe' => 0]],
                'otros_pagos' => []]],
        ]));
        $this->assertSame([], $r['errores']);
        $xml = NominaXml::armar($r['datos'])->saveXML();
        $this->assertStringContainsString('TipoNomina="E" FechaPago="2026-12-18" FechaInicialPago="2026-12-18" FechaFinalPago="2026-12-18"', $xml);
        $this->assertStringContainsString('PeriodicidadPago="99"', $xml);
        $this->assertFalse(str_contains($xml, 'OtrosPagos'));
        $this->assertStringContainsString('Total="5395.92"', $xml);
    }

    public function test_detecta_lo_que_el_sat_rechazaria(): void
    {
        $r = DatosCfdiNomina::desde($this->contexto([
            'empresa' => ['clave_ent_fed' => ''],
            'empleado' => ['codigo_postal' => ''],
            'recibo' => ['neto' => 9999, 'detalle' => ['percepciones' => [1 => ['clave' => 'P003', 'nombre' => 'Bono de 3 meses', 'clave_sat' => '038',
                'importe' => 500, 'gravado' => 400, 'exento' => 100]]]],
        ]));
        $texto = implode(' | ', $r['errores']);
        $this->assertStringContainsString('entidad federativa', $texto);
        $this->assertStringContainsString('código postal fiscal', $texto);
        $this->assertStringContainsString('038', $texto);
        $this->assertStringContainsString('no coincide con el neto', $texto);
    }

    public function test_antiguedad_en_semanas_cumplidas(): void
    {
        $this->assertSame('P0W', DatosCfdiNomina::antiguedad('2026-01-10', '2026-01-15'));
        $this->assertSame('P1W', DatosCfdiNomina::antiguedad('2026-01-01', '2026-01-08'));
        $this->assertSame('P52W', DatosCfdiNomina::antiguedad('2025-01-01', '2026-01-01'));
    }

    public function test_pac_simulado_timbra_con_leyenda_sin_validez(): void
    {
        $r = DatosCfdiNomina::desde($this->contexto());
        $xml = NominaXml::sellar(NominaXml::armar($r['datos']), $this->csd());
        $timbrado = (new PacSimulado)->timbrar($xml);
        $c = LectorCfdi::leer($timbrado);

        $this->assertTrue(LectorCfdi::esUuid($c['tfd']['uuid']));
        $this->assertSame(PacSimulado::LEYENDA, $c['tfd']['leyenda']);
        $this->assertSame($c['sello'], $c['tfd']['sello_cfd']);
        // El timbre no altera la cadena del emisor
        $this->assertSame(CadenaOriginal::de($xml), CadenaOriginal::de($timbrado));
        $this->assertStringContainsString('|'.PacSimulado::LEYENDA.'|', CadenaOriginal::delTimbre($c['tfd']));
        $this->assertStringContainsString('re=EKU9003173C9&rr=XOJI740919U48&tt=5311.72', LectorCfdi::urlVerificacion($c));
    }

    public function test_pac_simulado_rechaza_un_xml_alterado(): void
    {
        $r = DatosCfdiNomina::desde($this->contexto());
        $xml = NominaXml::sellar(NominaXml::armar($r['datos']), $this->csd());
        $this->expectException(CfdiException::class);
        (new PacSimulado)->timbrar(str_replace('Total="5311.72"', 'Total="6311.72"', $xml));
    }

    public function test_cadena_rechaza_nodos_desconocidos(): void
    {
        $xml = str_replace('<cfdi:Conceptos>', '<cfdi:CfdiRelacionados TipoRelacion="04"><cfdi:Otro/></cfdi:CfdiRelacionados><cfdi:Conceptos>', $this->fixture('cfdi/aguinaldo.xml'));
        $this->expectException(CfdiException::class);
        CadenaOriginal::de($xml);
    }

    public function test_lector_rechaza_lo_que_no_es_cfdi(): void
    {
        $this->expectException(CfdiException::class);
        LectorCfdi::leer('<?xml version="1.0"?><factura/>');
    }
}
