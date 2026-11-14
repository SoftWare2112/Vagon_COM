<?php

namespace Tests\Unit\Hojas;

use App\Support\Hojas\Importacion\FormatoExcel;
use App\Support\Hojas\Importacion\TraductorExcel;
use App\Support\Hojas\Importacion\TraductorFormula;
use PHPUnit\Framework\TestCase;

/** v9: importar cualquier Excel — fórmulas, formatos de número, fechas, estilos, combinadas y límites. */
class ImportacionExcelTest extends TestCase
{
    public function test_formulas_que_el_motor_calcula_se_conservan(): void
    {
        $this->assertSame('=SUM(B2:B9)', TraductorFormula::traducir('=SUM(B2:B9)'));
        $this->assertSame('=IF(A1>0,"Sí",B2*$C$3)', TraductorFormula::traducir('=IF(A1>0,"Sí",B2*$C$3)'));
        $this->assertSame('=ROUND(AVERAGE(A1:A3),2)&" pesos"', TraductorFormula::traducir('=ROUND(AVERAGE(A1:A3),2)&" pesos"'));
        $this->assertSame('=SUMA(A1;A2)', TraductorFormula::traducir('=SUMA(A1;A2)'));
        $this->assertSame('=CONCATENATE(A1," VLOOKUP ")', TraductorFormula::traducir('=_xlfn.CONCATENATE(A1," VLOOKUP ")'));   // texto entre comillas no cuenta
        $this->assertSame('=A1*1.5E+3', TraductorFormula::traducir('=A1*1.5E+3'));
        $this->assertSame('=AND(A1,TRUE)', TraductorFormula::traducir('=AND(A1,TRUE)'));
    }

    public function test_formulas_no_soportadas_se_rechazan(): void
    {
        foreach ([
            '=VLOOKUP(A1,B:C,2,FALSE)',   // función desconocida
            '=Hoja2!A1*2',                // otra hoja
            "='Datos 2026'!B4",           // otra hoja con espacios
            '=SUM(A:A)',                  // columna completa
            '=SUM(3:3)',                  // fila completa
            '=Tasa*B2',                   // nombre definido
            '=SUM(Tabla1[Total])',        // tabla con nombre
            '={1,2,3}',                   // matriz
            '=STDEV.S(A1:A3)',            // función con punto
            '=A1&"sin cerrar',            // comillas sin cerrar
        ] as $fx) {
            $this->assertNull(TraductorFormula::traducir($fx), $fx);
        }
    }

    public function test_formato_de_numero_de_excel(): void
    {
        $casos = [
            'General' => 'general', '' => 'general', '@' => 'texto', '0' => 'entero', '#,##0' => 'entero',
            '0.00' => 'numero', '#,##0.00' => 'numero', '0%' => 'porcentaje', '0.00%' => 'porcentaje',
            '"$"#,##0.00' => 'moneda', '[$$-80A]#,##0.00' => 'moneda', '$#,##0.00;[Red]-$#,##0.00' => 'moneda',
            'dd/mm/yyyy' => 'fecha', 'd-mmm-yy' => 'fecha', 'mmmm yyyy' => 'fecha', 'm/d/yyyy' => 'fecha',
            'dd/mm/yyyy hh:mm' => 'fecha_hora', 'h:mm AM/PM' => 'hora', 'hh:mm:ss' => 'hora', '[h]:mm' => 'hora', 'mm:ss' => 'hora',
            '0.00E+00' => 'numero',
        ];
        foreach ($casos as $codigo => $esperado) {
            $this->assertSame($esperado, FormatoExcel::tipo($codigo), "«{$codigo}»");
        }
    }

    public function test_numero_de_serie_a_fecha_y_hora(): void
    {
        $this->assertSame(['fecha' => '2026-10-30', 'hora' => '00:00'], FormatoExcel::desdeSerial(46325));
        $this->assertSame(['fecha' => '2026-10-30', 'hora' => '18:30'], FormatoExcel::desdeSerial(46325.770833333));
        $this->assertSame('1900-01-01', FormatoExcel::desdeSerial(1)['fecha']);
        $this->assertSame('1900-03-01', FormatoExcel::desdeSerial(61)['fecha']);
    }

    public function test_cada_celda_conserva_su_tipo(): void
    {
        $c = fn (array $d) => TraductorExcel::celda($d)[0];
        $this->assertSame(['t' => 'numero', 'v' => 1250.5], $c(['tipo' => 'n', 'valor' => 1250.5, 'formato' => '#,##0.00']));
        $this->assertSame(['t' => 'fecha', 'v' => '2026-10-30'], $c(['tipo' => 'n', 'valor' => 46325, 'formato' => 'dd/mm/yyyy']));
        $this->assertSame(['t' => 'hora', 'v' => '08:30'], $c(['tipo' => 'n', 'valor' => 0.354166667, 'formato' => 'hh:mm']));
        $this->assertSame(['t' => 'texto', 'v' => 'Hola'], $c(['tipo' => 's', 'valor' => 'Hola', 'formato' => 'General']));
        $this->assertSame(['t' => 'logico', 'v' => true], $c(['tipo' => 'b', 'valor' => true]));
        $this->assertSame(['t' => 'texto', 'v' => '#N/A'], $c(['tipo' => 'e', 'valor' => '#N/A']));
        $this->assertSame('texto', $c(['tipo' => 'n', 'valor' => 1.2345678901234568E+17])['t']);   // demasiado grande para número
    }

