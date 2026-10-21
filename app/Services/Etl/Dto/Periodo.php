<?php

namespace App\Services\Etl\Dto;

use DateTimeImmutable;

/**
 * Quincena de nómina. Se identifica por Ejercicio + número de periodo cuando el archivo los trae
 * (bloque "Ejercicio: 2026 / Periodo: 17"); el encabezado de la columna es solo una etiqueta de texto.
 */
final class Periodo
{
    public function __construct(
        public readonly DateTimeImmutable $inicio,
        public readonly DateTimeImmutable $fin,
        public readonly string $etiqueta,
        public readonly ?int $anioEncabezado = null,   // año escrito en el encabezado (si venía)
        public readonly ?int $ejercicio = null,        // "Ejercicio: 2026"
        public readonly ?int $numero = null,           // "Periodo: 17"
        public readonly ?string $tipo = null,          // "Tipo Periodo: Quincenal"
        public readonly ?string $titulo = null,        // "Incidencias para pago de nomina" (v6)
    ) {}

    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /** "1 de octubre al 13 de octubre de 2026" a partir de las fechas (periodos capturados a mano). */
    public static function etiquetaDe(DateTimeImmutable $inicio, DateTimeImmutable $fin): string
    {
        $mes = fn (DateTimeImmutable $d) => self::MESES[(int) $d->format('n') - 1];
        $anioIni = $inicio->format('Y') !== $fin->format('Y') ? ' de '.$inicio->format('Y') : '';

        return "{$inicio->format('j')} de {$mes($inicio)}{$anioIni} al {$fin->format('j')} de {$mes($fin)} de {$fin->format('Y')}";
    }

    /** Días que abarca, contando inicio y fin (1 al 13 de octubre = 13). */
    public function dias(): int
    {
        return $this->inicio->diff($this->fin)->days + 1;
    }

    public function conMetadatos(array $meta): self
    {
        return new self(
            $this->inicio, $this->fin, $this->etiqueta, $this->anioEncabezado,
            $meta['ejercicio'] ?? ($meta['numero'] !== null ? (int) $this->fin->format('Y') : null),
            $meta['numero'] ?? null,
            $meta['tipo'] ?? null,
            $meta['titulo'] ?? null,
        );
    }

    /** Fecha dentro del periodo cuyo día del mes es $dia (p. ej. 16 -> 2026-08-16). */
    public function fechaDelDia(int $dia): ?DateTimeImmutable
    {
        for ($d = $this->inicio; $d <= $this->fin; $d = $d->modify('+1 day')) {
            if ((int) $d->format('j') === $dia) {
                return $d;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'inicio' => $this->inicio->format('Y-m-d'),
            'fin' => $this->fin->format('Y-m-d'),
            'etiqueta' => $this->etiqueta,
            'ejercicio' => $this->ejercicio,
            'numero' => $this->numero,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
        ];
    }
}
