<?php

namespace App\Support\Cfdi;

/**
 * v14 — Cadena original del CFDI 4.0 con Complemento de Nómina 1.2, generada en PHP puro.
 *
 * Reproduce las hojas de transformación del SAT (cadenaoriginal_4_0.xslt + nomina12.xslt + utilerias.xslt):
 *  - recorre los nodos en el orden que fija el XSLT (no el orden del documento) y, en cada nodo,
 *    los atributos en el orden del XSLT;
 *  - cada valor se normaliza como `normalize-space()` (sin espacios al inicio/fin, espacios internos a uno);
 *  - el resultado es «||valor1|valor2|...||». Sello, Certificado y el Timbre Fiscal no forman parte.
 *
 * Solo admite los nodos que esta plataforma emite (nómina ordinaria y extraordinaria). Un nodo que no
 * conozca detiene el proceso: una cadena incompleta produciría un sello inválido (error 302 del PAC).
 * La prueba unitaria compara el resultado con el XSLT oficial del SAT.
 */
final class CadenaOriginal
{
    public const NS_CFDI = 'http://www.sat.gob.mx/cfd/4';

    public const NS_NOMINA = 'http://www.sat.gob.mx/nomina12';

    public const NS_TFD = 'http://www.sat.gob.mx/TimbreFiscalDigital';

    /** [atributos en orden, hijos en orden] por nodo, tal como los recorre el XSLT del SAT. */
    private const NODOS = [
        'cfdi:Comprobante' => [['Version', 'Serie', 'Folio', 'Fecha', 'FormaPago', 'NoCertificado', 'CondicionesDePago', 'SubTotal', 'Descuento', 'Moneda',
            'TipoCambio', 'Total', 'TipoDeComprobante', 'Exportacion', 'MetodoPago', 'LugarExpedicion', 'Confirmacion'],
            ['cfdi:InformacionGlobal', 'cfdi:CfdiRelacionados', 'cfdi:Emisor', 'cfdi:Receptor', 'cfdi:Conceptos', 'cfdi:Impuestos', 'cfdi:Complemento']],
        'cfdi:CfdiRelacionados' => [['TipoRelacion'], ['cfdi:CfdiRelacionado']],
        'cfdi:CfdiRelacionado' => [['UUID'], []],
        'cfdi:Emisor' => [['Rfc', 'Nombre', 'RegimenFiscal', 'FacAtrAdquirente'], []],
        'cfdi:Receptor' => [['Rfc', 'Nombre', 'DomicilioFiscalReceptor', 'ResidenciaFiscal', 'NumRegIdTrib', 'RegimenFiscalReceptor', 'UsoCFDI'], []],
        'cfdi:Conceptos' => [[], ['cfdi:Concepto']],
        'cfdi:Concepto' => [['ClaveProdServ', 'NoIdentificacion', 'Cantidad', 'ClaveUnidad', 'Unidad', 'Descripcion', 'ValorUnitario', 'Importe', 'Descuento', 'ObjetoImp'], []],
        'cfdi:Complemento' => [[], ['nomina12:Nomina']],   // el TFD se ignora: no es parte de la cadena del emisor
        'nomina12:Nomina' => [['Version', 'TipoNomina', 'FechaPago', 'FechaInicialPago', 'FechaFinalPago', 'NumDiasPagados', 'TotalPercepciones', 'TotalDeducciones', 'TotalOtrosPagos'],
            ['nomina12:Emisor', 'nomina12:Receptor', 'nomina12:Percepciones', 'nomina12:Deducciones', 'nomina12:OtrosPagos', 'nomina12:Incapacidades']],
        'nomina12:Emisor' => [['Curp', 'RegistroPatronal', 'RfcPatronOrigen'], ['nomina12:EntidadSNCF']],
        'nomina12:EntidadSNCF' => [['OrigenRecurso', 'MontoRecursoPropio'], []],
        'nomina12:Receptor' => [['Curp', 'NumSeguridadSocial', 'FechaInicioRelLaboral', 'Antigüedad', 'TipoContrato', 'Sindicalizado', 'TipoJornada', 'TipoRegimen',
            'NumEmpleado', 'Departamento', 'Puesto', 'RiesgoPuesto', 'PeriodicidadPago', 'Banco', 'CuentaBancaria', 'SalarioBaseCotApor', 'SalarioDiarioIntegrado', 'ClaveEntFed'],
            ['nomina12:SubContratacion']],
        'nomina12:SubContratacion' => [['RfcLabora', 'PorcentajeTiempo'], []],
        'nomina12:Percepciones' => [['TotalSueldos', 'TotalSeparacionIndemnizacion', 'TotalJubilacionPensionRetiro', 'TotalGravado', 'TotalExento'],
            ['nomina12:Percepcion', 'nomina12:JubilacionPensionRetiro', 'nomina12:SeparacionIndemnizacion']],
        'nomina12:Percepcion' => [['TipoPercepcion', 'Clave', 'Concepto', 'ImporteGravado', 'ImporteExento'], ['nomina12:AccionesOTitulos', 'nomina12:HorasExtra']],
        'nomina12:HorasExtra' => [['Dias', 'TipoHoras', 'HorasExtra', 'ImportePagado'], []],
        'nomina12:Deducciones' => [['TotalOtrasDeducciones', 'TotalImpuestosRetenidos'], ['nomina12:Deduccion']],
        'nomina12:Deduccion' => [['TipoDeduccion', 'Clave', 'Concepto', 'Importe'], []],
        'nomina12:OtrosPagos' => [[], ['nomina12:OtroPago']],
        'nomina12:OtroPago' => [['TipoOtroPago', 'Clave', 'Concepto', 'Importe'], ['nomina12:SubsidioAlEmpleo', 'nomina12:CompensacionSaldosAFavor']],
        'nomina12:SubsidioAlEmpleo' => [['SubsidioCausado'], []],
        'nomina12:CompensacionSaldosAFavor' => [['SaldoAFavor', 'Año', 'RemanenteSalFav'], []],
        'nomina12:Incapacidades' => [[], ['nomina12:Incapacidad']],
        'nomina12:Incapacidad' => [['DiasIncapacidad', 'TipoIncapacidad', 'ImporteMonetario'], []],
    ];