    public function test_formula_soportada_guarda_su_ultimo_resultado_y_la_otra_se_vuelve_valor(): void
    {
        [$si, $tipo] = TraductorExcel::celda(['tipo' => 'f', 'valor' => '=B2*C2', 'calculado' => 455, 'formato' => '"$"#,##0.00']);
        $this->assertSame(['t' => 'formula', 'fx' => '=B2*C2', 'rt' => 'numero', 'v' => 455], $si);
        $this->assertSame('formula', $tipo);

        [$no, $tipo] = TraductorExcel::celda(['tipo' => 'f', 'valor' => '=VLOOKUP(A1,B:C,2,0)', 'calculado' => 'Papel', 'formato' => 'General']);
        $this->assertSame(['t' => 'texto', 'v' => 'Papel'], $no);
        $this->assertSame('valor', $tipo);

        [$err] = TraductorExcel::celda(['tipo' => 'f', 'valor' => '=A1/0', 'calculado' => '#DIV/0!']);
        $this->assertSame(['t' => 'formula', 'fx' => '=A1/0', 'rt' => 'error', 'v' => '#DIV/0!'], $err);

        [$fecha] = TraductorExcel::celda(['tipo' => 'f', 'valor' => '=TODAY()', 'calculado' => 46325, 'formato' => 'dd/mm/yyyy']);
        $this->assertSame(['t' => 'formula', 'fx' => '=TODAY()', 'rt' => 'fecha', 'v' => '2026-10-30'], $fecha);
    }

    public function test_estilo_de_excel_a_formato_de_la_plataforma(): void
    {
        $this->assertSame(['a' => 'centro', 'f' => '#A9DFBF', 'fmt' => 'moneda', 'n' => true],
            TraductorExcel::estilo(['n' => true, 'f' => '#a9dfbf', 'a' => 'centro', 'x' => 'ignorado'], '"$"#,##0.00'));
        $this->assertSame([], TraductorExcel::estilo([], 'General'));
        $this->assertSame([], TraductorExcel::estilo(['c' => 'rojo'], null));   // color inválido: se descarta
    }

    public function test_pestana_completa_con_estilos_compartidos_combinadas_e_imagenes(): void
    {
        $r = TraductorExcel::traducir([
            'nombre' => 'Ventas', 'ancho_defecto' => 64, 'anchos' => [0 => 200, 3 => 5000], 'altos' => [0 => 40],
            'celdas' => [
                [0, 0, ['tipo' => 's', 'valor' => 'Concepto', 'estilo' => ['n' => true]]],
                [0, 1, ['tipo' => 's', 'valor' => 'Importe', 'estilo' => ['n' => true]]],
                [1, 1, ['tipo' => 'f', 'valor' => '=SUM(B5:B9)', 'calculado' => 10]],
                [2, 0, ['tipo' => 'f', 'valor' => '=Hoja2!A1', 'calculado' => 'X']],
                [3, 0, ['tipo' => 'null', 'valor' => null, 'estilo' => ['f' => '#FFFF00']]],   // solo formato
                [4, 0, ['tipo' => 'null', 'valor' => null, 'estilo' => []]],                    // vacía: se omite
            ],
            'combinadas' => [[10, 0, 10, 3], [5, 5, 5, 5]],   // la de una sola celda se ignora
            'imagenes' => [[0, 6, ['x' => 3, 'y' => 4, 'w' => 120, 'h' => 60, 'contenido' => 'png', 'mime' => 'image/png']]],
        ]);
        $def = $r['definicion'];

        $this->assertCount(5, $def['celdas']);
        $this->assertSame(['e1' => ['n' => true], 'e2' => ['f' => '#FFFF00']], $def['estilos']);
        $this->assertSame('e1', $def['celdas'][1][2]['e']);
        $this->assertSame([[10, 0, 10, 3]], $def['combinadas']);
        $this->assertCount(1, $def['imagenes']);
        $this->assertSame(26, count($def['columnas']));             // mínimo 26 columnas
        $this->assertSame([200, 64, 64, 800], array_slice($def['columnas'], 0, 4));   // ancho limitado a 800
        $this->assertSame(100, $def['filas']);                       // mínimo 100 filas
        $this->assertSame(40, $def['altos'][0]);
        $this->assertSame(['celdas' => 5, 'formulas' => 1, 'formulas_a_valor' => 1, 'combinadas' => 1, 'imagenes' => 1], $r['estadisticas']);
        $this->assertCount(1, $r['avisos']);
        $this->assertStringContainsString('1 fórmula(s)', $r['avisos'][0]);
    }

    public function test_lo_que_pasa_de_los_limites_se_avisa(): void
    {
        $r = TraductorExcel::traducir(['nombre' => 'Grande', 'celdas' => [
            [0, 0, ['tipo' => 's', 'valor' => 'ok']],
            [TraductorExcel::MAX_FILAS, 0, ['tipo' => 's', 'valor' => 'fuera']],
            [0, TraductorExcel::MAX_COLUMNAS, ['tipo' => 's', 'valor' => 'fuera']],
        ]]);
        $this->assertCount(1, $r['definicion']['celdas']);
        $this->assertStringContainsString('2 celda(s) quedaron fuera', $r['avisos'][0]);
    }
}
