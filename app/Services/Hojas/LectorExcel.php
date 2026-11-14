<?php

namespace App\Services\Hojas;

use App\Exceptions\ReglaNegocioException;
use App\Support\Hojas\Importacion\TraductorExcel;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * v9 — EXTRACCIÓN de un Excel con CUALQUIER estructura (capa de infraestructura).
 *
 * A diferencia del ETL de nómina (que busca una tabla con encabezados), aquí se lee TODO lo visible:
 * cada pestaña con sus celdas (valor, fórmula, último resultado, formato de número y estilo), celdas
 * combinadas, anchos de columna, altos de fila e imágenes. Lo entrega en la estructura intermedia que
 * entiende TraductorExcel (lógica pura, con pruebas).
 *
 * No se importan: gráficas de Excel, macros, comentarios, validaciones de datos ni formato condicional.
 */
class LectorExcel
{
    public const MAX_PESTANAS = 20;

    public const MAX_IMAGENES = 50;

    private const MAX_BYTES_IMAGEN = 5 * 1024 * 1024;

    private const MIMES_IMAGEN = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];

    /** @return array{pestanas: list<array>, avisos: list<string>} */
    public function leer(string $ruta): array
    {
        try {
            $tipo = IOFactory::identify($ruta);
            if (! in_array($tipo, ['Xlsx', 'Xls', 'Ods'], true)) {
                throw new \RuntimeException("Tipo {$tipo}");
            }
            $lector = IOFactory::createReader($tipo);
            $lector->setReadDataOnly(false);   // se necesitan estilos, combinadas e imágenes
            $libro = $lector->load($ruta);
        } catch (Throwable) {
            throw new ReglaNegocioException('Archivo incorrecto: selecciona un archivo de Excel válido (.xlsx o .xls).', 422, ['codigo' => 'ARCHIVO_INCORRECTO']);
        }

        $pestanas = $avisos = [];
        $ocultas = $imagenes = 0;
        foreach ($libro->getWorksheetIterator() as $ws) {
            if ($ws->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE) {
                $ocultas++;

                continue;
            }
            if (count($pestanas) >= self::MAX_PESTANAS) {
                $avisos[] = 'El libro tiene más de '.self::MAX_PESTANAS.' pestañas visibles; solo se importaron las primeras '.self::MAX_PESTANAS.'.';
                break;
            }
            $pestana = $this->pestana($ws, $imagenes);
            $imagenes += count($pestana['imagenes']);
            $pestanas[] = $pestana;
            if (count($ws->getChartCollection()) > 0) {
                $avisos[] = "«{$ws->getTitle()}»: las gráficas de Excel no se importan (los datos sí).";
            }
        }
        if ($ocultas > 0) {
            $avisos[] = "{$ocultas} pestaña(s) ocultas no se importaron.";
        }
        $libro->disconnectWorksheets();

        if ($pestanas === []) {
            throw new ReglaNegocioException('El archivo no tiene pestañas visibles para importar.', 422, ['codigo' => 'LIBRO_VACIO']);
        }

        return ['pestanas' => $pestanas, 'avisos' => $avisos];
    }

    private function pestana(Worksheet $ws, int $imagenesPrevias): array
    {
        $celdas = [];
        $coleccion = $ws->getCellCollection();
        foreach ($coleccion->getCoordinates() as $coord) {
            [$col, $fila] = Coordinate::coordinateFromString($coord);
            $c = Coordinate::columnIndexFromString($col) - 1;
            $f = (int) $fila - 1;
            if ($f >= TraductorExcel::MAX_FILAS || $c >= TraductorExcel::MAX_COLUMNAS) {
                if ($ws->getCell($coord)->getValue() !== null) {
                    $celdas[] = [$f, $c, ['tipo' => 'null']];   // con dato: el traductor la cuenta como "fuera"
                }

                continue;
            }
            $celdas[] = [$f, $c, $this->celda($ws, $coord)];
        }

        $combinadas = [];
        foreach (array_keys($ws->getMergeCells()) as $rango) {
            [[$c1, $f1], [$c2, $f2]] = Coordinate::rangeBoundaries($rango);
            $combinadas[] = [$f1 - 1, $c1 - 1, $f2 - 1, $c2 - 1];
        }

        $anchos = [];
        foreach ($ws->getColumnDimensions() as $letra => $dim) {
            $w = $dim->getWidth();
            if ($w > 0) {
                $anchos[Coordinate::columnIndexFromString($letra) - 1] = $dim->getVisible() ? $this->anchoPx($w) : 30;
            }
        }
        $defecto = $ws->getDefaultColumnDimension()->getWidth();

        $altos = [];
        foreach ($ws->getRowDimensions() as $n => $dim) {
            $h = $dim->getRowHeight();
            if ($h > 0) {
                $altos[(int) $n - 1] = $h * 4 / 3;   // puntos -> píxeles
            }
        }

        return [
            'nombre' => mb_substr($ws->getTitle(), 0, 60),
            'ancho_defecto' => $defecto > 0 ? $this->anchoPx($defecto) : 80,
            'anchos' => $anchos,
            'altos' => $altos,
            'celdas' => $celdas,
            'combinadas' => $combinadas,
            'imagenes' => $this->imagenes($ws, $imagenesPrevias),
        ];
    }

    private function celda(Worksheet $ws, string $coord): array
    {
        $celda = $ws->getCell($coord);
        $valor = $celda->getValue();
        $tipo = $celda->getDataType();
        if ($valor instanceof RichText) {
            $valor = $valor->getPlainText();
            $tipo = DataType::TYPE_STRING;
        }
        $estilo = $ws->getStyle($coord);
        $dato = [
            'tipo' => match ($tipo) {
                DataType::TYPE_FORMULA => 'f',
                DataType::TYPE_NUMERIC => 'n',
                DataType::TYPE_BOOL => 'b',
                DataType::TYPE_ERROR => 'e',
                DataType::TYPE_NULL => 'null',
                default => 's',
            },
            'valor' => $valor,
            'formato' => $estilo->getNumberFormat()->getFormatCode(),
            'estilo' => $this->estilo($estilo),
        ];
        if ($dato['tipo'] === 'f') {
            // El último resultado que calculó Excel (se usa si la fórmula no se puede conservar)
            $dato['calculado'] = $celda->getOldCalculatedValue();
            if ($dato['calculado'] === null) {
                try {
                    $dato['calculado'] = $celda->getCalculatedValue();
                } catch (Throwable) {
                    $dato['calculado'] = null;
                }
            }
            if ($dato['calculado'] instanceof RichText) {
                $dato['calculado'] = $dato['calculado']->getPlainText();
            }
        }

        return $dato;
    }

    /** Estilo con las claves cortas de la plataforma (n, i, s, c, f, a, w). */
    private function estilo(\PhpOffice\PhpSpreadsheet\Style\Style $s): array
    {
        $e = [];
        $fuente = $s->getFont();
        if ($fuente->getBold()) {
            $e['n'] = true;
        }
        if ($fuente->getItalic()) {
            $e['i'] = true;
        }
        if ($fuente->getUnderline() && $fuente->getUnderline() !== Font::UNDERLINE_NONE) {
            $e['s'] = true;
        }
        $color = $this->color($fuente->getColor()->getRGB());
        if ($color && $color !== '#000000') {
            $e['c'] = $color;
        }
        $relleno = $s->getFill();
        if ($relleno->getFillType() === Fill::FILL_SOLID) {
            $fondo = $this->color($relleno->getStartColor()->getRGB());
            if ($fondo && $fondo !== '#FFFFFF') {
                $e['f'] = $fondo;
            }
        }
        $alineacion = $s->getAlignment();
        $e['a'] = match ($alineacion->getHorizontal()) {
            Alignment::HORIZONTAL_LEFT => 'izq',
            Alignment::HORIZONTAL_CENTER, Alignment::HORIZONTAL_CENTER_CONTINUOUS => 'centro',
            Alignment::HORIZONTAL_RIGHT => 'der',
            default => null,
        };
        if ($alineacion->getWrapText()) {
            $e['w'] = true;
        }

        return array_filter($e, fn ($v) => $v !== null);
    }

    private function color(?string $rgb): ?string
    {
        return $rgb && preg_match('/^[0-9A-Fa-f]{6}$/', $rgb) ? '#'.strtoupper($rgb) : null;
    }

    /** Imágenes ancladas a una celda, con su contenido (se guardan en disco al crear el libro). */
    private function imagenes(Worksheet $ws, int $previas): array
    {
        $out = [];
        foreach ($ws->getDrawingCollection() as $d) {
            if ($previas + count($out) >= self::MAX_IMAGENES) {
                break;
            }
            try {
                [$contenido, $mime] = match (true) {
                    $d instanceof MemoryDrawing => $this->desdeMemoria($d),
                    $d instanceof Drawing => $this->desdeArchivo($d),
                    default => [null, null],
                };
            } catch (Throwable) {
                continue;
            }
            if ($contenido === null || strlen($contenido) > self::MAX_BYTES_IMAGEN || ! isset(self::MIMES_IMAGEN[$mime])) {
                continue;
            }
            [$col, $fila] = Coordinate::coordinateFromString($d->getCoordinates());
            $out[] = [(int) $fila - 1, Coordinate::columnIndexFromString($col) - 1, [
                'x' => $d->getOffsetX(), 'y' => $d->getOffsetY(), 'w' => $d->getWidth(), 'h' => $d->getHeight(),
                'contenido' => $contenido, 'mime' => $mime, 'nombre' => mb_substr((string) ($d->getName() ?: 'imagen'), 0, 120),
            ]];
        }

        return $out;
    }

    private function desdeArchivo(Drawing $d): array
    {
        $contenido = @file_get_contents($d->getPath());   // zip://archivo.xlsx#xl/media/image1.png
        if ($contenido === false) {
            return [null, null];
        }

        return [$contenido, (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido) ?: null];
    }

    private function desdeMemoria(MemoryDrawing $d): array
    {
        $recurso = $d->getImageResource();
        if (! $recurso || ! function_exists('imagepng')) {
            return [null, null];
        }
        ob_start();
        imagepng($recurso);

        return [(string) ob_get_clean(), 'image/png'];
    }

    /** Ancho de columna de Excel (en caracteres) a píxeles, aproximado como lo dibuja Excel. */
    private function anchoPx(float $caracteres): int
    {
        return (int) round($caracteres * 7 + 5);
    }
}
