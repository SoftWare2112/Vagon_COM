<?php

namespace App\Services\Etl\Dto;

/**
 * Resultado de la EXTRACCIÓN: valores crudos con su coordenada.
 *  - encabezados: ['A' => 'Nombre Completo', 'B' => 'PUESTO', ...]
 *  - filas: [['fila' => 2, 'celdas' => ['A' => 'Reynaldo...', ...]], ...]
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
    ) {}
}
