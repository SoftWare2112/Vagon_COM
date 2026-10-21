<?php

namespace App\Services\Etl\Dto;

/** Error (bloquea la carga) o advertencia (no bloquea) con ubicación exacta. */
final class Mensaje
{
    public const ERROR = 'ERROR';

    public const ADVERTENCIA = 'ADVERTENCIA';

    /**
     * Advertencias que NO bloquean pero que RH debe confirmar expresamente al aplicar,
     * porque pueden significar datos perdidos o guardados en la quincena equivocada.
     */
    public const CONFIRMAR = [
        'COLUMNA_AUSENTE', 'COLUMNA_SIN_ENCABEZADO', 'DIA_NO_ES_DOMINGO',
        'PERIODO_DURACION_INUSUAL', 'EJERCICIO_DISTINTO',
        'NOMINA_CONTINUIDAD', 'PERIODO_YA_CARGADO',   // v6
    ];

    public function __construct(
        public readonly string $nivel,
        public readonly string $codigo,
        public readonly string $mensaje,
        public readonly ?int $fila = null,
        public readonly ?string $columna = null,
        public readonly ?string $celda = null,
        public readonly ?string $valorRecibido = null,
    ) {}

    public static function error(string $codigo, string $mensaje, ?int $fila = null, ?string $columna = null, ?string $celda = null, mixed $valor = null): self
    {
        return new self(self::ERROR, $codigo, $mensaje, $fila, $columna, $celda, self::aTexto($valor));
    }

    public static function advertencia(string $codigo, string $mensaje, ?int $fila = null, ?string $columna = null, ?string $celda = null, mixed $valor = null): self
    {
        return new self(self::ADVERTENCIA, $codigo, $mensaje, $fila, $columna, $celda, self::aTexto($valor));
    }

    public function esError(): bool
    {
        return $this->nivel === self::ERROR;
    }

    public function requiereConfirmacion(): bool
    {
        return ! $this->esError() && in_array($this->codigo, self::CONFIRMAR, true);
    }

    public function toArray(): array
    {
        return [
            'nivel' => $this->nivel,
            'codigo' => $this->codigo,
            'mensaje' => $this->mensaje,
            'fila' => $this->fila,
            'columna' => $this->columna,
            'celda' => $this->celda,
            'valor_recibido' => $this->valorRecibido,
            'confirmar' => $this->requiereConfirmacion(),
        ];
    }

    private static function aTexto(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        return mb_substr(is_scalar($v) ? (string) $v : json_encode($v), 0, 255);
    }
}
