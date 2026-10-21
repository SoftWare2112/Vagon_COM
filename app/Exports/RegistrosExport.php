<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Excel con la MISMA estructura del archivo de RH (v6):
 *   filas 1-3  Tipo Periodo / Ejercicio / Periodo + título combinado
 *   fila 4     encabezados con su texto original
 *   fila 5+    datos
 * Así el archivo exportado se puede volver a subir a la plataforma tal cual.
 */
class RegistrosExport implements FromArray, WithEvents, WithStrictNullComparison, WithTitle
{
    private const FILA_ENCABEZADOS = 4;

    /** @param array $datos  salida de ReporteRh::construir() */
    public function __construct(private readonly array $datos) {}

    public function array(): array
    {
        $e = $this->datos['encabezado'];
        $n = max(count($this->datos['columnas']), 4);
        $fila = fn (array $celdas) => array_replace(array_fill(0, $n, null), $celdas);
        $colTitulo = $this->colTitulo() - 1;

        return [
            $fila([0 => 'Tipo Periodo:', 1 => $e['tipo'], $colTitulo => $e['titulo']]),
            $fila([0 => 'Ejercicio:', 1 => $e['ejercicio']]),
            $fila([0 => 'Periodo:', 1 => $e['numero']]),
            array_column($this->datos['columnas'], 'etiqueta'),
            ...$this->datos['filas'],
        ];
    }

    public function title(): string
    {
        return 'Hoja1';
    }

    /** El título va a la derecha del bloque de datos, como en el archivo de RH (D1:…3). */
    private function colTitulo(): int
    {
        return count($this->datos['columnas']) >= 6 ? 4 : 3;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $ev) {
            $hoja = $ev->sheet->getDelegate();
            $totalCols = max(count($this->datos['columnas']), 4);
            $ultimaCol = Coordinate::stringFromColumnIndex($totalCols);
            $f0 = self::FILA_ENCABEZADOS;
            $ultimaFila = $f0 + count($this->datos['filas']);
            $colT = Coordinate::stringFromColumnIndex($this->colTitulo());

            // Bloque Tipo Periodo / Ejercicio / Periodo
            $hoja->getStyle('A1:A3')->getFont()->setBold(true);
            $hoja->getStyle('A1:A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $hoja->getStyle('B1:B3')->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF99']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            ]);

            // Título combinado
            $hoja->mergeCells("{$colT}1:{$ultimaCol}3");
            $hoja->getStyle("{$colT}1:{$ultimaCol}3")->applyFromArray([
                'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1BA1E2']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);

            // Encabezados y tabla
            $hoja->getStyle("A{$f0}:{$ultimaCol}{$f0}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'A9DFBF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $hoja->getRowDimension($f0)->setRowHeight(32);
            $hoja->getStyle("A{$f0}:{$ultimaCol}{$ultimaFila}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $hoja->freezePane('A'.($f0 + 1));

            foreach ($this->datos['columnas'] as $i => $c) {
                $letra = Coordinate::stringFromColumnIndex($i + 1);
                $ancho = match (true) {
                    in_array($c['clave'], ['nombre_completo', 'detalle'], true) => 30,
                    $c['clave'] === null => 4,
                    default => max(10, min(22, mb_strlen($c['etiqueta']) + 2)),
                };
                $hoja->getColumnDimension($letra)->setWidth($ancho);
                if ($ultimaFila > $f0 && ! in_array($c['tipo'], ['texto', 'texto_largo'], true)) {
                    $hoja->getStyle("{$letra}".($f0 + 1).":{$letra}{$ultimaFila}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }
            }

            // Semáforo de faltas (1 = amarillo, 2+ = rojo), igual que en la web
            if ($this->datos['col_faltas'] !== null) {
                $col = Coordinate::stringFromColumnIndex($this->datos['col_faltas'] + 1);
                for ($r = $f0 + 1; $r <= $ultimaFila; $r++) {
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
