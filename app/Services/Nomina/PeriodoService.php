<?php

namespace App\Services\Nomina;

use App\Models\AuditLog;
use App\Models\Empresa;
use App\Models\PeriodoNomina;
use App\Support\Nomina\GeneradorPeriodos;

/** v11: calendario de periodos de pago. */
class PeriodoService
{
    /** Crea los periodos del ejercicio que falten (no toca los que ya existen). */
    public function generar(Empresa $empresa, string $tipo, int $ejercicio, ?string $primerDia = null): int
    {
        $nuevos = 0;
        foreach (GeneradorPeriodos::generar($tipo, $ejercicio, $primerDia) as $p) {
            $creado = PeriodoNomina::firstOrCreate(
                ['empresa_id' => $empresa->id, 'tipo' => $tipo, 'ejercicio' => $ejercicio, 'numero' => $p['numero']],
                ['inicio' => $p['inicio'], 'fin' => $p['fin'], 'fecha_pago' => $p['fecha_pago'], 'dias' => $p['dias'], 'fin_de_mes' => $p['fin_de_mes']],
            );
            $nuevos += $creado->wasRecentlyCreated ? 1 : 0;
        }
        if ($nuevos > 0) {
            AuditLog::registrar('GENERAR', 'PeriodoNomina', null, null, ['tipo' => $tipo, 'ejercicio' => $ejercicio, 'periodos' => $nuevos]);
        }

        return $nuevos;
    }

    /**
     * El periodo de pago que contiene una fecha. Si el calendario aún no se generó, se arma en memoria
     * (sin guardarlo) para poder simular.
     * @return array{numero: int, inicio: string, fin: string, fecha_pago: string, dias: int, fin_de_mes: bool, id?: int, guardado: bool}
     */
    public function enFecha(Empresa $empresa, string $tipo, string $fecha): array
    {
        $p = PeriodoNomina::where(['empresa_id' => $empresa->id, 'tipo' => $tipo])->whereDate('inicio', '<=', $fecha)->whereDate('fin', '>=', $fecha)->first();
        if ($p) {
            return ['id' => $p->id, 'numero' => $p->numero, 'inicio' => $p->inicio->format('Y-m-d'), 'fin' => $p->fin->format('Y-m-d'),
                'fecha_pago' => $p->fecha_pago->format('Y-m-d'), 'dias' => $p->dias, 'fin_de_mes' => $p->fin_de_mes, 'guardado' => true];
        }
        $anio = (int) substr($fecha, 0, 4);
        foreach ([$anio, $anio + 1] as $a) {
            foreach (GeneradorPeriodos::generar($tipo, $a) as $g) {
                if ($g['inicio'] <= $fecha && $g['fin'] >= $fecha) {
                    return [...$g, 'guardado' => false];
                }
            }
        }
        throw new \RuntimeException("No hay periodo {$tipo} para {$fecha}");
    }
}
