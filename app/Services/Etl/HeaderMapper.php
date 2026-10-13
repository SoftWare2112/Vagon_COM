<?php

namespace App\Services\Etl;

use App\Services\Etl\Dto\MapaColumnas;
use App\Services\Etl\Dto\Mensaje;
use App\Support\CatalogoColumnas;
use App\Support\Texto;

/**
 * Identifica cada columna POR SU ENCABEZADO (no por posición), con alias.
 * Recibe el catálogo de columnas (sistema + las que RH agregó), así que:
 *  - una columna nueva insertada en cualquier lugar del Excel se reconoce por su nombre;
 *  - una columna que FALTA solo genera advertencia (salvo las obligatorias);
 *  - una columna que el sistema no conoce se reporta para que RH decida si crearla.
 * Dinámicas por patrón: "Domingo 16" y "12 de agosto al 27 de agosto".
 */
final class HeaderMapper
{
    public const PATRON_DOMINGO = '/^domingo\s+(\d{1,2})$/';

    /** @var list<array> */
    private array $catalogo;

    /** @param list<array>|null $catalogo  filas de la tabla `columnas` (o CatalogoColumnas::SISTEMA) */
    public function __construct(?array $catalogo = null)
    {
        $this->catalogo = $catalogo ?? CatalogoColumnas::SISTEMA;
    }

    /** @return array{0: array<string,string>, 1: array<string,array>}  [encabezado normalizado => clave, clave => definición] */
    private function indice(): array
    {
        $indice = [];
        $porClave = [];
        foreach ($this->catalogo as $def) {
            if (! in_array($def['origen'], ['sistema', 'personalizada'], true)) {
                continue;
            }
            $porClave[$def['clave']] = $def;
            $alias = $def['alias'] ?? [];
            $alias[] = $def['etiqueta'];
            foreach ($alias as $a) {
                $indice[Texto::normalizarEncabezado($a)] = $def['clave'];
            }
        }

        return [$indice, $porClave];
    }

    /**
     * ¿Qué tanto se parece una fila a la fila de encabezados del reporte?
     * Lo usa SelectorHoja para encontrar la hoja y la fila correctas en libros con varias hojas.
     *
     * @return array{reconocidas:int, obligatorias:bool}
     */
    public function evaluarEncabezados(array $fila): array
    {
        [$indice, $porClave] = $this->indice();
        $claves = [];
        $extra = 0;
        foreach ($fila as $texto) {
            $norm = Texto::normalizarEncabezado(is_scalar($texto) ? (string) $texto : '');
            if ($norm === '') {
                continue;
            }
            if (isset($indice[$norm])) {
                $claves[$indice[$norm]] = true;
            } elseif (preg_match(self::PATRON_DOMINGO, $norm) || PeriodoParser::pareceRango($norm)) {
                $extra++;
            }
        }
        $obligatorias = array_keys(array_filter($porClave, fn ($d) => $d['obligatoria'] ?? false));

        return [
            'reconocidas' => count($claves) + $extra,
            'obligatorias' => array_diff($obligatorias, array_keys($claves)) === [],
        ];
    }

    /** @return array{0: ?MapaColumnas, 1: list<Mensaje>} */
    public function mapear(array $encabezados, int $filaEncabezado = 1): array
    {
        [$indice, $porClave] = $this->indice();

        $campos = $tipos = $extras = $domingos = $mensajes = [];
        $colPeriodo = $etiquetaPeriodo = null;

        foreach ($encabezados as $col => $texto) {
            $original = Texto::limpiar((string) $texto);
            $norm = Texto::normalizarEncabezado($original);
            if ($norm === '') {
                continue;   // columna sin encabezado: se ignora
            }

            if (isset($indice[$norm])) {
                $clave = $indice[$norm];
                if (in_array($clave, $campos, true)) {
                    $mensajes[] = Mensaje::error('ENCABEZADO_DUPLICADO', "La columna '{$original}' aparece más de una vez.", $filaEncabezado, $original, $col.$filaEncabezado);

                    continue;
                }
                $campos[$col] = $clave;
                $tipos[$clave] = $porClave[$clave]['tipo'];
                if ($porClave[$clave]['origen'] === 'personalizada') {
                    $extras[] = $clave;
                }

                continue;
            }

            if (preg_match(self::PATRON_DOMINGO, $norm, $m)) {
                $domingos[$col] = ['dia' => (int) $m[1], 'etiqueta' => $original];

                continue;
            }

            if (PeriodoParser::pareceRango($norm)) {
                $colPeriodo = $col;
                $etiquetaPeriodo = $original;

                continue;
            }

            // valor_recibido = encabezado: el frontend ofrece "Crear columna" con ese nombre
            $mensajes[] = Mensaje::advertencia('ENCABEZADO_DESCONOCIDO', "La columna '{$original}' no existe en la plataforma y será ignorada. Puedes crearla en Ajustes > Columnas y volver a validar.", $filaEncabezado, $original, $col.$filaEncabezado, $original);
        }

        // Columnas conocidas que no vienen en el archivo
        $ausentes = [];
        foreach ($porClave as $clave => $def) {
            if (in_array($clave, $campos, true) || ! ($def['activa'] ?? true)) {
                continue;
            }
            if ($def['obligatoria'] ?? false) {
                $mensajes[] = Mensaje::error('ENCABEZADO_FALTANTE', "Falta la columna obligatoria '{$def['etiqueta']}'.", $filaEncabezado);

                continue;
            }
            $ausentes[] = $clave;
            if ($def['en_excel'] ?? true) {
                $mensajes[] = Mensaje::advertencia('COLUMNA_AUSENTE', "La columna '{$def['etiqueta']}' no viene en el archivo: se conservan los valores actuales y en empleados nuevos quedará vacía.", $filaEncabezado, $def['etiqueta']);
            }
        }
        if ($domingos === []) {
            $mensajes[] = Mensaje::advertencia('COLUMNA_AUSENTE', "No vienen columnas 'Domingo N': se conservan los valores actuales.", $filaEncabezado);
        }

        $hayErrores = array_filter($mensajes, fn (Mensaje $m) => $m->esError()) !== [];

        return [$hayErrores ? null : new MapaColumnas($campos, $domingos, $colPeriodo, $etiquetaPeriodo, $tipos, $extras, $ausentes), $mensajes];
    }

    /** Etiqueta legible de una clave (para mensajes). */
    public function etiqueta(string $clave): string
    {
        foreach ($this->catalogo as $def) {
            if ($def['clave'] === $clave) {
                return $def['etiqueta'];
            }
        }

        return $clave;
    }
}
