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
        $v = str_replace(['#', '.', ':', '*', '°', 'º'], ' ', (string) $valor);

        return self::normalizar($v);
    }

    /** Palabras de relleno que no distinguen una columna de otra ("Número DE faltas", "Acredita PARA bono"). */
    private const RELLENO = ['de', 'del', 'la', 'las', 'el', 'los', 'en', 'para', 'por', 'a', 'al', 'y', 'e', 'o', 'con'];

    /**
     * Abreviaturas que NO son el inicio de la palabra completa (las que sí lo son, como "punt." o
     * "asist.", se resuelven por prefijo en HeaderMapper). RH puede agregar más como alias.
     */
    private const ABREVIATURAS = [
        'no' => 'numero', 'num' => 'numero', 'nro' => 'numero', 'nm' => 'numero',
        'f' => 'fecha', 'fch' => 'fecha', 'fec' => 'fecha',
        'pto' => 'puesto', 'dom' => 'domingo', 'dgo' => 'domingo',
        'vac' => 'vacacional', 'vacac' => 'vacacional', 'obs' => 'observaciones',
    ];

    /**
     * Clave de COMPARACIÓN de un encabezado (no para mostrar):
     *   "# de Faltas" / "Núm. de faltas" / "No. Faltas"  -> "numero faltas"
     *   "F. DE INGRESO"                                  -> "fecha ingreso"
     *   "Dom. 4"                                         -> "domingo 4"
     *   "Numero\nde\nFaltas" (celda con saltos de línea) -> "numero faltas"
     * Pasos: símbolos de número (#, N°) -> "numero", sin acentos ni signos, sin palabras de relleno,
     * abreviaturas conocidas expandidas.
     */
    public static function claveEncabezado(?string $valor): string
    {
        $v = (string) $valor;
        $v = preg_replace('/\b[nN]\s*[°º]|\b[nN]\.\s*[°º]|#|[°º]/u', ' numero ', $v) ?? $v;
        $v = self::normalizar($v);
        $v = preg_replace('/[^a-z0-9]+/', ' ', $v) ?? $v;

        $tokens = [];
        foreach (preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (in_array($t, self::RELLENO, true)) {
                continue;
            }
            $tokens[] = self::ABREVIATURAS[$t] ?? $t;
        }

        return implode(' ', $tokens);
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
