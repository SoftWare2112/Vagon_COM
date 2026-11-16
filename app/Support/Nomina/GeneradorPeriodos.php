<?php

namespace App\Support\Nomina;

/**
 * v11 — Calendario de periodos de pago de un ejercicio: semanal (52-53), quincenal (24) o mensual (12).
 * Marca el periodo que "cierra el mes" (ahí se pagan los bonos mensuales, como el de puntualidad).
 * Lógica pura (sin Laravel).
 */
final class GeneradorPeriodos
{
    public const TIPOS = ['semanal' => 7, 'quincenal' => 15, 'mensual' => 30];

    /**
     * @param  string|null  $primerDia  semanal: fecha en que inicia la semana 1 (por defecto, el lunes de la semana del 1 de enero)
     * @return list<array{numero: int, inicio: string, fin: string, fecha_pago: string, dias: int, fin_de_mes: bool, mes: int}>
     */
    public static function generar(string $tipo, int $ejercicio, ?string $primerDia = null): array
    {
        return match ($tipo) {
            'quincenal' => self::quincenal($ejercicio),
            'mensual' => self::mensual($ejercicio),
            'semanal' => self::semanal($ejercicio, $primerDia),
            default => throw new \InvalidArgumentException("Tipo de periodo desconocido: {$tipo}"),
        };
    }

    private static function quincenal(int $a): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $primero = new \DateTimeImmutable(sprintf('%d-%02d-01', $a, $m));
            $ultimo = $primero->modify('last day of this month');
            $out[] = self::periodo(count($out) + 1, $primero, $primero->modify('+14 days'), 15, false);
            $out[] = self::periodo(count($out) + 1, $primero->modify('+15 days'), $ultimo, 15, true);   // la nómina quincenal paga 15 días fijos
        }

        return $out;
    }

    private static function mensual(int $a): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $primero = new \DateTimeImmutable(sprintf('%d-%02d-01', $a, $m));
            $out[] = self::periodo($m, $primero, $primero->modify('last day of this month'), 30, true);
        }

        return $out;
    }

    private static function semanal(int $a, ?string $primerDia): array
    {
        $inicio = $primerDia ? new \DateTimeImmutable($primerDia) : (new \DateTimeImmutable("{$a}-01-01"))->modify('monday this week');
        $finAnio = new \DateTimeImmutable("{$a}-12-31");
        $out = [];
        for ($d = $inicio; $d <= $finAnio; $d = $d->modify('+7 days')) {
            $fin = $d->modify('+6 days');
            // Cierra el mes la última semana que TERMINA dentro del mes
            $finDeMes = $fin->modify('+7 days')->format('m') !== $fin->format('m');
            $out[] = self::periodo(count($out) + 1, $d, $fin, 7, $finDeMes);
        }

        return $out;
    }

    private static function periodo(int $n, \DateTimeImmutable $ini, \DateTimeImmutable $fin, int $dias, bool $finDeMes): array
    {
        return ['numero' => $n, 'inicio' => $ini->format('Y-m-d'), 'fin' => $fin->format('Y-m-d'), 'fecha_pago' => $fin->format('Y-m-d'),
            'dias' => $dias, 'fin_de_mes' => $finDeMes, 'mes' => (int) $fin->format('n')];
    }
}
