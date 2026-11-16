<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Support\Nomina\CalculadoraNomina;

/**
 * v11: "¿Cuánto le toca?" — calcula la nómina de un empleado para un periodo sin guardar nada.
 * La v12 usa este mismo motor para calcular y guardar la nómina completa del periodo.
 */
class SimuladorNomina
{
    public function __construct(
        private readonly ConfiguracionFiscal $config,
        private readonly PeriodoService $periodos,
    ) {}

    /** @param array{fecha?: string, faltas?: int, retardos?: int, domingos?: int} $opciones */
    public function simular(Empleado $e, array $opciones = []): array
    {
        if (! $e->salario_mensual) {
            throw new ReglaNegocioException('Captura el salario mensual del empleado para poder calcular su nómina.', 422, ['codigo' => 'SIN_SALARIO']);
        }
        $empresa = $e->empresa ?? Empresa::actual();
        $tipo = $e->periodicidad ?: $empresa->periodicidad;
        $periodo = $this->periodos->enFecha($empresa, $tipo, $opciones['fecha'] ?? now()->toDateString());

        $r = CalculadoraNomina::calcular([
            'salario_mensual' => (float) $e->salario_mensual, 'divisor' => (float) $empresa->divisor_salario_diario,
            'tipo_periodo' => $tipo, 'dias_periodo' => $periodo['dias'], 'fecha_ingreso' => $e->fecha_ingreso->format('Y-m-d'),
            'inicio' => $periodo['inicio'], 'fin' => $periodo['fin'], 'es_fin_de_mes' => $periodo['fin_de_mes'],
            'faltas' => (int) ($opciones['faltas'] ?? 0), 'retardos' => (int) ($opciones['retardos'] ?? 0), 'domingos' => (int) ($opciones['domingos'] ?? 0),
            'conceptos' => $this->config->conceptos($empresa),
            'parametros' => $this->config->parametros($periodo['fin'], $tipo, $empresa),
        ]);

        return [...$r, 'periodo' => [...$periodo, 'tipo' => $tipo]];
    }
}
