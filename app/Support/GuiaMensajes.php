<?php

namespace App\Support;

use App\Services\Etl\Dto\Mensaje;

/**
 * v7 — Mensajes de la carga en lenguaje de RH.
 * Los códigos (CELDA_VACIA, NOMINA_REPETIDA…) son para el sistema; aquí cada uno tiene un TÍTULO
 * corto y la SOLUCIÓN en una frase. agrupar() junta los mensajes iguales ("3 fechas no válidas:
 * D8, D14, D20") para que el asistente muestre pocos recuadros en lugar de una lista larga.
 * Lógica pura (sin Laravel): se prueba con arreglos.
 */
final class GuiaMensajes
{
    /** código => [título, solución] */
    private const GUIA = [
        // Errores: el archivo no se puede cargar así
        'ARCHIVO_VACIO' => ['El archivo no tiene empleados', 'Revisa que la tabla tenga datos debajo de los encabezados.'],
        'HOJA_NO_ENCONTRADA' => ['No se encontró la tabla del reporte', 'La tabla debe tener las columnas Nombre y Fecha de ingreso.'],
        'HOJA_NO_EXISTE' => ['La hoja elegida no existe', 'Elige otra hoja del libro.'],
        'HOJA_SIN_FORMATO' => ['La hoja elegida no tiene la tabla del reporte', 'Elige la hoja que tiene Nombre y Fecha de ingreso.'],
        'ENCABEZADO_FALTANTE' => ['Falta una columna obligatoria', 'El archivo debe tener Nombre y Fecha de ingreso. Si vienen con otro nombre, indícalo en "Columnas que no se reconocieron".'],
        'ENCABEZADO_DUPLICADO' => ['Columnas repetidas', 'Deja una sola columna para cada dato.'],
        'PERIODO_NO_DETECTADO' => ['No se reconoce el periodo de la quincena', 'Corrige el encabezado del periodo (por ejemplo "1 de octubre al 13 de octubre") o escribe las fechas aquí abajo.'],
        'PERIODO_INVALIDO' => ['El periodo no corresponde a una quincena', 'Corrige el encabezado del periodo o escribe las fechas correctas aquí abajo.'],
        'PERIODO_FECHAS_DISTINTAS' => ['Ese periodo ya existe con otras fechas', 'Usa las mismas fechas con las que se cargó antes, o revisa el número de "Periodo:".'],
        'PERIODO_NUMERO_DISTINTO' => ['Esas fechas ya pertenecen a otro periodo', 'Revisa el número de "Periodo:" arriba de la tabla.'],
        'DOMINGO_FUERA_DE_PERIODO' => ['Hay domingos fuera de la quincena', 'Revisa los encabezados "Domingo N" o las fechas del periodo.'],
        'CELDA_VACIA' => ['Celdas obligatorias vacías', 'Escribe el nombre y la fecha de ingreso en las celdas marcadas.'],
        'TIPO_INVALIDO' => ['Valores que no son del tipo esperado', 'En esas celdas va un número, una cantidad o Sí/No; quita letras o símbolos.'],
        'FECHA_INVALIDA' => ['Fechas no válidas', 'Escríbelas como dd/mm/aaaa, por ejemplo 15/01/2018.'],
        'CARACTER_INVALIDO' => ['Caracteres no válidos', 'Vuelve a escribir el texto de esas celdas; suele pasar al pegar desde otro sistema.'],
        'FUERA_DE_RANGO' => ['Valores fuera de rango', 'El número de faltas no puede ser mayor a 31.'],
        'DUPLICADO_EN_ARCHIVO' => ['Empleados repetidos', 'Deja una sola fila por empleado.'],
        'NOMINA_REPETIDA' => ['Números de nómina repetidos', 'Cada empleado debe tener un número de nómina distinto.'],

        // Avisos que se confirman antes de cargar
        'COLUMNA_AUSENTE' => ['Columnas que no vienen en el archivo', 'Se conservan los valores ya guardados; en empleados nuevos quedarán vacías.'],
        'COLUMNA_SIN_ENCABEZADO' => ['Una columna sin título trae datos', 'Esos datos no se cargarán. Si son importantes, ponle título en el Excel.'],
        'DIA_NO_ES_DOMINGO' => ['Un "Domingo" no cae en domingo', 'Puede que el periodo esté mal escrito; revísalo antes de continuar.'],
        'PERIODO_DURACION_INUSUAL' => ['El periodo no dura lo de una quincena', 'Normalmente una quincena dura de 13 a 16 días.'],
        'EJERCICIO_DISTINTO' => ['El año no coincide con el Ejercicio', 'Se usará el año escrito en el encabezado del periodo.'],
        'NOMINA_CONTINUIDAD' => ['El número de nómina no continúa', 'Revisa que sea la quincena siguiente a la última cargada.'],
        'PERIODO_YA_CARGADO' => ['Esta quincena ya tiene datos', 'Elige si se mantiene lo editado en la web o si el Excel lo reemplaza.'],

        // Informativos
        'ENCABEZADO_DESCONOCIDO' => ['Columnas que no se reconocieron', 'Indica qué es cada una o ignórala.'],
        'ENCABEZADO_AMBIGUO' => ['Columnas que podrían ser de dos tipos', 'Indica cuál es.'],
        'NOMINA_NO_CONSECUTIVA' => ['Números de nómina salteados', 'Solo informativo.'],
        'NOMINA_VACIA' => ['Filas sin número de nómina', 'Solo informativo.'],
        'FILAS_IGNORADAS' => ['Filas vacías, totales o firmas ignoradas', 'Solo informativo.'],
        'NOMBRES_NUMERADOS' => ['Se quitó la numeración de los nombres', 'Solo informativo.'],
        'FILAS_INESPERADAS' => ['El número de filas es distinto al habitual', 'Se ajusta en Ajustes > Filas esperadas.'],
        'PERIODO_MANUAL' => ['Se usaron las fechas escritas a mano', 'Solo informativo.'],
        'ANIO_DEL_ENCABEZADO' => ['Se usó el año del encabezado', 'Solo informativo.'],
        'HOJAS_IGNORADAS' => ['Otras hojas del libro se ignoraron', 'Solo informativo.'],
        'HOJA_AMBIGUA' => ['Varias hojas parecen el reporte', 'Si no es la correcta, elige otra hoja.'],
    ];

