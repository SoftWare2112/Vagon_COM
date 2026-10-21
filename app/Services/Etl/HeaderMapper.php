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
 *  - una columna que el sistema no conoce se reporta CON SUGERENCIAS para que RH la asigne
 *    a una columna existente (se guarda como alias) o la cree.
 *
 * Tolerancia (v5): mayúsculas, acentos, saltos de línea, símbolos ("# de Faltas", "N° faltas"),
 * palabras de relleno ("de", "para") y abreviaturas ("Núm.", "F. Ingr.", "Prima vac.", "Dom. 4").
 * Una abreviatura que podría ser de DOS columnas no se asigna sola: se pregunta.
 * Columnas de relleno de Excel ("Columna1") se ignoran sin aviso. "#" es el número de nómina (v6).
 */
final class HeaderMapper
{
    public const PATRON_DOMINGO = '/^domingo (\d{1,2})$/';

    /** "Columna1", "Column 2" (nombre automático de las tablas de Excel) */
    private const PATRON_RELLENO = '/^colum(na|n)? ?\d+$/';

    /** Consecutivo de la fila: no es un dato del reporte. ("#" ya NO es relleno: es el número de nómina, v6) */
    private const CONSECUTIVO = ['consecutivo', 'id'];

    /** @var list<array> */
    private array $catalogo;

    /** @var array<string,string>|null  clave de comparación exacta => clave de columna */
    private ?array $exactos = null;

    /** @var list<array{0:list<string>,1:string}>  tokens de cada alias => clave de columna */
    private array $tokens = [];

    /** @var array<string,array> */
    private array $porClave = [];

    /** @param list<array>|null $catalogo  filas de la tabla `columnas` (o CatalogoColumnas::SISTEMA) */
    public function __construct(?array $catalogo = null)
    {
        $this->catalogo = $catalogo ?? CatalogoColumnas::SISTEMA;
    }

    private function indexar(): void
    {
        if ($this->exactos !== null) {
            return;
        }
        $this->exactos = [];
        foreach ($this->catalogo as $def) {
            if (! in_array($def['origen'], ['sistema', 'personalizada'], true)) {
                continue;
            }
            $this->porClave[$def['clave']] = $def;
            foreach ([...($def['alias'] ?? []), $def['etiqueta']] as $a) {
                $k = Texto::claveEncabezado($a);
                if ($k === '') {
                    continue;
                }
                $this->exactos[$k] = $def['clave'];
                $this->tokens[] = [explode(' ', $k), $def['clave']];
            }
        }
    }

    /**
     * Qué es un encabezado.
     *
     * @return array{tipo:string, clave?:string, dia?:int, candidatas?:list<string>}
     *   tipo: vacio | relleno | campo | domingo | periodo | ambiguo | desconocido
     */
    public function clasificar(mixed $texto): array
    {
        $this->indexar();
        $original = Texto::limpiar(is_scalar($texto) ? (string) $texto : '');
        $clave = Texto::claveEncabezado($original);

        if ($clave === '') {
            return ['tipo' => 'vacio'];
        }
        if (preg_match(self::PATRON_RELLENO, $clave) || in_array($clave, self::CONSECUTIVO, true)) {
            return ['tipo' => 'relleno'];
        }
        if (isset($this->exactos[$clave])) {
            return ['tipo' => 'campo', 'clave' => $this->exactos[$clave]];
        }
        if (preg_match(self::PATRON_DOMINGO, $clave, $m)) {
            return ['tipo' => 'domingo', 'dia' => (int) $m[1]];
        }
        if (PeriodoParser::pareceRango(Texto::normalizarEncabezado($original))) {
            return ['tipo' => 'periodo'];
        }

        // Abreviaturas por prefijo: "bono punt" -> "bono puntualidad"
        $partes = explode(' ', $clave);
        $candidatas = [];
        foreach ($this->tokens as [$alias, $c]) {
            if ($this->coincidePorPrefijo($partes, $alias)) {
                $candidatas[$c] = true;
            }
        }
        if (count($candidatas) === 1) {
            return ['tipo' => 'campo', 'clave' => array_key_first($candidatas)];
        }
        if (count($candidatas) > 1) {
            return ['tipo' => 'ambiguo', 'candidatas' => array_keys($candidatas)];
        }

        return ['tipo' => 'desconocido'];
    }

