<?php

namespace App\Services\Etl;

use App\Models\Carga;
use App\Support\GuiaMensajes;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * v7 — "Descargar errores en Excel": devuelve EL MISMO archivo que subió RH, con cada celda
 * con problema pintada (rojo = error, amarillo = revisar) y un comentario que dice qué pasa,
 * más una hoja "Errores de carga" con la lista completa y cómo arreglar cada punto.
 * RH corrige ese mismo archivo y lo vuelve a subir.
 */
class ReporteErroresExcel
{
    private const ROJO = 'FFC7CE';

    private const AMARILLO = 'FFEB9C';

    /** @return string ruta de un archivo temporal .xlsx */
    public function generar(Carga $carga): string
    {
        $libro = IOFactory::load(Storage::disk(config('reportes.etl.disco'))->path($carga->ruta_archivo));
        $hoja = ($carga->encabezados['hoja'] ?? null) ? $libro->getSheetByName($carga->encabezados['hoja']) : null;
        $hoja ??= $libro->getActiveSheet();

        $mensajes = $carga->mensajes()->orderByRaw("nivel = 'ERROR' desc")->orderBy('fila')->get();

        foreach ($mensajes as $m) {
            if (! $m->celda || ! preg_match('/^[A-Z]{1,3}\d{1,7}$/', $m->celda)) {
                continue;
            }
            $color = $m->nivel === 'ERROR' ? self::ROJO : self::AMARILLO;
            $hoja->getStyle($m->celda)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
            $comentario = $hoja->getComment($m->celda);
            $comentario->setAuthor('Plataforma de reportes');
            $comentario->getText()->createTextRun($m->mensaje.' ');
            $comentario->setWidth('260pt')->setHeight('90pt');
        }

        // Hoja con la lista completa
        $lista = $libro->createSheet();
        $lista->setTitle('Errores de carga');
        $lista->fromArray(['Nivel', 'Celda', 'Columna', 'Problema', 'Cómo arreglarlo'], null, 'A1');
        $r = 2;
        foreach ($mensajes as $m) {
            $nivel = $m->nivel === 'ERROR' ? 'Error' : 'Revisar';
            $lista->fromArray([$nivel, $m->celda ?? ($m->fila ? "fila {$m->fila}" : ''), $m->columna ?? '', $m->mensaje, GuiaMensajes::de($m->codigo, $m->mensaje)['solucion']], null, "A{$r}");
            $lista->getStyle("A{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($m->nivel === 'ERROR' ? self::ROJO : self::AMARILLO);
            $r++;
        }
        $lista->getStyle('A1:E1')->getFont()->setBold(true);
        foreach (['A' => 10, 'B' => 10, 'C' => 24, 'D' => 80, 'E' => 60] as $col => $ancho) {
            $lista->getColumnDimension($col)->setWidth($ancho);
        }
        $lista->getStyle("D2:E{$r}")->getAlignment()->setWrapText(true);
        $libro->setActiveSheetIndex($libro->getIndex($hoja));

        $ruta = tempnam(sys_get_temp_dir(), 'err').'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();

        return $ruta;
    }
}
