<?php

namespace App\Services\Cfdi;

use App\Exceptions\ReglaNegocioException;
use App\Models\AjusteAnualIsr;
use App\Models\AuditLog;
use App\Models\CfdiEvento;
use App\Models\Empresa;
use App\Models\NominaRecibo;
use App\Models\PeriodoNomina;
use App\Models\User;
use App\Support\Cfdi\CadenaOriginal;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\Csd;
use App\Support\Cfdi\DatosCfdiNomina;
use App\Support\Cfdi\LectorCfdi;
use App\Support\Cfdi\NominaXml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * v14 — Timbrado OPCIONAL del CFDI de nómina (RH decide si se usa: Configuración > Timbrado).
 *
 * Flujo de un recibo AUTORIZADO:  datos → validaciones → XML 4.0 + nómina 1.2 → sello con el CSD → PAC → XML timbrado.
 * El UUID se guarda en el recibo (el UUID no forma parte de la huella, así que la integridad del recibo se conserva).
 * El XML timbrado se guarda en el disco privado: storage/app/private/cfdi/{ejercicio}/…
 */
class TimbradoService
{
    public const DISCO = 'local';

    public const MOTIVOS_CANCELACION = [
        '01' => 'Comprobante emitido con errores con relación (se sustituye por otro CFDI)',
        '02' => 'Comprobante emitido con errores sin relación',
        '03' => 'No se llevó a cabo la operación',
    ];

    public function proveedor(Empresa $e): ProveedorTimbrado
    {
        return match ($e->pac) {
            'sw' => new PacSwSapien((string) $e->pac_usuario, (string) $e->pac_password, (string) $e->pac_modo),
            default => new PacSimulado,
        };
    }

    /** Estado de la configuración para la pestaña «Timbrado (CFDI)». */
    public function estado(Empresa $e): array
    {
        $proveedor = $this->proveedor($e);
        $csd = $e->csd_no_certificado ? [
            'no_certificado' => $e->csd_no_certificado, 'rfc' => $e->csd_rfc,
            'vigente_desde' => $e->csd_vigente_desde?->format('Y-m-d'), 'vigente_hasta' => $e->csd_vigente_hasta?->format('Y-m-d'),
            'vigente' => $e->csd_vigente_hasta?->isFuture() && $e->csd_vigente_desde?->isPast(),
            'dias_restantes' => $e->csd_vigente_hasta ? (int) now()->diffInDays($e->csd_vigente_hasta, false) : null,
        ] : null;

        $revision = [
            ['clave' => 'emisor', 'ok' => $e->rfc && $e->razon_social && $e->regimen_fiscal && $e->codigo_postal, 'texto' => 'RFC, razón social, régimen fiscal y código postal de la empresa'],
            ['clave' => 'imss', 'ok' => (bool) $e->registro_patronal, 'texto' => 'Registro patronal del IMSS'],
            ['clave' => 'complemento', 'ok' => (bool) $e->clave_ent_fed, 'texto' => 'Entidad federativa, clase de riesgo y tipo de jornada'],
            ['clave' => 'csd', 'ok' => (bool) ($csd['vigente'] ?? false), 'texto' => 'Certificado de sello digital (CSD) vigente'],
            ['clave' => 'csd_rfc', 'ok' => $csd && $e->rfc && strtoupper($e->rfc) === $csd['rfc'], 'texto' => 'El CSD pertenece al RFC de la empresa'],
            ['clave' => 'pac', 'ok' => $e->pac !== 'sw' || ($e->pac_usuario && $e->pac_password), 'texto' => 'Usuario y contraseña del PAC'],
        ];
        $revision = array_map(fn ($r) => [...$r, 'ok' => (bool) $r['ok']], $revision);

        return [
            'timbrado_activo' => (bool) $e->timbrado_activo,
            'empresa' => $e->only(['rfc', 'razon_social', 'regimen_fiscal', 'codigo_postal', 'registro_patronal', 'curp', 'clave_ent_fed', 'riesgo_puesto',
                'tipo_jornada', 'serie_cfdi']),
            'pac' => ['proveedor' => $e->pac ?: 'simulado', 'modo' => $e->pac_modo ?: 'pruebas', 'usuario' => $e->pac_usuario,
                'tiene_password' => (bool) $e->pac_password, 'nombre' => $proveedor->nombre(), 'modo_timbre' => $proveedor->modo()],
            'csd' => $csd,
            'revision' => $revision,
            'listo' => collect($revision)->every(fn ($r) => $r['ok']),
            'catalogos' => ['estados' => DatosCfdiNomina::ESTADOS, 'jornadas' => DatosCfdiNomina::JORNADAS, 'riesgos' => DatosCfdiNomina::RIESGOS,
                'motivos_cancelacion' => self::MOTIVOS_CANCELACION],
            'resumen' => [
                'timbrados' => NominaRecibo::where('empresa_id', $e->id)->whereNotNull('uuid')->selectRaw('cfdi_modo, COUNT(*) as n')->groupBy('cfdi_modo')->pluck('n', 'cfdi_modo'),
                'pendientes' => NominaRecibo::where('empresa_id', $e->id)->where('estado', 'autorizado')->whereNull('uuid')->count(),
            ],
        ];
    }

