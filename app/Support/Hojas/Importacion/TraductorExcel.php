<?php

namespace App\Support\Hojas\Importacion;

use App\Support\Hojas\ValidadorCelda;
use App\Support\Hojas\ValidadorEstilo;
use InvalidArgumentException;

/**
 * v9 — Convierte una pestaña leída de Excel (estructura intermedia, sin PhpSpreadsheet) a la definición
 * con la que HojaService crea una hoja (el mismo formato que las plantillas).
 *
 * Pestaña de entrada:
 *   nombre, ancho_defecto (px), anchos [col => px], altos [fila => px],
 *   celdas     [[fila, col, ['tipo' => s|n|b|f|e|null, 'valor' => …, 'calculado' => …, 'formato' => '0.00', 'estilo' => [n,i,s,c,f,a,w]]]]
 *   combinadas [[fila1, col1, fila2, col2]]
 *   imagenes   [[fila, col, ['x','y','w','h','contenido','mime','nombre']]]
 * (filas y columnas desde 0)
 *
 * Reglas:
 *  - Cada celda conserva su TIPO: número, texto, fecha (los números con formato de fecha se convierten), lógico.
 *  - Una fórmula que el motor puede calcular se conserva; si no (otra hoja, BUSCARV…), se guarda el último
 *    valor que calculó Excel, para que nada aparezca vacío. Se cuenta para avisar al usuario.
 *  - Se respetan negrita, cursiva, subrayado, colores, alineación, ajuste de texto y formato de número.
 *  - Límites para no saturar el navegador: lo que queda fuera se avisa.
 * Lógica pura (sin Laravel).
 */
final class TraductorExcel
{
    public const MAX_FILAS = 5000;

    public const MAX_COLUMNAS = 200;

    public const MAX_CELDAS = 200000;

    private const ANCHO_MIN = 30;

    private const ANCHO_MAX = 800;

    /** @return array{definicion: array, avisos: list<string>, estadisticas: array{celdas:int, formulas:int, formulas_a_valor:int, combinadas:int, imagenes:int}} */
    public static function traducir(array $pestana): array
    {
        $avisos = [];
        $est = ['celdas' => 0, 'formulas' => 0, 'formulas_a_valor' => 0, 'combinadas' => 0, 'imagenes' => 0];
        $nombre = (string) ($pestana['nombre'] ?? 'Hoja');

        $estilos = [];      // huella => nombre corto
        $defEstilos = [];   // nombre corto => definición
        $celdas = [];
        $maxF = $maxC = -1;
        $fuera = 0;

        foreach ($pestana['celdas'] ?? [] as [$f, $c, $dato]) {
            if ($f >= self::MAX_FILAS || $c >= self::MAX_COLUMNAS || count($celdas) >= self::MAX_CELDAS) {
                $fuera++;

                continue;
            }
            [$celda, $tipoFormula] = self::celda($dato);
            $estilo = self::estilo($dato['estilo'] ?? [], $dato['formato'] ?? null);
            if ($celda['t'] === 'vacio' && $estilo === []) {
                continue;
            }
            if ($estilo !== []) {
                $huella = ValidadorEstilo::huella($estilo);
                if (! isset($estilos[$huella])) {
                    $estilos[$huella] = 'e'.(count($estilos) + 1);
                    $defEstilos[$estilos[$huella]] = $estilo;
                }
                $celda['e'] = $estilos[$huella];
            }
            if ($tipoFormula === 'formula') {
                $est['formulas']++;
            } elseif ($tipoFormula === 'valor') {
                $est['formulas_a_valor']++;
            }
            $celdas[] = [$f, $c, $celda];
            $est['celdas']++;
            $maxF = max($maxF, $f);
            $maxC = max($maxC, $c);
        }
        if ($fuera > 0) {
            $avisos[] = "«{$nombre}»: {$fuera} celda(s) quedaron fuera porque la hoja pasa de ".self::MAX_FILAS.' filas, '.self::MAX_COLUMNAS.' columnas o '.self::MAX_CELDAS.' celdas.';
        }
        if ($est['formulas_a_valor'] > 0) {
            $avisos[] = "«{$nombre}»: {$est['formulas_a_valor']} fórmula(s) usan otra hoja o funciones que la plataforma aún no calcula; se conservó su último resultado como valor.";
        }

        // Combinadas e imágenes también agrandan la cuadrícula
        $combinadas = [];
        foreach ($pestana['combinadas'] ?? [] as [$f1, $c1, $f2, $c2]) {
            [$f1, $f2] = [min($f1, $f2), max($f1, $f2)];
            [$c1, $c2] = [min($c1, $c2), max($c1, $c2)];
            if (($f1 === $f2 && $c1 === $c2) || $f2 >= self::MAX_FILAS || $c2 >= self::MAX_COLUMNAS) {
                continue;
            }
            $combinadas[] = [$f1, $c1, $f2, $c2];
            $maxF = max($maxF, $f2);
            $maxC = max($maxC, $c2);
        }
        $est['combinadas'] = count($combinadas);

        $imagenes = [];
        foreach ($pestana['imagenes'] ?? [] as [$f, $c, $img]) {
            if ($f >= self::MAX_FILAS || $c >= self::MAX_COLUMNAS) {
                continue;
            }
            $imagenes[] = [$f, $c, [
                ...$img,
                'x' => max(0, min(2000, (int) ($img['x'] ?? 0))), 'y' => max(0, min(2000, (int) ($img['y'] ?? 0))),
                'w' => max(8, min(2000, (int) ($img['w'] ?? 100))), 'h' => max(8, min(2000, (int) ($img['h'] ?? 100))),
            ]];
            $maxF = max($maxF, $f);
            $maxC = max($maxC, $c);
        }
        $est['imagenes'] = count($imagenes);

        // Cuadrícula: lo usado + espacio libre para seguir trabajando
        $nCols = min(self::MAX_COLUMNAS, max($maxC + 1 + 2, 26));
        $nFilas = min(self::MAX_FILAS + 50, max($maxF + 1 + 20, 100));
        $anchoDef = self::ancho($pestana['ancho_defecto'] ?? 100);
        $anchos = [];
        for ($i = 0; $i < $nCols; $i++) {
            $anchos[] = isset($pestana['anchos'][$i]) ? self::ancho($pestana['anchos'][$i]) : $anchoDef;
        }
        $altos = [];
        for ($i = 0; $i < $nFilas; $i++) {
            $altos[] = isset($pestana['altos'][$i]) ? max(18, min(400, (int) round($pestana['altos'][$i]))) : 28;
        }

        return [
            'definicion' => [
                'columnas' => $anchos, 'filas' => $nFilas, 'altos' => $altos,
                'estilos' => $defEstilos, 'celdas' => $celdas, 'combinadas' => $combinadas, 'imagenes' => $imagenes,
            ],
            'avisos' => $avisos,
            'estadisticas' => $est,
        ];
    }

