<?php

namespace Tests\Unit\Support;

use App\Support\GuiaMensajes;
use PHPUnit\Framework\TestCase;

/** v7: mensajes de la carga agrupados y en lenguaje de RH. */
class GuiaMensajesTest extends TestCase
{
    private function m(string $nivel, string $codigo, ?string $celda = null, ?int $fila = null): array
    {
        return ['nivel' => $nivel, 'codigo' => $codigo, 'mensaje' => "Mensaje {$codigo} {$celda}", 'fila' => $fila, 'celda' => $celda];
    }

    public function test_agrupa_por_codigo_con_sus_celdas(): void
    {
        $g = GuiaMensajes::agrupar([
            $this->m('ERROR', 'FECHA_INVALIDA', 'D8', 8),
            $this->m('ERROR', 'FECHA_INVALIDA', 'D14', 14),
            $this->m('ERROR', 'FECHA_INVALIDA', 'D20', 20),
        ]);

        $this->assertSame(1, count($g));
        $this->assertSame('error', $g[0]['tipo']);
        $this->assertSame(3, $g[0]['cantidad']);
        $this->assertSame(['D8', 'D14', 'D20'], $g[0]['ubicaciones']);
        $this->assertSame('Fechas no válidas', $g[0]['titulo']);
        $this->assertStringContainsString('dd/mm/aaaa', $g[0]['solucion']);
    }

    public function test_orden_errores_luego_confirmar_luego_informativos(): void
    {
        $g = GuiaMensajes::agrupar([
            $this->m('ADVERTENCIA', 'FILAS_INESPERADAS'),
            $this->m('ADVERTENCIA', 'PERIODO_YA_CARGADO'),
            $this->m('ERROR', 'NOMINA_REPETIDA', 'A10', 10),
        ]);

        $this->assertSame(['error', 'confirmar', 'info'], array_column($g, 'tipo'));
        $this->assertSame('Números de nómina repetidos', $g[0]['titulo']);
    }

    public function test_sin_celda_usa_la_fila_y_codigo_desconocido_usa_el_mensaje(): void
    {
        $g = GuiaMensajes::agrupar([$this->m('ERROR', 'DUPLICADO_EN_ARCHIVO', null, 31), $this->m('ADVERTENCIA', 'ALGO_NUEVO')]);

        $this->assertSame(['fila 31'], $g[0]['ubicaciones']);
        $this->assertSame('Mensaje ALGO_NUEVO ', $g[1]['titulo']);
    }

    public function test_limita_las_ubicaciones_a_doce(): void
    {
        $muchos = array_map(fn ($i) => $this->m('ERROR', 'CELDA_VACIA', "B{$i}", $i), range(5, 40));
        $g = GuiaMensajes::agrupar($muchos);

        $this->assertSame(36, $g[0]['cantidad']);
        $this->assertSame(12, count($g[0]['ubicaciones']));
    }
}
