<?php

namespace App\Support\Nomina;

/**
 * v13 — HTML del recibo de nómina para convertirlo a PDF (dompdf) o enviarlo por correo. Lógica pura.
 * Usa solo tablas y estilos en línea (dompdf no entiende flex/grid) y la fuente DejaVu Sans para los acentos.
 * Si el recibo no está timbrado, lo dice: no es un CFDI.
 * v14: si está timbrado agrega los datos del Timbre Fiscal Digital (representación impresa del CFDI) y, si el timbre
 * es simulado o del ambiente de pruebas del PAC, lo advierte con letras grandes: no tiene validez fiscal.
 */
final class ReciboHtml
{
    /** Un documento con uno o varios recibos (uno por página). */
    public static function documento(array $recibos, array $empresa): string
    {
        $paginas = array_map(fn ($r) => self::recibo($r, $empresa), $recibos);

        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Recibos de nómina</title><style>'.self::css().'</style></head><body>'
            .implode('<div class="salto"></div>', $paginas).'</body></html>';
    }

    public static function recibo(array $r, array $empresa): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => '$'.number_format((float) $v, 2);
        $f = fn (?string $d) => $d ? implode('/', array_reverse(explode('-', substr($d, 0, 10)))) : '—';
        $c = $r['calculo'];
        $p = $r['periodo'];
        $especial = ($c['tipo_calculo'] ?? null) === 'aguinaldo';

        $filas = fn (array $lineas) => implode('', array_map(fn ($l) => '<tr><td>'.$e($l['clave_sat'] ?? '').'</td><td>'.$e($l['nombre'])
            .(! empty($l['detalle']) ? '<div class="sub">'.$e($l['detalle']).'</div>' : '').'</td><td class="num">'.$m($l['importe']).'</td></tr>', $lineas))
            ?: '<tr><td colspan="3" class="tenue">—</td></tr>';
        $otros = array_values(array_filter($c['otros_pagos'] ?? [], fn ($o) => (float) $o['importe'] > 0));

        $cfdi = $r['cfdi']['uuid']
            ? self::timbre($r['cfdi'], $e)
            : '<b>Este documento no es un Comprobante Fiscal Digital por Internet (CFDI).</b> Es un comprobante interno del pago.';

        $datosEmpresa = array_filter([$empresa['rfc'] ?? null ? 'RFC '.$e($empresa['rfc']) : null,
            $empresa['registro_patronal'] ?? null ? 'Reg. patronal IMSS '.$e($empresa['registro_patronal']) : null,
            $empresa['regimen_fiscal'] ?? null ? 'Régimen '.$e($empresa['regimen_fiscal']) : null,
            $empresa['codigo_postal'] ?? null ? 'C.P. '.$e($empresa['codigo_postal']) : null]);

        $dias = $especial
            ? number_format((float) ($c['dias_aguinaldo'] ?? 0), 2).' días de aguinaldo'
            : $r['dias_pagados'].' de '.$p['dias'].($r['faltas'] ? " · {$r['faltas']} falta(s)" : '').($r['domingos'] ? " · {$r['domingos']} domingo(s)" : '');

