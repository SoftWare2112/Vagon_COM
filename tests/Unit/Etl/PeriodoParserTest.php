<?php

namespace Tests\Unit\Etl;

use App\Services\Etl\PeriodoParser;
use App\Support\Texto;
use PHPUnit\Framework\TestCase;

/** Encabezados de periodo de nómina: con y sin año, abreviados, numéricos y cruzando de año. */
class PeriodoParserTest extends TestCase
{
    private function rango(string $encabezado, int $anio = 2026): ?string
    {
        $p = (new PeriodoParser)->parsear($encabezado, $anio);

        return $p ? $p->inicio->format('Y-m-d').'..'.$p->fin->format('Y-m-d') : null;
    }

    public function test_formato_de_octubre_con_anio(): void
    {
        $this->assertSame('2026-10-01..2026-10-13', $this->rango('1 de octubre al 13 de octubre de 2026'));
        $this->assertSame(2026, (new PeriodoParser)->parsear('1 de octubre al 13 de octubre de 2026', 2030)->anioEncabezado);
    }

    public function test_el_anio_del_encabezado_gana_al_del_formulario(): void
    {
        $this->assertSame('2027-10-01..2027-10-13', $this->rango('1 de octubre al 13 de octubre de 2027', 2026));
    }

    public function test_sin_anio_usa_el_del_formulario(): void
    {
        $this->assertSame('2026-10-01..2026-10-13', $this->rango('1 de octubre al 13 de octubre'));
        $this->assertNull((new PeriodoParser)->parsear('1 de octubre al 13 de octubre', 2026)->anioEncabezado);
    }

    public function test_variantes_de_escritura(): void
    {
        $this->assertSame('2026-08-12..2026-08-27', $this->rango('12 de Agosto AL 27 de Agosto'));
        $this->assertSame('2026-08-12..2026-08-27', $this->rango('12 al 27 de agosto'));
        $this->assertSame('2026-10-01..2026-10-13', $this->rango('del 1 al 13 de oct'));
        $this->assertSame('2026-10-01..2026-10-13', $this->rango('1 de oct. al 13 de oct. de 2026'));
        $this->assertSame('2026-08-28..2026-09-11', $this->rango('28 de agosto al 11 de septiembre'));
        $this->assertSame('2026-10-01..2026-10-13', $this->rango('01/10/2026 al 13/10/2026'));
        $this->assertSame('2026-10-01..2026-10-13', $this->rango('1/10 al 13/10'));
    }

    public function test_cruce_de_anio(): void
    {
        $this->assertSame('2026-12-27..2027-01-09', $this->rango('27 de diciembre al 9 de enero de 2027'));
        $this->assertSame('2026-12-27..2027-01-09', $this->rango('27 de diciembre de 2026 al 9 de enero de 2027'));
    }

    public function test_rangos_invalidos(): void
    {
        $this->assertNull($this->rango('31 de febrero al 13 de marzo'));     // fecha imposible
        $this->assertNull($this->rango('13 de octubre al 1 de octubre'));    // fin antes que inicio
        $this->assertNull($this->rango('1 de enero al 30 de junio'));        // demasiado largo para una nómina
    }

    public function test_no_confunde_otros_encabezados(): void
    {
        foreach (['Bono 3 meses', 'Domingo 4', '1 a 3 meses', 'Numero de faltas'] as $h) {
            $this->assertFalse(PeriodoParser::pareceRango(Texto::normalizarEncabezado($h)), $h);
        }
        $this->assertTrue(PeriodoParser::pareceRango(Texto::normalizarEncabezado('1 de octubre al 13 de octubre de 2026')));
    }
}
