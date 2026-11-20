<?php

namespace App\Services\Nomina;

use App\Models\Empresa;
use App\Models\NominaRecibo;
use App\Models\PeriodoNomina;
use Illuminate\Support\Facades\DB;

/**
 * v12 — Reportes de nóminas AUTORIZADAS (las pre-nóminas no cuentan):
 *  - Lista de raya de un periodo: un renglón por empleado y una columna por concepto.
 *  - Acumulado de conceptos por periodo: un renglón por concepto y una columna por periodo del ejercicio.
 * El frontend los exporta a Excel (CSV) y a PDF (impresión).
 */
class ReportesNomina
{
    public function listaDeRaya(PeriodoNomina $p): array
    {
        $recibos = NominaRecibo::with('lineas')->where('periodo_id', $p->id)->orderBy('nombre')->get();
        $columnas = $recibos->flatMap->lineas->map(fn ($l) => ['clave' => $l->clave, 'nombre' => $l->nombre, 'tipo' => $l->tipo])
            ->unique('clave')->sortBy(fn ($c) => self::orden($c['tipo']).$c['clave'])->values();

        $filas = $recibos->map(function (NominaRecibo $r) use ($columnas) {
            $importes = $r->lineas->groupBy('clave')->map(fn ($ls) => round($ls->sum('importe'), 2));

            return ['recibo_id' => $r->id, 'numero_empleado' => $r->numero_empleado, 'nombre' => $r->nombre, 'rfc' => $r->rfc, 'puesto' => $r->puesto,
                'dias' => $r->dias_pagados, 'importes' => $columnas->mapWithKeys(fn ($c) => [$c['clave'] => $importes->get($c['clave'], 0.0)]),
                'percepciones' => $r->percepciones, 'deducciones' => $r->deducciones, 'neto' => $r->neto, 'uuid' => $r->uuid];
        })->values();

        return [
            'periodo' => app(NominaService::class)->periodoJson($p),
            'columnas' => $columnas,
            'filas' => $filas,
            'totales' => ['importes' => $columnas->mapWithKeys(fn ($c) => [$c['clave'] => round($filas->sum(fn ($f) => $f['importes'][$c['clave']]), 2)]),
                'percepciones' => round($recibos->sum('percepciones'), 2), 'deducciones' => round($recibos->sum('deducciones'), 2), 'neto' => round($recibos->sum('neto'), 2)],
        ];
    }

    /** Percepciones, deducciones y al final otros pagos (v13). */
    private static function orden(string $tipo): string
    {
        return ['percepcion' => '0', 'deduccion' => '1', 'otro_pago' => '2'][$tipo] ?? '3';
    }

    public function acumulados(Empresa $empresa, int $ejercicio, string $tipo, ?int $empleadoId = null): array
    {
        $periodos = PeriodoNomina::where(['empresa_id' => $empresa->id, 'tipo' => $tipo, 'ejercicio' => $ejercicio, 'estado' => 'autorizado'])
            ->orderBy('numero')->get(['id', 'numero', 'inicio', 'fin']);
        $datos = DB::table('nomina_recibo_lineas as l')->join('nomina_recibos as r', 'r.id', '=', 'l.recibo_id')
            ->whereIn('r.periodo_id', $periodos->pluck('id'))->where('r.estado', 'autorizado')
            ->when($empleadoId, fn ($q) => $q->where('r.empleado_id', $empleadoId))
            ->groupBy('l.tipo', 'l.clave', 'r.periodo_id')
            ->selectRaw('l.tipo, l.clave, MAX(l.nombre) as nombre, r.periodo_id, SUM(l.importe) as importe')->get();

        $filas = $datos->groupBy('clave')->map(function ($g) use ($periodos) {
            $porPeriodo = $g->keyBy('periodo_id');

            return ['clave' => $g->first()->clave, 'nombre' => $g->first()->nombre, 'tipo' => $g->first()->tipo,
                'periodos' => $periodos->mapWithKeys(fn ($p) => [$p->numero => round((float) ($porPeriodo->get($p->id)?->importe ?? 0), 2)]),
                'total' => round((float) $g->sum('importe'), 2)];
        })->sortBy(fn ($f) => self::orden($f['tipo']).$f['clave'])->values();

        return ['ejercicio' => $ejercicio, 'tipo' => $tipo, 'empleado_id' => $empleadoId,
            'periodos' => $periodos->map(fn ($p) => ['numero' => $p->numero, 'inicio' => $p->inicio->format('Y-m-d'), 'fin' => $p->fin->format('Y-m-d')])->values(),
            'filas' => $filas];
    }
}
