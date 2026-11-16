<?php

namespace App\Support\Nomina;

/**
 * v12 — Huella (SHA-256) de la fotografía de un recibo autorizado. Lógica pura.
 * Se calcula sobre el JSON con las llaves ordenadas, así la misma información siempre da la misma huella
 * y cualquier cambio posterior en la base de datos se detecta al verificar el historial.
 */
final class Huella
{
    public static function de(array $datos): string
    {
        return hash('sha256', json_encode(self::ordenar($datos), JSON_UNESCAPED_UNICODE));
    }

    private static function ordenar(mixed $v): mixed
    {
        if (! is_array($v)) {
            // 500, 500.0 y 500.00 deben dar la misma huella (el JSON de la base de datos no distingue entero de decimal)
            return is_int($v) || is_float($v) ? number_format((float) $v, 2, '.', '') : $v;
        }
        if (! array_is_list($v)) {
            ksort($v);
        }

        return array_map([self::class, 'ordenar'], $v);
    }
}
