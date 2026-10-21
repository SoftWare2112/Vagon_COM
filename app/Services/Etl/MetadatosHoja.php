<?php

namespace App\Services\Etl;

use App\Support\Texto;

/**
 * Lee el bloque de datos que RH pone ARRIBA de la tabla (formato Q17):
 *
 *     Tipo Periodo:  Quincenal
 *     Ejercicio:     2026
 *     Periodo:       17
 *
 * Cada dato es una etiqueta (con o sin ":") y su valor en la siguiente celda con contenido de la
 * misma fila. El texto restante más largo es el TÍTULO ("Incidencias para pago de nomina"), que se
 * reproduce al exportar e imprimir (v6).
 * Lógica pura: recibe las filas como arreglos.
 */
final class MetadatosHoja
{
    private const ETIQUETAS = [
        'ejercicio' => 'ejercicio', 'anio' => 'ejercicio', 'ano' => 'ejercicio', 'anio fiscal' => 'ejercicio',
        'periodo' => 'numero', 'numero periodo' => 'numero', 'periodo numero' => 'numero',
        'quincena' => 'numero', 'numero quincena' => 'numero',
        'tipo periodo' => 'tipo', 'tipo' => 'tipo', 'tipo nomina' => 'tipo',
    ];

    /**
     * @param  list<array<int|string, mixed>>  $filas  filas que están arriba de los encabezados
     * @return array{ejercicio:?int, numero:?int, tipo:?string, titulo:?string}
     */
    public function extraer(array $filas): array
    {
        $r = ['ejercicio' => null, 'numero' => null, 'tipo' => null];
        $sueltos = [];

        foreach ($filas as $fila) {
            $celdas = array_values($fila);
            $usadas = [];
            foreach ($celdas as $i => $c) {
                if (! is_string($c) || isset($usadas[$i])) {
                    continue;
                }
                $campo = self::ETIQUETAS[Texto::claveEncabezado($c)] ?? null;
                if ($campo === null) {
                    $sueltos[] = Texto::limpiar($c);   // candidato a título

                    continue;
                }
                if ($r[$campo] !== null) {
                    continue;
                }
                [$valor, $j] = $this->siguienteValor($celdas, $i);
                if ($j !== null) {
                    $usadas[$j] = true;
                }
                $r[$campo] = match ($campo) {
                    'ejercicio' => $this->entero($valor, 2000, 2100),
                    'numero' => $this->entero($valor, 1, 60),
                    default => $valor === null ? null : mb_substr(Texto::limpiar((string) $valor), 0, 20),
                };
            }
        }

        usort($sueltos, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $r['titulo'] = ($sueltos[0] ?? '') !== '' && mb_strlen($sueltos[0]) >= 5 ? mb_substr($sueltos[0], 0, 150) : null;

        return $r;
    }

    /** @return array{0:mixed, 1:?int}  valor y su posición */
    private function siguienteValor(array $celdas, int $desde): array
    {
        for ($j = $desde + 1, $n = count($celdas); $j < $n; $j++) {
            $v = $celdas[$j];
            if ($v !== null && trim((string) $v) !== '') {
                return [$v, $j];
            }
        }

        return [null, null];
    }

    private function entero(mixed $v, int $min, int $max): ?int
    {
        if ($v === null) {
            return null;
        }
        $txt = trim((string) $v);
        if (! preg_match('/^\d+(\.0+)?$/', $txt)) {
            return null;
        }
        $n = (int) $txt;

        return $n >= $min && $n <= $max ? $n : null;
    }
}
