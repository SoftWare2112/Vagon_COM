<?php

namespace App\Services\Registros;

use App\Models\Columna;
use App\Models\Periodo;
use App\Models\Registro;
use Illuminate\Support\Collection;

/**
 * Convierte el catálogo de columnas + un Registro en {clave => valor}.
 * La tabla web, el modal de resumen, el Histórico, el Excel y el PDF usan ESTA clase,
 * así que todos muestran las mismas columnas, en el mismo orden.
 *
 * Claves resultantes: 'nombre_completo', 'num_faltas', 'domingo:2026-08-16', 'valor_periodo', 'extra:bono_transporte'.
 */
class PresentadorFila
{
    private ?Collection $catalogo = null;

    /** @return list<array{clave:string, etiqueta:string, tipo:string, editable:bool, origen:string}> */
    public function columnas(Periodo $periodo, bool $soloVisibles = true): array
    {
        $this->catalogo ??= Columna::activas()->get();
        $salida = [];

        foreach ($this->catalogo as $c) {
            if ($soloVisibles && ! $c->visible) {
                continue;
            }
            match ($c->origen) {
                'domingos' => array_push($salida, ...array_map(fn ($d) => [
                    'clave' => 'domingo:'.$d['fecha'], 'etiqueta' => $d['etiqueta'], 'tipo' => 'decimal', 'editable' => true, 'origen' => 'domingos',
                ], $periodo->columnasDomingo())),
                'periodo' => $salida[] = ['clave' => 'valor_periodo', 'etiqueta' => $periodo->etiqueta, 'tipo' => 'texto', 'editable' => true, 'origen' => 'periodo'],
                'personalizada' => $salida[] = ['clave' => 'extra:'.$c->clave, 'etiqueta' => $c->etiqueta, 'tipo' => $c->tipo, 'editable' => true, 'origen' => 'personalizada'],
                default => $salida[] = ['clave' => $c->clave, 'etiqueta' => $c->etiqueta, 'tipo' => $c->tipo, 'editable' => $c->editable, 'origen' => 'sistema'],
            };
        }

        return $salida;
    }

    /** Valor "crudo" de cada columna (números como número, fechas dd/mm/aaaa). */
    public function valores(Registro $r, array $columnas): array
    {
        $e = $r->empleado;
        $dom = $r->domingos->keyBy(fn ($d) => $d->fecha->format('Y-m-d'));
        $v = [];

        foreach ($columnas as $c) {
            $clave = $c['clave'];
            $v[$clave] = match (true) {
                $clave === 'nombre_completo' => $e?->nombre_completo,
                $clave === 'puesto' => $r->puesto?->nombre,
                $clave === 'fecha_ingreso' => $e?->fecha_ingreso?->format('d/m/Y'),
                str_starts_with($clave, 'domingo:') => ($x = $dom->get(substr($clave, 8))?->cantidad) === null ? null : (float) $x,
                str_starts_with($clave, 'extra:') && $c['tipo'] === 'fecha' => ($x = $r->valores_extra[substr($clave, 6)] ?? null) ? date('d/m/Y', strtotime($x)) : null,
                str_starts_with($clave, 'extra:') => $r->valores_extra[substr($clave, 6)] ?? null,
                in_array($c['tipo'], ['dinero', 'decimal'], true) => $r->{$clave} === null ? null : (float) $r->{$clave},
                default => $r->{$clave},
            };
        }

        return $v;
    }

    /** Valor formateado para Excel/PDF ($600.00, Sí/No). */
    public function formatear(mixed $valor, string $tipo): string|int|float|null
    {
        return match (true) {
            $valor === null => null,
            $tipo === 'dinero' => '$'.number_format((float) $valor, 2),
            $tipo === 'booleano' => $valor ? 'Sí' : 'No',
            default => $valor,
        };
    }
}
