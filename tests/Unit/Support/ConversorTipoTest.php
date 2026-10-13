<?php

namespace Tests\Unit\Support;

use App\Support\ConversorTipo;
use PHPUnit\Framework\TestCase;

/** Reglas únicas de conversión (ETL, edición web y cambio de tipo de columna). */
class ConversorTipoTest extends TestCase
{
    private function v(mixed $valor, string $tipo): mixed
    {
        $r = ConversorTipo::convertir($valor, $tipo);
        $this->assertTrue($r['ok'], "No convirtió '".var_export($valor, true)."' a {$tipo}");

        return $r['valor'];
    }

    private function falla(mixed $valor, string $tipo): void
    {
        $this->assertFalse(ConversorTipo::convertir($valor, $tipo)['ok'], "Debió fallar '".var_export($valor, true)."' a {$tipo}");
    }

    public function test_texto_a_dinero_y_numero(): void
    {
        $this->assertSame(1200.0, $this->v('$1,200.00', 'dinero'));
        $this->assertSame(250.5, $this->v(' 250.50 MXN', 'dinero'));
        $this->assertSame(0.5, $this->v('0.5', 'decimal'));
        $this->assertSame(3, $this->v('3', 'entero'));
        $this->falla('pendiente', 'dinero');
        $this->falla('-5', 'dinero');
        $this->falla('2.5', 'entero');
    }

    public function test_texto_a_fecha(): void
    {
        $this->assertSame('2018-01-15', $this->v('15/01/2018', 'fecha'));
        $this->assertSame('2018-01-15', $this->v('15-1-18', 'fecha'));
        $this->assertSame('2018-01-15', $this->v(43115, 'fecha'));       // número de serie de Excel
        $this->assertSame('2018-01-15', $this->v('2018-01-15', 'fecha'));
        $this->falla('31/02/2020', 'fecha');
        $this->falla('mañana', 'fecha');
    }

    public function test_texto_a_si_no(): void
    {
        $this->assertTrue($this->v('Sí', 'booleano'));
        $this->assertTrue($this->v('x', 'booleano'));
        $this->assertFalse($this->v('NO', 'booleano'));
        $this->assertFalse($this->v('0', 'booleano'));
        $this->falla('tal vez', 'booleano');
    }

    public function test_cualquier_tipo_regresa_a_texto(): void
    {
        $this->assertSame('250', $this->v(250.0, 'texto'));
        $this->assertSame('250.5', $this->v(250.5, 'texto'));
        $this->assertSame('Sí', $this->v(true, 'texto'));
        $this->assertSame('15/01/2018', $this->v('2018-01-15', 'texto'));
    }

    public function test_vacios_se_respetan(): void
    {
        foreach (['texto', 'dinero', 'fecha', 'booleano', 'entero'] as $tipo) {
            $this->assertNull($this->v('  ', $tipo));
            $this->assertNull($this->v(null, $tipo));
        }
    }
}
