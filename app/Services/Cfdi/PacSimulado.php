<?php

namespace App\Services\Cfdi;

use App\Support\Cfdi\CadenaOriginal;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\Csd;

/**
 * v14 — «PAC simulado»: permite probar el flujo completo (armar, sellar, timbrar, PDF, cancelar) sin contrato con un PAC.
 *
 * Hace las revisiones que sí se pueden hacer sin el SAT: que el sello corresponda a la cadena original y al
 * certificado incluido en el XML, que el certificado esté vigente y que la fecha de emisión no tenga más de 72 horas.
 * El timbre que agrega lleva la leyenda «TIMBRE SIMULADO - SIN VALIDEZ FISCAL»: NO es un CFDI y no existe en el SAT.
 */
class PacSimulado implements ProveedorTimbrado
{
    public const LEYENDA = 'TIMBRE SIMULADO - SIN VALIDEZ FISCAL';

    public const RFC_PROVEEDOR = 'SIM010101000';

    public function modo(): string
    {
        return 'simulado';
    }

    public function nombre(): string
    {
        return 'PAC simulado (sin validez fiscal)';
    }

    public function probar(): array
    {
        return ['ok' => true, 'mensaje' => 'El PAC simulado está disponible. Los timbres que genera no tienen validez fiscal.'];
    }

    public function timbrar(string $xmlSellado): string
    {
        $doc = CadenaOriginal::cargar($xmlSellado);
        $c = $doc->documentElement;
        foreach (['Sello', 'Certificado', 'NoCertificado', 'Fecha'] as $a) {
            if (! $c->getAttribute($a)) {
                throw new CfdiException("Falta el atributo {$a} del comprobante.", 'PAC_RECHAZO');
            }
        }
        $csd = Csd::desdeArchivos(base64_decode($c->getAttribute('Certificado')));
        if ($csd->noCertificado() !== $c->getAttribute('NoCertificado')) {
            throw new CfdiException('NoCertificado no corresponde al certificado incluido (equivale al error CFDI40104).', 'PAC_RECHAZO');
        }
        if (! $csd->verificar(CadenaOriginal::de($doc), $c->getAttribute('Sello'))) {
            throw new CfdiException('El sello no corresponde a la cadena original (equivale al error 302 del PAC).', 'PAC_RECHAZO');
        }
        $fecha = new \DateTimeImmutable($c->getAttribute('Fecha'), new \DateTimeZone('America/Mexico_City'));
        if (! $csd->vigente($fecha)) {
            throw new CfdiException('El CSD no estaba vigente en la fecha de emisión.', 'PAC_RECHAZO');
        }
        $ahora = new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City'));
        if ($fecha > $ahora->modify('+5 minutes') || $fecha < $ahora->modify('-72 hours')) {
            throw new CfdiException('La fecha de emisión debe estar dentro de las últimas 72 horas (equivale al error 401).', 'PAC_RECHAZO');
        }

        $tfd = [
            'version' => '1.1', 'uuid' => self::uuid4(), 'fecha_timbrado' => $ahora->format('Y-m-d\TH:i:s'),
            'rfc_prov_certif' => self::RFC_PROVEEDOR, 'leyenda' => self::LEYENDA, 'sello_cfd' => $c->getAttribute('Sello'),
            'no_certificado_sat' => '00000000000000000000',
        ];
        $tfd['sello_sat'] = base64_encode(hash('sha256', CadenaOriginal::delTimbre($tfd), true));

        $complemento = null;
        foreach ($c->childNodes as $h) {
            if ($h instanceof \DOMElement && $h->localName === 'Complemento') {
                $complemento = $h;
            }
        }
        if (! $complemento) {
            throw new CfdiException('El comprobante no tiene el nodo Complemento.', 'PAC_RECHAZO');
        }
        $t = $doc->createElementNS(CadenaOriginal::NS_TFD, 'tfd:TimbreFiscalDigital');
        $t->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:schemaLocation',
            'http://www.sat.gob.mx/TimbreFiscalDigital http://www.sat.gob.mx/sitio_internet/cfd/TimbreFiscalDigital/TimbreFiscalDigitalv11.xsd');
        foreach (['Version' => 'version', 'UUID' => 'uuid', 'FechaTimbrado' => 'fecha_timbrado', 'RfcProvCertif' => 'rfc_prov_certif', 'Leyenda' => 'leyenda',
            'SelloCFD' => 'sello_cfd', 'NoCertificadoSAT' => 'no_certificado_sat', 'SelloSAT' => 'sello_sat'] as $atr => $k) {
            $t->setAttribute($atr, $tfd[$k]);
        }
        $complemento->appendChild($t);

        return $doc->saveXML();
    }

    private static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4)));
    }

    public function cancelar(array $p): array
    {
        return ['estado' => 'cancelado', 'mensaje' => 'Timbre simulado anulado (no había nada que cancelar ante el SAT).', 'acuse' => null];
    }
}
