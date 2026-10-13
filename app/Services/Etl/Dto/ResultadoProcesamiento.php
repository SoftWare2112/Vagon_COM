<?php

namespace App\Services\Etl\Dto;

/** Salida de todo el pipeline (transformado + validado), lista para staging. */
final class ResultadoProcesamiento
{
    /**
     * @param  list<array{fila:int, datos:array}>  $filas
     * @param  list<Mensaje>  $mensajes
     */
    public function __construct(
        public readonly ?MapaColumnas $mapa,
        public readonly ?Periodo $periodo,
        public readonly array $filas,
        public readonly array $mensajes,
    ) {}

    public function errores(): array
    {
        return array_values(array_filter($this->mensajes, fn (Mensaje $m) => $m->esError()));
    }

    public function advertencias(): array
    {
        return array_values(array_filter($this->mensajes, fn (Mensaje $m) => ! $m->esError()));
    }

    public function tieneErrores(): bool
    {
        return $this->errores() !== [];
    }

    /** Hash de los DATOS (no del archivo): detecta el mismo contenido guardado con otro nombre. */
    public function contenidoHash(): string
    {
        $datos = array_map(fn ($f) => $f['datos'], $this->filas);
        usort($datos, fn ($a, $b) => [$a['nombre_normalizado'], $a['fecha_ingreso']] <=> [$b['nombre_normalizado'], $b['fecha_ingreso']]);

        return hash('sha256', json_encode([$this->periodo?->etiqueta, $datos]));
    }
}
