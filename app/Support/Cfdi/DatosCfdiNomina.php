<?php

namespace App\Support\Cfdi;

/**
 * v14 — Traduce un recibo autorizado de la plataforma a los datos del CFDI de nómina (claves del SAT)
 * y revisa, antes de llamar al PAC, los datos que el SAT rechazaría.
 *
 * Reglas aplicadas (Guía de llenado del CFDI de nómina y su complemento 1.2, CFDI 4.0):
 *  - Nómina ordinaria: TipoNomina «O» con la periodicidad del periodo (02 semanal, 04 quincenal, 05 mensual).
 *  - Nómina extraordinaria (aguinaldo): TipoNomina «E», PeriodicidadPago «99» y las fechas inicial y final = fecha de pago.
 *  - Receptor: RegimenFiscalReceptor 605 (sueldos), UsoCFDI CN01, DomicilioFiscalReceptor = CP de la constancia del trabajador.
 *  - Subsidio para el empleo (decreto 2024): se acredita contra el ISR y no se paga en efectivo, por lo que va como
 *    OtroPago 002 con Importe 0.00 y el subsidio del periodo en SubsidioAlEmpleo@SubsidioCausado; la deducción
 *    de ISR (002) es el ISR ya disminuido del subsidio, que es lo que se retuvo.
 *  - ISR a favor del cálculo anual devuelto en nómina: OtroPago 004 con el nodo CompensacionSaldosAFavor.
 *  - Revisión E del complemento: la percepción 038 es totalmente gravada; una percepción no puede tener gravado y exento en cero.
 *
 * Clase pura: recibe arreglos, no modelos.
 */
final class DatosCfdiNomina
{
    /** c_TipoContrato del SAT para los tipos de contrato de la plataforma. */
    public const TIPO_CONTRATO = ['indeterminado' => '01', 'temporal' => '03', 'prueba' => '05', 'capacitacion' => '06'];

    /** c_PeriodicidadPago. */
    public const PERIODICIDAD = ['semanal' => '02', 'catorcenal' => '03', 'quincenal' => '04', 'mensual' => '05'];

    /** c_Estado (ClaveEntFed): entidad donde el trabajador presta el servicio. */
    public const ESTADOS = ['AGU' => 'Aguascalientes', 'BCN' => 'Baja California', 'BCS' => 'Baja California Sur', 'CAM' => 'Campeche',
        'CHP' => 'Chiapas', 'CHH' => 'Chihuahua', 'CMX' => 'Ciudad de México', 'COA' => 'Coahuila', 'COL' => 'Colima', 'DUR' => 'Durango',
        'GUA' => 'Guanajuato', 'GRO' => 'Guerrero', 'HID' => 'Hidalgo', 'JAL' => 'Jalisco', 'MEX' => 'Estado de México', 'MIC' => 'Michoacán',
        'MOR' => 'Morelos', 'NAY' => 'Nayarit', 'NLE' => 'Nuevo León', 'OAX' => 'Oaxaca', 'PUE' => 'Puebla', 'QUE' => 'Querétaro',
        'ROO' => 'Quintana Roo', 'SLP' => 'San Luis Potosí', 'SIN' => 'Sinaloa', 'SON' => 'Sonora', 'TAB' => 'Tabasco', 'TAM' => 'Tamaulipas',
        'TLA' => 'Tlaxcala', 'VER' => 'Veracruz', 'YUC' => 'Yucatán', 'ZAC' => 'Zacatecas'];

    /** c_TipoJornada más usados. */
    public const JORNADAS = ['01' => 'Diurna', '02' => 'Nocturna', '03' => 'Mixta', '04' => 'Por hora', '05' => 'Reducida', '06' => 'Continuada',
        '07' => 'Partida', '08' => 'Por turnos', '99' => 'Otra jornada'];

    /** c_RiesgoPuesto: clase de riesgo de trabajo de la empresa ante el IMSS. */
    public const RIESGOS = ['1' => 'Clase I', '2' => 'Clase II', '3' => 'Clase III', '4' => 'Clase IV', '5' => 'Clase V'];

