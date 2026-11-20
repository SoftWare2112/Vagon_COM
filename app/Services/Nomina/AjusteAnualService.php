<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Models\AjusteAnualIsr;
use App\Models\AuditLog;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\NominaIncidencia;
use App\Models\NominaRecibo;
use App\Models\PeriodoNomina;
use App\Models\User;
use App\Support\Nomina\AjusteAnual;
use Illuminate\Support\Facades\DB;

/**
 * v13 — Cálculo anual del ISR (art. 97 LISR) con los recibos AUTORIZADOS del ejercicio.
 * El resultado se aplica en una nómina ordinaria abierta: a cargo → deducción D003; a favor → otro pago O001.
 */
class AjusteAnualService
{
    public function __construct(private readonly ConfiguracionFiscal $config, private readonly NominaService $nomina) {}

    public function listar(Empresa $empresa, int $ejercicio): array
    {
        $filas = AjusteAnualIsr::with(['empleado:id,numero_empleado,nombre_completo', 'periodo'])->where(['empresa_id' => $empresa->id, 'ejercicio' => $ejercicio])
            ->get()->sortBy(fn ($a) => $a->empleado->nombre_completo)->values();
        $pendientes = PeriodoNomina::where(['empresa_id' => $empresa->id, 'ejercicio' => $ejercicio])->where('tipo', '!=', 'especial')
            ->where('estado', '!=', 'autorizado')->whereDate('fin', '<=', "{$ejercicio}-12-31")->count();

        return [
            'ejercicio' => $ejercicio,
            'periodos_sin_autorizar' => $pendientes,
            'filas' => $filas->map(fn (AjusteAnualIsr $a) => [
                'id' => $a->id, 'empleado_id' => $a->empleado_id, 'numero_empleado' => $a->empleado->numero_empleado, 'nombre' => $a->empleado->nombre_completo,
                'declara_por_su_cuenta' => $a->declara_por_su_cuenta, 'aplica' => $a->aplica, 'motivo' => $a->motivo, 'resultado' => $a->resultado,
                'diferencia' => $a->diferencia, 'datos' => $a->datos, 'estado' => $a->estado, 'periodo' => $a->periodo?->etiqueta(),
            ]),
            'totales' => [
                'a_cargo' => round($filas->where('aplica', true)->where('resultado', 'a_cargo')->sum('diferencia'), 2),
                'a_favor' => round(-$filas->where('aplica', true)->where('resultado', 'a_favor')->sum('diferencia'), 2),
                'aplican' => $filas->where('aplica', true)->count(),
            ],
        ];
    }

    /** Calcula (o recalcula) a todos los que tuvieron recibos autorizados en el ejercicio. Los ya aplicados no se tocan. */
    public function calcular(Empresa $empresa, int $ejercicio, User $u): array
    {
        $vigentes = $this->config->vigentes("{$ejercicio}-12-31");
        $tarifa = $vigentes['isr_anual']['renglones'] ?? throw new ReglaNegocioException(
            "Falta la tarifa anual de ISR vigente en {$ejercicio}. Agrégala en Configuración de nómina → Tablas fiscales.", 422, ['codigo' => 'SIN_TARIFA_ANUAL']);

        $sumas = NominaRecibo::where(['empresa_id' => $empresa->id, 'ejercicio' => $ejercicio, 'estado' => 'autorizado'])
            ->groupBy('empleado_id')
            ->selectRaw('empleado_id, SUM(percepciones) as ingresos, SUM(gravado) as gravado, SUM(isr_retenido) as isr, SUM(subsidio_aplicado) as subsidio')
            ->get()->keyBy('empleado_id');
        $previos = AjusteAnualIsr::where(['empresa_id' => $empresa->id, 'ejercicio' => $ejercicio])->get()->keyBy('empleado_id');

        DB::transaction(function () use ($empresa, $ejercicio, $tarifa, $sumas, $previos) {
            foreach (Empleado::whereIn('id', $sumas->keys())->get() as $e) {
                $previo = $previos->get($e->id);
                if ($previo?->estado === 'aplicado') {
                    continue;
                }
                $s = $sumas->get($e->id);
                $r = AjusteAnual::calcular([
                    'ejercicio' => $ejercicio, 'fecha_ingreso' => $e->fecha_ingreso->format('Y-m-d'), 'fecha_baja' => $e->fecha_baja?->format('Y-m-d'),
                    'declara_por_su_cuenta' => (bool) $previo?->declara_por_su_cuenta, 'ingresos_totales' => (float) $s->ingresos, 'gravado' => (float) $s->gravado,
                    'isr_retenido' => (float) $s->isr, 'subsidio_aplicado' => (float) $s->subsidio, 'tarifa_anual' => $tarifa,
                ]);
                AjusteAnualIsr::updateOrCreate(['empleado_id' => $e->id, 'ejercicio' => $ejercicio], [
                    'empresa_id' => $empresa->id, 'declara_por_su_cuenta' => (bool) $previo?->declara_por_su_cuenta, 'aplica' => $r['aplica'], 'motivo' => $r['motivo'],
                    'diferencia' => $r['diferencia'], 'resultado' => $r['resultado'], 'datos' => $r, 'estado' => 'calculado',
                ]);
            }
        });
        AuditLog::registrar('CALCULO_ANUAL', 'AjusteAnualIsr', null, null, ['ejercicio' => $ejercicio, 'empleados' => $sumas->count()], $u->id);

        return $this->listar($empresa, $ejercicio);
    }

