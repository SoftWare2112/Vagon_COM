<?php

namespace App\Support\Hojas\Importacion;

/**
 * v9 — Decide si una fórmula de Excel se puede calcular con el motor de la plataforma.
 *
 * Excel guarda las fórmulas en inglés con coma como separador ("=SUM(B2:B9)", "=IF(A1>0,1,0)"); el motor
 * acepta esos nombres y separadores, así que una fórmula soportada se conserva tal cual (en A1).
 * Se rechaza (y el importador conserva el último VALOR que calculó Excel) cuando usa:
 *   - otra hoja o libro ("Hoja2!A1", "[Libro.xlsx]"), tablas con nombre ("Tabla1[Total]")
 *   - columnas o filas completas ("A:A", "3:3"), matrices ("{1,2}"), nombres definidos ("=Tasa*B2")
 *   - funciones que el motor no tiene (BUSCARV, SUMAR.SI, ...)
 * Lógica pura (sin Laravel).
 */
final class TraductorFormula
{
    /** Funciones del motor (motor/evaluar.ts): nombre en español y alias en inglés. */
    public const FUNCIONES = [
        'SUMA', 'PROMEDIO', 'MIN', 'MAX', 'CONTAR', 'CONTARA', 'SI', 'Y', 'O', 'NO', 'REDONDEAR', 'ABS', 'ENTERO', 'RESIDUO',
        'HOY', 'AHORA', 'CONCATENAR',
        'SUM', 'AVERAGE', 'COUNT', 'COUNTA', 'IF', 'AND', 'OR', 'NOT', 'ROUND', 'TODAY', 'NOW', 'INT', 'MOD', 'CONCATENATE',
    ];

    private const LOGICOS = ['TRUE', 'FALSE', 'VERDADERO', 'FALSO'];

    public static function traducir(string $formula): ?string
    {
        $cuerpo = ltrim(trim($formula), '=');
        if ($cuerpo === '' || mb_strlen($cuerpo) > 3900) {
            return null;
        }
        $cuerpo = preg_replace('/_xl(fn|ws)\./i', '', $cuerpo);
        $fuera = self::sinTextos($cuerpo);
        if ($fuera === null) {
            return null;   // comillas sin cerrar
        }

        if (preg_match('/[!\[\]{}\'#@]/', $fuera)) {
            return null;   // otra hoja, tablas, matrices, errores escritos o referencias implícitas
        }
        if (preg_match('/(?<![A-Za-z0-9_.$])\$?[A-Za-z]{1,3}:\$?[A-Za-z]{1,3}(?![A-Za-z0-9_(])/', $fuera)
            || preg_match('/(?<![A-Za-z0-9_.$])\$?\d+:\$?\d+(?![0-9.])/', $fuera)) {
            return null;   // columnas o filas completas
        }

        // Cada palabra debe ser una función conocida, una celda (A1, $B$2) o VERDADERO/FALSO
        preg_match_all('/[A-Za-z_][A-Za-z0-9_.]*(\s*\()?/', str_replace('$', '', preg_replace('/\d+(\.\d+)?[eE][+-]?\d+/', '0', $fuera)), $m, PREG_SET_ORDER);
        foreach ($m as $t) {
            $palabra = rtrim($t[0], " \t(");
            $esFuncion = isset($t[1]) && $t[1] !== '';
            $mayus = strtoupper($palabra);
            if ($esFuncion) {
                if (! in_array($mayus, self::FUNCIONES, true)) {
                    return null;
                }
            } elseif (! in_array($mayus, self::LOGICOS, true) && ! preg_match('/^[A-Za-z]{1,3}\d{1,6}$/', $palabra)) {
                return null;   // nombre definido u otra cosa desconocida
            }
        }

        return '='.$cuerpo;
    }

    /** El texto sin lo que va entre comillas (las comillas dobles "" escapan una comilla). */
    private static function sinTextos(string $s): ?string
    {
        if (substr_count($s, '"') % 2 !== 0) {
            return null;
        }

        return preg_replace('/"(?:[^"]|"")*"/', '""', $s);
    }
}
