<?php

namespace Tests\Unit\Etl;

use App\Services\Etl\Dto\Mensaje;
use App\Services\Etl\SelectorHoja;
use PHPUnit\Framework\TestCase;

/** Libros de Excel con varias hojas: cuál se lee y qué se avisa. */
class SelectorHojaTest extends TestCase
{
    private const REPORTE = [
        ['Nombre', 'Fecha de ingreso', 'Puesto', 'Prima vacacional', 'Bono 3 meses', 'Numero de faltas', 'Domingo 4', '1 de octubre al 13 de octubre de 2026'],
        ['Ana López', '15/01/2018', 'Asesor', '$600.00', '$600.00', 1, 1, 'x'],
    ];

    private function hoja(string $nombre, array $filas, bool $visible = true): array
    {
        return ['nombre' => $nombre, 'visible' => $visible, 'filas' => $filas];
    }

    private function codigos(array $r): array
    {
        return array_map(fn (Mensaje $m) => $m->codigo, $r['mensajes']);
    }

    public function test_un_libro_de_una_hoja_no_genera_avisos(): void
    {
        $r = (new SelectorHoja)->elegir([$this->hoja('Hoja1', self::REPORTE)]);
        $this->assertSame(0, $r['indice']);
        $this->assertSame([], $r['mensajes']);
    }

    public function test_encuentra_el_reporte_aunque_no_sea_la_primera_hoja_y_avisa_las_ignoradas(): void
    {
        $r = (new SelectorHoja)->elegir([
            $this->hoja('Notas', [['Comentarios de RH'], ['Revisar a Luis']]),
            $this->hoja('Agosto 2Q', self::REPORTE),
            $this->hoja('Catálogo', [['Puesto', 'Sueldo'], ['Asesor', 100]]),
        ]);
        $this->assertSame(1, $r['indice']);
        $this->assertSame(['HOJAS_IGNORADAS'], $this->codigos($r));
        $this->assertStringContainsString("'Notas', 'Catálogo'", $r['mensajes'][0]->mensaje);
    }

    public function test_encabezados_debajo_de_un_titulo(): void
    {
        $r = (new SelectorHoja)->elegir([$this->hoja('Reporte', [['REPORTE DE PERSONAL'], [], ...self::REPORTE])]);
        $this->assertSame(3, $r['filaEncabezado']);
    }

    public function test_ninguna_hoja_con_formato_es_error_con_la_lista_de_hojas(): void
    {
        $r = (new SelectorHoja)->elegir([$this->hoja('Notas', [['hola']]), $this->hoja('Datos', [['Nombre', 'Correo']])]);
        $this->assertNull($r['indice']);
        $this->assertSame(['HOJA_NO_ENCONTRADA'], $this->codigos($r));
        $this->assertStringContainsString("'Notas', 'Datos'", $r['mensajes'][0]->mensaje);
    }

    public function test_varias_hojas_con_formato_usa_la_mas_completa_y_avisa(): void
    {
        $incompleta = [['Nombre', 'Fecha de ingreso'], ['Ana', '01/01/2020']];
        $r = (new SelectorHoja)->elegir([$this->hoja('Borrador', $incompleta), $this->hoja('Final', self::REPORTE)]);
        $this->assertSame(1, $r['indice']);
        $this->assertContains('HOJA_AMBIGUA', $this->codigos($r));
    }

    public function test_el_usuario_puede_elegir_la_hoja(): void
    {
        $hojas = [$this->hoja('1Q agosto', self::REPORTE), $this->hoja('2Q agosto', self::REPORTE)];
        $r = (new SelectorHoja)->elegir($hojas, '2Q agosto');
        $this->assertSame(1, $r['indice']);
        $this->assertNotContains('HOJA_AMBIGUA', $this->codigos($r));
    }

    public function test_hoja_elegida_inexistente_o_sin_formato_es_error(): void
    {
        $hojas = [$this->hoja('Datos', self::REPORTE), $this->hoja('Notas', [['x']])];
        $this->assertSame(['HOJA_NO_EXISTE'], $this->codigos((new SelectorHoja)->elegir($hojas, 'Otra')));
        $this->assertSame(['HOJA_SIN_FORMATO'], $this->codigos((new SelectorHoja)->elegir($hojas, 'Notas')));
    }

    public function test_hojas_ocultas_no_se_eligen_automaticamente(): void
    {
        $r = (new SelectorHoja)->elegir([$this->hoja('Oculta', self::REPORTE, false), $this->hoja('Visible', self::REPORTE)]);
        $this->assertSame(1, $r['indice']);
        $this->assertNotContains('HOJA_AMBIGUA', $this->codigos($r));
    }
}
