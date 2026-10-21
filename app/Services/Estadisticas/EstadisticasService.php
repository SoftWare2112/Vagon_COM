<?php

namespace App\Services\Estadisticas;

use App\Models\Periodo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Datos para la pantalla "Gráficas" (v6). Son CONTEOS y SUMAS para visualizar: no se guarda ni se
 * modifica ningún valor (la regla de RH de "no calcular" se refiere a los datos del reporte).
 * Solo cuenta filas vigentes (las eliminadas al Histórico no entran).
 */
class EstadisticasService
{
    private const QUINCENAS_EN_SERIE = 12;

    public function para(Periodo $actual): array
    {
        $serie = $this->serie($actual);
        $hoy = collect($serie)->firstWhere('periodo_id', $actual->id) ?? $this->vacio($actual);
        $anio = $actual->ejercicio ?? (int) $actual->fecha_fin->format('Y');

        return [
            'periodo' => ['id' => $actual->id, 'nombre' => $actual->nombre(), 'ejercicio' => $anio],
            'kpis' => [
                'empleados' => $hoy['empleados'],
                'faltas_total' => $hoy['faltas_total'],
                'con_faltas' => $hoy['con_faltas'],
                'pct_con_faltas' => $hoy['pct_con_faltas'],
                'monto_bonos' => round($hoy['bono_3_meses'] + $hoy['bono_puntualidad'] + $hoy['bono_asistencia'], 2),
                'acreditan_bono' => $hoy['acreditan_bono'],
                'nuevos' => $hoy['nuevos'],
            ],
            'serie' => $serie,
            'top_faltas' => $this->topFaltas($anio),
            'por_puesto' => $this->porPuesto($actual),
        ];
    }