    public function marcarDeclaracion(AjusteAnualIsr $a, bool $declara, User $u): array
    {
        if ($a->estado === 'aplicado') {
            throw new ReglaNegocioException('Este ajuste ya se aplicó en una nómina; quita la aplicación primero.', 409, ['codigo' => 'APLICADO']);
        }
        $a->update(['declara_por_su_cuenta' => $declara]);

        return $this->calcular(Empresa::findOrFail($a->empresa_id), $a->ejercicio, $u);
    }

    /** Escribe los resultados en las incidencias de una nómina ordinaria abierta (D003 a cargo, O001 a favor). */
    public function aplicar(Empresa $empresa, int $ejercicio, PeriodoNomina $destino, User $u): array
    {
        if ($destino->especial() || $destino->autorizado()) {
            throw new ReglaNegocioException('Elige una nómina ordinaria que no esté autorizada.', 422, ['codigo' => 'DESTINO_INVALIDO']);
        }
        $participan = $this->nomina->empleados($destino)->pluck('id')->all();
        $ajustes = AjusteAnualIsr::with('empleado:id,nombre_completo')->where(['empresa_id' => $empresa->id, 'ejercicio' => $ejercicio, 'aplica' => true, 'estado' => 'calculado'])
            ->where('resultado', '!=', 'cero')->get();
        $aplicados = 0;
        $fuera = [];
        DB::transaction(function () use ($ajustes, $destino, $participan, &$aplicados, &$fuera, $u) {
            foreach ($ajustes as $a) {
                if (! in_array($a->empleado_id, $participan, true)) {
                    $fuera[] = $a->empleado->nombre_completo;

                    continue;
                }
                $i = NominaIncidencia::firstOrNew(['periodo_id' => $destino->id, 'empleado_id' => $a->empleado_id]);
                $capturados = array_diff_key((array) $i->capturados, ['D003' => 0, 'O001' => 0]);
                $capturados[$a->resultado === 'a_cargo' ? 'D003' : 'O001'] = round(abs($a->diferencia), 2);
                $i->fill(['capturados' => $capturados, 'capturado_por' => $u->id])->save();
                $a->update(['estado' => 'aplicado', 'periodo_aplicacion_id' => $destino->id]);
                $aplicados++;
            }
            if ($aplicados && $destino->estado === 'calculado') {
                $destino->update(['estado' => 'abierto']);
            }
        });
        AuditLog::registrar('APLICAR_CALCULO_ANUAL', 'PeriodoNomina', $destino->id, null, ['ejercicio' => $ejercicio, 'aplicados' => $aplicados], $u->id);

        return ['aplicados' => $aplicados, 'fuera' => $fuera, 'periodo' => $destino->etiqueta()];
    }

    /** Quita la aplicación mientras la nómina destino no esté autorizada. */
    public function quitar(Empresa $empresa, int $ejercicio, User $u): int
    {
        $ajustes = AjusteAnualIsr::with('periodo')->where(['empresa_id' => $empresa->id, 'ejercicio' => $ejercicio, 'estado' => 'aplicado'])->get();
        if ($ajustes->contains(fn ($a) => $a->periodo?->autorizado())) {
            throw new ReglaNegocioException('La nómina donde se aplicó ya está autorizada: el ajuste quedó pagado.', 409, ['codigo' => 'AUTORIZADO']);
        }
        DB::transaction(function () use ($ajustes) {
            foreach ($ajustes as $a) {
                $i = NominaIncidencia::where(['periodo_id' => $a->periodo_aplicacion_id, 'empleado_id' => $a->empleado_id])->first();
                if ($i) {
                    $i->update(['capturados' => array_diff_key((array) $i->capturados, ['D003' => 0, 'O001' => 0]) ?: null]);
                }
                if ($a->periodo && $a->periodo->estado === 'calculado') {
                    $a->periodo->update(['estado' => 'abierto']);
                }
                $a->update(['estado' => 'calculado', 'periodo_aplicacion_id' => null]);
            }
        });
        AuditLog::registrar('QUITAR_CALCULO_ANUAL', 'AjusteAnualIsr', null, null, ['ejercicio' => $ejercicio, 'ajustes' => $ajustes->count()], $u->id);

        return $ajustes->count();
    }
}