    /**
     * @return array{datos: array, errores: list<string>}
     */
    public static function desde(array $ctx): array
    {
        $em = $ctx['empresa'];
        $ep = $ctx['empleado'];
        $pe = $ctx['periodo'];
        $re = $ctx['recibo'];
        $det = $re['detalle'] ?? [];
        $errores = [];

        // ── Emisor
        $rfcEmisor = strtoupper(trim((string) ($em['rfc'] ?? '')));
        foreach (['rfc' => 'RFC', 'razon_social' => 'razón social', 'regimen_fiscal' => 'régimen fiscal', 'codigo_postal' => 'código postal (lugar de expedición)',
            'registro_patronal' => 'registro patronal del IMSS', 'clave_ent_fed' => 'entidad federativa donde se presta el servicio'] as $c => $n) {
            if (empty($em[$c])) {
                $errores[] = "Empresa: falta {$n} (Configuración > Timbrado).";
            }
        }
        if (strlen($rfcEmisor) === 13 && empty($em['curp'])) {
            $errores[] = 'Empresa: el patrón es persona física y falta su CURP.';
        }

        // ── Receptor
        $nombre = 'Trabajador '.($ep['numero_empleado'] ?? '');
        foreach (['rfc' => 'RFC', 'curp' => 'CURP', 'nss' => 'NSS', 'codigo_postal' => 'código postal fiscal'] as $c => $n) {
            if (empty($ep[$c])) {
                $errores[] = "{$nombre}: falta {$n}.";
            }
        }
        if (empty($ep['fecha_ingreso'])) {
            $errores[] = "{$nombre}: falta la fecha de ingreso.";
        }
        $tipoContrato = self::TIPO_CONTRATO[$ep['tipo_contrato'] ?? ''] ?? null;
        if (! $tipoContrato) {
            $errores[] = "{$nombre}: tipo de contrato sin equivalente en el catálogo del SAT.";
        }

        // ── Periodo
        $extraordinaria = ($pe['tipo'] ?? '') === 'especial';
        $fechaPago = (string) $pe['fecha_pago'];
        $fInicial = $extraordinaria ? $fechaPago : (string) $pe['inicio'];
        $fFinal = $extraordinaria ? $fechaPago : (string) $pe['fin'];
        $periodicidad = $extraordinaria ? '99' : (self::PERIODICIDAD[$pe['tipo'] ?? ''] ?? null);
        if (! $periodicidad) {
            $errores[] = 'El periodo no tiene una periodicidad reconocida por el SAT.';
        }

        // ── Percepciones
        $percepciones = [];
        foreach ($det['percepciones'] ?? [] as $p) {
            $g = round((float) ($p['gravado'] ?? 0), 2);
            $x = round((float) ($p['exento'] ?? 0), 2);
            if ($g + $x <= 0) {
                continue;
            }
            if (empty($p['clave_sat'])) {
                $errores[] = "La percepción {$p['clave']} ({$p['nombre']}) no tiene clave SAT.";
            }
            if (($p['clave_sat'] ?? '') === '038' && $x > 0) {
                $errores[] = "La percepción {$p['clave']} usa la clave SAT 038, que debe ser totalmente gravada (revisión E del complemento).";
            }
            $percepciones[] = ['tipo' => (string) $p['clave_sat'], 'clave' => $p['clave'], 'concepto' => $p['nombre'], 'gravado' => $g, 'exento' => $x];
        }
        if (! $percepciones) {
            $errores[] = "{$nombre}: el recibo no tiene percepciones.";
        }

        // ── Deducciones (ISR neto del subsidio, IMSS y las demás)
        $deducciones = [];
        foreach ($det['deducciones'] ?? [] as $x) {
            if (round((float) $x['importe'], 2) <= 0) {
                continue;
            }
            if (empty($x['clave_sat'])) {
                $errores[] = "La deducción {$x['clave']} ({$x['nombre']}) no tiene clave SAT.";
            }
            $deducciones[] = ['tipo' => (string) $x['clave_sat'], 'clave' => $x['clave'], 'concepto' => $x['nombre'], 'importe' => round((float) $x['importe'], 2)];
        }

        // ── Otros pagos
        $otros = [];
        if (! $extraordinaria) {
            $otros[] = ['tipo' => '002', 'clave' => 'SUBSIDIO', 'concepto' => 'Subsidio para el empleo', 'importe' => 0.0,
                'subsidio_causado' => round((float) ($re['subsidio_causado'] ?? 0), 2)];
        }
        foreach ($det['otros_pagos'] ?? [] as $o) {
            if (($o['clave'] ?? '') === 'SUBSIDIO' || round((float) $o['importe'], 2) <= 0) {
                continue;
            }
            $op = ['tipo' => (string) $o['clave_sat'], 'clave' => $o['clave'], 'concepto' => $o['nombre'], 'importe' => round((float) $o['importe'], 2)];
            if ($op['tipo'] === '004') {
                $saldo = round((float) ($ctx['ajuste']['saldo'] ?? $op['importe']), 2);
                $op['compensacion'] = ['saldo' => $saldo, 'anio' => (int) ($ctx['ajuste']['anio'] ?? substr($fechaPago, 0, 4)),
                    'remanente' => max(0, round($saldo - $op['importe'], 2))];
            }
            $otros[] = $op;
        }

        $clabe = preg_replace('/\D/', '', (string) ($ep['clabe'] ?? ''));
        $ingreso = (string) ($ep['fecha_ingreso'] ?? $fFinal);

        $datos = [
            'comprobante' => ['Serie' => $em['serie_cfdi'] ?? 'N', 'Folio' => (string) $re['id'], 'Fecha' => $ctx['fecha'], 'LugarExpedicion' => (string) ($em['codigo_postal'] ?? '')],
            'emisor' => ['Rfc' => $rfcEmisor, 'Nombre' => self::nombreFiscal((string) ($em['razon_social'] ?? '')), 'RegimenFiscal' => (string) ($em['regimen_fiscal'] ?? ''),
                'RegistroPatronal' => $em['registro_patronal'] ?? null, 'Curp' => strlen($rfcEmisor) === 13 ? ($em['curp'] ?? null) : null],
            'receptor' => [
                'Rfc' => strtoupper((string) ($ep['rfc'] ?? '')), 'Nombre' => self::nombreFiscal((string) ($ep['nombre'] ?? '')),
                'DomicilioFiscalReceptor' => (string) ($ep['codigo_postal'] ?? ''), 'RegimenFiscalReceptor' => '605',
                'Curp' => strtoupper((string) ($ep['curp'] ?? '')), 'NumSeguridadSocial' => $ep['nss'] ?? null, 'FechaInicioRelLaboral' => $ingreso,
                'Antiguedad' => self::antiguedad($ingreso, $fFinal), 'TipoContrato' => $tipoContrato ?? '99', 'Sindicalizado' => 'No',
                'TipoJornada' => $em['tipo_jornada'] ?? '01', 'TipoRegimen' => '02', 'NumEmpleado' => (string) ($ep['numero_empleado'] ?? ''),
                'Departamento' => self::corto($ep['departamento'] ?? null), 'Puesto' => self::corto($ep['puesto'] ?? null),
                'RiesgoPuesto' => (string) ($em['riesgo_puesto'] ?? '1'), 'PeriodicidadPago' => $periodicidad ?? '99',
                'CuentaBancaria' => strlen($clabe) === 18 ? $clabe : null, 'SalarioBaseCotApor' => (float) ($re['sbc'] ?? 0),
                'SalarioDiarioIntegrado' => (float) ($re['sdi'] ?? 0), 'ClaveEntFed' => (string) ($em['clave_ent_fed'] ?? ''),
            ],
            'nomina' => ['TipoNomina' => $extraordinaria ? 'E' : 'O', 'FechaPago' => $fechaPago, 'FechaInicialPago' => $fInicial, 'FechaFinalPago' => $fFinal,
                'NumDiasPagados' => max(1, (float) ($re['dias_pagados'] ?? 1))],
            'percepciones' => $percepciones, 'deducciones' => $deducciones, 'otros_pagos' => $otros,
        ];

        // El total del CFDI debe ser el neto del recibo autorizado
        $total = round(array_sum(array_map(fn ($p) => $p['gravado'] + $p['exento'], $percepciones)) + array_sum(array_column($otros, 'importe'))
            - array_sum(array_column($deducciones, 'importe')), 2);
        if (isset($re['neto']) && abs($total - (float) $re['neto']) > 0.009) {
            $errores[] = sprintf('%s: el total del CFDI ($%s) no coincide con el neto del recibo ($%s).', $nombre, number_format($total, 2), number_format((float) $re['neto'], 2));
        }

        return ['datos' => $datos, 'errores' => $errores];
    }

    /** Antigüedad en semanas cumplidas, formato ISO 8601 (P{n}W), sin rebasar la fecha final de pago. */
    public static function antiguedad(string $ingreso, string $fechaFinal): string
    {
        $dias = (int) (new \DateTimeImmutable($ingreso))->diff(new \DateTimeImmutable($fechaFinal))->format('%r%a');

        return 'P'.max(0, intdiv($dias, 7)).'W';
    }

    /** Nombre como aparece en la constancia de situación fiscal: mayúsculas, sin espacios dobles. */
    public static function nombreFiscal(string $nombre): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $nombre)), 'UTF-8');
    }

    private static function corto(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : mb_substr($v, 0, 100);
    }
}