    public function guardarConfiguracion(Empresa $e, array $d, User $u): void
    {
        $antes = $e->only(['pac', 'pac_modo', 'pac_usuario', 'timbrado_activo', 'clave_ent_fed', 'riesgo_puesto', 'tipo_jornada', 'serie_cfdi', 'curp']);
        if (array_key_exists('pac_password', $d) && ($d['pac_password'] === null || $d['pac_password'] === '')) {
            unset($d['pac_password']);   // vacío = conservar la guardada
        }
        if (($d['pac_modo'] ?? $e->pac_modo) === 'produccion' && ($d['pac'] ?? $e->pac) !== 'sw') {
            $d['pac_modo'] = 'pruebas';
        }
        $e->fill($d);
        if ($e->timbrado_activo) {
            $faltan = collect($this->estado($e)['revision'])->reject(fn ($r) => $r['ok'])->pluck('texto')->all();
            if ($faltan) {
                throw new ReglaNegocioException('Para activar el timbrado falta: '.implode('; ', $faltan).'.', 422, ['codigo' => 'TIMBRADO_INCOMPLETO', 'faltan' => $faltan]);
            }
        }
        $e->save();
        if ($e->wasChanged('pac_usuario') || $e->wasChanged('pac_password') || $e->wasChanged('pac_modo')) {
            cache()->forget('cfdi:sw:token:pruebas:'.sha1((string) $e->pac_usuario));
            cache()->forget('cfdi:sw:token:produccion:'.sha1((string) $e->pac_usuario));
        }
        AuditLog::registrar('CONFIGURAR_TIMBRADO', 'Empresa', $e->id, $antes, $e->only(array_keys($antes)), $u->id);
    }

