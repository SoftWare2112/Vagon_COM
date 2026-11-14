<?php

namespace App\Support\Hojas\Importacion;

/**
 * v9 — Traduce un código de formato de número de Excel ("#,##0.00", "dd/mm/yyyy", "$#,##0") al formato
 * de la plataforma (ValidadorEstilo::FORMATOS) y convierte números de serie de Excel en fechas.
 * Lógica pura (sin Laravel).
 */
final class FormatoExcel
{
    public static function tipo(?string $codigo): string
    {
        $codigo = trim((string) $codigo);
        if ($codigo === '' || strcasecmp($codigo, 'General') === 0) {
            return 'general';
        }
        if ($codigo === '@') {
            return 'texto';
        }
        $seccion = explode(';', $codigo)[0];   // la primera sección es la de los positivos
        if (preg_match('/\[(h+|m+|s+)\]/i', $seccion)) {
            return 'hora';   // tiempo transcurrido: [h]:mm
        }
        $moneda = (bool) preg_match('/\[\$[^\]]*[$€£¥][^\]]*\]|[$€£¥]|\[\$(MXN|USD|EUR)/u', $seccion);
        // Sin textos entre comillas, caracteres escapados, colores/condiciones [Red] ni monedas [$$-409]
        $limpio = preg_replace(['/"[^"]*"/', '/\\\\./', '/\[[^\]]*\]/', '/_./', '/\*./'], '', $seccion);
        $limpio = strtolower((string) $limpio);

        if (str_contains($limpio, '%')) {
            return 'porcentaje';
        }
        if ($moneda) {
            return 'moneda';
        }
        $tieneFecha = (bool) preg_match('/[dy]|m{3,}/', $limpio) || ((bool) preg_match('/m/', $limpio) && ! preg_match('/[hs0#]/', $limpio));
        $tieneHora = (bool) preg_match('/[hs]|am\/pm|a\/p/', $limpio);
        if ($tieneFecha && $tieneHora) {
            return 'fecha_hora';
        }
        if ($tieneFecha) {
            return 'fecha';
        }
        if ($tieneHora) {
            return 'hora';
        }
        if (preg_match('/[0#?]/', $limpio)) {
            return preg_match('/\.[0#?]/', $limpio) ? 'numero' : 'entero';
        }

        return 'general';
    }

    /**
     * Número de serie de Excel (sistema 1900) a fecha y hora.
     * @return array{fecha: string, hora: string}  'Y-m-d' y 'H:i'
     */
    public static function desdeSerial(float $serial): array
    {
        $dias = (int) floor($serial);
        $segundos = (int) round(($serial - $dias) * 86400);
        if ($segundos >= 86400) {
            $dias++;
            $segundos -= 86400;
        }
        // Excel cuenta el 29/02/1900 que no existió: antes del día 60 la base se recorre un día
        $base = new \DateTimeImmutable($dias < 60 ? '1899-12-31' : '1899-12-30');
        $fecha = $base->modify("+{$dias} days");

        return ['fecha' => $fecha->format('Y-m-d'), 'hora' => sprintf('%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60))];
    }
}
