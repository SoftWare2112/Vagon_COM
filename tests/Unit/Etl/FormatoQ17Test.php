<?php

namespace Tests\Unit\Etl;

use App\Services\Etl\Dto\HojaExtraida;
use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\Dto\ResultadoProcesamiento;
use App\Services\Etl\HeaderMapper;
use App\Services\Etl\ProcesadorHoja;
use App\Services\Etl\SelectorHoja;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Etl\Fixtures\Q17;

/**
 * El archivo real INCIDENCIAS_Q17.xlsx de RH (v5): bloque Ejercicio/Periodo arriba, nombres
 * numerados, columnas de relleno y un encabezado de periodo que abarca 88 días.
 */
class FormatoQ17Test extends TestCase
{
    private function procesar(?array $periodoManual = null, ?array $filas = null, ?array $encabezados = null): ResultadoProcesamiento
    {
        $hoja = new HojaExtraida($encabezados ?? Q17::ENCABEZADOS, $filas ?? Q17::filas(), 4, 'Hoja1', Q17::PREVIAS);

        return (new ProcesadorHoja(filasEsperadas: 0))->procesar($hoja, 2026, $periodoManual);
    }

    private function codigos(array $mensajes): array
    {
        return array_map(fn (Mensaje $m) => $m->codigo, $mensajes);
    }

    public function test_encuentra_los_encabezados_debajo_del_bloque_de_datos(): void
    {
        $filas = [...Q17::PREVIAS, array_values(Q17::ENCABEZADOS)];
        $r = (new SelectorHoja(new HeaderMapper))->elegir([['nombre' => 'Hoja1', 'visible' => true, 'filas' => $filas]]);
        $this->assertSame(0, $r['indice']);
        $this->assertSame(4, $r['filaEncabezado']);
    }

    public function test_todas_las_columnas_se_reconocen_y_el_relleno_se_ignora_sin_aviso(): void
    {
        $r = $this->procesar(['inicio' => '2026-10-01', 'fin' => '2026-10-13']);

        $this->assertSame([
            'B' => 'numero_nomina', 'C' => 'nombre_completo', 'D' => 'puesto', 'E' => 'fecha_ingreso', 'G' => 'prima_vacacional', 'H' => 'bono_3_meses',
            'I' => 'bono_puntualidad', 'J' => 'bono_asistencia', 'K' => 'num_faltas', 'L' => 'detalle', 'M' => 'acredita_bono',
            'N' => 'programado_domingo',
        ], $r->mapa->campos);
        $this->assertSame(['O', 'P'], array_keys($r->mapa->domingos));
        $this->assertSame('Q', $r->mapa->columnaPeriodo);
        $this->assertNotContains('ENCABEZADO_DESCONOCIDO', $this->codigos($r->mensajes));   // "Columna1"
        $this->assertSame([], $r->desconocidas);
    }

    public function test_el_encabezado_de_88_dias_es_error_claro_con_el_numero_de_periodo(): void
    {
        $r = $this->procesar();

        $this->assertTrue($r->tieneErrores());
        $err = $r->errores()[0];
        $this->assertSame('PERIODO_INVALIDO', $err->codigo);
        $this->assertStringContainsString('88 días', $err->mensaje);
        $this->assertStringContainsString('quincena 17', $err->mensaje);
        $this->assertSame('Q4', $err->celda);
    }

    public function test_con_fechas_manuales_carga_los_24_empleados_del_periodo_17(): void
    {
        $r = $this->procesar(['inicio' => '2026-10-01', 'fin' => '2026-10-13']);

        $this->assertFalse($r->tieneErrores(), json_encode(array_map(fn ($m) => $m->toArray(), $r->errores()), JSON_UNESCAPED_UNICODE));
        $this->assertSame(24, count($r->filas));
        $this->assertSame(2026, $r->periodo->ejercicio);
        $this->assertSame(17, $r->periodo->numero);
        $this->assertSame('Quincenal', $r->periodo->tipo);
        $this->assertSame('1 de octubre al 13 de octubre de 2026', $r->periodo->etiqueta);   // de las fechas, no del encabezado erróneo
        $this->assertContains('PERIODO_MANUAL', $this->codigos($r->advertencias()));
        $this->assertFalse($r->requiereConfirmacion());   // los domingos 4 y 11 de octubre sí son domingo
    }

    public function test_quita_la_numeracion_de_los_nombres(): void
    {
        $r = $this->procesar(['inicio' => '2026-10-01', 'fin' => '2026-10-13']);
        $d = $r->filas[0]['datos'];

        $this->assertSame('Alejandro Martínez Salgado', $d['nombre_completo']);
        $this->assertSame('alejandro martinez salgado', $d['nombre_normalizado']);
        $this->assertSame('Mariana Ortega Silva', $r->filas[9]['datos']['nombre_completo']);   // "10. Mariana…"
        $this->assertContains('NOMBRES_NUMERADOS', $this->codigos($r->advertencias()));
    }