    /** Carga y valida el CSD: par .cer/.key, contraseña, vigencia, que no sea la e.firma y que el RFC sea el de la empresa. */
    public function cargarCsd(Empresa $e, UploadedFile $cer, UploadedFile $key, string $password, User $u): array
    {
        try {
            $csd = Csd::desdeArchivos((string) $cer->get(), (string) $key->get(), $password);
        } catch (CfdiException $ex) {
            throw new ReglaNegocioException($ex->getMessage(), 422, ['codigo' => $ex->codigo]);
        }
        if ($csd->esEfirma()) {
            throw new ReglaNegocioException('Ese certificado es la e.firma (FIEL). Para timbrar se usa el Certificado de Sello Digital (CSD).', 422, ['codigo' => 'ES_EFIRMA']);
        }
        if (! $csd->vigente()) {
            throw new ReglaNegocioException('El CSD no está vigente (vigencia: '.$csd->vigenteDesde()->format('d/m/Y').' al '.$csd->vigenteHasta()->format('d/m/Y').').', 422, ['codigo' => 'CSD_VENCIDO']);
        }
        if ($e->rfc && strtoupper($e->rfc) !== $csd->rfc()) {
            throw new ReglaNegocioException("El CSD pertenece al RFC {$csd->rfc()} y la empresa tiene el RFC ".strtoupper($e->rfc).'.', 422, ['codigo' => 'CSD_OTRO_RFC']);
        }

        $base = 'cfdi/csd/'.$csd->rfc().'-'.$csd->noCertificado();
        $disco = Storage::disk(self::DISCO);
        $disco->put("{$base}.cer", (string) $cer->get());
        $disco->put("{$base}.key", (string) $key->get());
        $anterior = $e->csd_no_certificado;
        $e->forceFill([
            'csd_cer_path' => "{$base}.cer", 'csd_key_path' => "{$base}.key", 'csd_password' => $password,
            'csd_no_certificado' => $csd->noCertificado(), 'csd_rfc' => $csd->rfc(),
            'csd_vigente_desde' => $csd->vigenteDesde()->format('Y-m-d H:i:s'), 'csd_vigente_hasta' => $csd->vigenteHasta()->format('Y-m-d H:i:s'),
        ])->save();
        $this->evento($e, null, 'csd', 'CSD cargado: certificado '.$csd->noCertificado().' de '.$csd->rfc(), null, ['anterior' => $anterior, ...$csd->resumen()], $u);
        AuditLog::registrar('CARGAR_CSD', 'Empresa', $e->id, ['no_certificado' => $anterior], ['no_certificado' => $csd->noCertificado()], $u->id);

        return $csd->resumen();
    }

    public function probarConexion(Empresa $e, User $u): array
    {
        $r = $this->proveedor($e)->probar();
        $this->evento($e, null, 'prueba', $r['mensaje'], null, ['ok' => $r['ok']], $u);

        return $r;
    }

    /** Errores que impedirían timbrar el recibo (vacío = listo). */
    public function validar(NominaRecibo $r): array
    {
        $e = Empresa::findOrFail($r->empresa_id);
        $errores = [];
        if (! $e->timbrado_activo) {
            $errores[] = 'El timbrado está desactivado (Configuración > Timbrado).';
        }
        if ($r->estado !== 'autorizado') {
            $errores[] = 'Solo se timbran recibos de periodos autorizados.';
        }
        if ($r->uuid) {
            $errores[] = "El recibo ya está timbrado (UUID {$r->uuid}).";
        }
        try {
            $this->csd($e);
        } catch (CfdiException $ex) {
            $errores[] = $ex->getMessage();
        }

        return [...$errores, ...DatosCfdiNomina::desde($this->contexto($e, $r))['errores']];
    }

    /** Arma, sella y timbra un recibo autorizado. Devuelve el resultado con el UUID. */
    public function timbrarRecibo(NominaRecibo $r, User $u): array
    {
        $e = Empresa::findOrFail($r->empresa_id);
        $errores = $this->validar($r);
        if ($errores) {
            throw new ReglaNegocioException('No se puede timbrar: '.$errores[0], 422, ['codigo' => 'NO_TIMBRABLE', 'errores' => $errores]);
        }
        $proveedor = $this->proveedor($e);
        $xmlSellado = null;
        try {
            $datos = DatosCfdiNomina::desde($this->contexto($e, $r))['datos'];
            $xmlSellado = NominaXml::sellar(NominaXml::armar($datos), $this->csd($e));
            $timbrado = $proveedor->timbrar($xmlSellado);
            $cfdi = LectorCfdi::leer($timbrado);
            if (! $cfdi['tfd'] || ! LectorCfdi::esUuid($cfdi['tfd']['uuid'])) {
                throw new CfdiException('El PAC respondió sin Timbre Fiscal Digital.', 'PAC_SIN_TIMBRE');
            }
        } catch (CfdiException $ex) {
            $this->evento($e, $r, 'error', mb_substr($ex->getMessage(), 0, 500), null, ['codigo' => $ex->codigo, 'proveedor' => $proveedor->nombre()], $u,
                $xmlSellado ? $this->guardarXml($r, $xmlSellado, 'rechazado-'.now()->format('YmdHis')) : null);
            throw new ReglaNegocioException($ex->getMessage(), 422, ['codigo' => $ex->codigo]);
        }

        $uuid = $cfdi['tfd']['uuid'];
        $ruta = $this->guardarXml($r, $timbrado, $uuid);
        $r->permitirCambio = true;
        $r->forceFill(['uuid' => $uuid, 'timbrado_at' => str_replace('T', ' ', $cfdi['tfd']['fecha_timbrado']), 'cfdi_modo' => $proveedor->modo(),
            'cfdi_xml_path' => $ruta, 'cfdi_total' => $cfdi['total']])->save();
        $this->evento($e, $r, 'timbrado', "Timbrado con {$proveedor->nombre()}", $uuid, ['total' => $cfdi['total']], $u, $ruta, $proveedor->modo());
        AuditLog::registrar('TIMBRAR', 'NominaRecibo', $r->id, null, ['uuid' => $uuid, 'modo' => $proveedor->modo()], $u->id);

        return ['recibo_id' => $r->id, 'uuid' => $uuid, 'modo' => $proveedor->modo(), 'total' => $cfdi['total']];
    }

