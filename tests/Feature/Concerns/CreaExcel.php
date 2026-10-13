<?php

namespace Tests\Feature\Concerns;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera archivos de Excel de prueba con el formato confirmado por RH (octubre 2026).
 * Las filas se describen POR NOMBRE DE COLUMNA, así una prueba puede quitar o insertar
 * columnas sin recalcular posiciones.
 */
trait CreaExcel
{
    /** Quincenas de prueba: encabezado del periodo + sus dos domingos. */
    protected array $quincenas = [
        '1Q' => ['1 de octubre al 13 de octubre de 2026', 'Domingo 4', 'Domingo 11'],
        '2Q' => ['14 de octubre al 27 de octubre de 2026', 'Domingo 18', 'Domingo 25'],
    ];

    /** @return list<string> encabezados en el orden del archivo de RH */
    protected function encabezados(string $q = '1Q'): array
    {
        [$periodo, $dom1, $dom2] = $this->quincenas[$q];

        return [
            'Nombre', 'Fecha de ingreso', 'Puesto', 'Prima vacacional', 'Bono 3 meses', 'Bono puntualidad', 'Bono asistencia',
            'Numero de faltas', 'Detalles', 'Acredita para bono puntualidad', 'Programado en Domingo', $dom1, $dom2, $periodo,
        ];
    }

    /**
     * Fila por nombre de columna. Los domingos y el periodo usan las claves DOM1, DOM2 y PERIODO
     * para servir en cualquier quincena.
     */
    protected function fila(string $nombre, int $faltas = 0, array $cambios = []): array
    {
        return array_replace([
            'Nombre' => $nombre, 'Fecha de ingreso' => '15/01/2018', 'Puesto' => 'Asesor', 'Prima vacacional' => '$600.00',
            'Bono 3 meses' => '$600.00', 'Bono puntualidad' => '$500.00', 'Bono asistencia' => '$300.00',
            'Numero de faltas' => $faltas, 'Detalles' => '', 'Acredita para bono puntualidad' => 1,
            'Programado en Domingo' => 1, 'DOM1' => 1, 'DOM2' => 0, 'PERIODO' => '1',
        ], $cambios);
    }

    /** Convierte filas por nombre a la fila de valores en el orden de los encabezados. */
    protected function ordenar(array $encabezados, array $fila): array
    {
        $domingos = 0;

        return array_map(function ($h) use ($fila, &$domingos) {
            if (str_starts_with($h, 'Domingo ')) {
                return $fila['DOM'.(++$domingos)] ?? null;
            }
            if (preg_match('/\bal\b/i', $h) && preg_match('/\d/', $h)) {
                return $fila['PERIODO'] ?? null;
            }

            return $fila[$h] ?? null;
        }, $encabezados);
    }

    /** Libro de una hoja. $encabezados permite simular columnas de más o de menos. */
    protected function excel(array $filas, string $nombre = 'reporte.xlsx', ?array $encabezados = null, string $q = '1Q'): UploadedFile
    {
        $enc = $encabezados ?? $this->encabezados($q);

        return $this->guardar(['Hoja1' => [$enc, ...array_map(fn ($f) => $this->ordenar($enc, $f), $filas)]], $nombre);
    }

    /** Libro con varias hojas: ['Notas' => [[...]], 'Octubre 1Q' => [[encabezados], [valores], ...]] (filas ya ordenadas). */
    protected function excelVariasHojas(array $hojas, string $nombre = 'libro.xlsx'): UploadedFile
    {
        return $this->guardar($hojas, $nombre);
    }

    /** Hoja de reporte lista para excelVariasHojas(). */
    protected function hojaReporte(array $filas, string $q = '1Q'): array
    {
        $enc = $this->encabezados($q);

        return [$enc, ...array_map(fn ($f) => $this->ordenar($enc, $f), $filas)];
    }

    private function guardar(array $hojas, string $nombre): UploadedFile
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);
        foreach ($hojas as $titulo => $filas) {
            $libro->createSheet()->setTitle($titulo)->fromArray($filas, null, 'A1', true);
        }
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, $nombre, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
