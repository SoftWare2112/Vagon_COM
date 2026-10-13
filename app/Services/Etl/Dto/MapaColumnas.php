<?php

namespace App\Services\Etl\Dto;

/**
 * Qué representa cada columna del Excel recibido.
 *  - campos:   ['A' => 'nombre_completo', 'B' => 'fecha_ingreso', 'N' => 'bono_transporte', ...]
 *  - tipos:    ['nombre_completo' => 'texto', ...]
 *  - extras:   claves de columnas PERSONALIZADAS (se guardan en registros.valores_extra)
 *  - domingos: ['K' => ['dia' => 16, 'etiqueta' => 'Domingo 16'], ...]
 *  - columnaPeriodo / etiquetaPeriodo: "12 de agosto al 27 de agosto"
 *  - ausentes: claves conocidas que NO vienen en este archivo (no se tocan al aplicar)
 */
final class MapaColumnas
{
    public function __construct(
        public readonly array $campos,
        public readonly array $domingos,
        public readonly ?string $columnaPeriodo,
        public readonly ?string $etiquetaPeriodo,
        public readonly array $tipos = [],
        public readonly array $extras = [],
        public readonly array $ausentes = [],
    ) {}

    public function columnaDe(string $campo): ?string
    {
        $col = array_search($campo, $this->campos, true);

        return $col === false ? null : $col;
    }

    public function toArray(): array
    {
        return [
            'campos' => $this->campos,
            'tipos' => $this->tipos,
            'extras' => $this->extras,
            'domingos' => $this->domingos,
            'columna_periodo' => $this->columnaPeriodo,
            'etiqueta_periodo' => $this->etiquetaPeriodo,
            'ausentes' => $this->ausentes,
        ];
    }
}