    /** Timbra los recibos autorizados y sin timbrar del periodo. Un error no detiene a los demás. */
    public function timbrarPeriodo(PeriodoNomina $p, User $u): array
    {
        if (! $p->autorizado()) {
            throw new ReglaNegocioException('Primero autorice el periodo: solo se timbran nóminas autorizadas.', 409, ['codigo' => 'NO_AUTORIZADO']);
        }
        @set_time_limit(600);
        $resultados = [];
        foreach (NominaRecibo::where('periodo_id', $p->id)->whereNull('uuid')->orderBy('nombre')->get() as $r) {
            try {
                $resultados[] = ['ok' => true, 'nombre' => $r->nombre, ...$this->timbrarRecibo($r, $u)];
            } catch (ReglaNegocioException $ex) {
                $extra = $ex->render()->getData(true);
                $resultados[] = ['ok' => false, 'recibo_id' => $r->id, 'nombre' => $r->nombre, 'error' => $ex->getMessage(), 'errores' => $extra['errores'] ?? [$ex->getMessage()]];
            }
        }

        return ['timbrados' => count(array_filter($resultados, fn ($x) => $x['ok'])), 'errores' => count(array_filter($resultados, fn ($x) => ! $x['ok'])),
            'resultados' => $resultados];
    }

    /**
     * Cancela el CFDI de un recibo. Simulado: se anula localmente. Pruebas/producción: se pide al PAC con el CSD.
     * Importado (lo timbró el departamento de nóminas en otro sistema): solo se desliga; la cancelación se hace en ese sistema.
     */
    public function cancelar(NominaRecibo $r, string $motivo, ?string $folioSustitucion, User $u): array
    {
        if (! $r->uuid) {
            throw new ReglaNegocioException('El recibo no está timbrado.', 409, ['codigo' => 'SIN_TIMBRE']);
        }
        if (! isset(self::MOTIVOS_CANCELACION[$motivo])) {
            throw new ReglaNegocioException('Motivo de cancelación no válido.', 422, ['codigo' => 'MOTIVO']);
        }
        if ($motivo === '01' && ! LectorCfdi::esUuid((string) $folioSustitucion)) {
            throw new ReglaNegocioException('El motivo 01 requiere el UUID del CFDI que lo sustituye.', 422, ['codigo' => 'FOLIO_SUSTITUCION']);
        }
        $e = Empresa::findOrFail($r->empresa_id);
        $uuid = $r->uuid;
        $modo = $r->cfdi_modo;

        if ($modo === 'importado') {
            $resultado = ['estado' => 'desligado', 'mensaje' => 'El XML se desligó del recibo. La cancelación ante el SAT se hace en el sistema donde se timbró.', 'acuse' => null];
        } elseif ($modo === 'simulado') {
            $resultado = (new PacSimulado)->cancelar([]);
        } else {
            $proveedor = $this->proveedor($e);
            if ($proveedor->modo() !== $modo) {
                throw new ReglaNegocioException("El recibo se timbró en modo «{$modo}» y el PAC configurado está en «{$proveedor->modo()}». Cambie la configuración para cancelarlo.", 409, ['codigo' => 'MODO_DISTINTO']);
            }
            $csd = $this->csd($e);
            $disco = Storage::disk(self::DISCO);
            try {
                $resultado = $proveedor->cancelar(['rfc' => $csd->rfc(), 'uuid' => $uuid, 'motivo' => $motivo, 'folio_sustitucion' => $folioSustitucion,
                    'cer_b64' => base64_encode($disco->get($e->csd_cer_path)), 'key_b64' => base64_encode($disco->get($e->csd_key_path)), 'password' => (string) $e->csd_password]);
            } catch (CfdiException $ex) {
                $this->evento($e, $r, 'error', mb_substr($ex->getMessage(), 0, 500), $uuid, ['codigo' => $ex->codigo, 'operacion' => 'cancelacion'], $u);
                throw new ReglaNegocioException($ex->getMessage(), 422, ['codigo' => $ex->codigo]);
            }
        }

        $acuse = $resultado['acuse'] ? $this->guardarXml($r, $resultado['acuse'], "{$uuid}-acuse-cancelacion") : null;
        $r->permitirCambio = true;
        $r->forceFill(['uuid' => null, 'timbrado_at' => null, 'cfdi_modo' => null, 'cfdi_xml_path' => null, 'cfdi_total' => null])->save();
        $this->evento($e, $r, 'cancelacion', $resultado['mensaje'], $uuid, ['motivo' => $motivo, 'folio_sustitucion' => $folioSustitucion,
            'estado' => $resultado['estado'], 'modo' => $modo], $u, $acuse, $modo);
        AuditLog::registrar('CANCELAR_CFDI', 'NominaRecibo', $r->id, ['uuid' => $uuid], ['motivo' => $motivo, 'estado' => $resultado['estado']], $u->id);

        return ['uuid' => $uuid, ...$resultado];
    }

