<?php

namespace App\Support\Hojas;

/**
 * Plantillas de hojas que vienen con la plataforma (v8). Se siembran en `hoja_plantillas`.
 *
 * definicion = [
 *   'columnas' => [anchos en px, …],          // cuántas columnas y su ancho
 *   'filas'    => 40,                          // cuántas filas
 *   'estilos'  => ['nombre' => [n, i, s, c, f, a, w, fmt], …],
 *   'celdas'   => [[fila, columna, celda], …], // posiciones desde 0
 * ]
 * celda = ['t' => 'texto', 'v' => 'Nombre', 'e' => 'encabezado'] | ['t' => 'formula', 'fx' => '=SUMA(B2:F2)'] …
 * Las fórmulas se escriben con referencias A1; al crear la hoja se convierten a ids (ReferenciasFormula).
 */
final class PlantillasPredefinidas
{
    public static function todas(): array
    {
        return [self::incidencias(), self::asistencia(), self::presupuesto(), self::empleados()];
    }

    private static function encabezados(array $titulos, int $fila = 0, string $estilo = 'encabezado'): array
    {
        $celdas = [];
        foreach ($titulos as $c => $t) {
            $celdas[] = [$fila, $c, ['t' => 'texto', 'v' => $t, 'e' => $estilo]];
        }

        return $celdas;
    }

    /** El formato de RH (bloque de arriba + tabla de incidencias) para capturarlo directo en la web. */
    private static function incidencias(): array
    {
        $titulos = ['#', 'Nombre Completo', 'Puesto', 'F. de ingreso', 'Prima vacacional', 'Bono 3 meses', 'Bono puntualidad',
            'Bono asistencia', 'Número de faltas', 'Detalle', 'Acredita para bono', 'Programado en domingo', 'Domingo 1', 'Domingo 2', 'Periodo'];
        $celdas = [
            [0, 0, ['t' => 'texto', 'v' => 'Tipo Periodo:', 'e' => 'etiqueta']], [0, 1, ['t' => 'texto', 'v' => 'Quincenal', 'e' => 'dato']],
            [1, 0, ['t' => 'texto', 'v' => 'Ejercicio:', 'e' => 'etiqueta']], [1, 1, ['t' => 'numero', 'v' => (int) date('Y'), 'e' => 'dato']],
            [2, 0, ['t' => 'texto', 'v' => 'Periodo:', 'e' => 'etiqueta']], [2, 1, ['t' => 'vacio', 'e' => 'dato']],
            [0, 3, ['t' => 'texto', 'v' => 'Incidencias para pago de nómina', 'e' => 'titulo']],
            ...self::encabezados($titulos, 3),
        ];
        for ($f = 4; $f < 34; $f++) {   // 30 filas de captura
            foreach ([4, 5, 6, 7] as $c) {
                $celdas[] = [$f, $c, ['t' => 'vacio', 'e' => 'moneda']];
            }
            $celdas[] = [$f, 3, ['t' => 'vacio', 'e' => 'fecha']];
        }
        $celdas[] = [34, 7, ['t' => 'texto', 'v' => 'Total de faltas', 'e' => 'total']];
        $celdas[] = [34, 8, ['t' => 'formula', 'fx' => '=SUMA(I5:I34)', 'e' => 'total']];

        return [
            'clave' => 'incidencias_rh',
            'nombre' => 'Incidencias de nómina (formato RH)',
            'descripcion' => 'El mismo formato del Excel quincenal: bloque Tipo Periodo / Ejercicio / Periodo, título y tabla de incidencias.',
            'definicion' => [
                'columnas' => [56, 220, 130, 100, 110, 100, 110, 110, 90, 160, 100, 110, 85, 85, 160],
                'filas' => 40,
                'estilos' => [
                    'etiqueta' => ['n' => true, 'a' => 'der'],
                    'dato' => ['f' => '#FFFF99', 'a' => 'centro'],
                    'titulo' => ['n' => true, 'c' => '#FFFFFF', 'f' => '#1BA1E2', 'a' => 'centro'],
                    'encabezado' => ['n' => true, 'f' => '#A9DFBF', 'a' => 'centro', 'w' => true],
                    'moneda' => ['fmt' => 'moneda'],
                    'fecha' => ['fmt' => 'fecha', 'a' => 'centro'],
                    'total' => ['n' => true],
                ],
                'celdas' => $celdas,
            ],
        ];
    }

