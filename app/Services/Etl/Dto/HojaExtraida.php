<?php

namespace App\Services\Etl\Dto;

/**
 * Resultado de la EXTRACCIÓN: valores crudos con su coordenada.
 *  - encabezados: ['A' => 'Nombre Completo', 'B' => 'PUESTO', ...]
 *  - filas: [['fila' => 2, 'celdas' => ['A' => 'Reynaldo...', ...]], ...]
 *  - filasPrevias: lo que está ARRIBA de los encabezados (título, "Ejercicio: 2026", "Periodo: 17")
 */
final class HojaExtraida
{
    /**
     * @param  array<string, mixed>  $encabezados
     * @param  list<array{fila:int, celdas:array<string, mixed>}>  $filas
     */
    public function __construct(
        public readonly array $encabezados,
        public readonly array $filas,
        public readonly int $filaEncabezado = 1,
        public readonly string $nombre = 'Hoja1',
        public readonly array $filasPrevias = [],
    ) {}
}
