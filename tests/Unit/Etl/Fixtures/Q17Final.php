<?php

namespace Tests\Unit\Etl\Fixtures;

use App\Services\Etl\Dto\HojaExtraida;
use App\Services\Etl\HeaderMapper;
use App\Services\Etl\SelectorHoja;

/**
 * Archivo DEFINITIVO de RH (INCIDENCIAS_Q17.xlsx del 20/10/2026), tal como lo entrega ExcelExtractor:
 *  - A1:B3  Tipo Periodo / Ejercicio / Periodo; título combinado D1:O3.
 *  - Fila 4 encabezados; "#" = número de nómina (200…223); E sin título.
 *  - Filas 5-28: 24 empleados, sin numeración (algunos con espacio al inicio).
 * libro() permite MOVER toda la estructura (la tabla puede llegar en otra posición).
 */
final class Q17Final
{
    public const PREVIAS = [
        ['Tipo Periodo:', 'Quincenal', null, 'Incidencias para pago de nomina'],
        ['Ejercicio:', 2026],
        ['Periodo:', 17],
    ];

    public const ENCABEZADOS = [
        'A' => '#', 'B' => 'Nombre Completo', 'C' => 'PUESTO', 'D' => 'F. DE INGRESO', 'E' => null,
        'F' => 'PRIMA VACACIONAL', 'G' => 'BONO 3 MESES', 'H' => 'Bono Puntualidad', 'I' => 'Bono Asistencia',
        'J' => 'Numero de Faltas', 'K' => 'Detalle', 'L' => 'Acredita para bono', 'M' => 'Programado en Domingo',
        'N' => 'Domingo 4', 'O' => 'Domingo 11', 'P' => ' 1 de Octubre AL 13 de Octubre',
    ];

    public const FILAS = [
        ['A' => 200, 'B' => ' Alejandro Martínez Salgado', 'C' => 'SUPERVISOR MSI', 'D' => 45425],
        ['A' => 201, 'B' => ' Valeria Hernández Cruz', 'C' => 'ASESOR MSI', 'D' => 45434, 'N' => 1],
        ['A' => 202, 'B' => 'Diego Ramírez Navarro', 'C' => 'ASESOR MSI', 'D' => 45505],
        ['A' => 203, 'B' => 'Camila Torres Mendoza', 'C' => 'ASESOR MSI', 'D' => 45544, 'F' => 1, 'N' => 1, 'O' => 1],
        ['A' => 204, 'B' => 'Sebastián Flores Ríos', 'C' => 'ASESOR MSI', 'D' => 45600, 'O' => 1],
        ['A' => 205, 'B' => 'Natalia Vargas Castillo', 'C' => 'ASESOR MSI', 'D' => 45649, 'J' => 1, 'O' => 1],
        ['A' => 206, 'B' => 'Emiliano Reyes Fuentes', 'C' => 'ASESOR MSI', 'D' => 45677, 'N' => 1],
        ['A' => 207, 'B' => 'Renata Morales Pineda', 'C' => 'RECURSOS HUMANOS ', 'D' => 45896, 'F' => 1],
        ['A' => 208, 'B' => 'Leonardo Sánchez Duarte', 'C' => 'ASESOR MSI', 'D' => 45938],
        ['A' => 209, 'B' => 'Mariana Ortega Silva', 'C' => 'ASESOR MSI', 'D' => 45993, 'N' => 1, 'O' => 1],
        ['A' => 210, 'B' => 'Santiago Cabrera León', 'C' => 'ASESOR MSI', 'D' => 46000, 'J' => 1],
        ['A' => 211, 'B' => 'Fernanda Medina Lozano', 'C' => 'SUPERVISOR MSI', 'D' => 46041, 'J' => 1],
        ['A' => 212, 'B' => 'Mateo González Vera', 'C' => 'ASESOR MSI', 'D' => 46069, 'O' => 1],
        ['A' => 213, 'B' => ' Regina Castro Méndez', 'C' => 'ASESOR MSI', 'D' => 46141, 'N' => 1, 'O' => 1],
        ['A' => 214, 'B' => 'Daniel Espinoza Lara', 'C' => 'ASESOR MSI', 'D' => 46164, 'N' => 1, 'O' => 1],
        ['A' => 215, 'B' => 'Sofía Domínguez Arias', 'C' => 'ASESOR MSI', 'D' => 46198, 'N' => 1],
        ['A' => 216, 'B' => ' Adrián Herrera Campos', 'C' => 'ASESOR MSI', 'D' => 46199, 'O' => 1],
        ['A' => 217, 'B' => 'Ximena Romero Valdez', 'C' => 'ASESOR MSI', 'D' => 46213, 'O' => 1],
        ['A' => 218, 'B' => 'Gabriel Jiménez Rocha', 'C' => 'ASESOR MSI', 'D' => 46218, 'J' => 3, 'O' => 1],
        ['A' => 219, 'B' => 'Paulina Núñez Serrano', 'C' => 'ASESOR MSI', 'D' => 46227, 'N' => 1, 'O' => 1],
        ['A' => 220, 'B' => 'Rodrigo Salazar Vega', 'C' => 'ASESOR MSI', 'D' => 46239],
        ['A' => 221, 'B' => 'Andrea Cortés Molina', 'C' => 'ASESOR MSI', 'D' => 46255],
        ['A' => 222, 'B' => 'Mauricio Delgado Reyes', 'C' => 'ASESOR MSI', 'D' => 46260],
        ['A' => 223, 'B' => 'Isabella Peña Navarro', 'C' => 'ASESOR MSI', 'D' => 46273],
    ];

