<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Models\AuditLog;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\NominaAcumulado;
use App\Models\NominaIncidencia;
use App\Models\NominaRecibo;
use App\Models\NominaReciboLinea;
use App\Models\PeriodoNomina;
use App\Models\User;
use App\Support\Nomina\CalculadoraNomina;
use App\Support\Nomina\DiasLaborados;
use App\Support\Nomina\Huella;
use App\Support\Nomina\PagoExtraordinario;
use App\Models\Vacacion;
use App\Models\NominaAcumulado as Acumulado;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * v12 — Nómina del periodo, al estilo CONTPAQi Nóminas:
 *
 *   1. Capturar incidencias (faltas, retardos, domingos, montos)          periodo "abierto"
 *   2. Calcular la pre-nómina (recibos en borrador, se pueden revisar)    periodo "calculado"
 *   3. Autorizar: se congela la fotografía de cada recibo, se guarda su   periodo "autorizado" (solo lectura)
 *      huella y se actualizan los acumulados anuales.
 *
 * Cambiar una incidencia después de calcular regresa el periodo a "abierto": hay que volver a calcular.
 * Solo el ÚLTIMO periodo autorizado y SIN timbrar se puede reabrir, con motivo, y queda en la bitácora.
 */
class NominaService
{
    public function __construct(private readonly ConfiguracionFiscal $config) {}

    /** Empleados que entran en la nómina del periodo: su periodicidad, con RFC, contratados al menos un día del periodo. */
    public function empleados(PeriodoNomina $p): Collection
    {
        if ($p->especial()) {
            // v13: aguinaldo → todos los empleados con RFC que siguen contratados el día de pago (sin importar su periodicidad).
            // A quien se fue antes, el aguinaldo proporcional se le paga en su finiquito.
            return Empleado::with('puesto')->where('empresa_id', $p->empresa_id)->whereNotNull('rfc')
                ->whereDate('fecha_ingreso', '<=', $p->fecha_pago)
                ->where(fn ($q) => $q->whereNull('fecha_baja')->orWhereDate('fecha_baja', '>=', $p->fecha_pago))
                ->orderBy('apellido_paterno')->orderBy('apellido_materno')->orderBy('nombres')->get();
        }

        return Empleado::with('puesto')
            ->where('empresa_id', $p->empresa_id)->where('periodicidad', $p->tipo)->whereNotNull('rfc')
            ->whereDate('fecha_ingreso', '<=', $p->fin)
            ->where(fn ($q) => $q->whereNull('fecha_baja')->orWhereDate('fecha_baja', '>=', $p->inicio))
            ->orderBy('apellido_paterno')->orderBy('apellido_materno')->orderBy('nombres')->get();
    }

