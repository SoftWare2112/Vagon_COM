<?php

namespace App\Support\Hojas;

/**
 * Las fórmulas se GUARDAN apuntando a ids de fila y columna, no a posiciones:
 *   lo que escribe el usuario   =C2*D2          (A1, como Excel)
 *   lo que se guarda            =[f812c45]*[f812c46]
 * Así, insertar o borrar filas no rompe ninguna fórmula; si la celda apuntada se borra, se muestra #REF!.
 * El navegador hace la misma conversión (motor/referencias.ts); aquí se usa al crear hojas desde plantilla.
 * Lógica pura (sin Laravel).
 */
final class ReferenciasFormula
{
    /** A1 suelto, no seguido de "(" (para no confundir LOG10( con la celda LOG10). */
    private const A1 = '/(?<![A-Za-z0-9_\[])\$?([A-Za-z]{1,3})\$?(\d{1,6})(?![A-Za-z0-9_(])/';

    private const INTERNA = '/\[f(\d+)c(\d+)\]/';

    /** "A" -> 0, "Z" -> 25, "AA" -> 26 */
    public static function indiceColumna(string $letras): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letras)) as $l) {
            $n = $n * 26 + (ord($l) - 64);
        }

        return $n - 1;
    }

    public static function letraColumna(int $indice): string
    {
        $s = '';
        for ($i = $indice + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    /**
     * @param  list<int>  $filaIds     id de cada fila por posición (0 = fila 1)
     * @param  list<int>  $columnaIds  id de cada columna por posición (0 = A)
     */
    public static function aInterno(string $formula, array $filaIds, array $columnaIds): string
    {
        return self::fueraDeTextos($formula, fn (string $tramo) => preg_replace_callback(self::A1, function ($m) use ($filaIds, $columnaIds) {
            $c = self::indiceColumna($m[1]);
            $f = (int) $m[2] - 1;
            if (! isset($filaIds[$f], $columnaIds[$c])) {
                return '#REF!';
            }

            return "[f{$filaIds[$f]}c{$columnaIds[$c]}]";
        }, $tramo));
    }

    /**
     * @param  array<int,int>  $posFila     id de fila => posición (0 = fila 1)
     * @param  array<int,int>  $posColumna  id de columna => posición (0 = A)
     */
    public static function aVisible(string $formula, array $posFila, array $posColumna): string
    {
        return self::fueraDeTextos($formula, fn (string $tramo) => preg_replace_callback(self::INTERNA, function ($m) use ($posFila, $posColumna) {
            $f = $posFila[(int) $m[1]] ?? null;
            $c = $posColumna[(int) $m[2]] ?? null;

            return $f === null || $c === null ? '#REF!' : self::letraColumna($c).($f + 1);
        }, $tramo));
    }

    /** Aplica $fn solo a lo que está fuera de comillas ("texto" no se toca). */
    private static function fueraDeTextos(string $formula, callable $fn): string
    {
        $partes = preg_split('/("(?:[^"]|"")*")/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($partes as $i => $p) {
            if ($i % 2 === 0) {
                $partes[$i] = $fn($p);
            }
        }

        return implode('', $partes);
    }
}
