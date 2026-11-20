<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Models\Empresa;
use App\Models\NominaRecibo;
use App\Support\Nomina\ReciboHtml;

/**
 * v13 — Recibos en PDF (uno o todos los de un periodo) con dompdf.
 * Requiere:  composer require barryvdh/laravel-dompdf
 */
class RecibosPdf
{
    public function __construct(private readonly NominaService $nomina) {}

    /** @param iterable<NominaRecibo> $recibos */
    public function generar(iterable $recibos): string
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new ReglaNegocioException('Falta la librería de PDF. En la carpeta de Laravel ejecuta: composer require barryvdh/laravel-dompdf', 500, ['codigo' => 'SIN_DOMPDF']);
        }
        $html = $this->html($recibos);

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('letter')->setOption('defaultFont', 'DejaVu Sans')->output();
    }

    /** @param iterable<NominaRecibo> $recibos */
    public function html(iterable $recibos): string
    {
        $datos = [];
        foreach ($recibos as $r) {
            $datos[] = $this->nomina->reciboJson($r);
        }
        $empresa = Empresa::actual()->only(['nombre', 'razon_social', 'rfc', 'regimen_fiscal', 'registro_patronal', 'codigo_postal']);

        return ReciboHtml::documento($datos, $empresa);
    }

    public static function nombreArchivo(NominaRecibo $r): string
    {
        $r->loadMissing('periodo');

        return 'recibo-'.$r->numero_empleado.'-'.$r->ejercicio.'-'.($r->periodo->especial() ? 'aguinaldo' : str_pad((string) $r->periodo->numero, 2, '0', STR_PAD_LEFT)).'.pdf';
    }
}