    /**
     * Mismo número de palabras y cada palabra del encabezado es igual o el inicio de la del alias.
     * La primera palabra debe tener al menos 3 letras ("Det." sí, "D." no); los números deben ser iguales.
     */
    private function coincidePorPrefijo(array $h, array $a): bool
    {
        if (count($h) !== count($a)) {
            return false;
        }
        foreach ($h as $i => $t) {
            if ($t === $a[$i]) {
                continue;
            }
            if (ctype_digit($t) || ctype_digit($a[$i]) || ! str_starts_with($a[$i], $t) || ($i === 0 && strlen($t) < 3)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Qué tanto se parece una fila a la fila de encabezados del reporte?
     * Lo usa SelectorHoja para encontrar la hoja y la fila correctas en libros con varias hojas.
     *
     * @return array{reconocidas:int, obligatorias:bool}
     */
    public function evaluarEncabezados(array $fila): array
    {
        $this->indexar();
        $claves = [];
        $extra = 0;
        foreach ($fila as $texto) {
            $c = $this->clasificar($texto);
            if ($c['tipo'] === 'campo') {
                $claves[$c['clave']] = true;
            } elseif (in_array($c['tipo'], ['domingo', 'periodo'], true)) {
                $extra++;
            }
        }
        $obligatorias = array_keys(array_filter($this->porClave, fn ($d) => $d['obligatoria'] ?? false));

        return [
            'reconocidas' => count($claves) + $extra,
            'obligatorias' => array_diff($obligatorias, array_keys($claves)) === [],
        ];
    }

    /**
     * @return array{0: ?MapaColumnas, 1: list<Mensaje>, 2: list<array{columna:string, encabezado:string, sugerencias:list<array{clave:string, etiqueta:string}>}>}
     *   [mapa, mensajes, columnas no reconocidas con sugerencias]
     */
    public function mapear(array $encabezados, int $filaEncabezado = 1): array
    {
        $this->indexar();

        $campos = $tipos = $extras = $domingos = $mensajes = $pendientes = $sinEncabezado = [];
        $colPeriodo = $etiquetaPeriodo = null;

        foreach ($encabezados as $col => $texto) {
            $original = Texto::limpiar(is_scalar($texto) ? (string) $texto : '');
            $c = $this->clasificar($original);

            switch ($c['tipo']) {
                case 'vacio':
                    $sinEncabezado[] = $col;   // si trae datos, ProcesadorHoja avisa
                    break;

                case 'relleno':
                    break;

                case 'campo':
                    $clave = $c['clave'];
                    if (in_array($clave, $campos, true)) {
                        $mensajes[] = Mensaje::error('ENCABEZADO_DUPLICADO', "La columna '{$original}' (celda {$col}{$filaEncabezado}) se reconoció como '{$this->etiqueta($clave)}', que ya aparece en otra columna.", $filaEncabezado, $original, $col.$filaEncabezado);
                        break;
                    }
                    $campos[$col] = $clave;
                    $tipos[$clave] = $this->porClave[$clave]['tipo'];
                    if ($this->porClave[$clave]['origen'] === 'personalizada') {
                        $extras[] = $clave;
                    }
                    break;

                case 'domingo':
                    $domingos[$col] = ['dia' => $c['dia'], 'etiqueta' => $original];
                    break;

                case 'periodo':
                    $colPeriodo = $col;
                    $etiquetaPeriodo = $original;
                    break;

                default:   // ambiguo | desconocido
                    $pendientes[] = [$col, $original, $c['candidatas'] ?? []];
            }
        }

        // Columnas no reconocidas: se ignoran, con sugerencias para asignarlas (valor_recibido = encabezado)
        $desconocidas = [];
        foreach ($pendientes as [$col, $original, $candidatas]) {
            $sugeridas = array_values(array_diff($candidatas ?: $this->sugerir($original), $campos));
            $desconocidas[] = [
                'columna' => $col,
                'encabezado' => $original,
                'sugerencias' => array_map(fn ($k) => ['clave' => $k, 'etiqueta' => $this->etiqueta($k)], $sugeridas),
            ];
            $pista = $sugeridas ? ' ¿Es '.implode(' o ', array_map(fn ($k) => "'{$this->etiqueta($k)}'", $sugeridas)).'?' : '';
            $mensajes[] = $candidatas
                ? Mensaje::advertencia('ENCABEZADO_AMBIGUO', "La columna '{$original}' puede ser más de una columna de la plataforma y no se asignó sola.{$pista} Asígnala en el asistente y vuelve a validar.", $filaEncabezado, $original, $col.$filaEncabezado, $original)
                : Mensaje::advertencia('ENCABEZADO_DESCONOCIDO', "La columna '{$original}' no existe en la plataforma y será ignorada.{$pista} Asígnala a una columna existente o créala, y vuelve a validar.", $filaEncabezado, $original, $col.$filaEncabezado, $original);
        }

        // Columnas conocidas que no vienen en el archivo
        $ausentes = [];
        foreach ($this->porClave as $clave => $def) {
            if (in_array($clave, $campos, true) || ! ($def['activa'] ?? true)) {
                continue;
            }
            if ($def['obligatoria'] ?? false) {
                $pista = $desconocidas ? ' Si viene con otro nombre, asígnala en la lista de columnas no reconocidas.' : '';
                $mensajes[] = Mensaje::error('ENCABEZADO_FALTANTE', "Falta la columna obligatoria '{$def['etiqueta']}'.{$pista}", $filaEncabezado);

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
        $mapa = $hayErrores ? null : new MapaColumnas($campos, $domingos, $colPeriodo, $etiquetaPeriodo, $tipos, $extras, $ausentes, $sinEncabezado);

        return [$mapa, $mensajes, $desconocidas];
    }

    /**
     * Columnas del catálogo que más se parecen a un encabezado no reconocido (máximo 3).
     *
     * @return list<string>
     */
    public function sugerir(string $encabezado): array
    {
        $this->indexar();
        $h = Texto::claveEncabezado($encabezado);
        $partes = array_filter(explode(' ', $h), fn ($t) => strlen($t) >= 3 && ! ctype_digit($t));
        $puntaje = [];
        foreach ($this->tokens as [$alias, $clave]) {
            similar_text($h, implode(' ', $alias), $pct);
            $comunes = 0;
            foreach ($partes as $t) {
                foreach ($alias as $a) {
                    if (strlen($a) >= 3 && (str_starts_with($a, $t) || str_starts_with($t, $a))) {
                        $comunes++;
                        break;
                    }
                }
            }
            $p = max($pct / 100, $partes ? $comunes / count($partes) : 0);
            $puntaje[$clave] = max($puntaje[$clave] ?? 0, $p);
        }
        $puntaje = array_filter($puntaje, fn ($p) => $p >= 0.6);
        arsort($puntaje);

        return array_slice(array_keys($puntaje), 0, 3);
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
