<?php

namespace App\Support\Cfdi;

/**
 * v14 — Arma el XML del CFDI 4.0 de tipo N (nómina) con el Complemento de Nómina 1.2 y lo sella con el CSD.
 *
 * Recibe los datos ya traducidos a claves del SAT (ver DatosCfdiNomina) y calcula los totales que exige la
 * guía de llenado del SAT:
 *  - Nomina@TotalPercepciones = TotalSueldos + TotalSeparacionIndemnizacion + TotalJubilacionPensionRetiro
 *  - Nomina@TotalDeducciones  = TotalOtrasDeducciones + TotalImpuestosRetenidos (ISR, clave 002)
 *  - Comprobante@SubTotal     = TotalPercepciones + TotalOtrosPagos
 *  - Comprobante@Descuento    = TotalDeducciones;  Total = SubTotal − Descuento
 * El único concepto es «Pago de nómina» (84111505, ACT, ObjetoImp 01). Sin FormaPago (no aplica al tipo N).
 */
final class NominaXml
{
    /** Percepciones que NO suman a TotalSueldos (indemnizaciones, jubilaciones y pensiones). */
    private const NO_SUELDOS = ['022', '023', '025', '039', '044'];

    public static function armar(array $d): \DOMDocument
    {
        $per = array_values(array_filter($d['percepciones'], fn ($p) => round($p['gravado'] + $p['exento'], 2) > 0));
        $ded = array_values(array_filter($d['deducciones'], fn ($x) => round($x['importe'], 2) > 0));
        $otros = array_values($d['otros_pagos'] ?? []);

        $totalSueldos = self::suma(array_map(fn ($p) => in_array($p['tipo'], self::NO_SUELDOS, true) ? 0 : $p['gravado'] + $p['exento'], $per));
        $totalGravado = self::suma(array_column($per, 'gravado'));
        $totalExento = self::suma(array_column($per, 'exento'));
        $totalPercepciones = round($totalGravado + $totalExento, 2);
        $impuestos = self::suma(array_map(fn ($x) => $x['tipo'] === '002' ? $x['importe'] : 0, $ded));
        $otrasDed = self::suma(array_map(fn ($x) => $x['tipo'] === '002' ? 0 : $x['importe'], $ded));
        $totalDeducciones = round($impuestos + $otrasDed, 2);
        $totalOtros = self::suma(array_column($otros, 'importe'));
        $subTotal = round($totalPercepciones + $totalOtros, 2);
        $total = round($subTotal - $totalDeducciones, 2);
        if ($total < 0) {
            throw new CfdiException('El total del CFDI sería negativo: las deducciones superan a las percepciones.', 'TOTAL_NEGATIVO');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;
        $c = $doc->createElementNS(CadenaOriginal::NS_CFDI, 'cfdi:Comprobante');
        $doc->appendChild($c);
        $c->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:nomina12', CadenaOriginal::NS_NOMINA);
        $c->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation',
            'http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd '
            .'http://www.sat.gob.mx/nomina12 http://www.sat.gob.mx/sitio_internet/cfd/nomina/nomina12.xsd');

        $comp = $d['comprobante'];
        self::atributos($c, [
            'Version' => '4.0', 'Serie' => $comp['Serie'] ?? null, 'Folio' => $comp['Folio'] ?? null, 'Fecha' => $comp['Fecha'],
            'NoCertificado' => '', 'SubTotal' => self::m($subTotal), 'Descuento' => $totalDeducciones > 0 ? self::m($totalDeducciones) : null,
            'Moneda' => 'MXN', 'Total' => self::m($total), 'TipoDeComprobante' => 'N', 'Exportacion' => '01', 'MetodoPago' => 'PUE',
            'LugarExpedicion' => $comp['LugarExpedicion'],
        ]);

        $e = $d['emisor'];
        self::hijo($c, CadenaOriginal::NS_CFDI, 'cfdi:Emisor', ['Rfc' => $e['Rfc'], 'Nombre' => $e['Nombre'], 'RegimenFiscal' => $e['RegimenFiscal']]);
        $r = $d['receptor'];
        self::hijo($c, CadenaOriginal::NS_CFDI, 'cfdi:Receptor', ['Rfc' => $r['Rfc'], 'Nombre' => $r['Nombre'],
            'DomicilioFiscalReceptor' => $r['DomicilioFiscalReceptor'], 'RegimenFiscalReceptor' => $r['RegimenFiscalReceptor'] ?? '605', 'UsoCFDI' => 'CN01']);
        $conceptos = self::hijo($c, CadenaOriginal::NS_CFDI, 'cfdi:Conceptos', []);
        self::hijo($conceptos, CadenaOriginal::NS_CFDI, 'cfdi:Concepto', ['ClaveProdServ' => '84111505', 'Cantidad' => '1', 'ClaveUnidad' => 'ACT',
            'Descripcion' => 'Pago de nómina', 'ValorUnitario' => self::m($subTotal), 'Importe' => self::m($subTotal),
            'Descuento' => $totalDeducciones > 0 ? self::m($totalDeducciones) : null, 'ObjetoImp' => '01']);

        $complemento = self::hijo($c, CadenaOriginal::NS_CFDI, 'cfdi:Complemento', []);
        $n = $d['nomina'];
        $nomina = self::hijo($complemento, CadenaOriginal::NS_NOMINA, 'nomina12:Nomina', [
            'Version' => '1.2', 'TipoNomina' => $n['TipoNomina'], 'FechaPago' => $n['FechaPago'], 'FechaInicialPago' => $n['FechaInicialPago'],
            'FechaFinalPago' => $n['FechaFinalPago'], 'NumDiasPagados' => self::dias($n['NumDiasPagados']),
            'TotalPercepciones' => $per ? self::m($totalPercepciones) : null, 'TotalDeducciones' => $ded ? self::m($totalDeducciones) : null,
            'TotalOtrosPagos' => $otros ? self::m($totalOtros) : null,
        ]);
        self::hijo($nomina, CadenaOriginal::NS_NOMINA, 'nomina12:Emisor', ['Curp' => $e['Curp'] ?? null, 'RegistroPatronal' => $e['RegistroPatronal'] ?? null]);
        self::hijo($nomina, CadenaOriginal::NS_NOMINA, 'nomina12:Receptor', [
            'Curp' => $r['Curp'], 'NumSeguridadSocial' => $r['NumSeguridadSocial'] ?? null, 'FechaInicioRelLaboral' => $r['FechaInicioRelLaboral'] ?? null,
            'Antigüedad' => $r['Antiguedad'] ?? null, 'TipoContrato' => $r['TipoContrato'], 'Sindicalizado' => $r['Sindicalizado'] ?? 'No',
            'TipoJornada' => $r['TipoJornada'] ?? null, 'TipoRegimen' => $r['TipoRegimen'] ?? '02', 'NumEmpleado' => $r['NumEmpleado'],
            'Departamento' => $r['Departamento'] ?? null, 'Puesto' => $r['Puesto'] ?? null, 'RiesgoPuesto' => $r['RiesgoPuesto'] ?? null,
            'PeriodicidadPago' => $r['PeriodicidadPago'], 'CuentaBancaria' => $r['CuentaBancaria'] ?? null,
            'SalarioBaseCotApor' => isset($r['SalarioBaseCotApor']) ? self::m($r['SalarioBaseCotApor']) : null,
            'SalarioDiarioIntegrado' => isset($r['SalarioDiarioIntegrado']) ? self::m($r['SalarioDiarioIntegrado']) : null, 'ClaveEntFed' => $r['ClaveEntFed'],
        ]);

        if ($per) {
            $nodo = self::hijo($nomina, CadenaOriginal::NS_NOMINA, 'nomina12:Percepciones', ['TotalSueldos' => self::m($totalSueldos),
                'TotalGravado' => self::m($totalGravado), 'TotalExento' => self::m($totalExento)]);
            foreach ($per as $p) {
                self::hijo($nodo, CadenaOriginal::NS_NOMINA, 'nomina12:Percepcion', ['TipoPercepcion' => $p['tipo'], 'Clave' => $p['clave'],
                    'Concepto' => $p['concepto'], 'ImporteGravado' => self::m($p['gravado']), 'ImporteExento' => self::m($p['exento'])]);
            }
        }
        if ($ded) {
            $nodo = self::hijo($nomina, CadenaOriginal::NS_NOMINA, 'nomina12:Deducciones', [
                'TotalOtrasDeducciones' => $otrasDed > 0 ? self::m($otrasDed) : null, 'TotalImpuestosRetenidos' => $impuestos > 0 ? self::m($impuestos) : null]);
            foreach ($ded as $x) {
                self::hijo($nodo, CadenaOriginal::NS_NOMINA, 'nomina12:Deduccion', ['TipoDeduccion' => $x['tipo'], 'Clave' => $x['clave'],
                    'Concepto' => $x['concepto'], 'Importe' => self::m($x['importe'])]);
            }
        }
        if ($otros) {
            $nodo = self::hijo($nomina, CadenaOriginal::NS_NOMINA, 'nomina12:OtrosPagos', []);
            foreach ($otros as $o) {
                $op = self::hijo($nodo, CadenaOriginal::NS_NOMINA, 'nomina12:OtroPago', ['TipoOtroPago' => $o['tipo'], 'Clave' => $o['clave'],
                    'Concepto' => $o['concepto'], 'Importe' => self::m($o['importe'])]);
                if (array_key_exists('subsidio_causado', $o)) {
                    self::hijo($op, CadenaOriginal::NS_NOMINA, 'nomina12:SubsidioAlEmpleo', ['SubsidioCausado' => self::m($o['subsidio_causado'])]);
                }
                if (isset($o['compensacion'])) {
                    self::hijo($op, CadenaOriginal::NS_NOMINA, 'nomina12:CompensacionSaldosAFavor', ['SaldoAFavor' => self::m($o['compensacion']['saldo']),
                        'Año' => (string) $o['compensacion']['anio'], 'RemanenteSalFav' => self::m($o['compensacion']['remanente'])]);
                }
            }
        }

        return $doc;
    }

