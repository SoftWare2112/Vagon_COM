<?php

namespace App\Services\Exportacion;

use App\Exports\RegistrosExport;
use App\Models\AuditLog;
use App\Models\InformeExportado;
use App\Models\Periodo;
use App\Models\User;
use App\Repositories\Contracts\RegistroRepository;
use App\Repositories\FiltrosTabla;
use App\Services\Registros\PresentadorFila;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Exporta EXACTAMENTE lo que se ve (mismos filtros, mismas columnas, mismo orden)
 * y lo registra en "Informes".
 */
class ExportacionService
{
    public function __construct(
        private readonly RegistroRepository $repo,
        private readonly PresentadorFila $presentador,
    ) {}

    public function generar(string $formato, FiltrosTabla $filtros, User $usuario): InformeExportado
    {
        $periodo = Periodo::findOrFail($filtros->periodoId);
        $registros = $this->repo->paraExportar($filtros);
        $columnas = $this->presentador->columnas($periodo);

        $encabezados = ['No. Empleado', ...array_column($columnas, 'etiqueta')];
        $filas = $registros->map(function ($r) use ($columnas) {
            $v = $this->presentador->valores($r, $columnas);

            return [$r->empleado->numero_empleado, ...array_map(fn ($c) => $this->presentador->formatear($v[$c['clave']], $c['tipo']), $columnas)];
        })->all();
        $idx = array_search('num_faltas', array_column($columnas, 'clave'), true);
        $colFaltas = $idx === false ? null : $idx + 1;   // +1 por "No. Empleado"

        $base = 'reporte_'.$periodo->fecha_inicio->format('Ymd').'_'.$periodo->fecha_fin->format('Ymd').'_'.now()->format('His');
        $ruta = 'exportaciones/'.$base.($formato === 'PDF' ? '.pdf' : '.xlsx');

        if ($formato === 'PDF') {
            $pdf = Pdf::loadView('pdf.registros', [
                'periodo' => $periodo, 'encabezados' => $encabezados, 'filas' => $filas, 'colFaltas' => $colFaltas,
                'generado' => now()->format('d/m/Y H:i'), 'usuario' => $usuario->name,
            ])->setPaper('letter', 'landscape');
            Storage::disk('local')->put($ruta, $pdf->output());
        } else {
            Excel::store(new RegistrosExport($encabezados, $filas, $colFaltas), $ruta, 'local');
        }

        $informe = InformeExportado::create([
            'user_id' => $usuario->id, 'periodo_id' => $periodo->id, 'formato' => $formato,
            'nombre' => basename($ruta), 'ruta_archivo' => $ruta, 'filtros' => $filtros->toArray(), 'filas' => count($filas),
        ]);
        AuditLog::registrar('EXPORTAR', 'InformeExportado', $informe->id, null, ['formato' => $formato, 'filas' => $informe->filas]);

        return $informe;
    }
}