    /** Últimas 12 quincenas hasta la elegida, de la más antigua a la más reciente. */
    private function serie(Periodo $actual): array
    {
        $periodos = Periodo::whereDate('fecha_inicio', '<=', $actual->fecha_inicio)
            ->orderByDesc('fecha_inicio')->limit(self::QUINCENAS_EN_SERIE)->get()->reverse()->values();
        $ids = $periodos->pluck('id');

        $agg = DB::table('registros as r')
            ->join('empleados as e', 'e.id', '=', 'r.empleado_id')
            ->join('periodos as p', 'p.id', '=', 'r.periodo_id')
            ->whereNull('r.deleted_at')
            ->whereIn('r.periodo_id', $ids)
            ->groupBy('r.periodo_id')
            ->selectRaw('r.periodo_id,
                COUNT(*) as empleados,
                COALESCE(SUM(r.num_faltas), 0) as faltas_total,
                COALESCE(SUM(CASE WHEN r.num_faltas > 0 THEN 1 ELSE 0 END), 0) as con_faltas,
                COALESCE(SUM(CASE WHEN r.acredita_bono = 1 THEN 1 ELSE 0 END), 0) as acreditan_bono,
                COALESCE(SUM(r.prima_vacacional), 0) as prima_vacacional,
                COALESCE(SUM(r.bono_3_meses), 0) as bono_3_meses,
                COALESCE(SUM(r.bono_puntualidad), 0) as bono_puntualidad,
                COALESCE(SUM(r.bono_asistencia), 0) as bono_asistencia,
                COALESCE(SUM(r.programado_domingo), 0) as domingos_programados,
                COALESCE(SUM(CASE WHEN e.fecha_ingreso BETWEEN p.fecha_inicio AND p.fecha_fin THEN 1 ELSE 0 END), 0) as nuevos')
            ->get()->keyBy('periodo_id');

        $domingos = DB::table('registro_domingos as d')
            ->join('registros as r', 'r.id', '=', 'd.registro_id')
            ->whereNull('r.deleted_at')->whereIn('r.periodo_id', $ids)
            ->groupBy('r.periodo_id')
            ->selectRaw('r.periodo_id, COALESCE(SUM(d.cantidad), 0) as trabajados')
            ->pluck('trabajados', 'periodo_id');

        return $periodos->map(function (Periodo $p) use ($agg, $domingos) {
            $a = $agg->get($p->id);
            if (! $a) {
                return $this->vacio($p);
            }
            $emp = (int) $a->empleados;

            return [
                ...$this->etiquetas($p),
                'empleados' => $emp,
                'faltas_total' => (int) $a->faltas_total,
                'con_faltas' => (int) $a->con_faltas,
                'pct_con_faltas' => $emp ? round(100 * $a->con_faltas / $emp, 1) : 0,
                'acreditan_bono' => (int) $a->acreditan_bono,
                'prima_vacacional' => (float) $a->prima_vacacional,
                'bono_3_meses' => (float) $a->bono_3_meses,
                'bono_puntualidad' => (float) $a->bono_puntualidad,
                'bono_asistencia' => (float) $a->bono_asistencia,
                'domingos_programados' => (float) $a->domingos_programados,
                'domingos_trabajados' => (float) ($domingos[$p->id] ?? 0),
                'nuevos' => (int) $a->nuevos,
            ];
        })->all();
    }

    /** Empleados con más faltas en el año (Ejercicio), con cuántas quincenas tuvieron al menos una. */
    private function topFaltas(int $anio): array
    {
        $ids = Periodo::get()->filter(fn (Periodo $p) => ($p->ejercicio ?? (int) $p->fecha_fin->format('Y')) === $anio)->pluck('id');

        return DB::table('registros as r')
            ->join('empleados as e', 'e.id', '=', 'r.empleado_id')
            ->whereNull('r.deleted_at')->whereIn('r.periodo_id', $ids)
            ->groupBy('e.id', 'e.numero_empleado', 'e.nombre_completo')
            ->havingRaw('SUM(r.num_faltas) > 0')
            ->orderByRaw('SUM(r.num_faltas) DESC')->orderBy('e.nombre_completo')
            ->limit(10)
            ->selectRaw('e.numero_empleado, e.nombre_completo as nombre, SUM(r.num_faltas) as faltas,
                SUM(CASE WHEN r.num_faltas > 0 THEN 1 ELSE 0 END) as quincenas')
            ->get()
            ->map(fn ($f) => ['numero_empleado' => $f->numero_empleado, 'nombre' => $f->nombre, 'faltas' => (int) $f->faltas, 'quincenas' => (int) $f->quincenas])
            ->all();
    }

    private function porPuesto(Periodo $p): array
    {
        return DB::table('registros as r')
            ->leftJoin('puestos as pu', 'pu.id', '=', 'r.puesto_id')
            ->whereNull('r.deleted_at')->where('r.periodo_id', $p->id)
            ->groupBy('pu.nombre')
            ->orderByRaw('COUNT(*) DESC')
            ->selectRaw("COALESCE(pu.nombre, 'Sin puesto') as puesto, COUNT(*) as empleados")
            ->get()->map(fn ($f) => ['puesto' => $f->puesto, 'empleados' => (int) $f->empleados])->all();
    }

    private function etiquetas(Periodo $p): array
    {
        return [
            'periodo_id' => $p->id,
            'corto' => $p->numero ? "P{$p->numero}" : $p->fecha_inicio->format('d/m'),
            'nombre' => $p->nombre(),
        ];
    }

    private function vacio(Periodo $p): array
    {
        return [...$this->etiquetas($p), 'empleados' => 0, 'faltas_total' => 0, 'con_faltas' => 0, 'pct_con_faltas' => 0,
            'acreditan_bono' => 0, 'prima_vacacional' => 0.0, 'bono_3_meses' => 0.0, 'bono_puntualidad' => 0.0, 'bono_asistencia' => 0.0,
            'domingos_programados' => 0.0, 'domingos_trabajados' => 0.0, 'nuevos' => 0];
    }
}
