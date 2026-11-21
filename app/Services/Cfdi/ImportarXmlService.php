<?php

namespace App\Services\Cfdi;

use App\Models\AuditLog;
use App\Models\Empresa;
use App\Models\NominaRecibo;
use App\Models\User;
use App\Support\Cfdi\CfdiException;
use App\Support\Cfdi\LectorCfdi;
use Illuminate\Support\Facades\DB;

/**
 * v14 — Importa los XML de nómina que timbró el departamento de nóminas (en su propio sistema) y liga cada
 * folio fiscal (UUID) con el recibo autorizado correspondiente, comparando los importes.
 *
 * Emparejamiento: RFC del receptor (o CURP) + periodo:
 *  - nómina ordinaria (O): mismas FechaInicialPago/FechaFinalPago que el periodo; si no, misma fecha de pago;
 *  - nómina extraordinaria (E): nómina especial de la misma fecha de pago (o del mismo año si solo hay una).
 * Conciliación: Total del CFDI contra neto del recibo, y percepciones / deducciones (tolerancia de 1 centavo).
 *
 * Se trabaja en dos pasos: primero se ANALIZA (no cambia nada) y luego se APLICA. Un XML con diferencias solo
 * se liga si RH lo marca expresamente.
 */
class ImportarXmlService
{
    public const MAX_ARCHIVOS = 2000;

    public const MAX_BYTES = 2_000_000;

    public const ESTADOS = [
        'listo' => 'Listo para ligar', 'ligado' => 'Ligado', 'diferencia' => 'Importes distintos', 'ya_ligado' => 'Ya estaba ligado',
        'conflicto' => 'El recibo tiene otro UUID', 'uuid_en_otro' => 'UUID ligado a otro recibo', 'sin_recibo' => 'Sin recibo autorizado',
        'emisor_distinto' => 'Otro emisor', 'no_es_nomina' => 'No es CFDI de nómina', 'sin_timbre' => 'Sin timbre fiscal',
        'invalido' => 'XML inválido', 'repetido' => 'Repetido en la carga',
    ];

    public function __construct(private readonly TimbradoService $timbrado) {}

    /**
     * Expande ZIP y devuelve [nombre => contenido] solo de los .xml.
     *
     * @param  array<string, string>  $archivos  nombre original => ruta temporal
     */
    public function expandir(array $archivos): array
    {
        $salida = [];
        foreach ($archivos as $nombre => $ruta) {
            if (str_ends_with(strtolower($nombre), '.zip')) {
                $zip = new \ZipArchive;
                if ($zip->open($ruta) !== true) {
                    $salida[$nombre] = null;

                    continue;
                }
                for ($i = 0; $i < $zip->numFiles && count($salida) < self::MAX_ARCHIVOS; $i++) {
                    $st = $zip->statIndex($i);
                    if (! $st || ! str_ends_with(strtolower($st['name']), '.xml') || str_contains($st['name'], '__MACOSX') || $st['size'] > self::MAX_BYTES) {
                        continue;
                    }
                    $salida[$nombre.' › '.basename($st['name'])] = $zip->getFromIndex($i);
                }
                $zip->close();
            } elseif (count($salida) < self::MAX_ARCHIVOS) {
                $salida[$nombre] = filesize($ruta) <= self::MAX_BYTES ? (string) file_get_contents($ruta) : null;
            }
        }

        return $salida;
    }

    /**
     * @param  array<string, ?string>  $xmls  nombre => contenido
     * @param  list<string>  $forzar  UUID con diferencias que RH decidió ligar de todos modos
     */
    public function procesar(Empresa $e, array $xmls, bool $aplicar, array $forzar, User $u): array
    {
        $forzar = array_map('strtoupper', $forzar);
        $vistos = [];
        $filas = [];
        foreach ($xmls as $nombre => $xml) {
            $fila = $this->analizarUno($e, (string) $nombre, $xml, $vistos);
            if ($aplicar && in_array($fila['estado'], ['listo', 'diferencia'], true)
                && ($fila['estado'] === 'listo' || in_array($fila['uuid'], $forzar, true))) {
                $fila = $this->ligar($e, $fila, (string) $xml, $u);
            }
            unset($fila['_recibo']);
            $filas[] = $fila;
        }
        $conteo = array_count_values(array_column($filas, 'estado'));
        if ($aplicar) {
            AuditLog::registrar('IMPORTAR_CFDI', 'NominaRecibo', null, null, ['archivos' => count($filas), 'ligados' => $conteo['ligado'] ?? 0], $u->id);
        }

        return ['aplicado' => $aplicar, 'total' => count($filas), 'conteo' => $conteo, 'estados' => self::ESTADOS, 'filas' => $filas];
    }