    /** Contenido del XML timbrado del recibo. */
    public function xml(NominaRecibo $r): ?string
    {
        return $r->cfdi_xml_path && Storage::disk(self::DISCO)->exists($r->cfdi_xml_path) ? Storage::disk(self::DISCO)->get($r->cfdi_xml_path) : null;
    }

    /** Datos del timbre para el PDF y la vista del recibo. */
    public function datosTimbre(NominaRecibo $r): ?array
    {
        $xml = $this->xml($r);
        if (! $xml) {
            return null;
        }
        try {
            $cfdi = LectorCfdi::leer($xml);
        } catch (CfdiException) {
            return null;
        }
        if (! $cfdi['tfd']) {
            return null;
        }

        return ['uuid' => $cfdi['tfd']['uuid'], 'fecha_timbrado' => $cfdi['tfd']['fecha_timbrado'], 'rfc_prov_certif' => $cfdi['tfd']['rfc_prov_certif'],
            'no_certificado_emisor' => $cfdi['no_certificado'], 'no_certificado_sat' => $cfdi['tfd']['no_certificado_sat'],
            'sello_cfd' => $cfdi['tfd']['sello_cfd'], 'sello_sat' => $cfdi['tfd']['sello_sat'], 'cadena_tfd' => CadenaOriginal::delTimbre($cfdi['tfd']),
            'leyenda' => $cfdi['tfd']['leyenda'], 'simulado' => $r->cfdi_modo === 'simulado', 'pruebas' => $r->cfdi_modo === 'pruebas',
            'url_verificacion' => LectorCfdi::urlVerificacion($cfdi), 'serie' => $cfdi['serie'], 'folio' => $cfdi['folio'], 'total' => $cfdi['total'],
            'emisor' => $cfdi['emisor'], 'receptor' => $cfdi['receptor'], 'modo' => $r->cfdi_modo];
    }