    /**
     * @return array{0: array, 1: ?string}  [celda {t, v|fx, rt}, 'formula' | 'valor' (fórmula convertida) | null]
     */
    public static function celda(array $dato): array
    {
        $tipo = $dato['tipo'] ?? null;
        $fmt = FormatoExcel::tipo($dato['formato'] ?? null);

        if ($tipo === 'f') {
            $fx = TraductorFormula::traducir((string) ($dato['valor'] ?? ''));
            $resultado = self::valor($dato['calculado'] ?? null, $fmt);
            if ($fx === null) {
                return [$resultado, 'valor'];
            }
            $celda = ['t' => 'formula', 'fx' => $fx, 'rt' => $resultado['t']];
            if (array_key_exists('v', $resultado)) {
                $celda['v'] = $resultado['v'];
            }
            if ($resultado['t'] === 'texto' && in_array($resultado['v'], ValidadorCelda::ERRORES, true)) {
                $celda['rt'] = 'error';
            }

            return [self::comprobada($celda), 'formula'];
        }
        if ($tipo === 'e') {
            return [['t' => 'texto', 'v' => mb_substr((string) $dato['valor'], 0, 30)], null];
        }

        return [self::comprobada(self::valor($dato['valor'] ?? null, $fmt)), null];
    }

    /** Un valor de Excel a una celda simple de la plataforma. */
    public static function valor(mixed $v, string $fmt): array
    {
        if ($v === null || $v === '') {
            return ['t' => 'vacio'];
        }
        if (is_bool($v)) {
            return ['t' => 'logico', 'v' => $v];
        }
        if (is_int($v) || is_float($v)) {
            if (in_array($fmt, ['fecha', 'fecha_hora', 'hora'], true) && $v >= 0 && $v < 2958466) {   // hasta 31/12/9999
                $d = FormatoExcel::desdeSerial((float) $v);

                return match ($fmt) {
                    'fecha' => ['t' => 'fecha', 'v' => $d['fecha']],
                    'fecha_hora' => ['t' => 'fecha_hora', 'v' => $d['fecha'].'T'.$d['hora']],
                    'hora' => ['t' => 'hora', 'v' => $d['hora']],
                };
            }
            if (! is_finite((float) $v) || abs((float) $v) >= 1e14) {
                return ['t' => 'texto', 'v' => (string) $v];
            }

            return ['t' => 'numero', 'v' => is_int($v) ? $v : (float) $v];
        }
        $texto = (string) $v;

        return ['t' => 'texto', 'v' => mb_substr($texto, 0, 5000)];
    }

    /** Formato de la celda con las claves de la plataforma; 'general' no se guarda. */
    public static function estilo(array $e, ?string $formatoExcel): array
    {
        $fmt = FormatoExcel::tipo($formatoExcel);
        $e = array_intersect_key($e, array_flip(['n', 'i', 's', 'c', 'f', 'a', 'w']));
        if ($fmt !== 'general') {
            $e['fmt'] = $fmt;
        }
        try {
            return ValidadorEstilo::normalizar($e);
        } catch (InvalidArgumentException) {
            return [];
        }
    }

    /** Si algo no pasa la validación del servidor (texto muy largo, número enorme…), se guarda como texto. */
    private static function comprobada(array $celda): array
    {
        try {
            ValidadorCelda::normalizar($celda);

            return $celda;
        } catch (InvalidArgumentException) {
            if ($celda['t'] === 'formula') {
                return ['t' => 'formula', 'fx' => $celda['fx'], 'rt' => 'vacio'];
            }

            return ['t' => 'texto', 'v' => mb_substr(is_scalar($celda['v'] ?? null) ? (string) $celda['v'] : '', 0, 5000)];
        }
    }

    private static function ancho(mixed $px): int
    {
        return max(self::ANCHO_MIN, min(self::ANCHO_MAX, (int) round((float) $px)));
    }
}
