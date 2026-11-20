<?php

namespace App\Support\Nomina;

/** v13 — Importe con letra para el recibo: 5311.72 → "CINCO MIL TRESCIENTOS ONCE PESOS 72/100 M.N." (lógica pura). */
final class ImporteConLetra
{
    private const UNIDADES = ['', 'UN', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE', 'TRECE',
        'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIÚN', 'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO',
        'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE'];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS', 'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public static function pesos(float $importe): string
    {
        $negativo = $importe < 0;
        $centavos = (int) round(abs($importe) * 100);
        $enteros = intdiv($centavos, 100);
        $resto = $centavos % 100;
        $texto = $enteros === 0 ? 'CERO' : self::numero($enteros);
        $moneda = $enteros === 1 ? 'PESO' : (($enteros % 1000000 === 0) ? 'DE PESOS' : 'PESOS');

        return ($negativo ? 'MENOS ' : '').$texto.' '.$moneda.' '.str_pad((string) $resto, 2, '0', STR_PAD_LEFT).'/100 M.N.';
    }

    private static function numero(int $n): string
    {
        if ($n >= 1000000) {
            $millones = intdiv($n, 1000000);
            $resto = $n % 1000000;

            return trim(($millones === 1 ? 'UN MILLÓN' : self::numero($millones).' MILLONES').($resto ? ' '.self::numero($resto) : ''));
        }
        if ($n >= 1000) {
            $miles = intdiv($n, 1000);
            $resto = $n % 1000;

            return trim(($miles === 1 ? 'MIL' : self::centenas($miles).' MIL').($resto ? ' '.self::centenas($resto) : ''));
        }

        return self::centenas($n);
    }

    private static function centenas(int $n): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $c = intdiv($n, 100);
        $r = $n % 100;
        $texto = self::CENTENAS[$c];
        if ($r < 30) {
            $d = self::UNIDADES[$r];
        } else {
            $u = $r % 10;
            $d = self::DECENAS[intdiv($r, 10)].($u ? ' Y '.self::UNIDADES[$u] : '');
        }

        return trim($texto.' '.$d);
    }
}
