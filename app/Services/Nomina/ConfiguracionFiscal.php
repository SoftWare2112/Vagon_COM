<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Models\ConceptoNomina;
use App\Models\Empresa;
use App\Models\TablaFiscal;
use App\Support\Nomina\ValoresIniciales;

/** v11: lee de la base de datos las tablas fiscales vigentes en una fecha y el catálogo de conceptos. */
class ConfiguracionFiscal
{
    /** @return array<string, array> tipo => datos, vigentes en la fecha */
    public function vigentes(string $fecha): array
    {
        $filas = TablaFiscal::query()->where('vigente_desde', '<=', $fecha)->orderBy('vigente_desde')->get()
            ->map(fn (TablaFiscal $t) => [$t->tipo, $t->vigente_desde->format('Y-m-d'), $t->datos])->all();
        $vigentes = ValoresIniciales::vigentesEn($filas, $fecha);
        $faltan = array_diff(['uma', 'imss_obrero', 'isr_semanal', 'isr_quincenal', 'isr_mensual'], array_keys($vigentes));
        if ($faltan) {
            throw new ReglaNegocioException('Faltan tablas fiscales vigentes al '.date('d/m/Y', strtotime($fecha)).': '.implode(', ', $faltan)
                .'. Agrégalas en Configuración de nómina → Tablas fiscales.', 422, ['codigo' => 'TABLAS_FALTANTES']);
        }

        return $vigentes;
    }

    public function parametros(string $fecha, string $tipoPeriodo, Empresa $empresa): array
    {
        $vigentes = $this->vigentes($fecha);
        $p = ValoresIniciales::parametros($vigentes, $tipoPeriodo);
        if ($empresa->zona_salario_minimo === 'frontera_norte' && isset($vigentes['salario_minimo']['frontera_norte'])) {
            $p['salario_minimo'] = (float) $vigentes['salario_minimo']['frontera_norte'];
        }

        return $p;
    }

    /** @return list<array> conceptos de la empresa en la forma de CalculadoraNomina */
    public function conceptos(Empresa $empresa): array
    {
        return ConceptoNomina::where('empresa_id', $empresa->id)->orderBy('orden')->orderBy('clave')->get()
            ->map(fn (ConceptoNomina $c) => $c->paraCalculo())->all();
    }
}