    private static function asistencia(): array
    {
        $celdas = self::encabezados(['Empleado', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Total']);
        for ($f = 1; $f <= 20; $f++) {
            $n = $f + 1;
            $celdas[] = [$f, 7, ['t' => 'formula', 'fx' => "=SUMA(B{$n}:G{$n})", 'e' => 'total']];
        }
        $celdas[] = [21, 0, ['t' => 'texto', 'v' => 'Total del día', 'e' => 'total']];
        foreach (range(1, 7) as $c) {
            $letra = chr(65 + $c);
            $celdas[] = [21, $c, ['t' => 'formula', 'fx' => "=SUMA({$letra}2:{$letra}21)", 'e' => 'total']];
        }

        return [
            'clave' => 'asistencia_semanal',
            'nombre' => 'Control de asistencia semanal',
            'descripcion' => 'Días trabajados por empleado (1 = asistió). Suma por persona y por día.',
            'definicion' => [
                'columnas' => [220, 80, 80, 90, 80, 80, 80, 80],
                'filas' => 30,
                'estilos' => [
                    'encabezado' => ['n' => true, 'f' => '#DCE6F1', 'a' => 'centro'],
                    'total' => ['n' => true, 'a' => 'centro'],
                ],
                'celdas' => $celdas,
            ],
        ];
    }

    private static function presupuesto(): array
    {
        $celdas = self::encabezados(['Concepto', 'Cantidad', 'Precio unitario', 'Importe']);
        for ($f = 1; $f <= 15; $f++) {
            $n = $f + 1;
            $celdas[] = [$f, 2, ['t' => 'vacio', 'e' => 'moneda']];
            $celdas[] = [$f, 3, ['t' => 'formula', 'fx' => "=B{$n}*C{$n}", 'e' => 'moneda']];
        }
        $celdas[] = [17, 2, ['t' => 'texto', 'v' => 'Subtotal', 'e' => 'etiqueta']];
        $celdas[] = [17, 3, ['t' => 'formula', 'fx' => '=SUMA(D2:D16)', 'e' => 'moneda']];
        $celdas[] = [18, 2, ['t' => 'texto', 'v' => 'IVA (16%)', 'e' => 'etiqueta']];
        $celdas[] = [18, 3, ['t' => 'formula', 'fx' => '=D18*16%', 'e' => 'moneda']];
        $celdas[] = [19, 2, ['t' => 'texto', 'v' => 'Total', 'e' => 'etiqueta']];
        $celdas[] = [19, 3, ['t' => 'formula', 'fx' => '=D18+D19', 'e' => 'total']];

        return [
            'clave' => 'presupuesto',
            'nombre' => 'Presupuesto simple',
            'descripcion' => 'Conceptos con cantidad × precio, subtotal, IVA y total calculados.',
            'definicion' => [
                'columnas' => [260, 90, 130, 130],
                'filas' => 25,
                'estilos' => [
                    'encabezado' => ['n' => true, 'f' => '#E2EFDA', 'a' => 'centro'],
                    'moneda' => ['fmt' => 'moneda'],
                    'etiqueta' => ['n' => true, 'a' => 'der'],
                    'total' => ['n' => true, 'fmt' => 'moneda', 'f' => '#FFF2CC'],
                ],
                'celdas' => $celdas,
            ],
        ];
    }

    private static function empleados(): array
    {
        $celdas = self::encabezados(['No.', 'Nombre', 'Puesto', 'Fecha de ingreso', 'Antigüedad (años)']);
        for ($f = 1; $f <= 30; $f++) {
            $n = $f + 1;
            $celdas[] = [$f, 3, ['t' => 'vacio', 'e' => 'fecha']];
            $celdas[] = [$f, 4, ['t' => 'formula', 'fx' => "=SI(D{$n}=\"\";\"\";REDONDEAR((HOY()-D{$n})/365;1))", 'e' => 'centro']];
        }

        return [
            'clave' => 'lista_empleados',
            'nombre' => 'Lista de empleados',
            'descripcion' => 'Nombre, puesto, fecha de ingreso y antigüedad calculada a la fecha de hoy.',
            'definicion' => [
                'columnas' => [56, 240, 150, 120, 130],
                'filas' => 40,
                'estilos' => [
                    'encabezado' => ['n' => true, 'f' => '#DCE6F1', 'a' => 'centro'],
                    'fecha' => ['fmt' => 'fecha', 'a' => 'centro'],
                    'centro' => ['a' => 'centro'],
                ],
                'celdas' => $celdas,
            ],
        ];
    }
}
