<?php

namespace App\Services\Etl;

use App\Services\Etl\Dto\HojaExtraida;
use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\Dto\Periodo;
use App\Services\Etl\Dto\ResultadoProcesamiento;
use App\Services\Etl\Reglas\ReglaFila;
use App\Support\CatalogoColumnas;
use DateTimeImmutable;

/**
 * Pipeline ETL "puro" (sin BD ni framework): Mapear encabezados -> Periodo ->
 * Transformar/validar cada fila -> Reglas de negocio (enchufables) -> Validaciones de archivo.
 * Al no tocar la BD se prueba con arreglos simples (ver tests/Unit/Etl).
 */
final class ProcesadorHoja
{
    private HeaderMapper $mapper;

    private RowTransformer $transformer;

    /**
     * @param  list<array>|null  $catalogo  columnas activas (tabla `columnas`); null = solo las del sistema
     * @param  list<ReglaFila>  $reglas  reglas extra por fila (hoy ninguna: RH pidió leer los datos tal cual)
     */
    public function __construct(
        ?array $catalogo = null,
        private readonly array $reglas = [],
        private readonly int $filasEsperadas = 48,
        int $maxFaltas = 31,
        private readonly PeriodoParser $periodoParser = new PeriodoParser,
    ) {
        $catalogo ??= CatalogoColumnas::SISTEMA;
        $this->mapper = new HeaderMapper($catalogo);
        $this->transformer = new RowTransformer($maxFaltas, array_column($catalogo, 'etiqueta', 'clave'));
    }

    /**
     * @param  int  $anio  año de la quincena (el Excel no lo trae)
     * @param  array{inicio:string, fin:string}|null  $periodoManual  si el encabezado no trae el rango
     */
    public function procesar(HojaExtraida $hoja, int $anio, ?array $periodoManual = null): ResultadoProcesamiento
    {
        if ($hoja->filas === []) {
            return new ResultadoProcesamiento(null, null, [], [Mensaje::error('ARCHIVO_VACIO', 'El archivo no contiene filas de datos.')]);
        }

        // 1) Encabezados
        [$mapa, $mensajes] = $this->mapper->mapear($hoja->encabezados, $hoja->filaEncabezado);
        if ($mapa === null) {
            return new ResultadoProcesamiento(null, null, [], $mensajes);
        }

        // 2) Periodo (quincena)
        $periodo = $periodoManual
            ? new Periodo(new DateTimeImmutable($periodoManual['inicio']), new DateTimeImmutable($periodoManual['fin']), $mapa->etiquetaPeriodo ?? 'Periodo manual')
            : ($mapa->etiquetaPeriodo ? $this->periodoParser->parsear($mapa->etiquetaPeriodo, $anio) : null);

        if ($periodo === null) {
            $mensajes[] = Mensaje::error('PERIODO_NO_DETECTADO', 'No se pudo identificar la quincena. Debe existir una columna con el periodo de nómina, por ejemplo "1 de octubre al 13 de octubre de 2026" (el año es opcional), o indica las fechas manualmente al subir.');

            return new ResultadoProcesamiento($mapa, null, [], $mensajes);
        }

        if ($periodo->anioEncabezado !== null && $periodo->anioEncabezado !== $anio && $periodoManual === null) {
            $mensajes[] = Mensaje::advertencia('ANIO_DEL_ENCABEZADO', "El encabezado del periodo indica el año {$periodo->anioEncabezado}; se usó ese año en lugar de {$anio}.");
        }

        foreach ($mapa->domingos as $col => $info) {
            $fecha = $periodo->fechaDelDia($info['dia']);
            if ($fecha === null) {
                $mensajes[] = Mensaje::error('DOMINGO_FUERA_DE_PERIODO', "La columna '{$info['etiqueta']}' no cae dentro de {$periodo->etiqueta}.", $hoja->filaEncabezado, $info['etiqueta'], $col.$hoja->filaEncabezado);
            } elseif ($fecha->format('w') !== '0') {
                $mensajes[] = Mensaje::advertencia('DIA_NO_ES_DOMINGO', "La columna '{$info['etiqueta']}' corresponde al {$fecha->format('d/m/Y')}, que no es domingo.", $hoja->filaEncabezado, $info['etiqueta'], $col.$hoja->filaEncabezado);
            }
        }

        // 3) Filas
        $filas = [];
        $vistos = [];
        foreach ($hoja->filas as $f) {
            [$datos, $msgFila] = $this->transformer->transformar($f['fila'], $f['celdas'], $mapa, $periodo);

            if (array_filter($msgFila, fn (Mensaje $m) => $m->esError()) === []) {
                foreach ($this->reglas as $regla) {
                    array_push($msgFila, ...$regla->evaluar($f['fila'], $datos, $mapa));
                }

                // Duplicados dentro del mismo archivo (misma persona dos veces)
                $clave = $datos['nombre_normalizado'].'|'.$datos['fecha_ingreso'];
                if (isset($vistos[$clave])) {
                    $msgFila[] = Mensaje::error('DUPLICADO_EN_ARCHIVO', "Las filas {$vistos[$clave]} y {$f['fila']} corresponden al mismo empleado ({$datos['nombre_completo']}).", $f['fila']);
                } else {
                    $vistos[$clave] = $f['fila'];
                }
            }

            array_push($mensajes, ...$msgFila);
            $filas[] = ['fila' => $f['fila'], 'datos' => $datos];
        }

        // 4) Validaciones de archivo (no bloqueantes)
        if ($this->filasEsperadas > 0 && count($filas) !== $this->filasEsperadas) {
            $mensajes[] = Mensaje::advertencia('FILAS_INESPERADAS', 'El archivo trae '.count($filas)." filas; normalmente son {$this->filasEsperadas}.");
        }

        return new ResultadoProcesamiento($mapa, $periodo, $filas, $mensajes);
    }
}