    public function csd(Empresa $e): Csd
    {
        $disco = Storage::disk(self::DISCO);
        if (! $e->csd_cer_path || ! $disco->exists($e->csd_cer_path) || ! $disco->exists((string) $e->csd_key_path)) {
            throw new CfdiException('Falta cargar el certificado de sello digital (CSD) de la empresa (Configuración > Timbrado).', 'SIN_CSD');
        }
        $csd = Csd::desdeArchivos($disco->get($e->csd_cer_path), $disco->get($e->csd_key_path), (string) $e->csd_password);
        if (! $csd->vigente()) {
            throw new CfdiException('El CSD de la empresa venció el '.$csd->vigenteHasta()->format('d/m/Y').'. Tramite uno nuevo en el SAT y cárguelo.', 'CSD_VENCIDO');
        }

        return $csd;
    }

    /** Los datos que DatosCfdiNomina necesita, a partir de la fotografía del recibo y el expediente del trabajador. */
    public function contexto(Empresa $e, NominaRecibo $r): array
    {
        $r->loadMissing(['periodo', 'empleado']);
        $emp = $r->empleado;
        $ajuste = AjusteAnualIsr::where('empleado_id', $r->empleado_id)->where('periodo_aplicacion_id', $r->periodo_id)->first();

        return [
            'fecha' => now('America/Mexico_City')->subMinute()->format('Y-m-d\TH:i:s'),
            'empresa' => $e->only(['rfc', 'razon_social', 'regimen_fiscal', 'codigo_postal', 'registro_patronal', 'curp', 'clave_ent_fed', 'riesgo_puesto', 'tipo_jornada', 'serie_cfdi']),
            'empleado' => ['rfc' => $r->rfc, 'curp' => $r->curp, 'nss' => $r->nss, 'nombre' => $r->nombre, 'numero_empleado' => $r->numero_empleado, 'puesto' => $r->puesto,
                'codigo_postal' => $emp?->codigo_postal, 'fecha_ingreso' => $emp?->fecha_ingreso?->format('Y-m-d'), 'tipo_contrato' => $emp?->tipo_contrato,
                'departamento' => $emp?->departamento, 'clabe' => $emp?->clabe],
            'periodo' => ['tipo' => $r->periodo->tipo, 'clase' => $r->periodo->clase, 'inicio' => $r->periodo->inicio->format('Y-m-d'),
                'fin' => $r->periodo->fin->format('Y-m-d'), 'fecha_pago' => $r->periodo->fecha_pago->format('Y-m-d')],
            'recibo' => ['id' => $r->id, 'dias_pagados' => $r->dias_pagados, 'sbc' => $r->sbc, 'sdi' => $r->sdi, 'subsidio_causado' => $r->subsidio_causado,
                'neto' => $r->neto, 'detalle' => $r->detalle],
            'ajuste' => $ajuste ? ['saldo' => abs((float) $ajuste->diferencia), 'anio' => $ajuste->ejercicio] : null,
        ];
    }

    public function guardarXml(NominaRecibo $r, string $xml, string $nombre): string
    {
        $r->loadMissing('periodo');
        $ruta = sprintf('cfdi/%d/%s/%s.xml', $r->ejercicio, $r->periodo?->especial() ? 'especial-'.$r->periodo_id : 'periodo-'.$r->periodo_id, $nombre);
        Storage::disk(self::DISCO)->put($ruta, $xml);

        return $ruta;
    }

    public function evento(Empresa $e, ?NominaRecibo $r, string $tipo, string $mensaje, ?string $uuid = null, ?array $datos = null, ?User $u = null,
        ?string $xml = null, ?string $modo = null): CfdiEvento
    {
        return CfdiEvento::create(['empresa_id' => $e->id, 'recibo_id' => $r?->id, 'tipo' => $tipo, 'uuid' => $uuid, 'modo' => $modo,
            'mensaje' => mb_substr($mensaje, 0, 500), 'datos' => $datos, 'xml_path' => $xml, 'user_id' => $u?->id]);
    }
}
