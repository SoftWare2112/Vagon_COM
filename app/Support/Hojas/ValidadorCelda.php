<?php

namespace App\Support\Hojas;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Valida una celda que manda el navegador y la convierte a las columnas de `hoja_celdas`.
 * El navegador decide el tipo al escribir (motor/entrada.ts); el servidor NO adivina, solo comprueba
 * que el valor corresponda a su tipo. Una fórmula llega con su texto (ids) y su último resultado.
 *
 * Entrada: ['t' => tipo, 'v' => valor, 'fx' => fórmula, 'rt' => tipo del resultado]
 * Lógica pura (sin Laravel).
 */
final class ValidadorCelda
{
    public const TIPOS = ['vacio', 'texto', 'numero', 'fecha', 'fecha_hora', 'hora', 'logico', 'formula'];

    public const ERRORES = ['#DIV/0!', '#REF!', '#VALOR!', '#NOMBRE?', '#CICLO!', '#N/A', '#NUM!'];

    private const MAX_TEXTO = 5000;

    private const MAX_FORMULA = 4000;

    /** @return array{tipo:string, valor_texto:?string, valor_numero:?string, valor_fecha:?string, valor_logico:?bool, formula:?string, resultado_tipo:?string} */
    public static function normalizar(array $c): array
    {
        $tipo = $c['t'] ?? null;
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException('Tipo de celda no válido.');
        }

        $fila = ['tipo' => $tipo, 'valor_texto' => null, 'valor_numero' => null, 'valor_fecha' => null, 'valor_logico' => null, 'formula' => null, 'resultado_tipo' => null];

        if ($tipo === 'formula') {
            $fx = $c['fx'] ?? null;
            if (! is_string($fx) || ! str_starts_with($fx, '=') || mb_strlen($fx) > self::MAX_FORMULA) {
                throw new InvalidArgumentException('La fórmula debe empezar con "=" y tener menos de '.self::MAX_FORMULA.' caracteres.');
            }
            $rt = $c['rt'] ?? 'vacio';
            if ($rt === 'error') {
                if (! in_array($c['v'] ?? null, self::ERRORES, true)) {
                    throw new InvalidArgumentException('Código de error no válido.');
                }
                $fila['valor_texto'] = $c['v'];
            } elseif ($rt === 'formula' || ! in_array($rt, self::TIPOS, true)) {
                throw new InvalidArgumentException('Tipo de resultado no válido.');
            } else {
                $fila = [...$fila, ...self::valor($rt, $c['v'] ?? null)];
            }

            return [...$fila, 'tipo' => 'formula', 'formula' => $fx, 'resultado_tipo' => $rt];
        }

        return [...$fila, ...self::valor($tipo, $c['v'] ?? null)];
    }

    /** @return array<string, mixed> columnas de valor para un tipo simple */
    private static function valor(string $tipo, mixed $v): array
    {
        return match ($tipo) {
            'vacio' => [],
            'texto' => ['valor_texto' => self::texto($v)],
            'numero' => ['valor_numero' => self::numero($v)],
            'fecha' => ['valor_fecha' => self::fecha($v, 'Y-m-d').' 00:00:00'],
            'fecha_hora' => ['valor_fecha' => self::fechaHora($v)],
            'hora' => ['valor_texto' => self::hora($v)],
            'logico' => ['valor_logico' => is_bool($v) ? $v : throw new InvalidArgumentException('Un valor lógico debe ser VERDADERO o FALSO.')],
        };
    }

    private static function texto(mixed $v): string
    {
        if (! is_string($v) || mb_strlen($v) > self::MAX_TEXTO) {
            throw new InvalidArgumentException('El texto debe tener menos de '.self::MAX_TEXTO.' caracteres.');
        }

        return $v;
    }

    private static function numero(mixed $v): string
    {
        if (! is_int($v) && ! is_float($v) || ! is_finite((float) $v) || abs((float) $v) >= 1e14) {
            throw new InvalidArgumentException('Número no válido o demasiado grande.');
        }

        return number_format((float) $v, 6, '.', '');
    }

    private static function fecha(mixed $v, string $formato): string
    {
        $d = is_string($v) ? DateTimeImmutable::createFromFormat('!'.$formato, $v) : false;
        if (! $d || $d->format($formato) !== $v) {
            throw new InvalidArgumentException('Fecha no válida.');
        }

        return $d->format('Y-m-d');
    }

    private static function fechaHora(mixed $v): string
    {
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $f) {
            $d = is_string($v) ? DateTimeImmutable::createFromFormat('!'.$f, $v) : false;
            if ($d && $d->format($f) === $v) {
                return $d->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException('Fecha y hora no válidas.');
    }

    private static function hora(mixed $v): string
    {
        if (! is_string($v) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $v, $m)) {
            throw new InvalidArgumentException('Hora no válida (hh:mm).');
        }

        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }
}