    public function test_lee_los_valores_de_cada_columna(): void
    {
        $r = $this->procesar(['inicio' => '2026-10-01', 'fin' => '2026-10-13']);
        $camila = $r->filas[3]['datos'];     // fila 8: prima 1, domingo 4 = 1, domingo 11 = 1
        $gabriel = $r->filas[18]['datos'];   // fila 23: 3 faltas

        $this->assertSame('2024-09-09', $camila['fecha_ingreso']);
        $this->assertSame('ASESOR MSI', $camila['puesto']);
        $this->assertEquals(1, $camila['prima_vacacional']);
        $this->assertSame('2026-10-04', $camila['domingos'][0]['fecha']);
        $this->assertEquals(1, $camila['domingos'][1]['cantidad']);
        $this->assertSame(3, $gabriel['num_faltas']);
        $this->assertNull($r->filas[0]['datos']['num_faltas']);   // celda vacía: no se calcula nada
    }

    public function test_el_periodo_correcto_en_el_encabezado_no_necesita_fechas_manuales(): void
    {
        $enc = Q17::ENCABEZADOS;
        $enc['Q'] = '1 de octubre al 13 de octubre';   // sin año: se toma el Ejercicio 2026
        $r = $this->procesar(null, null, $enc);

        $this->assertFalse($r->tieneErrores());
        $this->assertSame('2026-10-01', $r->periodo->inicio->format('Y-m-d'));
        $this->assertSame(17, $r->periodo->numero);
    }

    public function test_agosto_con_domingos_de_octubre_pide_confirmacion(): void
    {
        $enc = Q17::ENCABEZADOS;
        $enc['Q'] = '1 de agosto al 13 de agosto';   // el 4 y el 11 de agosto de 2026 no son domingo
        $r = $this->procesar(null, null, $enc);

        $this->assertFalse($r->tieneErrores());
        $this->assertContains('DIA_NO_ES_DOMINGO', $this->codigos($r->advertencias()));
        $this->assertTrue($r->requiereConfirmacion());
    }

    public function test_periodo_de_27_dias_pide_confirmacion(): void
    {
        $enc = Q17::ENCABEZADOS;
        $enc['Q'] = '1 de octubre al 27 de octubre';
        $r = $this->procesar(null, null, $enc);

        $this->assertContains('PERIODO_DURACION_INUSUAL', $this->codigos($r->advertencias()));
        $this->assertTrue($r->requiereConfirmacion());
    }

    public function test_filas_vacias_con_formato_totales_y_firmas_se_ignoran(): void
    {
        $vacia = array_fill_keys(array_keys(Q17::ENCABEZADOS), null);
        $filas = [
            ...Q17::filas(),
            ['fila' => 29, 'celdas' => array_replace($vacia, ['B' => 25])],                         // solo el consecutivo
            ['fila' => 30, 'celdas' => array_replace($vacia, ['C' => 'TOTAL', 'K' => 5, 'O' => 9])],   // totales
            ['fila' => 32, 'celdas' => array_replace($vacia, ['C' => 'Elaboró: Recursos Humanos'])],
        ];
        $r = $this->procesar(['inicio' => '2026-10-01', 'fin' => '2026-10-13'], $filas);

        $this->assertFalse($r->tieneErrores());
        $this->assertSame(24, count($r->filas));
        $this->assertContains('FILAS_IGNORADAS', $this->codigos($r->advertencias()));
    }

    public function test_fila_con_datos_pero_sin_nombre_si_es_error(): void
    {
        $vacia = array_fill_keys(array_keys(Q17::ENCABEZADOS), null);
        $filas = [...Q17::filas(), ['fila' => 29, 'celdas' => array_replace($vacia, ['E' => 46000, 'K' => 2])]];
        $r = $this->procesar(['inicio' => '2026-10-01', 'fin' => '2026-10-13'], $filas);

        $this->assertSame('C29', $r->errores()[0]->celda);
    }

    public function test_columna_sin_encabezado_con_datos_pide_confirmacion(): void
    {
        $filas = Q17::filas();
        $filas[0]['celdas']['F'] = 'dato suelto';
        $r = $this->procesar(['inicio' => '2026-10-01', 'fin' => '2026-10-13'], $filas);

        $this->assertContains('COLUMNA_SIN_ENCABEZADO', $this->codigos($r->advertencias()));
        $this->assertTrue($r->requiereConfirmacion());
    }
}
