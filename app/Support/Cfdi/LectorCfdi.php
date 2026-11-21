<?php

namespace App\Support\Cfdi;

/**
 * v14 — Lee un CFDI de nómina timbrado (4.0, y 3.3 para archivos antiguos) y devuelve sus datos clave:
 * emisor, receptor, totales, fechas del complemento de nómina y el Timbre Fiscal Digital (UUID).
 * Lo usan la importación de XML del departamento de nóminas y el PDF del recibo timbrado.
 */
final class LectorCfdi
{
    private const NS_CFDI = ['4.0' => 'http://www.sat.gob.mx/cfd/4', '3.3' => 'http://www.sat.gob.mx/cfd/3'];

    public static function leer(string $xml): array
    {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);   // BOM
        $doc = CadenaOriginal::cargar($xml);
        $c = $doc->documentElement;
        $version = array_search($c->namespaceURI, self::NS_CFDI, true);
        if ($version === false || $c->localName !== 'Comprobante') {
            throw new CfdiException('El archivo no es un CFDI.', 'NO_ES_CFDI');
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('cfdi', self::NS_CFDI[$version]);
        $xp->registerNamespace('nomina12', CadenaOriginal::NS_NOMINA);
        $xp->registerNamespace('tfd', CadenaOriginal::NS_TFD);

        $a = fn (?\DOMNode $n, string $atr) => $n instanceof \DOMElement && $n->hasAttribute($atr) ? $n->getAttribute($atr) : null;
        $num = fn (?string $v) => $v === null ? null : round((float) $v, 2);
        $uno = fn (string $q) => $xp->query($q)->item(0);

        $emisor = $uno('/cfdi:Comprobante/cfdi:Emisor');
        $receptor = $uno('/cfdi:Comprobante/cfdi:Receptor');
        $nomina = $uno('/cfdi:Comprobante/cfdi:Complemento/nomina12:Nomina');
        $nomReceptor = $uno('/cfdi:Comprobante/cfdi:Complemento/nomina12:Nomina/nomina12:Receptor');
        $tfd = $uno('/cfdi:Comprobante/cfdi:Complemento/tfd:TimbreFiscalDigital');

        $percepciones = [];
        foreach ($xp->query('//nomina12:Nomina/nomina12:Percepciones/nomina12:Percepcion') as $p) {
            $percepciones[] = ['tipo' => $a($p, 'TipoPercepcion'), 'clave' => $a($p, 'Clave'), 'concepto' => $a($p, 'Concepto'),
                'gravado' => $num($a($p, 'ImporteGravado')), 'exento' => $num($a($p, 'ImporteExento'))];
        }
        $deducciones = [];
        foreach ($xp->query('//nomina12:Nomina/nomina12:Deducciones/nomina12:Deduccion') as $x) {
            $deducciones[] = ['tipo' => $a($x, 'TipoDeduccion'), 'clave' => $a($x, 'Clave'), 'concepto' => $a($x, 'Concepto'), 'importe' => $num($a($x, 'Importe'))];
        }

        return [
            'version' => $version,
            'serie' => $a($c, 'Serie'), 'folio' => $a($c, 'Folio'), 'fecha' => $a($c, 'Fecha'),
            'tipo_comprobante' => $a($c, 'TipoDeComprobante'),
            'subtotal' => $num($a($c, 'SubTotal')), 'descuento' => $num($a($c, 'Descuento')) ?? 0.0, 'total' => $num($a($c, 'Total')),
            'sello' => $a($c, 'Sello'), 'no_certificado' => $a($c, 'NoCertificado'),
            'emisor' => ['rfc' => strtoupper((string) $a($emisor, 'Rfc')), 'nombre' => $a($emisor, 'Nombre'), 'regimen' => $a($emisor, 'RegimenFiscal')],
            'receptor' => ['rfc' => strtoupper((string) $a($receptor, 'Rfc')), 'nombre' => $a($receptor, 'Nombre')],
            'nomina' => $nomina ? [
                'tipo' => $a($nomina, 'TipoNomina'), 'fecha_pago' => $a($nomina, 'FechaPago'), 'fecha_inicial' => $a($nomina, 'FechaInicialPago'),
                'fecha_final' => $a($nomina, 'FechaFinalPago'), 'dias' => (float) $a($nomina, 'NumDiasPagados'),
                'total_percepciones' => $num($a($nomina, 'TotalPercepciones')) ?? 0.0, 'total_deducciones' => $num($a($nomina, 'TotalDeducciones')) ?? 0.0,
                'total_otros_pagos' => $num($a($nomina, 'TotalOtrosPagos')) ?? 0.0,
                'curp' => strtoupper((string) $a($nomReceptor, 'Curp')), 'num_empleado' => $a($nomReceptor, 'NumEmpleado'),
                'periodicidad' => $a($nomReceptor, 'PeriodicidadPago'),
                'percepciones' => $percepciones, 'deducciones' => $deducciones,
            ] : null,
            'tfd' => $tfd ? [
                'version' => $a($tfd, 'Version'), 'uuid' => strtoupper((string) $a($tfd, 'UUID')), 'fecha_timbrado' => $a($tfd, 'FechaTimbrado'),
                'rfc_prov_certif' => $a($tfd, 'RfcProvCertif'), 'leyenda' => $a($tfd, 'Leyenda'), 'sello_cfd' => $a($tfd, 'SelloCFD'),
                'no_certificado_sat' => $a($tfd, 'NoCertificadoSAT'), 'sello_sat' => $a($tfd, 'SelloSAT'),
            ] : null,
        ];
    }

    /** Liga de verificación del SAT (la misma que codifica el QR del recibo impreso). */
    public static function urlVerificacion(array $cfdi): string
    {
        $tt = rtrim(rtrim(number_format((float) $cfdi['total'], 6, '.', ''), '0'), '.');
        $q = http_build_query(['id' => $cfdi['tfd']['uuid'] ?? '', 're' => $cfdi['emisor']['rfc'], 'rr' => $cfdi['receptor']['rfc'],
            'tt' => $tt === '' ? '0' : $tt, 'fe' => substr((string) ($cfdi['sello'] ?? ''), -8)]);

        return 'https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx?'.$q;
    }

    public static function esUuid(string $v): bool
    {
        return (bool) preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i', $v);
    }
}
