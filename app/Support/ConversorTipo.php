<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * ÚNICA fuente de reglas de conversión de valores ("$600.00" -> 600.00, "15/01/2018" -> 2018-01-15, "Sí" -> true).
 * La usan el ETL (al leer el Excel), la edición en la web y el cambio de tipo de una columna,
 * así las tres partes aceptan y rechazan exactamente lo mismo.
 * Sin dependencias de Laravel: se prueba con PHPUnit puro.
 */
final class ConversorTipo
{
    public const TIPOS = ['texto', 'texto_largo', 'entero', 'decimal', 'dinero', 'fecha', 'booleano'];

    private const ESPERADO = [
        'entero' => 'un número entero (ej. 2)',
        'decimal' => 'un número (ej. 1 o 0.5)',
        'dinero' => 'una cantidad en pesos (ej. $600.00)',
        'fecha' => 'una fecha dd/mm/aaaa',
        'booleano' => 'Sí / No (1 o 0)',
    ];

    /**
     * @return array{ok: bool, valor: mixed, esperado: ?string}
     *   ok=false -> el valor no se puede convertir; `esperado` describe lo que se esperaba.
     */
    public static function convertir(mixed $v, string $tipo): array
    {
        if ($v === null || (is_string($v) && trim($v) === '')) {
            return self::ok(null);
        }

        switch ($tipo) {
            case 'texto':
            case 'texto_largo':
                $txt = match (true) {
                    is_bool($v) => $v ? 'Sí' : 'No',
                    is_float($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'),
                    is_scalar($v) => (string) $v,
                    default => json_encode($v),
                };
                // Una fecha ISO guardada como dato tipado vuelve a dd/mm/aaaa al pasar a texto
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $txt, $m)) {
                    $txt = "{$m[3]}/{$m[2]}/{$m[1]}";
                }

                return self::ok($tipo === 'texto' ? mb_substr(Texto::limpiar($txt), 0, 255) : mb_substr(trim($txt), 0, 2000));

            case 'dinero':
            case 'decimal':
                $n = self::numero($v);

                return $n === null || $n < 0 ? self::falla($tipo) : self::ok(round($n, 2));

            case 'entero':
                $n = self::numero($v);

                return $n === null || floor($n) != $n || $n < 0 ? self::falla($tipo) : self::ok((int) $n);

            case 'fecha':
                $f = self::fecha($v);

                return $f ? self::ok($f->format('Y-m-d')) : self::falla($tipo);

            case 'booleano':
                if (is_bool($v)) {
                    return self::ok($v);
                }
                $t = Texto::normalizar((string) $v);
                if (in_array($t, ['1', 'si', 'x', 'true', 'verdadero'], true)) {
                    return self::ok(true);
                }
                if (in_array($t, ['0', 'no', 'false', 'falso'], true)) {
                    return self::ok(false);
                }

                return self::falla($tipo);
        }

        return self::ok($v);
    }

    public static function numero(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_bool($v)) {
            return null;
        }
        $limpio = str_replace(['$', ',', ' ', 'MXN', 'mxn'], '', (string) $v);

        return is_numeric($limpio) ? (float) $limpio : null;
    }

    public static function fecha(mixed $v): ?DateTimeImmutable
    {
        // Número de serie de Excel (lo normal al leer un .xlsx)
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            $serie = (int) floor((float) $v);

            return $serie >= 1 && $serie <= 80000 ? (new DateTimeImmutable('1899-12-30'))->modify("+{$serie} days") : null;
        }

        $txt = trim((string) $v);
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $txt, $m)) {                        // 2018-01-15 (ISO)
            [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2}|\d{4})$#', $txt, $m)) { // 15/01/2018
            [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($a < 100) {
                $a += $a >= 70 ? 1900 : 2000;
            }
        } else {
            return null;
        }

        return checkdate($mes, $d, $a) ? new DateTimeImmutable(sprintf('%04d-%02d-%02d', $a, $mes, $d)) : null;
    }

    private static function ok(mixed $valor): array
    {
        return ['ok' => true, 'valor' => $valor, 'esperado' => null];
    }

    private static function falla(string $tipo): array
    {
        return ['ok' => false, 'valor' => null, 'esperado' => self::ESPERADO[$tipo] ?? $tipo];
    }
}
