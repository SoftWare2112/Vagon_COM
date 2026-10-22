<?php

namespace Tests\Unit\Etl\Fixtures;

/**
 * Contenido real de INCIDENCIAS_Q17.xlsx (hoja "Hoja1"), tal como lo entrega ExcelExtractor:
 * fechas como número de serie de Excel, celdas vacías omitidas.
 *  - Filas 1-3: bloque "Tipo Periodo / Ejercicio / Periodo" + título combinado E1:P3.
 *  - Fila 4: encabezados (con "Columna1", "#" y una columna F sin título).
 *  - Filas 5-28: 24 empleados con el nombre numerado ("1. Alejandro…").
 *  - Filas 29-38: filas vacías con formato.
 */
final class Q17
{
    public const PREVIAS = [
        [null, 'Tipo Periodo:', 'Quincenal', null, 'Incidencias para pago de nomina'],
        [null, 'Ejercicio:', 2026],
        [null, 'Periodo:', 17],
    ];

    public const ENCABEZADOS = [
        'A' => 'Columna1', 'B' => '#', 'C' => 'Nombre Completo', 'D' => 'PUESTO', 'E' => 'F. DE INGRESO', 'F' => null,
        'G' => 'PRIMA VACACIONAL', 'H' => 'BONO 3 MESES', 'I' => 'Bono Puntualidad', 'J' => 'Bono Asistencia',
        'K' => 'Numero de Faltas', 'L' => 'Detalle', 'M' => 'Acredita para bono', 'N' => 'Programado en Domingo',
        'O' => 'Domingo 4', 'P' => 'Domingo 11', 'Q' => ' 1 de Agosto AL 27 de Octubre',
    ];

    public const FILAS = [
        ['C' => '1. Alejandro Martínez Salgado', 'D' => 'SUPERVISOR MSI', 'E' => 45425],
        ['C' => '2. Valeria Hernández Cruz', 'D' => 'ASESOR MSI', 'E' => 45434, 'O' => 1],
        ['C' => '3. Diego Ramírez Navarro', 'D' => 'ASESOR MSI', 'E' => 45505],
        ['C' => '4. Camila Torres Mendoza', 'D' => 'ASESOR MSI', 'E' => 45544, 'G' => 1, 'O' => 1, 'P' => 1],
        ['C' => '5. Sebastián Flores Ríos', 'D' => 'ASESOR MSI', 'E' => 45600, 'P' => 1],
        ['C' => '6. Natalia Vargas Castillo', 'D' => 'ASESOR MSI', 'E' => 45649, 'K' => 1, 'P' => 1],
        ['C' => '7. Emiliano Reyes Fuentes', 'D' => 'ASESOR MSI', 'E' => 45677, 'O' => 1],
        ['C' => '8. Renata Morales Pineda', 'D' => 'RECURSOS HUMANOS ', 'E' => 45896, 'G' => 1],
        ['C' => '9. Leonardo Sánchez Duarte', 'D' => 'ASESOR MSI', 'E' => 45938],
        ['C' => '10. Mariana Ortega Silva', 'D' => 'ASESOR MSI', 'E' => 45993, 'O' => 1, 'P' => 1],
        ['C' => '11. Santiago Cabrera León', 'D' => 'ASESOR MSI', 'E' => 46000, 'K' => 1],
        ['C' => '12. Fernanda Medina Lozano', 'D' => 'SUPERVISOR MSI', 'E' => 46041, 'K' => 1],
        ['C' => '13. Mateo González Vera', 'D' => 'ASESOR MSI', 'E' => 46069, 'P' => 1],
        ['C' => '14. Regina Castro Méndez', 'D' => 'ASESOR MSI', 'E' => 46141, 'O' => 1, 'P' => 1],
        ['C' => '15. Daniel Espinoza Lara', 'D' => 'ASESOR MSI', 'E' => 46164, 'O' => 1, 'P' => 1],
        ['C' => '16. Sofía Domínguez Arias', 'D' => 'ASESOR MSI', 'E' => 46198, 'O' => 1],
        ['C' => '17. Adrián Herrera Campos', 'D' => 'ASESOR MSI', 'E' => 46199, 'P' => 1],
        ['C' => '18. Ximena Romero Valdez', 'D' => 'ASESOR MSI', 'E' => 46213, 'P' => 1],
        ['C' => '19. Gabriel Jiménez Rocha', 'D' => 'ASESOR MSI', 'E' => 46218, 'K' => 3, 'P' => 1],
        ['C' => '20. Paulina Núñez Serrano', 'D' => 'ASESOR MSI', 'E' => 46227, 'O' => 1, 'P' => 1],
        ['C' => '21. Rodrigo Salazar Vega', 'D' => 'ASESOR MSI', 'E' => 46239],
        ['C' => '22. Andrea Cortés Molina', 'D' => 'ASESOR MSI', 'E' => 46255],
        ['C' => '23. Mauricio Delgado Reyes', 'D' => 'ASESOR MSI', 'E' => 46260],
        ['C' => '24. Isabella Peña Navarro', 'D' => 'ASESOR MSI', 'E' => 46273],
    ];

    /** @return list<array{fila:int, celdas:array}> */
    public static function filas(): array
    {
        $vacia = array_fill_keys(array_keys(self::ENCABEZADOS), null);
        $filas = [];
        foreach (self::FILAS as $i => $celdas) {
            $filas[] = ['fila' => $i + 5, 'celdas' => array_replace($vacia, $celdas)];
        }

        return $filas;
    }
}
