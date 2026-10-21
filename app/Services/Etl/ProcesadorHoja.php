<?php

namespace App\Services\Etl;

use App\Services\Etl\Dto\HojaExtraida;
use App\Services\Etl\Dto\MapaColumnas;
use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\Dto\Periodo;
use App\Services\Etl\Dto\ResultadoProcesamiento;
use App\Services\Etl\Reglas\ReglaFila;
use App\Support\CatalogoColumnas;
use App\Support\Texto;
use DateTimeImmutable;

/**
 * Pipeline ETL "puro" (sin BD ni framework): Mapear encabezados -> Datos de arriba (Ejercicio,
 * Periodo) -> Periodo -> Filas de datos (sin pies ni filas vacías) -> Transformar/validar cada
 * fila -> Reglas de negocio (enchufables) -> Validaciones de archivo.
 * Al no tocar la BD se prueba con arreglos simples (ver tests/Unit/Etl).
 */
final class ProcesadorHoja
{
    /** Filas al pie que no son empleados: "Total", "Elaboró", "Autorizó", "Vo. Bo.", firmas. */
    private const PIE = '/^(total|totales|suma|subtotal|elaboro|elaborado|autorizo|autorizado|reviso|revisado|firma|vo ?bo)\b/';

    private HeaderMapper $mapper;

    private RowTransformer $transformer;

    /**
     * @param  list<array>|null  $catalogo  columnas activas (tabla `columnas`); null = solo las del sistema
     * @param  list<ReglaFila>  $reglas  reglas extra por fila (hoy ninguna: RH pidió leer los datos tal cual)
     * @param  int  $diasMin  duración normal de una quincena (fuera de rango: RH confirma)
     */
    public function __construct(
        ?array $catalogo = null,
        private readonly array $reglas = [],
        private readonly int $filasEsperadas = 48,
        int $maxFaltas = 31,
        private readonly PeriodoParser $periodoParser = new PeriodoParser,
        private readonly int $diasMin = 13,
        private readonly int $diasMax = 16,
    ) {
        $catalogo ??= CatalogoColumnas::SISTEMA;
        $this->mapper = new HeaderMapper($catalogo);
        $this->transformer = new RowTransformer($maxFaltas, array_column($catalogo, 'etiqueta', 'clave'));
    }

