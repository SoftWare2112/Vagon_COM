<?php

namespace App\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** DTO con los 4 filtros del cuestionario + orden + paginación. */
final class FiltrosTabla
{
    public function __construct(
        public readonly int $periodoId,
        public readonly ?string $nombre = null,
        public readonly ?string $puesto = null,
        public readonly ?string $ingresoDesde = null,   // Y-m-d
        public readonly ?string $ingresoHasta = null,   // Y-m-d
        public readonly ?int $faltas = null,
        public readonly string $orden = 'numero_nomina',   // v6: mismo orden que el archivo de RH
        public readonly string $direccion = 'asc',
        public readonly int $porPagina = 50,
    ) {}

    /** El frontend manda fechas como dd/mm/aaaa (formato pedido por RH). */
    public static function desdeRequest(Request $r, int $periodoId): self
    {
        return new self(
            periodoId: $periodoId,
            nombre: $r->filled('nombre') ? (string) $r->input('nombre') : null,
            puesto: $r->filled('puesto') ? (string) $r->input('puesto') : null,
            ingresoDesde: self::fecha($r->input('ingreso_desde')),
            ingresoHasta: self::fecha($r->input('ingreso_hasta')),
            faltas: $r->filled('faltas') ? (int) $r->input('faltas') : null,
            // el repositorio valida el orden contra una lista blanca / el catálogo de columnas
            orden: preg_match('/^[a-z0-9_:]{1,70}$/', (string) $r->input('orden')) ? $r->input('orden') : 'numero_nomina',
            direccion: $r->input('direccion') === 'desc' ? 'desc' : 'asc',
            porPagina: min(max((int) $r->input('por_pagina', 50), 10), 200),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    private static function fecha(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!d/m/Y', $v)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
