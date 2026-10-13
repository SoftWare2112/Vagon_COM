<?php

namespace App\Services\Etl;

use App\Services\Etl\Dto\MapaColumnas;
use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\Dto\Periodo;
use App\Support\ConversorTipo;
use App\Support\Texto;

/**
 * TRANSFORMACIÓN + VALIDACIÓN por celda.
 * El Excel trae "texto" ($600.00, 15/01/2018, 1); aquí se convierte al tipo real para que
 * la BD ordene y filtre bien. No se CALCULA nada: los valores se leen tal como vienen
 * (RH los corrige en la web si llegan mal o vacíos). Cada problema lleva su celda ("G14").
 *
 * Solo se incluyen en $datos las columnas que SÍ vienen en el archivo: una columna
 * ausente no aparece, y al aplicar no se toca su valor guardado.
 */
final class RowTransformer
{
    /** @param array<string,string> $etiquetas  clave => encabezado (para los mensajes) */
    public function __construct(
        private readonly int $maxFaltas = 31,
        private readonly array $etiquetas = [],
    ) {}

    /** @return array{0: array, 1: list<Mensaje>} */
    public function transformar(int $fila, array $celdas, MapaColumnas $mapa, Periodo $periodo): array
    {
        $datos = ['extras' => []];
        $mensajes = [];

        foreach ($mapa->campos as $col => $clave) {
            $tipo = $clave === 'nombre_completo' ? 'nombre' : ($mapa->tipos[$clave] ?? 'texto');
            $obligatoria = in_array($clave, ['nombre_completo', 'fecha_ingreso'], true);
            [$valor, $msg] = $this->convertir($tipo, $celdas[$col] ?? null, $this->etiqueta($clave), $fila, $col, $obligatoria, $clave);
            if ($msg) {
                $mensajes[] = $msg;
            }
            if (in_array($clave, $mapa->extras, true)) {
                $datos['extras'][$clave] = $valor;
            } else {
                $datos[$clave] = $valor;
            }
        }

        if ($mapa->domingos !== []) {
            $datos['domingos'] = [];
            foreach ($mapa->domingos as $col => $info) {
                [$cantidad, $msg] = $this->convertir('decimal', $celdas[$col] ?? null, $info['etiqueta'], $fila, $col, false);
                if ($msg) {
                    $mensajes[] = $msg;
                }
                $datos['domingos'][] = [
                    'fecha' => $periodo->fechaDelDia($info['dia'])?->format('Y-m-d'),
                    'etiqueta' => $info['etiqueta'],
                    'cantidad' => $cantidad,     // null si la celda viene vacía
                ];
            }
        }

        if ($mapa->columnaPeriodo) {
            // Texto libre, tal como viene (confirmado por RH)
            [$datos['valor_periodo'], $msg] = $this->convertir('texto', $celdas[$mapa->columnaPeriodo] ?? null, (string) $mapa->etiquetaPeriodo, $fila, $mapa->columnaPeriodo, false);
            if ($msg) {
                $mensajes[] = $msg;
            }
        }

        $datos['nombre_normalizado'] = Texto::normalizar($datos['nombre_completo'] ?? '');

        return [$datos, $mensajes];
    }

    /** @return array{0:mixed, 1:?Mensaje} */
    private function convertir(string $tipo, mixed $valor, string $etiqueta, int $fila, string $col, bool $obligatoria, string $clave = ''): array
    {
        $celda = $col.$fila;
        $vacio = $valor === null || (is_string($valor) && trim($valor) === '');

        if (is_string($valor) && Texto::tieneCaracteresInvalidos($valor)) {
            return [null, Mensaje::error('CARACTER_INVALIDO', "Celda {$celda} ({$etiqueta}): contiene caracteres no válidos.", $fila, $etiqueta, $celda, $valor)];
        }
        if ($vacio) {
            return $obligatoria
                ? [null, Mensaje::error('CELDA_VACIA', "Fila {$fila}, {$etiqueta} (celda {$celda}): está vacía.", $fila, $etiqueta, $celda)]
                : [null, null];
        }

        if ($tipo === 'nombre') {
            $v = Texto::limpiar((string) $valor);
            if (! preg_match("/^[\p{L}][\p{L} .'\-]*$/u", $v)) {
                return [null, Mensaje::error('CARACTER_INVALIDO', "Celda {$celda} ({$etiqueta}): solo se permiten letras, espacios, punto, guion y apóstrofo.", $fila, $etiqueta, $celda, $valor)];
            }

            return [mb_substr($v, 0, 150), null];
        }

        // Mismas reglas que la edición web y el cambio de tipo de columna
        $r = ConversorTipo::convertir($valor, $tipo);
        if (! $r['ok']) {
            return [null, $tipo === 'fecha'
                ? Mensaje::error('FECHA_INVALIDA', "Celda {$celda} ({$etiqueta}): fecha no reconocida, usa dd/mm/aaaa.", $fila, $etiqueta, $celda, $valor)
                : $this->tipo($etiqueta, $fila, $celda, $valor, $r['esperado'])];
        }
        if ($clave === 'num_faltas' && $r['valor'] > $this->maxFaltas) {
            return [null, Mensaje::error('FUERA_DE_RANGO', "Celda {$celda} ({$etiqueta}): {$r['valor']} supera el máximo permitido ({$this->maxFaltas}).", $fila, $etiqueta, $celda, $valor)];
        }

        return [$r['valor'], null];
    }

    private function tipo(string $etiqueta, int $fila, string $celda, mixed $valor, string $esperado): Mensaje
    {
        $txt = is_scalar($valor) ? (string) $valor : '';

        return Mensaje::error('TIPO_INVALIDO', "Celda {$celda} ({$etiqueta}): se esperaba {$esperado}; se recibió '{$txt}'.", $fila, $etiqueta, $celda, $valor);
    }

    private function etiqueta(string $clave): string
    {
        return $this->etiquetas[$clave] ?? (new HeaderMapper)->etiqueta($clave);
    }
}