    private function analizarUno(Empresa $e, string $nombre, ?string $xml, array &$vistos): array
    {
        $fila = ['archivo' => $nombre, 'estado' => 'invalido', 'mensaje' => null, 'uuid' => null, 'receptor' => null, 'rfc' => null,
            'fecha_pago' => null, 'tipo_nomina' => null, 'total' => null, 'recibo' => null, 'diferencias' => []];
        if ($xml === null || trim($xml) === '') {
            return [...$fila, 'mensaje' => 'Archivo vacío, demasiado grande o ilegible.'];
        }
        try {
            $c = LectorCfdi::leer($xml);
        } catch (CfdiException $ex) {
            return [...$fila, 'mensaje' => $ex->getMessage()];
        }
        $fila = [...$fila, 'uuid' => $c['tfd']['uuid'] ?? null, 'receptor' => $c['receptor']['nombre'], 'rfc' => $c['receptor']['rfc'],
            'fecha_pago' => $c['nomina']['fecha_pago'] ?? null, 'tipo_nomina' => $c['nomina']['tipo'] ?? null, 'total' => $c['total']];

        if ($c['tipo_comprobante'] !== 'N' || ! $c['nomina']) {
            return [...$fila, 'estado' => 'no_es_nomina', 'mensaje' => 'El CFDI no es de nómina (tipo '.$c['tipo_comprobante'].').'];
        }
        if (! $c['tfd'] || ! LectorCfdi::esUuid($c['tfd']['uuid'])) {
            return [...$fila, 'estado' => 'sin_timbre', 'mensaje' => 'El XML no trae Timbre Fiscal Digital: no está timbrado.'];
        }
        $uuid = $c['tfd']['uuid'];
        if (isset($vistos[$uuid])) {
            return [...$fila, 'estado' => 'repetido', 'mensaje' => "El mismo UUID ya viene en «{$vistos[$uuid]}»."];
        }
        $vistos[$uuid] = $nombre;
        if ($e->rfc && strtoupper($e->rfc) !== $c['emisor']['rfc']) {
            return [...$fila, 'estado' => 'emisor_distinto', 'mensaje' => "Lo emitió el RFC {$c['emisor']['rfc']}, no la empresa (".strtoupper($e->rfc).').'];
        }

        $otro = NominaRecibo::where('uuid', $uuid)->first();
        $recibo = $this->buscarRecibo($e, $c);
        if ($otro && (! $recibo || $otro->id !== $recibo->id)) {
            return [...$fila, 'estado' => 'uuid_en_otro', 'recibo' => $this->reciboFila($otro), 'mensaje' => 'Ese UUID ya está ligado al recibo de '.$otro->nombre.'.'];
        }
        if (! $recibo) {
            return [...$fila, 'estado' => 'sin_recibo', 'mensaje' => 'No hay un recibo AUTORIZADO de ese trabajador para la fecha de pago '.$c['nomina']['fecha_pago'].'.'];
        }
        $fila['recibo'] = $this->reciboFila($recibo);
        if ($recibo->uuid === $uuid) {
            return [...$fila, 'estado' => 'ya_ligado', 'mensaje' => 'Ya estaba ligado a este recibo.'];
        }
        if ($recibo->uuid) {
            return [...$fila, 'estado' => 'conflicto', 'mensaje' => "El recibo ya tiene el UUID {$recibo->uuid}. Cancele o desligue ese CFDI antes de ligar otro."];
        }

        $diferencias = [];
        foreach ([['Neto / Total', $recibo->neto, $c['total']],
            ['Percepciones', $recibo->percepciones, $c['nomina']['total_percepciones']],
            ['Deducciones', $recibo->deducciones, $c['nomina']['total_deducciones']]] as [$concepto, $nuestro, $xmlValor]) {
            if (abs(round((float) $nuestro - (float) $xmlValor, 2)) > 0.01) {
                $diferencias[] = ['concepto' => $concepto, 'recibo' => round((float) $nuestro, 2), 'xml' => round((float) $xmlValor, 2),
                    'diferencia' => round((float) $xmlValor - (float) $nuestro, 2)];
            }
        }
        $fila['diferencias'] = $diferencias;
        $fila['_recibo'] = $recibo;

        return $diferencias
            ? [...$fila, 'estado' => 'diferencia', 'mensaje' => 'Los importes no coinciden con el recibo autorizado. Revise antes de ligarlo.']
            : [...$fila, 'estado' => 'listo', 'mensaje' => 'Coincide con el recibo autorizado.'];
    }

