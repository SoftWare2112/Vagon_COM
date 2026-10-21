<?php

namespace App\Services\Exportacion;

use App\Exports\RegistrosExport;
use App\Models\AuditLog;
use App\Models\InformeExportado;
use App\Models\Periodo;
use App\Models\User;
use App\Repositories\FiltrosTabla;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Exporta EXACTAMENTE lo que se ve (mismos filtros) con la ESTRUCTURA del archivo de RH
 * (bloque Tipo Periodo / Ejercicio / Periodo, título y encabezados originales; ver ReporteRh)
 * y lo registra en "Informes".
 */
class ExportacionService
{
    public function __construct(private readonly ReporteRh $reporte) {}

    public function generar(string $formato, FiltrosTabla $filtros, User $usuario): InformeExportado
    {
        $periodo = Periodo::findOrFail($filtros->periodoId);
        $datos = $this->reporte->construir($periodo, $filtros);

        $base = $periodo->numero
            ? "incidencias_P{$periodo->numero}_{$datos['encabezado']['ejercicio']}_".now()->format('His')
            : 'reporte_'.$periodo->fecha_inicio->format('Ymd').'_'.$periodo->fecha_fin->format('Ymd').'_'.now()->format('His');
        $ruta = 'exportaciones/'.$base.($formato === 'PDF' ? '.pdf' : '.xlsx');

        if ($formato === 'PDF') {
            $pdf = Pdf::loadView('pdf.registros', [
                ...$datos, 'generado' => now()->format('d/m/Y H:i'), 'usuario' => $usuario->name,
            ])->setPaper('letter', 'landscape');
            Storage::disk('local')->put($ruta, $pdf->output());
        } else {
            Excel::store(new RegistrosExport($datos), $ruta, 'local');
        }

        $informe = InformeExportado::create([
            'user_id' => $usuario->id, 'periodo_id' => $periodo->id, 'formato' => $formato,
            'nombre' => basename($ruta), 'ruta_archivo' => $ruta, 'filtros' => $filtros->toArray(), 'filas' => count($datos['filas']),
        ]);
        AuditLog::registrar('EXPORTAR', 'InformeExportado', $informe->id, null, ['formato' => $formato, 'filas' => $informe->filas]);

        return $informe;
    }
}
