<?php

namespace Tests\Unit\Etl;

use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\Dto\ResultadoProcesamiento;
use App\Services\Etl\ProcesadorHoja;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Etl\Fixtures\Q17Final;

/**
 * v6: archivo definitivo de RH — "#" es el número de nómina (200…223), la tabla puede llegar en
 * otra posición y el título del bloque de arriba se conserva para exportar.
 */
class FormatoFinalRhTest extends TestCase
{
    private function procesar(array $grid): ResultadoProcesamiento
    {
        return (new ProcesadorHoja(filasEsperadas: 0))->procesar(Q17Final::extraer($grid), 2026);
    }

    private function codigos(array $mensajes): array
    {
        return array_map(fn (Mensaje $m) => $m->codigo, $mensajes);
    }

    public function test_el_archivo_carga_sin_errores_ni_avisos_a_confirmar(): void
    {
        $r = $this->procesar(Q17Final::libro());

        $this->assertFalse($r->tieneErrores(), json_encode(array_map(fn ($m) => $m->toArray(), $r->mensajes), JSON_UNESCAPED_UNICODE));
        $this->assertFalse($r->requiereConfirmacion());
        $this->assertSame(24, count($r->filas));
        $this->assertSame([2026, 17, 'Quincenal'], [$r->periodo->ejercicio, $r->periodo->numero, $r->periodo->tipo]);
        $this->assertSame('2026-10-01', $r->periodo->inicio->format('Y-m-d'));
        $this->assertSame('Incidencias para pago de nomina', $r->periodo->titulo);
    }

    public function test_el_numero_de_nomina_se_lee_por_fila(): void
    {
        $r = $this->procesar(Q17Final::libro());

        $this->assertSame('numero_nomina', $r->mapa->campos['A']);
        $this->assertSame(200, $r->filas[0]['datos']['numero_nomina']);
        $this->assertSame(223, $r->filas[23]['datos']['numero_nomina']);
        $this->assertSame('Alejandro Martínez Salgado', $r->filas[0]['datos']['nombre_completo']);   // sin el espacio inicial
        $this->assertNotContains('NOMBRES_NUMERADOS', $this->codigos($r->mensajes));
    }

    public function test_la_tabla_movida_de_posicion_se_lee_igual(): void
    {
        $original = $this->procesar(Q17Final::libro());
        $movida = $this->procesar(Q17Final::libro(abajo: 9, derecha: 3));   // la estructura empieza en D10

        $this->assertFalse($movida->tieneErrores());
        $this->assertSame(13, Q17Final::extraer(Q17Final::libro(9, 3))->filaEncabezado);
        $this->assertSame('numero_nomina', $movida->mapa->campos['D']);
        $this->assertSame(17, $movida->periodo->numero);
        $this->assertSame($original->contenidoHash(), $movida->contenidoHash());   // mismos datos
    }

    public function test_tabla_muy_abajo_dentro_de_50_filas(): void
    {
        $r = $this->procesar(Q17Final::libro(abajo: 40));
        $this->assertFalse($r->tieneErrores());
        $this->assertSame(24, count($r->filas));
    }

    public function test_numero_de_nomina_repetido_es_error_con_celda(): void
    {
        $r = $this->procesar(Q17Final::libro(cambios: [5 => ['A' => 200]]));   // fila 10 repite el 200

        $err = $r->errores()[0];
        $this->assertSame('NOMINA_REPETIDA', $err->codigo);
        $this->assertSame('A10', $err->celda);
    }

    public function test_numeros_de_nomina_salteados_solo_informan(): void
    {
        $r = $this->procesar(Q17Final::libro(cambios: [23 => ['A' => 230]]));

        $this->assertFalse($r->tieneErrores());
        $this->assertContains('NOMINA_NO_CONSECUTIVA', $this->codigos($r->advertencias()));
        $this->assertFalse($r->requiereConfirmacion());
    }

    public function test_numero_de_nomina_que_no_es_numero_es_error(): void
    {
        $r = $this->procesar(Q17Final::libro(cambios: [0 => ['A' => 'doscientos']]));
        $this->assertSame('TIPO_INVALIDO', $r->errores()[0]->codigo);
        $this->assertSame('A5', $r->errores()[0]->celda);
    }

    public function test_sin_columna_de_nomina_pide_confirmar(): void
    {
        $grid = Q17Final::libro();
        $grid[3][0] = null;   // encabezado "#" borrado
        foreach (array_keys($grid) as $i) {
            if ($i >= 4) {
                $grid[$i][0] = null;
            }
        }
        $r = $this->procesar($grid);

        $this->assertContains('COLUMNA_AUSENTE', $this->codigos($r->advertencias()));
        $this->assertTrue($r->requiereConfirmacion());
    }
}
