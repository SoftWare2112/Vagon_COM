<?php

namespace App\Support;

/**
 * Columnas del sistema, en el orden del Excel confirmado por RH (formato de octubre 2026):
 * Nombre, Fecha de ingreso, Puesto, Prima vacacional, Bono 3 meses, Bono puntualidad,
 * Bono asistencia, Número de faltas, Detalles, Acredita para bono puntualidad,
 * Programado en domingo, Domingo N..., periodo ("1 de octubre al 13 de octubre de 2026").
 * Se siembran en la tabla `columnas`; RH puede agregar más (origen 'personalizada')
 * e insertarlas en cualquier posición desde Ajustes > Columnas.
 *
 * en_excel=false (opcional): la columna no viene en el archivo y su ausencia no genera advertencia.
 */
final class CatalogoColumnas
{
    public const SISTEMA = [
        ['clave' => 'nombre_completo', 'etiqueta' => 'Nombre', 'tipo' => 'texto', 'origen' => 'sistema', 'obligatoria' => true, 'editable' => false,
            'alias' => ['nombre', 'nombre completo', 'nombre del empleado']],
        ['clave' => 'fecha_ingreso', 'etiqueta' => 'Fecha de ingreso', 'tipo' => 'fecha', 'origen' => 'sistema', 'obligatoria' => true, 'editable' => false,
            'alias' => ['fecha de ingreso', 'f de ingreso', 'fecha ingreso', 'f ingreso']],
        ['clave' => 'puesto', 'etiqueta' => 'Puesto', 'tipo' => 'texto', 'origen' => 'sistema',
            'alias' => ['puesto', 'cargo']],
        ['clave' => 'prima_vacacional', 'etiqueta' => 'Prima vacacional', 'tipo' => 'dinero', 'origen' => 'sistema',
            'alias' => ['prima vacacional']],
        ['clave' => 'bono_3_meses', 'etiqueta' => 'Bono 3 meses', 'tipo' => 'dinero', 'origen' => 'sistema',
            'alias' => ['bono 3 meses', 'bono 3meses', 'bono tres meses']],
        ['clave' => 'bono_puntualidad', 'etiqueta' => 'Bono puntualidad', 'tipo' => 'dinero', 'origen' => 'sistema',
            'alias' => ['bono puntualidad', 'bono de puntualidad']],
        ['clave' => 'bono_asistencia', 'etiqueta' => 'Bono asistencia', 'tipo' => 'dinero', 'origen' => 'sistema',
            'alias' => ['bono asistencia', 'bono de asistencia']],
        ['clave' => 'num_faltas', 'etiqueta' => 'Número de faltas', 'tipo' => 'entero', 'origen' => 'sistema',
            'alias' => ['numero de faltas', 'faltas', 'no faltas', 'num faltas', 'no de faltas']],
        ['clave' => 'detalle', 'etiqueta' => 'Detalles', 'tipo' => 'texto_largo', 'origen' => 'sistema',
            'alias' => ['detalles', 'detalle', 'observaciones']],
        ['clave' => 'acredita_bono', 'etiqueta' => 'Acredita para bono puntualidad', 'tipo' => 'booleano', 'origen' => 'sistema',
            'alias' => ['acredita para bono puntualidad', 'acredita para bono', 'acredita bono puntualidad', 'acredita para bono de puntualidad', 'acredita bono']],
        ['clave' => 'programado_domingo', 'etiqueta' => 'Programado en domingo', 'tipo' => 'decimal', 'origen' => 'sistema',
            'alias' => ['programado en domingo', 'programado en domingos', 'programando en domingos', 'programado domingo']],
        ['clave' => 'domingos', 'etiqueta' => 'Domingos del periodo', 'tipo' => 'decimal', 'origen' => 'domingos', 'alias' => []],
        ['clave' => 'periodo', 'etiqueta' => 'Periodo de nómina', 'tipo' => 'texto', 'origen' => 'periodo', 'alias' => []],
    ];

    /** Columnas que viven en la tabla `empleados` (no cambian entre quincenas). */
    public const DE_EMPLEADO = ['nombre_completo', 'fecha_ingreso'];

    /** Columnas del sistema guardadas en columnas propias de `registros`. */
    public const DE_REGISTRO = [
        'prima_vacacional', 'bono_3_meses', 'bono_puntualidad', 'bono_asistencia', 'num_faltas',
        'detalle', 'acredita_bono', 'programado_domingo',
    ];
}
