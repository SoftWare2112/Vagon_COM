<?php

namespace Tests\Unit\Hojas;

use App\Support\Hojas\ReferenciasFormula as R;
use App\Support\Hojas\ValidadorCelda;
use App\Support\Hojas\ValidadorEstilo;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** v8: fórmulas guardadas por id, tipos de celda y formatos. */
class ReferenciasYValidacionTest extends TestCase
{
    private const FILAS = [101, 102, 103];      // filas 1, 2, 3

    private const COLUMNAS = [201, 202, 203, 204];   // A, B, C, D

    public function test_letras_de_columna(): void
    {
        $this->assertSame(0, R::indiceColumna('A'));
        $this->assertSame(27, R::indiceColumna('AB'));
        $this->assertSame('Z', R::letraColumna(25));
        $this->assertSame('AA', R::letraColumna(26));
        $this->assertSame('ZZ', R::letraColumna(701));
    }

    public function test_a1_a_interno_y_de_regreso(): void
    {
        $interno = R::aInterno('=SUMA(B1:B3)*$C$2+"A1"', self::FILAS, self::COLUMNAS);
        $this->assertSame('=SUMA([f101c202]:[f103c202])*[f102c203]+"A1"', $interno);   // el texto "A1" no se toca

        $pos = fn (array $ids) => array_flip($ids);
        $this->assertSame('=SUMA(B1:B3)*C2+"A1"', R::aVisible($interno, $pos(self::FILAS), $pos(self::COLUMNAS)));
    }

    public function test_insertar_una_fila_arriba_mueve_la_referencia_visible(): void
    {
        $interno = R::aInterno('=C2', self::FILAS, self::COLUMNAS);
        // Se insertó una fila nueva (999) en la posición 1: la fila 102 ahora es la 3
        $this->assertSame('=C3', R::aVisible($interno, array_flip([101, 999, 102, 103]), array_flip(self::COLUMNAS)));
    }

    public function test_celda_borrada_o_fuera_de_la_hoja_es_ref(): void
    {
        $interno = R::aInterno('=A1+D9', self::FILAS, self::COLUMNAS);
        $this->assertSame('=[f101c201]+#REF!', $interno);
        $this->assertSame('=#REF!+#REF!', R::aVisible($interno, array_flip([102, 103]), array_flip(self::COLUMNAS)));
    }

    public function test_no_confunde_funciones_con_celdas(): void
    {
        $this->assertSame('=LOG10([f101c201])', R::aInterno('=LOG10(A1)', self::FILAS, self::COLUMNAS));
    }

    public function test_tipos_de_celda(): void
    {
        $this->assertSame('1250.500000', ValidadorCelda::normalizar(['t' => 'numero', 'v' => 1250.5])['valor_numero']);
        $this->assertSame('2026-10-28 00:00:00', ValidadorCelda::normalizar(['t' => 'fecha', 'v' => '2026-10-28'])['valor_fecha']);
        $this->assertSame('2026-10-28 14:18:00', ValidadorCelda::normalizar(['t' => 'fecha_hora', 'v' => '2026-10-28T14:18'])['valor_fecha']);
        $this->assertSame('09:05:00', ValidadorCelda::normalizar(['t' => 'hora', 'v' => '09:05'])['valor_texto']);
        $this->assertTrue(ValidadorCelda::normalizar(['t' => 'logico', 'v' => true])['valor_logico']);

        $f = ValidadorCelda::normalizar(['t' => 'formula', 'fx' => '=[f1c1]*2', 'rt' => 'numero', 'v' => 10]);
        $this->assertSame(['formula', '=[f1c1]*2', 'numero', '10.000000'], [$f['tipo'], $f['formula'], $f['resultado_tipo'], $f['valor_numero']]);

        $e = ValidadorCelda::normalizar(['t' => 'formula', 'fx' => '=1/0', 'rt' => 'error', 'v' => '#DIV/0!']);
        $this->assertSame('#DIV/0!', $e['valor_texto']);
    }

    public function test_valores_que_no_corresponden_a_su_tipo_se_rechazan(): void
    {
        foreach ([
            ['t' => 'numero', 'v' => 'doce'], ['t' => 'fecha', 'v' => '2026-02-31'], ['t' => 'hora', 'v' => '25:00'],
            ['t' => 'logico', 'v' => 'si'], ['t' => 'formula', 'fx' => 'SUMA(A1)'], ['t' => 'otro'],
            ['t' => 'formula', 'fx' => '=1', 'rt' => 'error', 'v' => '#INVENTADO'],
        ] as $c) {
            try {
                ValidadorCelda::normalizar($c);
                $this->fail('Debió rechazar '.json_encode($c));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_estilo_normalizado_y_huella_estable(): void
    {
        $a = ValidadorEstilo::normalizar(['f' => '#a9dfbf', 'n' => true, 'i' => false, 'fmt' => 'moneda', 'a' => 'centro']);
        $b = ValidadorEstilo::normalizar(['a' => 'centro', 'fmt' => 'moneda', 'n' => true, 'f' => '#A9DFBF']);

        $this->assertSame(['a' => 'centro', 'f' => '#A9DFBF', 'fmt' => 'moneda', 'n' => true], $a);
        $this->assertSame(ValidadorEstilo::huella($a), ValidadorEstilo::huella($b));
        $this->assertSame([], ValidadorEstilo::normalizar(['fmt' => 'general', 'n' => false]));
    }
}
