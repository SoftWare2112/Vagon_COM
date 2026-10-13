<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** Excel con la misma estructura y encabezado verde del archivo de RH + semáforo en faltas. */
class RegistrosExport implements FromArray, ShouldAutoSize, WithEvents, WithTitle
{
    /**
     * @param  list<string>  $encabezados
     * @param  list<list<mixed>>  $filas
     * @param  int|null  $colFaltas  índice (0-based) de la columna de faltas, si está visible
     */
    public function __construct(private readonly array $encabezados, private readonly array $filas, private readonly ?int $colFaltas) {}

    public function array(): array
    {
        return [$this->encabezados, ...$this->filas];
    }

    public function title(): string
    {
        return 'Reporte';
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $ev) {
            $hoja = $ev->sheet->getDelegate();
            $ultimaCol = Coordinate::stringFromColumnIndex(count($this->encabezados));
            $ultimaFila = count($this->filas) + 1;

            $hoja->getStyle("A1:{$ultimaCol}1")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'A9DFBF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $hoja->getStyle("A1:{$ultimaCol}{$ultimaFila}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $hoja->freezePane('C2');

            if ($this->colFaltas !== null) {
                $col = Coordinate::stringFromColumnIndex($this->colFaltas + 1);
                for ($r = 2; $r <= $ultimaFila; $r++) {
                    $faltas = (int) $hoja->getCell("{$col}{$r}")->getValue();
                    if ($faltas >= 1) {
                        $hoja->getStyle("{$col}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setRGB($faltas === 1 ? 'FFF4C2' : 'FDE0E0');
                    }
                }
            }
        }];
    }
}
