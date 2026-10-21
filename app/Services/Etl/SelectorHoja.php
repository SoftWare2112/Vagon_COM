<?php

namespace App\Services\Etl;

use App\Services\Etl\Dto\Mensaje;

/**
 * Libros con VARIAS HOJAS: decide cuál contiene el reporte y en qué fila están los encabezados.
 * Lógica pura (sin PhpSpreadsheet ni BD) para probarla con arreglos.
 *
 * Reglas:
 *  - Una hoja es candidata si alguna de sus primeras 50 filas tiene las columnas obligatorias
 *    (Nombre y Fecha de ingreso). Las hojas ocultas solo se leen si el usuario las pide.
 *  - 0 candidatas -> ERROR con la lista de hojas.
 *  - 1 candidata  -> se usa; las demás hojas (información complementaria) se ignoran con aviso.
 *  - 2+ candidatas -> se usa la que reconoce más columnas (empate: la primera) y se avisa para que
 *    el usuario elija otra si no es la correcta.
 *  - Si el usuario eligió una hoja, se usa esa (si no existe o no tiene el formato -> ERROR).
 */
final class SelectorHoja
{
    public const FILAS_A_REVISAR = 50;   // títulos, logotipo y bloque de datos arriba de la tabla

    public function __construct(private readonly HeaderMapper $mapper = new HeaderMapper) {}

    /**
     * @param  list<array{nombre:string, visible:bool, filas:list<array>}>  $hojas  primeras filas de cada hoja
     * @return array{indice:?int, filaEncabezado:int, mensajes:list<Mensaje>}
     */
    public function elegir(array $hojas, ?string $solicitada = null): array
    {
        if ($hojas === []) {
            return $this->falla(Mensaje::error('ARCHIVO_VACIO', 'El libro no tiene hojas.'));
        }

        $evaluadas = [];
        foreach ($hojas as $i => $h) {
            $evaluadas[$i] = $this->mejorFila($h['filas']);
        }
        $nombres = array_column($hojas, 'nombre');
        $lista = implode(', ', array_map(fn ($n) => "'{$n}'", $nombres));

        if ($solicitada !== null && $solicitada !== '') {
            $i = array_search($solicitada, $nombres, true);
            if ($i === false) {
                return $this->falla(Mensaje::error('HOJA_NO_EXISTE', "La hoja '{$solicitada}' no existe en el archivo. Hojas disponibles: {$lista}."));
            }
            if (! $evaluadas[$i]['obligatorias']) {
                return $this->falla(Mensaje::error('HOJA_SIN_FORMATO', "La hoja '{$solicitada}' no tiene las columnas Nombre y Fecha de ingreso."));
            }

            return ['indice' => $i, 'filaEncabezado' => $evaluadas[$i]['fila'], 'mensajes' => $this->ignoradas($nombres, $i)];
        }

        $candidatas = array_keys(array_filter($evaluadas, fn ($e, $i) => $e['obligatorias'] && $hojas[$i]['visible'], ARRAY_FILTER_USE_BOTH));

        if ($candidatas === []) {
            return $this->falla(Mensaje::error('HOJA_NO_ENCONTRADA', "Ninguna hoja tiene las columnas Nombre y Fecha de ingreso en sus primeras ".self::FILAS_A_REVISAR." filas. Hojas del archivo: {$lista}."));
        }

        // La que reconoce más columnas; en empate, la primera del libro
        usort($candidatas, fn ($a, $b) => [$evaluadas[$b]['reconocidas'], $a] <=> [$evaluadas[$a]['reconocidas'], $b]);
        $elegida = $candidatas[0];
        $mensajes = $this->ignoradas($nombres, $elegida);

        if (count($candidatas) > 1) {
            $otras = implode(', ', array_map(fn ($i) => "'{$nombres[$i]}'", array_slice($candidatas, 1)));
            $mensajes[] = Mensaje::advertencia('HOJA_AMBIGUA', "Varias hojas tienen el formato del reporte. Se leyó '{$nombres[$elegida]}'; si no es la correcta, elige otra ({$otras}) y valida de nuevo.");
        }

        return ['indice' => $elegida, 'filaEncabezado' => $evaluadas[$elegida]['fila'], 'mensajes' => $mensajes];
    }

    /** @return array{fila:int, reconocidas:int, obligatorias:bool} */
    private function mejorFila(array $filas): array
    {
        $mejor = ['fila' => 1, 'reconocidas' => 0, 'obligatorias' => false];
        foreach (array_slice($filas, 0, self::FILAS_A_REVISAR) as $i => $fila) {
            $e = $this->mapper->evaluarEncabezados($fila);
            if ([$e['obligatorias'], $e['reconocidas']] > [$mejor['obligatorias'], $mejor['reconocidas']]) {
                $mejor = ['fila' => $i + 1, ...$e];
            }
        }

        return $mejor;
    }

    private function ignoradas(array $nombres, int $elegida): array
    {
        $otras = array_values(array_filter($nombres, fn ($n, $i) => $i !== $elegida, ARRAY_FILTER_USE_BOTH));
        if ($otras === []) {
            return [];
        }
        $lista = implode(', ', array_map(fn ($n) => "'{$n}'", $otras));

        return [Mensaje::advertencia('HOJAS_IGNORADAS', "Se leyó la hoja '{$nombres[$elegida]}'. Las hojas {$lista} se ignoraron (no se guardan en la base de datos).")];
    }

    private function falla(Mensaje $m): array
    {
        return ['indice' => null, 'filaEncabezado' => 1, 'mensajes' => [$m]];
    }
}