        return '<div class="recibo">'
            .'<table class="cab"><tr><td><div class="empresa">'.$e($empresa['razon_social'] ?: $empresa['nombre']).'</div><div class="sub">'.implode(' · ', $datosEmpresa).'</div></td>'
            .'<td class="der"><div class="titulo">RECIBO DE NÓMINA'.($especial ? ' · AGUINALDO' : '').'</div><div class="sub">'.$e($p['etiqueta']).'</div>'
            .'<div class="sub">Del '.$f($p['inicio']).' al '.$f($p['fin']).' · Pago '.$f($p['fecha_pago']).'</div></td></tr></table>'
            .'<table class="datos"><tr><td class="et">Empleado</td><td><b>'.$e($r['nombre']).'</b></td><td class="et">Núm.</td><td>'.$e($r['numero_empleado']).'</td></tr>'
            .'<tr><td class="et">RFC</td><td>'.$e($r['rfc']).'</td><td class="et">CURP</td><td>'.$e($r['curp']).'</td></tr>'
            .'<tr><td class="et">NSS</td><td>'.$e($r['nss']).'</td><td class="et">Puesto</td><td>'.$e($r['puesto']).'</td></tr>'
            .'<tr><td class="et">Salario diario</td><td>'.$m($r['salario_diario']).($especial ? '' : ' · SDI '.$m($r['sdi'])).'</td><td class="et">Días</td><td>'.$e($dias).'</td></tr></table>'
            .'<table class="cuerpo"><tr><td class="col"><table class="lineas"><tr><th>SAT</th><th>Percepciones</th><th class="num">Importe</th></tr>'.$filas($c['percepciones'])
            .'<tr class="total"><td></td><td>Total percepciones</td><td class="num">'.$m($c['totales']['percepciones']).'</td></tr></table></td>'
            .'<td class="col"><table class="lineas"><tr><th>SAT</th><th>Deducciones</th><th class="num">Importe</th></tr>'.$filas($c['deducciones'])
            .'<tr class="total"><td></td><td>Total deducciones</td><td class="num">'.$m($c['totales']['deducciones']).'</td></tr></table>'
            .($otros ? '<table class="lineas otros"><tr><th>SAT</th><th>Otros pagos</th><th class="num">Importe</th></tr>'.$filas($otros).'</table>' : '')
            .'</td></tr></table>'
            .'<table class="neto"><tr><td>NETO A PAGAR<div class="letra">'.$e(ImporteConLetra::pesos((float) $c['totales']['neto'])).'</div></td><td class="num grande">'.$m($c['totales']['neto']).'</td></tr></table>'
            .'<div class="fiscal">'.$cfdi.'</div>'
            .'<table class="firma"><tr><td>Recibí de '.$e($empresa['razon_social'] ?: $empresa['nombre']).' la cantidad neta indicada, que cubre los conceptos de este recibo por el periodo señalado.</td>'
            .'<td class="linea"><div>&nbsp;</div><div class="raya">'.$e($r['nombre']).'</div></td></tr></table>'
            .'<p class="pie">Folio interno R-'.str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT).($r['estado'] === 'autorizado' ? ' · autorizado' : ' · PRE-NÓMINA (no autorizado)').'</p>'
            .'</div>';
    }

    /** v14: bloque fiscal de un recibo timbrado. */
    private static function timbre(array $cfdi, callable $e): string
    {
        $t = $cfdi['timbre'] ?? null;
        if (! $t) {
            return '<b>Folio fiscal (UUID):</b> '.$e($cfdi['uuid']).' · Timbrado: '.$e($cfdi['timbrado_at']);
        }
        $aviso = match ($t['modo'] ?? null) {
            'simulado' => '<div class="aviso">TIMBRE SIMULADO — SIN VALIDEZ FISCAL. Documento de prueba generado por la plataforma; no existe en el SAT.</div>',
            'pruebas' => '<div class="aviso">TIMBRADO EN EL AMBIENTE DE PRUEBAS DEL PAC — SIN VALIDEZ FISCAL.</div>',
            default => '',
        };
        $qr = '';
        if (empty($t['simulado']) && class_exists(\chillerlan\QRCode\QRCode::class)) {
            try {
                $qr = '<img class="qr" src="'.(new \chillerlan\QRCode\QRCode)->render($t['url_verificacion']).'" alt="QR">';
            } catch (\Throwable) {
                $qr = '';
            }
        }
        $sello = fn (?string $s) => '<div class="sello">'.$e(chunk_split((string) $s, 110, ' ')).'</div>';

        return $aviso
            .'<table class="tfd"><tr>'.($qr ? '<td class="qrcol">'.$qr.'</td>' : '').'<td>'
            .'<b>Este documento es una representación impresa de un CFDI de nómina.</b><br>'
            .'<b>Folio fiscal (UUID):</b> '.$e($t['uuid']).' · <b>Serie y folio:</b> '.$e(trim(($t['serie'] ?? '').'-'.($t['folio'] ?? ''), '-'))
            .' · <b>Fecha de certificación:</b> '.$e(str_replace('T', ' ', (string) $t['fecha_timbrado'])).'<br>'
            .'<b>No. certificado del emisor:</b> '.$e($t['no_certificado_emisor']).' · <b>No. certificado del SAT:</b> '.$e($t['no_certificado_sat'])
            .' · <b>RFC del proveedor de certificación:</b> '.$e($t['rfc_prov_certif']).'<br>'
            .'<b>Uso CFDI:</b> CN01 Nómina · <b>Método de pago:</b> PUE · <b>Régimen del receptor:</b> 605 Sueldos y salarios'
            .'</td></tr></table>'
            .'<div class="et2">Sello digital del CFDI</div>'.$sello($t['sello_cfd'])
            .'<div class="et2">Sello del SAT</div>'.$sello($t['sello_sat'])
            .'<div class="et2">Cadena original del complemento de certificación digital del SAT</div>'.$sello($t['cadena_tfd'])
            .(empty($t['simulado']) ? '<div class="et2">Verificación: '.$e($t['url_verificacion']).'</div>' : '');
    }

    private static function css(): string
    {
        return '@page{margin:14mm 12mm}body{font-family:"DejaVu Sans",Arial,sans-serif;font-size:9pt;color:#1d2228}'
            .'table{width:100%;border-collapse:collapse}td,th{vertical-align:top;padding:3px 5px}.salto{page-break-after:always}'
            .'.cab td{padding:0 0 8px}.cab{border-bottom:2px solid #1d2228;margin-bottom:8px}.empresa{font-size:12pt;font-weight:bold}.der{text-align:right}'
            .'.titulo{font-size:11pt;font-weight:bold}.sub{font-size:7.5pt;color:#5b636e}.tenue{color:#8a929c}'
            .'.datos{margin-bottom:10px}.datos td{border-bottom:1px solid #e3e6ea}.datos .et{color:#5b636e;width:14%}'
            .'.cuerpo .col{width:50%;padding:0 4px}.lineas th{background:#2b3138;color:#fff;text-align:left;font-size:8pt}.lineas td{border-bottom:1px dashed #d5d9de}'
            .'.lineas td:first-child,.lineas th:first-child{width:32px}.num{text-align:right;white-space:nowrap}.total td{font-weight:bold;border-bottom:0;border-top:1px solid #1d2228}'
            .'.otros{margin-top:8px}.neto{margin-top:10px;background:#e8f4ec}.neto td{padding:8px 10px;font-weight:bold}.grande{font-size:14pt;color:#1e6b3a}'
            .'.letra{font-weight:normal;font-size:7.5pt;margin-top:2px}.fiscal{font-size:7.5pt;margin:8px 0;padding:6px;border:1px solid #d5d9de}'
            .'.firma{margin-top:22px}.firma td{font-size:8pt}.firma .linea{width:42%;text-align:center}.raya{border-top:1px solid #1d2228;padding-top:3px;margin-top:28px}'
            .'.pie{font-size:7pt;color:#8a929c;text-align:right;margin-top:10px}'
            // v14: timbre fiscal
            .'.aviso{font-size:10pt;font-weight:bold;color:#9b1c1c;border:2px solid #9b1c1c;padding:5px;text-align:center;margin-bottom:6px}'
            .'.tfd td{padding:0 4px 4px 0;font-size:7pt}.qrcol{width:92px}.qr{width:88px;height:88px}.et2{font-size:6.5pt;font-weight:bold;margin-top:3px}'
            .'.sello{font-size:5.5pt;color:#3b424b;word-wrap:break-word;line-height:1.25}';
    }
}