    private function buscarRecibo(Empresa $e, array $c): ?NominaRecibo
    {
        $n = $c['nomina'];
        $base = NominaRecibo::query()->where('nomina_recibos.empresa_id', $e->id)->where('nomina_recibos.estado', 'autorizado')
            ->where(fn ($q) => $q->where('nomina_recibos.rfc', $c['receptor']['rfc'])->when($n['curp'], fn ($q2) => $q2->orWhere('nomina_recibos.curp', $n['curp'])))
            ->join('periodos_nomina as p', 'p.id', '=', 'nomina_recibos.periodo_id')->select('nomina_recibos.*');

        if ($n['tipo'] === 'E') {
            $especiales = (clone $base)->where('p.tipo', 'especial');
            $r = (clone $especiales)->whereDate('p.fecha_pago', $n['fecha_pago'])->first();
            if (! $r) {
                $delAnio = (clone $especiales)->where('p.ejercicio', (int) substr((string) $n['fecha_pago'], 0, 4))->get();
                $r = $delAnio->count() === 1 ? $delAnio->first() : null;
            }

            return $r;
        }
        $ordinarias = (clone $base)->where('p.tipo', '!=', 'especial');

        return (clone $ordinarias)->whereDate('p.inicio', $n['fecha_inicial'])->whereDate('p.fin', $n['fecha_final'])->first()
            ?? (clone $ordinarias)->whereDate('p.fecha_pago', $n['fecha_pago'])->first();
    }

    private function ligar(Empresa $e, array $fila, string $xml, User $u): array
    {
        /** @var NominaRecibo $r */
        $r = $fila['_recibo'];
        $c = LectorCfdi::leer($xml);
        DB::transaction(function () use ($e, $r, $c, $xml, $u, $fila) {
            $ruta = $this->timbrado->guardarXml($r, $xml, $c['tfd']['uuid']);
            $r->permitirCambio = true;
            $r->forceFill(['uuid' => $c['tfd']['uuid'], 'timbrado_at' => str_replace('T', ' ', (string) $c['tfd']['fecha_timbrado']), 'cfdi_modo' => 'importado',
                'cfdi_xml_path' => $ruta, 'cfdi_total' => $c['total']])->save();
            $this->timbrado->evento($e, $r, 'importacion', 'XML importado y ligado'.($fila['diferencias'] ? ' con diferencias aceptadas por RH' : ''),
                $c['tfd']['uuid'], ['archivo' => $fila['archivo'], 'diferencias' => $fila['diferencias'], 'rfc_prov_certif' => $c['tfd']['rfc_prov_certif']], $u, $ruta, 'importado');
        });

        return [...$fila, 'estado' => 'ligado', 'mensaje' => $fila['diferencias'] ? 'Ligado con diferencias (aceptadas).' : 'Ligado al recibo.'];
    }

    private function reciboFila(NominaRecibo $r): array
    {
        $r->loadMissing('periodo');

        return ['id' => $r->id, 'nombre' => $r->nombre, 'numero_empleado' => $r->numero_empleado, 'periodo' => $r->periodo?->etiqueta(),
            'periodo_id' => $r->periodo_id, 'neto' => $r->neto, 'uuid' => $r->uuid];
    }
}
