<?php

namespace App\Services\Etl;

use App\Exceptions\ReglaNegocioException;
use App\Services\Etl\Dto\HojaExtraida;
use App\Services\Etl\Dto\Mensaje;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * EXTRACCIÓN: abre el archivo de verdad (no basta la extensión) y elige la hoja correcta.
 *  - Un .docx renombrado a .xlsx o un .odt fallan aquí con "Archivo incorrecto".
 *  - En libros con varias hojas solo se lee la hoja del reporte (SelectorHoja); las demás
 *    se ignoran y nunca llegan a la base de datos.
 *  - Logotipos e imágenes no se leen (setReadDataOnly): no ocupan celdas y no afectan la carga.
 *  - Un título o bloque de datos arriba de la tabla se respeta: los encabezados se buscan en las
 *    primeras 50 filas y lo de arriba se entrega como filasPrevias (Ejercicio, Periodo).
 */
class ExcelExtractor
{
    /**
     * @param  list<array>|null  $catalogo  columnas activas (para reconocer la hoja)
     * @return array{0: ?HojaExtraida, 1: list<Mensaje>, 2: list<string>}  [hoja, avisos/errores, nombres de hojas visibles]
     */
    public function extraer(string $ruta, ?array $catalogo = null, ?string $hojaSolicitada = null): array
    {
        try {
            $tipo = IOFactory::identify($ruta);
            if (! in_array($tipo, ['Xlsx', 'Xls'], true)) {
                throw new \RuntimeException("Tipo {$tipo}");
            }
            $reader = IOFactory::createReader($tipo);
            $reader->setReadDataOnly(true);   // sin estilos ni gráficas: más rápido y seguro
            if ($reader instanceof Xlsx) {
                $reader->setReadEmptyCells(false);
            }
            $libro = $reader->load($ruta);    // se cargan todas las hojas: una fórmula puede apuntar a otra
        } catch (Throwable) {
            throw new ReglaNegocioException('Archivo incorrecto: selecciona un archivo de Excel válido (.xlsx). No se aceptan Word, PowerPoint ni .odt.', 422, ['codigo' => 'ARCHIVO_INCORRECTO']);
        }

        $hojas = [];
        foreach ($libro->getWorksheetIterator() as $ws) {
            $hojas[] = [
                'nombre' => $ws->getTitle(),
                'visible' => $ws->getSheetState() === Worksheet::SHEETSTATE_VISIBLE,
                'filas' => $this->primerasFilas($ws, SelectorHoja::FILAS_A_REVISAR),
                'ws' => $ws,
            ];
        }
        $nombresVisibles = array_values(array_map(fn ($h) => $h['nombre'], array_filter($hojas, fn ($h) => $h['visible'])));

        $sel = (new SelectorHoja(new HeaderMapper($catalogo)))->elegir($hojas, $hojaSolicitada);
        if ($sel['indice'] === null) {
            return [null, $sel['mensajes'], $nombresVisibles];
        }

        /** @var Worksheet $hoja */
        $hoja = $hojas[$sel['indice']]['ws'];
        $filaEnc = $sel['filaEncabezado'];
        $ultimaCol = Coordinate::columnIndexFromString($hoja->getHighestDataColumn());
        $ultimaFila = $hoja->getHighestDataRow();

        $encabezados = [];
        for ($c = 1; $c <= $ultimaCol; $c++) {
            $encabezados[Coordinate::stringFromColumnIndex($c)] = $this->valor($hoja, $c, $filaEnc);
        }

        $filas = [];
        for ($r = $filaEnc + 1; $r <= $ultimaFila; $r++) {
            $celdas = [];
            $vacia = true;
            foreach (array_keys($encabezados) as $i => $col) {
                $v = $this->valor($hoja, $i + 1, $r);
                $celdas[$col] = $v;
                if ($v !== null && trim((string) $v) !== '') {
                    $vacia = false;
                }
            }
            if (! $vacia) {
                $filas[] = ['fila' => $r, 'celdas' => $celdas];
            }
        }

        // Lo que está arriba de los encabezados: "Tipo Periodo: Quincenal", "Ejercicio: 2026", "Periodo: 17"
        $previas = array_slice($hojas[$sel['indice']]['filas'], 0, $filaEnc - 1);

        $libro->disconnectWorksheets();   // libera memoria

        return [new HojaExtraida($encabezados, $filas, $filaEnc, $hoja->getTitle(), $previas), $sel['mensajes'], $nombresVisibles];
    }

    private function primerasFilas(Worksheet $ws, int $n): array
    {
        $ultimaCol = min(Coordinate::columnIndexFromString($ws->getHighestDataColumn()), 60);
        $ultimaFila = min($ws->getHighestDataRow(), $n);
        $filas = [];
        for ($r = 1; $r <= $ultimaFila; $r++) {
            $fila = [];
            for ($c = 1; $c <= $ultimaCol; $c++) {
                $fila[] = $this->valor($ws, $c, $r);
            }
            $filas[] = $fila;
        }

        return $filas;
    }

    private function valor(Worksheet $hoja, int $col, int $fila): mixed
    {
        $celda = $hoja->getCell([$col, $fila]);
        $v = $celda->getValue();
        if ($v instanceof RichText) {
            return $v->getPlainText();
        }
        if (is_string($v) && str_starts_with($v, '=')) {
            try {
                return $celda->getCalculatedValue();
            } catch (Throwable) {
                return $celda->getOldCalculatedValue();   // valor que Excel guardó al último cálculo
            }
        }

        return $v;
    }
}
