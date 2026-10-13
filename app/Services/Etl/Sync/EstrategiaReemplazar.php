<?php

namespace App\Services\Etl\Sync;

use App\Models\Carga;
use App\Models\CargaFila;
use App\Models\Periodo;
use App\Models\Registro;
use Illuminate\Support\Collection;

/**
 * REEMPLAZAR (dentro de la quincena del archivo): el Excel es la fuente de verdad.
 *  - Nuevos se crean; existentes se sobrescriben en las columnas que vienen (se borra la marca "editado en web").
 *  - Registros eliminados de ESA quincena que vienen en el Excel se restauran.
 *  - Registros de ESA quincena que no vienen en el Excel salen de la vista (soft delete -> Histórico),
 *    excepto los restaurados a mano desde Histórico (conservar_en_cargas = true).
 *  - Otras quincenas nunca se tocan.
 */
class EstrategiaReemplazar extends EstrategiaBase
{
    public function previsualizar(Collection $filas, ?Periodo $periodo): array
    {
        $nuevos = collect();
        $restaurados = collect();
        $actualizados = 0;
        $ids = [];
        foreach ($filas as $f) {
            $reg = $this->buscarRegistro($this->buscarEmpleado($f), $periodo);
            match (true) {
                $reg === null => $nuevos->push($f),
                $reg->trashed() => $restaurados->push($f),
                default => $actualizados++,
            };
            if ($reg) {
                $ids[] = $reg->id;
            }
        }
        $aEliminar = $periodo ? $this->ausentes($periodo, $ids)->load('empleado') : collect();

        return [
            'nuevos' => $nuevos->count(), 'actualizados' => $actualizados, 'restaurados' => $restaurados->count(),
            'eliminados' => $aEliminar->count(), 'omitidos' => 0,
            'detalle' => ['nuevos' => $this->nombres($nuevos), 'restaurados' => $this->nombres($restaurados), 'eliminados' => $this->nombres($aEliminar)],
        ];
    }

    public function aplicar(Carga $carga, Collection $filas, Periodo $periodo, int $userId): array
    {
        $res = ['nuevos' => 0, 'actualizados' => 0, 'restaurados' => 0, 'eliminados' => 0, 'omitidos' => 0];
        $ids = [];

        /** @var CargaFila $f */
        foreach ($filas as $f) {
            $emp = $this->buscarEmpleado($f) ?? $this->crearEmpleado($f->datos);

            $reg = $this->buscarRegistro($emp, $periodo);
            if ($reg === null) {
                $res['nuevos']++;
            } elseif ($reg->trashed()) {
                $reg->restore();
                $reg->forceFill(['motivo_baja' => null, 'deleted_by' => null])->saveQuietly();
                $res['restaurados']++;
            } else {
                $res['actualizados']++;
            }
            $ids[] = $this->guardarRegistro($emp, $periodo, $carga, $f, $reg, respetarEditados: false)->id;
        }

        foreach ($this->ausentes($periodo, $ids) as $reg) {
            $reg->forceFill(['deleted_by' => $userId, 'motivo_baja' => 'REEMPLAZO_CARGA'])->saveQuietly();
            $reg->delete();
            $res['eliminados']++;
        }

        return $res;
    }

    private function ausentes(Periodo $periodo, array $idsPresentes): Collection
    {
        return Registro::query()->where('periodo_id', $periodo->id)
            ->whereNotIn('id', $idsPresentes)->where('conservar_en_cargas', false)->get();
    }
}
