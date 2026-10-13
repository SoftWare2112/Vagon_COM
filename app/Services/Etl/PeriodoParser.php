<?php

namespace App\Services\Etl;

use App\Services\Etl\Dto\Periodo;
use App\Support\Texto;
use DateTimeImmutable;

/**
 * Convierte el encabezado del periodo de nómina en fechas reales. Acepta, entre otros:
 *   "1 de octubre al 13 de octubre de 2026"      (año al final, opcional)
 *   "1 de octubre al 13 de octubre"              (sin año: se usa el que indica el usuario)
 *   "12 al 27 de agosto" · "del 1 al 13 de oct"  (mes de inicio omitido, meses abreviados)
 *   "27 de diciembre de 2026 al 9 de enero de 2027" (cruza de año)
 *   "01/10/2026 al 13/10/2026" · "1/10 al 13/10"  (numérico)
 * El periodo es el rango de corte de nómina: no se asume que sea del 1 al 15.
 */
final class PeriodoParser
{
    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6,
        'julio' => 7, 'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
        'noviembre' => 11, 'diciembre' => 12,
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7,
        'ago' => 8, 'sep' => 9, 'sept' => 9, 'set' => 9, 'oct' => 10, 'nov' => 11, 'dic' => 12,
    ];

    // "1 de octubre de 2026 al 13 de octubre de 2026" (cada año opcional; el mes de inicio también)
    private const PATRON_TEXTO = '/^(?:del?\s+)?(\d{1,2})(?:\s+de)?(?:\s+([a-z]+))?(?:\s+(?:de|del)\s+(\d{4}))?\s+(?:al|a)\s+(\d{1,2})(?:\s+de)?\s+([a-z]+)(?:\s+(?:de|del)\s+(\d{4}))?$/';

    // "01/10/2026 al 13/10/2026" o "1/10 al 13/10"
    private const PATRON_NUMERICO = '#^(?:del?\s+)?(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?\s+(?:al|a)\s+(\d{1,2})/(\d{1,2})(?:/(\d{2}|\d{4}))?$#';

    public static function pareceRango(string $normalizado): bool
    {
        if (preg_match(self::PATRON_NUMERICO, $normalizado)) {
            return true;
        }
        if (! preg_match(self::PATRON_TEXTO, $normalizado, $m)) {
            return false;
        }

        // Evita confundir otros encabezados: los meses deben ser meses reales
        return isset(self::MESES[$m[5]]) && ($m[2] === '' || isset(self::MESES[$m[2]]));
    }

    /** @param int $anio  año a usar si el encabezado no lo trae (lo indica el usuario al subir) */
    public function parsear(string $etiqueta, int $anio): ?Periodo
    {
        $norm = Texto::normalizarEncabezado($etiqueta);

        if (preg_match(self::PATRON_NUMERICO, $norm, $m)) {
            [$dIni, $mIni, $aIni, $dFin, $mFin, $aFin] = [(int) $m[1], (int) $m[2], $this->anio($m[3] ?? ''), (int) $m[4], (int) $m[5], $this->anio($m[6] ?? '')];
        } elseif (preg_match(self::PATRON_TEXTO, $norm, $m)) {
            $mFin = self::MESES[$m[5]] ?? null;
            $mIni = $m[2] !== '' ? (self::MESES[$m[2]] ?? null) : $mFin;
            if ($mFin === null || $mIni === null) {
                return null;
            }
            [$dIni, $aIni, $dFin, $aFin] = [(int) $m[1], $this->anio($m[3] ?? ''), (int) $m[4], $this->anio($m[6] ?? '')];
        } else {
            return null;
        }

        $anioEncabezado = $aFin ?? $aIni;
        $aFin ??= $aIni !== null && $mIni > $mFin ? $aIni + 1 : ($aIni ?? $anio);
        $aIni ??= $mIni > $mFin ? $aFin - 1 : $aFin;          // p. ej. "27 de diciembre al 9 de enero de 2027"

        if (! checkdate($mIni, $dIni, $aIni) || ! checkdate($mFin, $dFin, $aFin)) {
            return null;
        }

        $inicio = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $aIni, $mIni, $dIni));
        $fin = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $aFin, $mFin, $dFin));
        $dias = $inicio->diff($fin)->days;

        // Un periodo de nómina va de 1 a 31 días; otra cosa es un error de captura
        if ($inicio > $fin || $dias > 31) {
            return null;
        }

        return new Periodo($inicio, $fin, Texto::limpiar($etiqueta), $anioEncabezado);
    }

    private function anio(string $txt): ?int
    {
        if ($txt === '') {
            return null;
        }
        $a = (int) $txt;

        return $a < 100 ? 2000 + $a : $a;
    }
}
