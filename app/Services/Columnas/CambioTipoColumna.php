<?php

namespace App\Services\Columnas;

use App\Exceptions\ReglaNegocioException;
use App\Models\AuditLog;
use App\Models\Columna;
use App\Models\Registro;
use App\Support\ConversorTipo;
use Illuminate\Support\Facades\DB;

/**
 * Cambia el tipo de una columna agregada por RH (p. ej. de texto a dinero) y convierte
 * los valores YA guardados con las mismas reglas del ETL (ConversorTipo).
 *
 * 1) simular=true  -> solo informa cuántos valores se convierten y cuáles no (no escribe nada).
 * 2) aplicar       -> si todos se convierten, aplica en una transacción.
 *                     Si alguno no se puede convertir, se rechaza salvo forzar=true,
 *                     en cuyo caso esos valores quedan vacíos y el original queda en la auditoría.
 */
class CambioTipoColumna
{
    private const MAX_EJEMPLOS = 50;

    public function ejecutar(Columna $columna, string $tipo, bool $simular, bool $forzar): array
    {
        if ($columna->origen !== 'personalizada') {
            throw new ReglaNegocioException('Solo las columnas agregadas por RH pueden cambiar de tipo; las del sistema tienen un tipo fijo.');
        }
        if ($columna->tipo === $tipo) {
            throw new ReglaNegocioException("La columna ya es de tipo {$tipo}.");
        }

        $clave = $columna->clave;
        $convertidos = [];   // registro_id => valor nuevo
        $fallas = [];        // registro_id => ['antes' => ..., 'ejemplo' => [...]]

        Registro::withTrashed()->whereNotNull('valores_extra')->with(['empleado:id,numero_empleado,nombre_completo', 'periodo:id,etiqueta'])
            ->chunkById(500, function ($registros) use ($clave, $tipo, &$convertidos, &$fallas) {
                foreach ($registros as $r) {
                    $valor = $r->valores_extra[$clave] ?? null;
                    if ($valor === null || $valor === '') {
                        continue;
                    }
                    $res = ConversorTipo::convertir($valor, $tipo);
                    if ($res['ok']) {
                        $convertidos[$r->id] = $res['valor'];
                    } else {
                        $fallas[$r->id] = [
                            'antes' => $valor,
                            'ejemplo' => [
                                'numero_empleado' => $r->empleado?->numero_empleado,
                                'nombre' => $r->empleado?->nombre_completo,
                                'periodo' => $r->periodo?->etiqueta,
                                'valor' => is_scalar($valor) ? (string) $valor : json_encode($valor),
                                'esperado' => $res['esperado'],
                            ],
                        ];
                    }
                }
            });

        $reporte = [
            'columna' => $columna->etiqueta,
            'tipo_actual' => $columna->tipo,
            'tipo_nuevo' => $tipo,
            'con_valor' => count($convertidos) + count($fallas),
            'convertibles' => count($convertidos),
            'no_convertibles' => count($fallas),
            'ejemplos' => array_slice(array_column($fallas, 'ejemplo'), 0, self::MAX_EJEMPLOS),
            'aplicado' => false,
        ];

        if ($simular) {
            return $reporte;
        }
        if ($fallas !== [] && ! $forzar) {
            throw new ReglaNegocioException(
                "{$reporte['no_convertibles']} valor(es) no se pueden convertir a {$tipo}. Corrígelos o confirma para dejarlos vacíos.",
                422, ['reporte' => $reporte]
            );
        }

        DB::transaction(function () use ($columna, $tipo, $clave, $convertidos, $fallas) {
            $nuevos = $convertidos + array_fill_keys(array_keys($fallas), null);
            foreach (array_chunk($nuevos, 500, true) as $lote) {
                Registro::withTrashed()->whereIn('id', array_keys($lote))->lockForUpdate()->get()
                    ->each(function (Registro $r) use ($clave, $lote) {
                        $extras = $r->valores_extra ?? [];
                        $extras[$clave] = $lote[$r->id];
                        $r->valores_extra = $extras;
                        $r->version++;            // quien tenga la fila abierta recibirá 409 y recargará
                        $r->saveQuietly();        // una sola entrada de auditoría para todo el cambio (abajo)
                    });
            }

            $antes = $columna->tipo;
            $columna->update(['tipo' => $tipo]);
            AuditLog::registrar('CAMBIO_TIPO', 'Columna', $columna->id,
                ['tipo' => $antes, 'valores_no_convertibles' => array_map(fn ($f) => $f['antes'], $fallas)],
                ['tipo' => $tipo, 'convertidos' => count($convertidos), 'vaciados' => count($fallas)]);
        });

        return [...$reporte, 'aplicado' => true];
    }
}
