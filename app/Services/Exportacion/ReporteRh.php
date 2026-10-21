<?php

namespace App\Services\Exportacion;

use App\Models\Periodo;
use App\Repositories\Contracts\RegistroRepository;
use App\Repositories\FiltrosTabla;
use App\Services\Registros\PresentadorFila;

/**
 * El reporte con la MISMA ESTRUCTURA del archivo de RH (v6), para Excel, PDF e impresión:
 *
 *     Tipo Periodo:  Quincenal      ┌──────────────────────────────────┐
 *     Ejercicio:     2026           │ Incidencias para pago de nomina  │
 *     Periodo:       17             └──────────────────────────────────┘
 *     #  | Nombre Completo | PUESTO | F. DE INGRESO |   | PRIMA VACACIONAL | ... | 1 de Octubre AL 13 de Octubre
 *
 * Las columnas salen en el orden y con el texto que traía el último archivo aplicado de esa quincena
 * (periodos.formato). Una quincena capturada sin archivo usa el catálogo de columnas.
 * Se respetan los filtros de la tabla: se exporta o imprime lo que se ve.
 */
class ReporteRh
{
    public const TITULO_PREDETERMINADO = 'Incidencias para pago de nómina';

    public function __construct(
        private readonly RegistroRepository $repo,
        private readonly PresentadorFila $presentador,
    ) {}

    /**
     * @return array{
     *   encabezado: array{tipo:?string, ejercicio:?int, numero:?int, titulo:string, periodo:string},
     *   columnas: list<array{clave:?string, etiqueta:string, tipo:string}>,
     *   filas: list<list<mixed>>,
     *   col_faltas: ?int
     * }
     */
    public function construir(Periodo $periodo, FiltrosTabla $filtros): array
    {
        $columnas = $this->columnas($periodo);
        $conClave = array_values(array_filter($columnas, fn ($c) => $c['clave'] !== null));

        $filas = $this->repo->paraExportar($filtros)->map(function ($r) use ($columnas, $conClave) {
            $v = $this->presentador->valores($r, $conClave);

            return array_map(fn ($c) => $c['clave'] === null ? null : $this->presentador->formatear($v[$c['clave']], $c['tipo']), $columnas);
        })->all();

        $idx = array_search('num_faltas', array_column($columnas, 'clave'), true);

        return [
            'encabezado' => [
                'tipo' => $periodo->tipo,
                'ejercicio' => $periodo->ejercicio ?? (int) $periodo->fecha_fin->format('Y'),
                'numero' => $periodo->numero,
                'titulo' => $periodo->formato['titulo'] ?? self::TITULO_PREDETERMINADO,
                'periodo' => $periodo->nombre(),
            ],
            'columnas' => $columnas,
            'filas' => $filas,
            'col_faltas' => $idx === false ? null : $idx,
        ];
    }

    /** @return list<array{clave:?string, etiqueta:string, tipo:string}> */
    public function columnas(Periodo $periodo): array
    {
        $visibles = $this->presentador->columnas($periodo);
        $formato = $periodo->formato['columnas'] ?? [];
        if ($formato === []) {
            return array_map(fn ($c) => ['clave' => $c['clave'], 'etiqueta' => $c['etiqueta'], 'tipo' => $c['tipo']], $visibles);
        }

        $todas = collect($this->presentador->columnas($periodo, false))->keyBy('clave');
        $salida = [];
        $usadas = [];
        foreach ($formato as $f) {
            if ($f['clave'] === null) {
                $salida[] = ['clave' => null, 'etiqueta' => $f['etiqueta'] ?? '', 'tipo' => 'texto'];
            } elseif ($todas->has($f['clave'])) {
                $c = $todas->get($f['clave']);
                $salida[] = ['clave' => $c['clave'], 'etiqueta' => $f['etiqueta'] !== '' ? $f['etiqueta'] : $c['etiqueta'], 'tipo' => $c['tipo']];
                $usadas[$c['clave']] = true;
            }
        }

        // Columnas que RH agregó en la plataforma después de la carga: al final
        foreach ($visibles as $c) {
            if (! isset($usadas[$c['clave']]) && $c['origen'] === 'personalizada') {
                $salida[] = ['clave' => $c['clave'], 'etiqueta' => $c['etiqueta'], 'tipo' => $c['tipo']];
            }
        }

        return $salida;
    }
}