    private const PREFIJOS = [self::NS_CFDI => 'cfdi', self::NS_NOMINA => 'nomina12'];

    public static function de(\DOMDocument|string $xml): string
    {
        $doc = $xml instanceof \DOMDocument ? $xml : self::cargar($xml);
        $raiz = $doc->documentElement;
        if (! $raiz || self::nombre($raiz) !== 'cfdi:Comprobante') {
            throw new CfdiException('El documento no es un CFDI 4.0 (falta cfdi:Comprobante).', 'NO_ES_CFDI');
        }
        $valores = [];
        self::recorrer($raiz, $valores);

        return '||'.implode('|', $valores).'||';
    }

    private static function recorrer(\DOMElement $nodo, array &$valores): void
    {
        $nombre = self::nombre($nodo);
        if (! isset(self::NODOS[$nombre])) {
            throw new CfdiException("El nodo {$nombre} no está soportado por el generador de cadena original.", 'NODO_NO_SOPORTADO');
        }
        [$atributos, $hijos] = self::NODOS[$nombre];

        foreach ($nodo->attributes as $a) {
            if ($a->namespaceURI === null && ! in_array($a->name, $atributos, true) && ! in_array($a->name, ['Sello', 'Certificado'], true)) {
                throw new CfdiException("El atributo {$nombre}@{$a->name} no está soportado por el generador de cadena original.", 'ATRIBUTO_NO_SOPORTADO');
            }
        }
        foreach ($atributos as $a) {
            if ($nodo->hasAttribute($a)) {
                $valores[] = self::normalizar($nodo->getAttribute($a));
            }
        }

        $porNombre = [];
        foreach ($nodo->childNodes as $h) {
            if (! $h instanceof \DOMElement) {
                continue;
            }
            if ($h->namespaceURI === self::NS_TFD || ($nombre === 'cfdi:Complemento' && ! isset(self::PREFIJOS[$h->namespaceURI ?? '']))) {
                continue;   // Timbre Fiscal Digital u otro complemento ajeno a la nómina
            }
            $n = self::nombre($h);
            if (! in_array($n, $hijos, true)) {
                throw new CfdiException("El nodo {$n} dentro de {$nombre} no está soportado por el generador de cadena original.", 'NODO_NO_SOPORTADO');
            }
            $porNombre[$n][] = $h;
        }
        foreach ($hijos as $n) {
            foreach ($porNombre[$n] ?? [] as $h) {
                self::recorrer($h, $valores);
            }
        }
    }

    /** Equivalente a normalize-space() de XPath. */
    public static function normalizar(string $v): string
    {
        return trim(preg_replace('/[ \t\r\n]+/', ' ', $v));
    }

    private static function nombre(\DOMElement $e): string
    {
        $prefijo = self::PREFIJOS[$e->namespaceURI ?? ''] ?? ($e->prefix ?: '?');

        return $prefijo.':'.$e->localName;
    }

    public static function cargar(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument;
        $previo = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (! $ok) {
            throw new CfdiException('El XML está mal formado.', 'XML_INVALIDO');
        }

        return $doc;
    }

    /** Cadena original del complemento de certificación del SAT (Timbre Fiscal Digital 1.1), para el PDF. */
    public static function delTimbre(array $tfd): string
    {
        $v = array_filter([$tfd['version'] ?? '1.1', $tfd['uuid'] ?? '', $tfd['fecha_timbrado'] ?? '', $tfd['rfc_prov_certif'] ?? '',
            $tfd['leyenda'] ?? null, $tfd['sello_cfd'] ?? '', $tfd['no_certificado_sat'] ?? ''], fn ($x) => $x !== null && $x !== '');

        return '||'.implode('|', array_map([self::class, 'normalizar'], $v)).'||';
    }
}