    /** @return array{titulo:string, solucion:string} */
    public static function de(string $codigo, string $mensaje = ''): array
    {
        [$titulo, $solucion] = self::GUIA[$codigo] ?? [$mensaje !== '' ? $mensaje : $codigo, ''];

        return ['titulo' => $titulo, 'solucion' => $solucion];
    }

    /**
     * Junta los mensajes por código. Orden: errores, avisos a confirmar, informativos.
     *
     * @param  iterable<array{nivel:string, codigo:string, mensaje:string, fila:?int, celda:?string}>  $mensajes
     * @return list<array{codigo:string, tipo:string, titulo:string, solucion:string, cantidad:int, ubicaciones:list<string>, detalle:list<string>}>
     *   tipo: error | confirmar | info
     */
    public static function agrupar(iterable $mensajes): array
    {
        $grupos = [];
        foreach ($mensajes as $m) {
            $m = (array) $m;
            $tipo = $m['nivel'] === Mensaje::ERROR ? 'error' : (in_array($m['codigo'], Mensaje::CONFIRMAR, true) ? 'confirmar' : 'info');
            $g = &$grupos[$m['codigo']];
            if ($g === null) {
                $g = ['codigo' => $m['codigo'], 'tipo' => $tipo, ...self::de($m['codigo'], $m['mensaje']), 'cantidad' => 0, 'ubicaciones' => [], 'detalle' => []];
            }
            $g['cantidad']++;
            $donde = $m['celda'] ?? (! empty($m['fila']) ? "fila {$m['fila']}" : null);
            if ($donde !== null && count($g['ubicaciones']) < 12 && ! in_array($donde, $g['ubicaciones'], true)) {
                $g['ubicaciones'][] = $donde;
            }
            if (count($g['detalle']) < 5) {
                $g['detalle'][] = $m['mensaje'];
            }
            unset($g);
        }

        $orden = ['error' => 0, 'confirmar' => 1, 'info' => 2];
        $lista = array_values($grupos);
        usort($lista, fn ($a, $b) => [$orden[$a['tipo']], -$a['cantidad']] <=> [$orden[$b['tipo']], -$b['cantidad']]);

        return $lista;
    }
}
