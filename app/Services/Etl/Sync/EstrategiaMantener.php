<?php

namespace App\Services\Etl\Sync;

use App\Models\Carga;
use App\Models\CargaFila;
use App\Models\Periodo;
use Illuminate\Support\Collection;

/**
 * MANTENER: lo hecho en la web gana.
 *  - Nuevos del Excel se crean.
 *  - Existentes: por CAMPO, si RH lo editó en la web se conserva; si no, toma el valor del Excel.
 *  - Registros eliminados en la web siguen eliminados aunque vengan en el Excel (omitidos).
 *  - Lo creado en la web y ausente del Excel se conserva.
 */
class EstrategiaMantener extends EstrategiaBase
{
    public function previsualizar(Collection $filas, ?Periodo $periodo): array
    {
        $nuevos = collect();
        $omitidos = collect();
        $actualizados = 0;
        foreach ($filas as $f) {
            $reg = $this->buscarRegistro($this->buscarEmpleado($f), $periodo);
            if ($reg === null) {
                $nuevos->push($f);
            } elseif ($reg->trashed()) {
                $omitidos->push($f);
            } else {
                $actualizados++;
            }
        }

        return [
            'nuevos' => $nuevos->count(), 'actualizados' => $actualizados, 'restaurados' => 0,
            'eliminados' => 0, 'omitidos' => $omitidos->count(),
            'detalle' => ['nuevos' => $this->nombres($nuevos), 'omitidos' => $this->nombres($omitidos)],
        ];
    }

    public function aplicar(Carga $carga, Collection $filas, Periodo $periodo, int $userId): array
    {
        $res = ['nuevos' => 0, 'actualizados' => 0, 'restaurados' => 0, 'eliminados' => 0, 'omitidos' => 0];

        /** @var CargaFila $f */
        foreach ($filas as $f) {
            $emp = $this->buscarEmpleado($f) ?? $this->crearEmpleado($f->datos);
            $reg = $this->buscarRegistro($emp, $periodo);

            if ($reg?->trashed()) {
                $res['omitidos']++;

                continue;
            }
            $reg === null ? $res['nuevos']++ : $res['actualizados']++;
            $this->guardarRegistro($emp, $periodo, $carga, $f, $reg, respetarEditados: true);
        }

        return $res;
    }
}