    /**
     * @param  int  $anio  año a usar si ni el encabezado ni el bloque "Ejercicio" lo traen
     * @param  array{inicio:string, fin:string}|null  $periodoManual  fechas que RH escribió en el asistente
     */
    public function procesar(HojaExtraida $hoja, int $anio, ?array $periodoManual = null): ResultadoProcesamiento
    {
        if ($hoja->filas === []) {
            return new ResultadoProcesamiento(null, null, [], [Mensaje::error('ARCHIVO_VACIO', 'El archivo no contiene filas de datos.')]);
        }

        // 1) Encabezados
        [$mapa, $mensajes, $desconocidas] = $this->mapper->mapear($hoja->encabezados, $hoja->filaEncabezado);
        if ($mapa === null) {
            return new ResultadoProcesamiento(null, null, [], $mensajes, $desconocidas);
        }

        // 2) Periodo (quincena): Ejercicio + número del bloque de arriba; fechas del encabezado o manuales
        $meta = (new MetadatosHoja)->extraer($hoja->filasPrevias);
        [$periodo, $msgPeriodo] = $this->periodo($mapa, $meta, $anio, $periodoManual, $hoja->filaEncabezado);
        array_push($mensajes, ...$msgPeriodo);
        if ($periodo === null) {
            return new ResultadoProcesamiento($mapa, null, [], $mensajes, $desconocidas);
        }

        foreach ($mapa->domingos as $col => $info) {
            $fecha = $periodo->fechaDelDia($info['dia']);
            if ($fecha === null) {
                $mensajes[] = Mensaje::error('DOMINGO_FUERA_DE_PERIODO', "La columna '{$info['etiqueta']}' no cae dentro del periodo ({$this->rango($periodo)}).", $hoja->filaEncabezado, $info['etiqueta'], $col.$hoja->filaEncabezado);
            } elseif ($fecha->format('w') !== '0') {
                $mensajes[] = Mensaje::advertencia('DIA_NO_ES_DOMINGO', "La columna '{$info['etiqueta']}' corresponde al {$fecha->format('d/m/Y')}, que no es domingo. Revisa que el periodo sea el correcto.", $hoja->filaEncabezado, $info['etiqueta'], $col.$hoja->filaEncabezado);
            }
        }

        // 3) Solo filas de empleados: sin filas vacías con formato, totales ni firmas
        [$filasDatos, $ignoradas] = $this->filasDeDatos($hoja->filas, $mapa);
        if ($ignoradas !== []) {
            $lista = implode(', ', array_slice($ignoradas, 0, 8)).(count($ignoradas) > 8 ? '…' : '');
            $mensajes[] = Mensaje::advertencia('FILAS_IGNORADAS', count($ignoradas)." fila(s) sin nombre ni fecha de ingreso se ignoraron (filas vacías, totales o firmas): {$lista}.");
        }
        array_push($mensajes, ...$this->columnasSinEncabezadoConDatos($filasDatos, $mapa, $hoja->filaEncabezado));

        // 4) Transformar y validar cada fila
        $filas = [];
        $vistos = [];
        $numerados = 0;
        foreach ($filasDatos as $f) {
            [$datos, $msgFila] = $this->transformer->transformar($f['fila'], $f['celdas'], $mapa, $periodo);
            if (! empty($datos['_numeracion_quitada'])) {
                $numerados++;
            }
            unset($datos['_numeracion_quitada']);

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

        array_push($mensajes, ...$this->validarNomina($filas, $mapa));

        if ($filas === []) {
            $mensajes[] = Mensaje::error('ARCHIVO_VACIO', 'El archivo no contiene filas de empleados (todas las filas están vacías o son totales/firmas).');
        }
        if ($numerados > 0) {
            $mensajes[] = Mensaje::advertencia('NOMBRES_NUMERADOS', "Se quitó la numeración del inicio de {$numerados} nombre(s) (p. ej. \"1. Alejandro…\" -> \"Alejandro…\"), para que coincidan con el mismo empleado en otras quincenas.");
        }

        // 5) Validaciones de archivo (no bloqueantes)
        if ($this->filasEsperadas > 0 && count($filas) !== $this->filasEsperadas) {
            $mensajes[] = Mensaje::advertencia('FILAS_INESPERADAS', 'El archivo trae '.count($filas)." filas; normalmente son {$this->filasEsperadas}.");
        }

        return new ResultadoProcesamiento($mapa, $periodo, $filas, $mensajes, $desconocidas);
    }

    /**
     * Prioridad de las fechas: 1) las que RH escribió en el asistente, 2) el encabezado de la columna
     * del periodo. El año, si el encabezado no lo trae: Ejercicio del archivo y luego el del formulario.
     *
     * @return array{0: ?Periodo, 1: list<Mensaje>}
     */
    private function periodo(MapaColumnas $mapa, array $meta, int $anio, ?array $manual, int $filaEnc): array
    {
        $mensajes = [];
        $etiqueta = $mapa->etiquetaPeriodo;
        $celda = $mapa->columnaPeriodo ? $mapa->columnaPeriodo.$filaEnc : null;
        $anioBase = $meta['ejercicio'] ?? $anio;
        $num = $meta['numero'] !== null ? " {$meta['numero']}" : '';

        if ($manual) {
            // La etiqueta sale de las fechas capturadas: el encabezado del archivo puede ser justo lo que estaba mal
            $ini = new DateTimeImmutable($manual['inicio']);
            $fin = new DateTimeImmutable($manual['fin']);
            $periodo = new Periodo($ini, $fin, Periodo::etiquetaDe($ini, $fin));
            if ($etiqueta && ($leido = $this->periodoParser->parsear($etiqueta, $anioBase))
                && [$leido->inicio, $leido->fin] != [$periodo->inicio, $periodo->fin]) {
                $mensajes[] = Mensaje::advertencia('PERIODO_MANUAL', "Se usaron las fechas indicadas ({$this->rango($periodo)}) en lugar del encabezado '{$etiqueta}'.", $filaEnc, $etiqueta, $celda);
            }

            return [$periodo->conMetadatos($meta), $mensajes];
        }

        if ($etiqueta === null) {
            return [null, [Mensaje::error('PERIODO_NO_DETECTADO', "No se encontró la columna del periodo{$num} (por ejemplo \"1 de octubre al 13 de octubre de 2026\"). Escribe las fechas de la quincena en el asistente y valida de nuevo.")]];
        }

        $periodo = $this->periodoParser->parsear($etiqueta, $anioBase);
        if ($periodo === null) {
            return [null, [Mensaje::error('PERIODO_NO_DETECTADO', "El encabezado '{$etiqueta}' no es un rango de fechas válido. Corrígelo o escribe las fechas de la quincena en el asistente.", $filaEnc, $etiqueta, $celda, $etiqueta)]];
        }

        $dias = $periodo->dias();
        if ($dias > 31) {
            return [null, [Mensaje::error('PERIODO_INVALIDO', "El encabezado '{$etiqueta}' abarca {$dias} días ({$this->rango($periodo)}); una quincena dura entre {$this->diasMin} y {$this->diasMax}. Corrige el encabezado o escribe las fechas de la quincena{$num} en el asistente.", $filaEnc, $etiqueta, $celda, $etiqueta)]];
        }
        if ($dias < $this->diasMin || $dias > $this->diasMax) {
            $mensajes[] = Mensaje::advertencia('PERIODO_DURACION_INUSUAL', "El periodo '{$etiqueta}' abarca {$dias} días ({$this->rango($periodo)}); una quincena normalmente dura entre {$this->diasMin} y {$this->diasMax}. Confirma que es correcto.", $filaEnc, $etiqueta, $celda);
        }

        if ($periodo->anioEncabezado !== null && $meta['ejercicio'] !== null && $periodo->anioEncabezado !== $meta['ejercicio']) {
            $mensajes[] = Mensaje::advertencia('EJERCICIO_DISTINTO', "El encabezado del periodo dice {$periodo->anioEncabezado} pero el Ejercicio del archivo es {$meta['ejercicio']}; se usó el año del encabezado.", $filaEnc, $etiqueta, $celda);
        } elseif ($periodo->anioEncabezado !== null && $meta['ejercicio'] === null && $periodo->anioEncabezado !== $anio) {
            $mensajes[] = Mensaje::advertencia('ANIO_DEL_ENCABEZADO', "El encabezado del periodo indica el año {$periodo->anioEncabezado}; se usó ese año en lugar de {$anio}.");
        }

        return [$periodo->conMetadatos($meta), $mensajes];
    }

    /**
     * Separa las filas de empleados de las que no lo son. Una fila se ignora si no trae nada en las
     * columnas reconocidas (filas vacías con formato o solo con el número de nómina) o si es un pie
     * ("Total", "Elaboró"…) sin fecha de ingreso. Una fila con datos pero sin nombre NO se ignora:
     * se reporta como error para que RH la corrija.
     *
     * @return array{0: list<array>, 1: list<int>}
     */
    private function filasDeDatos(array $filas, MapaColumnas $mapa): array
    {
        // El número de nómina no cuenta: una plantilla puede traer filas numeradas pero vacías
        $columnas = [...array_keys(array_diff($mapa->campos, ['numero_nomina'])), ...array_keys($mapa->domingos)];
        $colNombre = $mapa->columnaDe('nombre_completo');
        $colFecha = $mapa->columnaDe('fecha_ingreso');
        $vacio = fn ($v) => $v === null || (is_string($v) && trim($v) === '');

        $datos = $ignoradas = [];
        foreach ($filas as $f) {
            $c = $f['celdas'];
            $sinDatos = array_filter($columnas, fn ($col) => ! $vacio($c[$col] ?? null)) === [];
            $esPie = $vacio($c[$colFecha] ?? null) && is_string($c[$colNombre] ?? null)
                && preg_match(self::PIE, Texto::claveEncabezado($c[$colNombre]));

            if ($sinDatos || $esPie) {
                $ignoradas[] = $f['fila'];
            } else {
                $datos[] = $f;
            }
        }

        return [$datos, $ignoradas];
    }

    /** Una columna sin título que trae datos: se ignora, pero RH debe saberlo (podría ser un dato real). */
    private function columnasSinEncabezadoConDatos(array $filas, MapaColumnas $mapa, int $filaEnc): array
    {
        $mensajes = [];
        foreach ($mapa->sinEncabezado as $col) {
            foreach ($filas as $f) {
                $v = $f['celdas'][$col] ?? null;
                if ($v !== null && trim((string) $v) !== '') {
                    $mensajes[] = Mensaje::advertencia('COLUMNA_SIN_ENCABEZADO', "La columna {$col} no tiene encabezado pero trae datos (p. ej. celda {$col}{$f['fila']}); se ignoró. Si es un dato que debe cargarse, ponle título en el Excel.", $filaEnc, null, $col.$filaEnc, $v);
                    break;
                }
            }
        }

        return $mensajes;
    }

    /**
     * Número de nómina ("#"): entero (lo valida RowTransformer), sin repetirse en el archivo
     * y normalmente consecutivo (200, 201, 202...). La continuidad con la quincena anterior
     * necesita la BD y la revisa CargaService.
     *
     * @return list<Mensaje>
     */
    private function validarNomina(array $filas, MapaColumnas $mapa): array
    {
        $col = $mapa->columnaDe('numero_nomina');
        if ($col === null) {
            return [];
        }
        $mensajes = $vistos = $numeros = [];
        foreach ($filas as $f) {
            $n = $f['datos']['numero_nomina'] ?? null;
            if ($n === null) {
                continue;
            }
            if (isset($vistos[$n])) {
                $mensajes[] = Mensaje::error('NOMINA_REPETIDA', "El número de nómina {$n} se repite en las filas {$vistos[$n]} y {$f['fila']}.", $f['fila'], $this->mapper->etiqueta('numero_nomina'), $col.$f['fila'], $n);
            } else {
                $vistos[$n] = $f['fila'];
                $numeros[] = (int) $n;
            }
        }
        $vacios = count($filas) - count($vistos) - count(array_filter($mensajes, fn ($m) => $m->codigo === 'NOMINA_REPETIDA'));
        if ($vacios > 0 && $numeros !== []) {
            $mensajes[] = Mensaje::advertencia('NOMINA_VACIA', "{$vacios} fila(s) no traen número de nómina.", null, $this->mapper->etiqueta('numero_nomina'));
        }

        sort($numeros);
        $huecos = [];
        for ($i = 1, $t = count($numeros); $i < $t; $i++) {
            if ($numeros[$i] !== $numeros[$i - 1] + 1) {
                $huecos[] = "{$numeros[$i - 1]} → {$numeros[$i]}";
            }
        }
        if ($huecos !== []) {
            $lista = implode(', ', array_slice($huecos, 0, 5)).(count($huecos) > 5 ? '…' : '');
            $mensajes[] = Mensaje::advertencia('NOMINA_NO_CONSECUTIVA', "Los números de nómina no son consecutivos: {$lista}.", null, $this->mapper->etiqueta('numero_nomina'));
        }

        return $mensajes;
    }

    private function rango(Periodo $p): string
    {
        return "del {$p->inicio->format('d/m/Y')} al {$p->fin->format('d/m/Y')}";
    }
}
