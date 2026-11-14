<?php

namespace App\Support\Hojas;

use InvalidArgumentException;

/**
 * Formato de celda permitido (v8, etapa 1). Claves cortas porque viajan en cada celda:
 *   n negrita · i cursiva · s subrayado · c color de texto · f color de fondo
 *   a alineación (izq|centro|der) · w ajustar texto · fmt formato de número
 * Se normaliza (orden fijo, sin valores por defecto) para que formatos iguales tengan la misma huella.
 */
final class ValidadorEstilo
{
    public const FORMATOS = ['general', 'numero', 'entero', 'moneda', 'porcentaje', 'fecha', 'fecha_hora', 'hora', 'texto'];

    public static function normalizar(array $e): array
    {
        $r = [];
        foreach (['n', 'i', 's', 'w'] as $k) {
            if (array_key_exists($k, $e) && ! is_bool($e[$k])) {
                throw new InvalidArgumentException("Formato '{$k}' no válido.");
            }
            if (! empty($e[$k])) {
                $r[$k] = true;
            }
        }
        foreach (['c', 'f'] as $k) {
            if (isset($e[$k]) && $e[$k] !== '') {
                if (! is_string($e[$k]) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $e[$k])) {
                    throw new InvalidArgumentException('Color no válido (usa #RRGGBB).');
                }
                $r[$k] = strtoupper($e[$k]);
            }
        }
        if (isset($e['a']) && $e['a'] !== '') {
            if (! in_array($e['a'], ['izq', 'centro', 'der'], true)) {
                throw new InvalidArgumentException('Alineación no válida.');
            }
            $r['a'] = $e['a'];
        }
        if (isset($e['fmt']) && $e['fmt'] !== 'general') {
            if (! in_array($e['fmt'], self::FORMATOS, true)) {
                throw new InvalidArgumentException('Formato de número no válido.');
            }
            $r['fmt'] = $e['fmt'];
        }
        ksort($r);

        return $r;
    }

    public static function huella(array $normalizado): string
    {
        return sha1(json_encode($normalizado));
    }
}
