<?php

namespace App\Support;

/**
 * Utilidades de texto sin dependencias de framework (fáciles de probar).
 */
final class Texto
{
    private const ACENTOS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
    ];

    /** "  José   PÉREZ " -> "jose perez" (clave de comparación, NO para mostrar). */
    public static function normalizar(?string $valor): string
    {
        $v = strtr((string) $valor, self::ACENTOS);
        $v = mb_strtolower($v, 'UTF-8');
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;

        return trim($v);
    }

    /** Limpia para mostrar: recorta y colapsa espacios, conserva mayúsculas y acentos. */
    public static function limpiar(?string $valor): string
    {
        $v = preg_replace('/\s+/u', ' ', (string) $valor) ?? (string) $valor;

        return trim($v);
    }

    /** Encabezado de Excel -> forma canónica: sin acentos, sin '#', '.', ':' ni saltos de línea. */
    public static function normalizarEncabezado(?string $valor): string
    {
        $v = str_replace(['#', '.', ':', '*'], ' ', (string) $valor);

        return self::normalizar($v);
    }

    /** Caracteres de control, UTF-8 inválido o el carácter de reemplazo (U+FFFD). */
    public static function tieneCaracteresInvalidos(string $valor): bool
    {
        if (! mb_check_encoding($valor, 'UTF-8')) {
            return true;
        }

        return (bool) preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{FFFD}/u', $valor);
    }
}