    /**
     * La hoja completa como cuadrícula (filas de celdas), desplazada $abajo filas y $derecha columnas.
     *
     * @param  array<int, array>  $cambios  [índice de FILAS => [letra => valor]] para variar datos
     * @return list<list<mixed>>
     */
    public static function libro(int $abajo = 0, int $derecha = 0, array $cambios = []): array
    {
        $ancho = count(self::ENCABEZADOS) + $derecha;
        $vacia = array_fill(0, $ancho, null);
        $mover = fn (array $celdas) => array_replace($vacia, array_combine(
            array_map(fn ($i) => $i + $derecha, array_keys($celdas)), array_values($celdas)));

        $grid = array_fill(0, $abajo, $vacia);
        foreach (self::PREVIAS as $f) {
            $grid[] = $mover($f);
        }
        $grid[] = $mover(array_values(self::ENCABEZADOS));
        foreach (self::FILAS as $i => $f) {
            $f = array_replace($f, $cambios[$i] ?? []);
            $porIndice = [];
            foreach ($f as $letra => $v) {
                $porIndice[self::indice($letra)] = $v;
            }
            $grid[] = $mover($porIndice);
        }

        return $grid;
    }

    /** Hace lo mismo que ExcelExtractor con una cuadrícula: elige la fila de encabezados y separa todo. */
    public static function extraer(array $grid): HojaExtraida
    {
        $sel = (new SelectorHoja(new HeaderMapper))->elegir([['nombre' => 'Hoja1', 'visible' => true, 'filas' => array_slice($grid, 0, SelectorHoja::FILAS_A_REVISAR)]]);
        $fe = $sel['filaEncabezado'];
        $letras = array_map(fn ($i) => self::letra($i), array_keys($grid[$fe - 1]));
        $filas = [];
        foreach (array_slice($grid, $fe, null, true) as $i => $celdas) {
            if (array_filter($celdas, fn ($v) => $v !== null && trim((string) $v) !== '') !== []) {
                $filas[] = ['fila' => $i + 1, 'celdas' => array_combine($letras, $celdas)];
            }
        }

        return new HojaExtraida(array_combine($letras, $grid[$fe - 1]), $filas, $fe, 'Hoja1', array_slice($grid, 0, $fe - 1));
    }

    /** "A" -> 0, "AA" -> 26 (sin depender de PhpSpreadsheet: pruebas unitarias puras) */
    private static function indice(string $letra): int
    {
        $n = 0;
        foreach (str_split($letra) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }

        return $n - 1;
    }

    private static function letra(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }
}