    /** Pone NoCertificado y Certificado, calcula la cadena original y el Sello. Devuelve el XML listo para el PAC. */
    public static function sellar(\DOMDocument $doc, Csd $csd): string
    {
        $c = $doc->documentElement;
        $c->setAttribute('NoCertificado', $csd->noCertificado());
        $c->setAttribute('Certificado', $csd->certificadoBase64());
        $c->setAttribute('Sello', $csd->sellar(CadenaOriginal::de($doc)));

        return $doc->saveXML();
    }

    public static function m(float|int|string $v): string
    {
        return number_format(round((float) $v, 2), 2, '.', '');
    }

    private static function dias(float|int $d): string
    {
        $d = round((float) $d, 3);

        return rtrim(rtrim(number_format($d, 3, '.', ''), '0'), '.');
    }

    private static function suma(array $v): float
    {
        return round(array_sum(array_map('floatval', $v)), 2);
    }

    private static function hijo(\DOMElement $padre, string $ns, string $nombre, array $atributos): \DOMElement
    {
        $e = $padre->ownerDocument->createElementNS($ns, $nombre);
        $padre->appendChild($e);
        self::atributos($e, $atributos);

        return $e;
    }

    private static function atributos(\DOMElement $e, array $atributos): void
    {
        foreach ($atributos as $k => $v) {
            if ($v !== null) {
                $e->setAttribute($k, CadenaOriginal::normalizar((string) $v));
            }
        }
    }
}
