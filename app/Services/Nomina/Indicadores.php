<?php

namespace App\Services\Nomina;

use App\Models\Empresa;
use Illuminate\Support\Facades\DB;

/**
 * v12 — Indicadores de Vagón COM por quincena o por mes del ejercicio:
 * faltas, retardos, domingos trabajados, bonos pagados (por concepto), prima dominical, altas, bajas,
 * plantilla al cierre y rotación. Las cifras de nómina salen SOLO de periodos autorizados;
 * altas y bajas, de la trayectoria de los empleados.
 */
class Indicadores
{
    public function calcular(Empresa $empresa, int $ejercicio, string $agrupar = 'quincena'): array
    {
        $cortes = $this->cortes($ejercicio, $agrupar);
        $bonos = DB::table('conceptos_nomina')->where('empresa_id', $empresa->id)->where('tipo', 'percepcion')->where('metodo', 'fijo')
            ->orderBy('clave')->get(['clave', 'nombre']);
        $primas = DB::table('conceptos_nomina')->where('empresa_id', $empresa->id)->where('metodo', 'por_evento')->pluck('clave');

        $recibos = DB::table('nomina_recibos as r')->join('periodos_nomina as p', 'p.id', '=', 'r.periodo_id')
            ->where('r.empresa_id', $empresa->id)->where('r.estado', 'autorizado')->whereYear('p.fin', $ejercicio)
            ->get(['r.id', 'p.fin', 'r.faltas', 'r.retardos', 'r.domingos', 'r.neto']);
        $lineas = DB::table('nomina_recibo_lineas')->whereIn('recibo_id', $recibos->pluck('id'))
            ->whereIn('clave', $bonos->pluck('clave')->merge($primas))->get(['recibo_id', 'clave', 'importe'])->groupBy('recibo_id');
        $movs = DB::table('empleado_movimientos as m')->join('empleados as e', 'e.id', '=', 'm.empleado_id')->where('e.empresa_id', $empresa->id)
            ->whereIn('m.tipo', ['alta', 'reingreso', 'baja'])->whereYear('m.fecha', $ejercicio)->get(['m.tipo', 'm.fecha']);
        $empleados = DB::table('empleados')->where('empresa_id', $empresa->id)->whereNotNull('rfc')->get(['fecha_ingreso', 'fecha_baja']);

        $serie = [];
        foreach ($cortes as $c) {
            $rs = $recibos->filter(fn ($r) => substr($r->fin, 0, 10) >= $c['inicio'] && substr($r->fin, 0, 10) <= $c['fin']);
            $ls = $rs->flatMap(fn ($r) => $lineas->get($r->id, collect()));
            $enCorte = fn ($m) => $m->fecha >= $c['inicio'] && $m->fecha <= $c['fin'];
            $plantilla = $empleados->filter(fn ($e) => $e->fecha_ingreso <= $c['fin'] && ($e->fecha_baja === null || $e->fecha_baja > $c['fin']))->count();
            $bajas = $movs->where('tipo', 'baja')->filter($enCorte)->count();
            $serie[] = [
                'clave' => $c['clave'], 'etiqueta' => $c['etiqueta'], 'inicio' => $c['inicio'], 'fin' => $c['fin'],
                'recibos' => $rs->count(),
                'faltas' => (int) $rs->sum('faltas'), 'retardos' => (int) $rs->sum('retardos'), 'domingos' => (int) $rs->sum('domingos'),
                'bonos' => $bonos->mapWithKeys(fn ($b) => [$b->clave => round((float) $ls->where('clave', $b->clave)->sum('importe'), 2)]),
                'prima_dominical' => round((float) $ls->whereIn('clave', $primas)->sum('importe'), 2),
                'neto' => round((float) $rs->sum('neto'), 2),
                'altas' => $movs->whereIn('tipo', ['alta', 'reingreso'])->filter($enCorte)->count(),
                'bajas' => $bajas,
                'plantilla' => $plantilla,
                'rotacion' => $plantilla > 0 ? round($bajas / $plantilla * 100, 1) : 0.0,
            ];
        }

        return ['ejercicio' => $ejercicio, 'agrupar' => $agrupar, 'bonos' => $bonos->map(fn ($b) => ['clave' => $b->clave, 'nombre' => $b->nombre])->values(),
            'serie' => $serie, 'con_nomina' => $recibos->isNotEmpty(),
            'totales' => ['faltas' => array_sum(array_column($serie, 'faltas')), 'retardos' => array_sum(array_column($serie, 'retardos')),
                'domingos' => array_sum(array_column($serie, 'domingos')), 'altas' => array_sum(array_column($serie, 'altas')),
                'bajas' => array_sum(array_column($serie, 'bajas')),
                'bonos' => round(array_sum(array_map(fn ($s) => array_sum($s['bonos']->all()), $serie)), 2)]];
    }

    /** Quincenas (1–15 y 16–fin de mes) o meses del ejercicio, por FECHA, sin importar la periodicidad de pago. */
    private function cortes(int $anio, string $agrupar): array
    {
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $ultimo = (int) date('t', mktime(0, 0, 0, $m, 1, $anio));
            $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
            if ($agrupar === 'mes') {
                $out[] = ['clave' => "{$anio}-{$mm}", 'etiqueta' => $meses[$m - 1], 'inicio' => "{$anio}-{$mm}-01", 'fin' => "{$anio}-{$mm}-{$ultimo}"];

                continue;
            }
            $out[] = ['clave' => "{$anio}-{$mm}-1", 'etiqueta' => '1.ª '.$meses[$m - 1], 'inicio' => "{$anio}-{$mm}-01", 'fin' => "{$anio}-{$mm}-15"];
            $out[] = ['clave' => "{$anio}-{$mm}-2", 'etiqueta' => '2.ª '.$meses[$m - 1], 'inicio' => "{$anio}-{$mm}-16", 'fin' => "{$anio}-{$mm}-{$ultimo}"];
        }

        return $out;
    }
}