    /** Lo que muestra la pantalla de captura: una fila por empleado con su incidencia y, si ya se calculó, su recibo. */
    public function captura(PeriodoNomina $p): array
    {
        $incidencias = NominaIncidencia::where('periodo_id', $p->id)->get()->keyBy('empleado_id');
        $recibos = NominaRecibo::where('periodo_id', $p->id)->get()->keyBy('empleado_id');
        $empleados = $p->autorizado()
            ? Empleado::with('puesto')->whereIn('id', $recibos->keys())->orderBy('apellido_paterno')->orderBy('nombres')->get()
            : $this->empleados($p);
        $empresa = Empresa::findOrFail($p->empresa_id);
        $vacaciones = $p->especial() ? collect() : $this->vacacionesDelPeriodo($p);

        $filas = $empleados->map(function (Empleado $e) use ($p, $incidencias, $recibos, $vacaciones) {
            $i = $incidencias->get($e->id);
            $r = $recibos->get($e->id);

            return [
                'empleado_id' => $e->id, 'numero_empleado' => $e->numero_empleado, 'nombre' => $e->nombre_completo, 'puesto' => $e->puesto?->nombre,
                'dias_fuera' => $p->especial() ? 0 : DiasLaborados::fuera($p->inicio->format('Y-m-d'), $p->fin->format('Y-m-d'), $p->dias,
                    $e->fecha_ingreso->format('Y-m-d'), $e->fecha_baja?->format('Y-m-d')),
                'vacaciones' => (int) $vacaciones->get($e->id, 0),
                'faltas' => $i?->faltas ?? 0, 'retardos' => $i?->retardos ?? 0, 'domingos' => $i?->domingos ?? 0,
                'capturados' => (object) ($i?->capturados ?? []), 'notas' => $i?->notas,
                'recibo' => $r ? $this->resumen($r) : null,
            ];
        })->values();

        $recibosValidos = $recibos->filter(fn ($r) => $filas->contains('empleado_id', $r->empleado_id));
        // Columnas capturables; las del ajuste anual solo aparecen cuando alguien tiene un importe
        $usadas = $filas->flatMap(fn ($f) => array_keys((array) $f['capturados']))->unique()->all();
        $columnas = $p->especial() ? collect() : collect($this->config->conceptos($empresa))->where('metodo', 'captura')->where('activo', true)
            ->filter(fn ($c) => empty($c['regla']['solo_ajuste']) || in_array($c['clave'], $usadas, true));

        return [
            'periodo' => $this->periodoJson($p),
            'timbrado_activo' => (bool) $empresa->timbrado_activo,
            'columnas' => $columnas->map(fn ($c) => ['clave' => $c['clave'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo'],
                'solo_ajuste' => ! empty($c['regla']['solo_ajuste'])])->values(),
            'filas' => $filas,
            'totales' => $p->estado === 'abierto' && ! $p->calculado_at ? null : [
                'empleados' => $recibosValidos->count(),
                'percepciones' => round($recibosValidos->sum('percepciones'), 2), 'deducciones' => round($recibosValidos->sum('deducciones'), 2),
                'isr' => round($recibosValidos->sum('isr_retenido'), 2), 'imss' => round($recibosValidos->sum('imss'), 2), 'neto' => round($recibosValidos->sum('neto'), 2),
            ],
            'pendiente_recalcular' => $p->estado === 'abierto' && $p->calculado_at !== null,
        ];
    }

    /** Guarda las incidencias capturadas (una o varias filas). */
    public function guardarIncidencias(PeriodoNomina $p, array $filas, User $u): void
    {
        $this->exigirEditable($p);
        if ($p->especial()) {
            throw new ReglaNegocioException('La nómina de aguinaldo no lleva incidencias: se calcula con los días trabajados en el año.', 422, ['codigo' => 'SIN_INCIDENCIAS']);
        }
        $permitidos = $this->empleados($p)->pluck('id')->all();
        $capturables = collect($this->config->conceptos(Empresa::findOrFail($p->empresa_id)))->where('metodo', 'captura')->pluck('clave')->all();

        DB::transaction(function () use ($p, $filas, $u, $permitidos, $capturables) {
            foreach ($filas as $f) {
                if (! in_array((int) $f['empleado_id'], $permitidos, true)) {
                    throw new ReglaNegocioException('Uno de los empleados no pertenece a la nómina de este periodo.', 422, ['codigo' => 'EMPLEADO_FUERA']);
                }
                $capturados = array_filter(array_intersect_key((array) ($f['capturados'] ?? []), array_flip($capturables)), fn ($v) => (float) $v > 0);
                NominaIncidencia::updateOrCreate(['periodo_id' => $p->id, 'empleado_id' => (int) $f['empleado_id']], [
                    'faltas' => min($p->dias, (int) ($f['faltas'] ?? 0)), 'retardos' => (int) ($f['retardos'] ?? 0),
                    'domingos' => (int) ($f['domingos'] ?? 0), 'capturados' => array_map(fn ($v) => round((float) $v, 2), $capturados) ?: null,
                    'notas' => $f['notas'] ?? null, 'capturado_por' => $u->id,
                ]);
            }
            if ($p->estado === 'calculado') {
                $p->update(['estado' => 'abierto']);   // la pre-nómina ya no corresponde a lo capturado
            }
        });
    }

    /** Calcula la pre-nómina: un recibo en borrador por empleado. Se puede repetir las veces que haga falta. */
    public function calcular(PeriodoNomina $p, User $u): array
    {
        $this->exigirEditable($p);
        $empresa = Empresa::findOrFail($p->empresa_id);
        $inicio = $p->inicio->format('Y-m-d');
        $fin = $p->fin->format('Y-m-d');
        $conceptos = $this->config->conceptos($empresa);
        $parametros = $this->config->parametros($fin, $p->tipo, $empresa);
        $incidencias = NominaIncidencia::where('periodo_id', $p->id)->get()->keyBy('empleado_id');
        $empleados = $this->empleados($p);
        $avisos = [];
        $vacaciones = $p->especial() ? collect() : $this->vacacionesDelPeriodo($p);
        $porClave = collect($conceptos)->keyBy('clave');
        // Exención anual ya usada (aguinaldo 30 UMA, prima vacacional 15 UMA) según los acumulados autorizados del año
        $exentos = Acumulado::whereIn('empleado_id', $empleados->pluck('id'))->where('ejercicio', $p->ejercicio)->whereIn('clave', ['P006', 'P007'])
            ->get()->groupBy('empleado_id')->map(fn ($g) => $g->pluck('exento', 'clave')->all());

        DB::transaction(function () use ($p, $empresa, $inicio, $fin, $conceptos, $parametros, $incidencias, $empleados, &$avisos, $u, $vacaciones, $porClave, $exentos) {
            // Quien ya no entra en este periodo (p. ej. se corrigió su fecha de baja) pierde su borrador
            NominaRecibo::where('periodo_id', $p->id)->whereNotIn('empleado_id', $empleados->pluck('id'))->get()->each->delete();

            foreach ($empleados as $e) {
                $salario = $e->salarioEn($p->especial() ? $p->fecha_pago->format('Y-m-d') : $fin);
                if (! $salario) {
                    $avisos[] = "{$e->nombre_completo}: no tiene salario capturado; no se calculó.";

                    continue;
                }
                $i = $incidencias->get($e->id);
                if ($p->especial()) {
                    $calculo = PagoExtraordinario::aguinaldo([
                        'salario_mensual' => $salario, 'divisor' => (float) $empresa->divisor_salario_diario, 'fecha_ingreso' => $e->fecha_ingreso->format('Y-m-d'),
                        'ejercicio' => $p->ejercicio, 'dias_aguinaldo' => (int) $empresa->dias_aguinaldo, 'metodo_isr' => $empresa->metodo_isr_extraordinario ?? 'art174',
                        'exento_acumulado' => (float) ($exentos[$e->id]['P007'] ?? 0),
                        'concepto' => $porClave['P007'] ?? throw new ReglaNegocioException('Falta el concepto P007 Aguinaldo: ejecuta php artisan migrate.', 422),
                        'deduccion_isr' => $porClave->firstWhere('metodo', 'isr'), 'parametros' => $parametros,
                    ]);
                    foreach ($calculo['avisos'] as $a) {
                        $avisos[] = "{$e->nombre_completo}: {$a}";
                    }
                    $this->guardarBorrador($p, $e, $salario, null, $calculo);

                    continue;
                }
                $calculo = CalculadoraNomina::calcular([
                    'salario_mensual' => $salario, 'divisor' => (float) $empresa->divisor_salario_diario, 'tipo_periodo' => $p->tipo,
                    'dias_periodo' => $p->dias, 'fecha_ingreso' => $e->fecha_ingreso->format('Y-m-d'), 'inicio' => $inicio, 'fin' => $fin,
                    'es_fin_de_mes' => $p->fin_de_mes, 'faltas' => $i?->faltas ?? 0, 'retardos' => $i?->retardos ?? 0, 'domingos' => $i?->domingos ?? 0,
                    'capturados' => $i?->capturados ?? [],
                    'dias_fuera' => DiasLaborados::fuera($inicio, $fin, $p->dias, $e->fecha_ingreso->format('Y-m-d'), $e->fecha_baja?->format('Y-m-d')),
                    'conceptos' => $conceptos, 'parametros' => $parametros,
                    'vacaciones' => (int) $vacaciones->get($e->id, 0), 'prima_vacacional' => (float) $empresa->prima_vacacional,
                    'exento_acumulado' => $exentos[$e->id] ?? [],
                ]);
                if ($e->faltantes()) {
                    $calculo['avisos'][] = 'Expediente incompleto: falta '.implode(', ', $e->faltantes()).'.';
                }
                foreach ($calculo['avisos'] as $a) {
                    $avisos[] = "{$e->nombre_completo}: {$a}";
                }
                $this->guardarBorrador($p, $e, $salario, $i, $calculo);
            }
            $p->update(['estado' => 'calculado', 'calculado_at' => now()]);
            AuditLog::registrar('CALCULAR', 'PeriodoNomina', $p->id, null, ['empleados' => $empleados->count()], $u->id);
        });

        return ['captura' => $this->captura($p->fresh()), 'avisos' => $avisos];
    }

    /**
     * Autoriza el periodo: congela los recibos, guarda su huella y actualiza los acumulados anuales.
     * Antes, la pantalla sugiere crear un respaldo de la base de datos.
     */
    public function autorizar(PeriodoNomina $p, User $u): void
    {
        if ($p->estado !== 'calculado') {
            throw new ReglaNegocioException($p->autorizado() ? 'Este periodo ya está autorizado.' : 'Calcula la pre-nómina antes de autorizar (hay cambios sin calcular).', 409, ['codigo' => 'NO_CALCULADO']);
        }
        $pendiente = PeriodoNomina::where(['empresa_id' => $p->empresa_id, 'tipo' => $p->tipo])->where('fin', '<', $p->inicio)
            ->where('estado', '!=', 'autorizado')->whereHas('recibos')->orderBy('inicio')->first();
        if ($pendiente) {
            throw new ReglaNegocioException("Primero autoriza o descarta la pre-nómina anterior: {$pendiente->etiqueta()}.", 409, ['codigo' => 'PERIODO_ANTERIOR']);
        }
        $recibos = NominaRecibo::with('lineas')->where('periodo_id', $p->id)->get();
        if ($recibos->isEmpty()) {
            throw new ReglaNegocioException('El periodo no tiene recibos calculados.', 422, ['codigo' => 'SIN_RECIBOS']);
        }

        DB::transaction(function () use ($p, $u, $recibos) {
            foreach ($recibos as $r) {
                $r->update(['estado' => 'autorizado', 'huella' => Huella::de($this->fotografia($r))]);
                $this->sumarAcumulados($r, 1);
            }
            $p->update(['estado' => 'autorizado', 'autorizado_at' => now(), 'autorizado_por' => $u->id]);
            AuditLog::registrar('AUTORIZAR', 'PeriodoNomina', $p->id, null, ['recibos' => $recibos->count(), 'neto' => round($recibos->sum('neto'), 2)], $u->id);
        });
    }

    /** Reabre el ÚLTIMO periodo autorizado de su calendario, solo si ningún recibo está timbrado. */
    public function reabrir(PeriodoNomina $p, string $motivo, User $u): void
    {
        if (! $p->autorizado()) {
            throw new ReglaNegocioException('El periodo no está autorizado.', 409, ['codigo' => 'NO_AUTORIZADO']);
        }
        $recibos = NominaRecibo::with('lineas')->where('periodo_id', $p->id)->get();
        if ($recibos->contains(fn (NominaRecibo $r) => $r->timbrado())) {
            throw new ReglaNegocioException('El periodo tiene recibos timbrados ante el SAT: es de solo lectura. Los errores se corrigen en el siguiente periodo o cancelando el CFDI.', 409, ['codigo' => 'TIMBRADO']);
        }
        $posterior = PeriodoNomina::where(['empresa_id' => $p->empresa_id, 'tipo' => $p->tipo, 'estado' => 'autorizado'])->where('inicio', '>', $p->inicio)->exists();
        if ($posterior) {
            throw new ReglaNegocioException('Solo se puede reabrir el último periodo autorizado. Los anteriores son de solo lectura.', 409, ['codigo' => 'NO_ES_ULTIMO']);
        }

        DB::transaction(function () use ($p, $motivo, $u, $recibos) {
            foreach ($recibos as $r) {
                $this->sumarAcumulados($r, -1);
                $r->permitirCambio = true;
                $r->update(['estado' => 'borrador', 'huella' => null]);
            }
            $p->update(['estado' => 'calculado', 'autorizado_at' => null, 'autorizado_por' => null]);
            AuditLog::registrar('REABRIR', 'PeriodoNomina', $p->id, ['estado' => 'autorizado'], ['estado' => 'calculado', 'motivo' => $motivo], $u->id);
        });
    }

    /**
     * Herramienta "Reconstruir historial": revisa la huella de cada recibo autorizado (detecta cambios hechos
     * fuera del sistema) y vuelve a armar los acumulados anuales a partir de los recibos.
     * @return array{recibos: int, alterados: list<array>, acumulados: int}
     */
    public function reconstruir(Empresa $empresa, ?int $ejercicio = null): array
    {
        $alterados = [];
        $total = 0;
        DB::transaction(function () use ($empresa, $ejercicio, &$alterados, &$total) {
            NominaAcumulado::where('empresa_id', $empresa->id)->when($ejercicio, fn ($q) => $q->where('ejercicio', $ejercicio))->delete();
            NominaRecibo::with(['lineas', 'periodo'])->where(['empresa_id' => $empresa->id, 'estado' => 'autorizado'])
                ->when($ejercicio, fn ($q) => $q->where('ejercicio', $ejercicio))->orderBy('id')
                ->chunk(200, function ($lote) use (&$alterados, &$total) {
                    foreach ($lote as $r) {
                        $total++;
                        if ($r->huella !== Huella::de($this->fotografia($r))) {
                            $alterados[] = ['recibo_id' => $r->id, 'empleado' => $r->nombre, 'periodo' => $r->periodo->etiqueta()];
                        }
                        $this->sumarAcumulados($r, 1);
                    }
                });
        });
        AuditLog::registrar('RECONSTRUIR', 'NominaAcumulado', null, null, ['ejercicio' => $ejercicio, 'recibos' => $total, 'alterados' => count($alterados)]);

        return ['recibos' => $total, 'alterados' => $alterados,
            'acumulados' => NominaAcumulado::where('empresa_id', $empresa->id)->when($ejercicio, fn ($q) => $q->where('ejercicio', $ejercicio))->count()];
    }

    public function reciboJson(NominaRecibo $r): array
    {
        $r->loadMissing(['periodo', 'lineas']);
        $timbrado = (bool) Empresa::whereKey($r->empresa_id)->value('timbrado_activo');

        return [
            ...$this->resumen($r),
            'empleado_id' => $r->empleado_id, 'numero_empleado' => $r->numero_empleado, 'nombre' => $r->nombre, 'rfc' => $r->rfc, 'curp' => $r->curp,
            'nss' => $r->nss, 'puesto' => $r->puesto, 'salario_mensual' => $r->salario_mensual, 'salario_diario' => $r->salario_diario,
            'sdi' => $r->sdi, 'sbc' => $r->sbc, 'faltas' => $r->faltas, 'retardos' => $r->retardos, 'domingos' => $r->domingos,
            'periodo' => $this->periodoJson($r->periodo),
            'calculo' => $r->detalle,
            'cfdi' => ['activo' => $timbrado, 'uuid' => $r->uuid, 'timbrado_at' => $r->timbrado_at?->format('d/m/Y H:i'),
                'estado' => $r->uuid ? ($r->cfdi_modo === 'simulado' ? 'simulado' : 'timbrado') : ($timbrado ? ($r->estado === 'autorizado' ? 'pendiente' : 'no_aplica') : 'desactivado'),
                // v14: modo del timbre (simulado | pruebas | produccion | importado), XML disponible y datos del Timbre Fiscal Digital
                'modo' => $r->cfdi_modo, 'xml' => (bool) $r->cfdi_xml_path, 'total' => $r->cfdi_total,
                'timbre' => $r->uuid ? app(\App\Services\Cfdi\TimbradoService::class)->datosTimbre($r) : null],
            'integridad' => $r->estado === 'autorizado' ? ($r->huella === Huella::de($this->fotografia($r)) ? 'ok' : 'alterado') : null,
        ];
    }

    public function periodoJson(PeriodoNomina $p): array
    {
        return ['id' => $p->id, 'tipo' => $p->tipo, 'ejercicio' => $p->ejercicio, 'numero' => $p->numero, 'inicio' => $p->inicio->format('Y-m-d'),
            'fin' => $p->fin->format('Y-m-d'), 'fecha_pago' => $p->fecha_pago->format('Y-m-d'), 'dias' => $p->dias, 'fin_de_mes' => $p->fin_de_mes,
            'estado' => $p->estado, 'etiqueta' => $p->etiqueta(), 'clase' => $p->clase ?? 'ordinaria', 'calculado_at' => $p->calculado_at?->format('d/m/Y H:i'),
            'autorizado_at' => $p->autorizado_at?->format('d/m/Y H:i'), 'autorizado_por' => $p->autorizador?->name];
    }

    /**
     * v13: crea la nómina especial de aguinaldo del ejercicio (una por año). La LFT pide pagarlo antes del 20 de diciembre.
     */
    public function crearAguinaldo(Empresa $empresa, int $ejercicio, string $fechaPago, User $u): PeriodoNomina
    {
        if (substr($fechaPago, 0, 4) !== (string) $ejercicio) {
            throw new ReglaNegocioException("La fecha de pago debe ser del {$ejercicio}.", 422, ['codigo' => 'FECHA_FUERA']);
        }
        $existe = PeriodoNomina::where(['empresa_id' => $empresa->id, 'tipo' => 'especial', 'clase' => 'aguinaldo', 'ejercicio' => $ejercicio])->first();
        if ($existe) {
            throw new ReglaNegocioException("Ya existe la nómina de aguinaldo {$ejercicio}.", 409, ['codigo' => 'YA_EXISTE', 'periodo_id' => $existe->id]);
        }
        $numero = (int) PeriodoNomina::where(['empresa_id' => $empresa->id, 'tipo' => 'especial', 'ejercicio' => $ejercicio])->max('numero') + 1;
        $p = PeriodoNomina::create(['empresa_id' => $empresa->id, 'tipo' => 'especial', 'clase' => 'aguinaldo', 'ejercicio' => $ejercicio, 'numero' => $numero,
            'inicio' => "{$ejercicio}-01-01", 'fin' => "{$ejercicio}-12-31", 'fecha_pago' => $fechaPago, 'dias' => (int) $empresa->dias_aguinaldo,
            'fin_de_mes' => false, 'estado' => 'abierto']);
        AuditLog::registrar('CREAR_AGUINALDO', 'PeriodoNomina', $p->id, null, ['ejercicio' => $ejercicio, 'fecha_pago' => $fechaPago], $u->id);

        return $p;
    }

    /** v13: días de vacaciones que INICIAN dentro del periodo, por empleado (generan prima vacacional en ese periodo). */
    public function vacacionesDelPeriodo(PeriodoNomina $p): \Illuminate\Support\Collection
    {
        return Vacacion::whereDate('desde', '>=', $p->inicio->format('Y-m-d'))->whereDate('desde', '<=', $p->fin->format('Y-m-d'))
            ->groupBy('empleado_id')->selectRaw('empleado_id, SUM(dias) as dias')->pluck('dias', 'empleado_id');
    }

    // ───────────────────────── internos ─────────────────────────

    private function exigirEditable(PeriodoNomina $p): void
    {
        if ($p->autorizado()) {
            throw new ReglaNegocioException('El periodo está autorizado: es de solo lectura.', 409, ['codigo' => 'AUTORIZADO']);
        }
    }

    private function guardarBorrador(PeriodoNomina $p, Empleado $e, float $salario, ?NominaIncidencia $i, array $c): void
    {
        $r = NominaRecibo::updateOrCreate(['periodo_id' => $p->id, 'empleado_id' => $e->id], [
            'empresa_id' => $p->empresa_id, 'ejercicio' => $p->ejercicio, 'estado' => 'borrador',
            'numero_empleado' => $e->numero_empleado, 'nombre' => $e->nombre_completo, 'rfc' => $e->rfc, 'curp' => $e->curp, 'nss' => $e->nss,
            'puesto' => $e->puesto?->nombre, 'salario_mensual' => $salario, 'salario_diario' => $c['salario_diario'], 'sdi' => $c['sdi'], 'sbc' => $c['sbc'],
            'dias_pagados' => $c['dias_pagados'], 'faltas' => $i?->faltas ?? 0, 'retardos' => $i?->retardos ?? 0, 'domingos' => $i?->domingos ?? 0,
            'percepciones' => $c['totales']['percepciones'], 'gravado' => $c['totales']['gravado'], 'exento' => $c['totales']['exento'],
            'deducciones' => $c['totales']['deducciones'], 'isr_retenido' => $c['isr']['isr_retenido'], 'subsidio_causado' => $c['isr']['subsidio_causado'],
            'subsidio_aplicado' => $c['isr']['subsidio_aplicado'], 'imss' => round(array_sum(array_column($c['imss'], 'importe')), 2),
            'neto' => $c['totales']['neto'], 'detalle' => $c, 'huella' => null,
        ]);
        $r->lineas()->delete();
        foreach ($c['percepciones'] as $l) {
            NominaReciboLinea::create(['recibo_id' => $r->id, 'tipo' => 'percepcion', 'clave' => $l['clave'], 'nombre' => $l['nombre'], 'clave_sat' => $l['clave_sat'],
                'importe' => $l['importe'], 'gravado' => $l['gravado'], 'exento' => $l['exento']]);
        }
        foreach ($c['deducciones'] as $l) {
            NominaReciboLinea::create(['recibo_id' => $r->id, 'tipo' => 'deduccion', 'clave' => $l['clave'], 'nombre' => $l['nombre'], 'clave_sat' => $l['clave_sat'],
                'importe' => $l['importe']]);
        }
        foreach (array_filter($c['otros_pagos'] ?? [], fn ($o) => (float) $o['importe'] > 0) as $l) {   // v13 (el subsidio es informativo, importe 0)
            NominaReciboLinea::create(['recibo_id' => $r->id, 'tipo' => 'otro_pago', 'clave' => $l['clave'], 'nombre' => $l['nombre'], 'clave_sat' => $l['clave_sat'],
                'importe' => $l['importe']]);
        }
    }

    /** Lo que protege la huella: los datos del trabajador, los totales, el cálculo completo y sus líneas. */
    private function fotografia(NominaRecibo $r): array
    {
        $campos = ['periodo_id', 'empleado_id', 'ejercicio', 'numero_empleado', 'nombre', 'rfc', 'curp', 'nss', 'puesto', 'salario_mensual', 'salario_diario',
            'sdi', 'sbc', 'dias_pagados', 'faltas', 'retardos', 'domingos', 'percepciones', 'gravado', 'exento', 'deducciones', 'isr_retenido',
            'subsidio_causado', 'subsidio_aplicado', 'imss', 'neto', 'detalle'];
        $lineas = $r->lineas->sortBy('id')->map(fn ($l) => [$l->tipo, $l->clave, $l->importe, $l->gravado, $l->exento])->values()->all();

        return [...$r->only($campos), 'lineas' => $lineas];
    }

    private function sumarAcumulados(NominaRecibo $r, int $signo): void
    {
        foreach ($r->lineas as $l) {
            $a = NominaAcumulado::firstOrNew(['empleado_id' => $r->empleado_id, 'ejercicio' => $r->ejercicio, 'clave' => $l->clave],
                ['empresa_id' => $r->empresa_id, 'tipo' => $l->tipo, 'nombre' => $l->nombre]);
            $a->importe = round(($a->importe ?? 0) + $signo * $l->importe, 2);
            $a->gravado = round(($a->gravado ?? 0) + $signo * $l->gravado, 2);
            $a->exento = round(($a->exento ?? 0) + $signo * $l->exento, 2);
            $a->periodos = max(0, ($a->periodos ?? 0) + $signo);
            $a->periodos === 0 && abs($a->importe) < 0.005 ? ($a->exists ? $a->delete() : null) : $a->save();
        }
    }

    private function resumen(NominaRecibo $r): array
    {
        return ['id' => $r->id, 'estado' => $r->estado, 'dias_pagados' => $r->dias_pagados, 'percepciones' => $r->percepciones,
            'deducciones' => $r->deducciones, 'isr' => $r->isr_retenido, 'imss' => $r->imss, 'neto' => $r->neto, 'uuid' => $r->uuid, 'cfdi_modo' => $r->cfdi_modo,
            'avisos' => $r->detalle['avisos'] ?? []];
    }
}
