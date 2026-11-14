<?php

namespace Tests\Feature;

use App\Models\HojaCelda;
use App\Models\HojaPlantilla;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v8: módulo Hojas — crear, escribir con versión, formato, filas/columnas y plantillas. v9: dentro de libros. */
class HojasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@demo.test')->first());
    }

    /** v9: crea un libro y devuelve los datos de su primera hoja. */
    private function nueva(array $extra = []): array
    {
        $libro = $this->postJson('/api/libros', ['nombre' => 'Prueba', ...$extra])->assertCreated()->json();

        return $this->getJson('/api/hojas/'.$libro['hojas'][0]['id'])->assertOk()->json();
    }

    public function test_hoja_en_blanco_con_filas_y_columnas(): void
    {
        $h = $this->nueva();
        $this->assertSame(26, count($h['columnas']));
        $this->assertSame(100, count($h['filas']));
        $this->assertSame([], $h['celdas']);
    }

    public function test_escribe_celdas_de_cada_tipo_y_una_formula_con_su_resultado(): void
    {
        $h = $this->nueva();
        [$f1, $f2] = [$h['filas'][0]['id'], $h['filas'][1]['id']];
        [$a, $b] = [$h['columnas'][0]['id'], $h['columnas'][1]['id']];

        $r = $this->patchJson("/api/hojas/{$h['hoja']['id']}/celdas", ['cambios' => [
            ['f' => $f1, 'c' => $a, 't' => 'numero', 'v' => 1250.5],
            ['f' => $f1, 'c' => $b, 't' => 'fecha', 'v' => '2026-10-28'],
            ['f' => $f2, 'c' => $a, 't' => 'formula', 'fx' => "=[f{$f1}c{$a}]*2", 'rt' => 'numero', 'v' => 2501],
            ['f' => $f2, 'c' => $b, 't' => 'logico', 'v' => true],
        ]])->assertOk()->json();

        $this->assertSame(4, count($r['celdas']));
        $this->assertSame([], $r['conflictos']);
        $celdas = collect($this->getJson("/api/hojas/{$h['hoja']['id']}")->json('celdas'))->keyBy(fn ($c) => $c['f'].':'.$c['c']);
        $this->assertEquals(1250.5, $celdas["{$f1}:{$a}"]['v']);
        $this->assertSame('2026-10-28', $celdas["{$f1}:{$b}"]['v']);
        $this->assertSame(["=[f{$f1}c{$a}]*2", 'numero'], [$celdas["{$f2}:{$a}"]['fx'], $celdas["{$f2}:{$a}"]['rt']]);
        $this->assertTrue($celdas["{$f2}:{$b}"]['v']);
    }

    public function test_dos_personas_en_la_misma_celda_la_segunda_recibe_conflicto(): void
    {
        $h = $this->nueva();
        $celda = ['f' => $h['filas'][0]['id'], 'c' => $h['columnas'][0]['id']];
        $url = "/api/hojas/{$h['hoja']['id']}/celdas";

        $this->patchJson($url, ['cambios' => [[...$celda, 't' => 'texto', 'v' => 'Ana', 'ver' => 0]]])->assertOk();   // persona A
        $r = $this->patchJson($url, ['cambios' => [[...$celda, 't' => 'texto', 'v' => 'Luis', 'ver' => 0]]])->json(); // B con versión vieja

        $this->assertSame([], $r['celdas']);
        $this->assertSame('Ana', $r['conflictos'][0]['v']);
        $this->assertSame('Ana', HojaCelda::sole()->valor_texto);
    }

    public function test_formato_se_guarda_una_vez_y_se_comparte(): void
    {
        $h = $this->nueva();
        $celdas = [[$h['filas'][0]['id'], $h['columnas'][0]['id']], [$h['filas'][0]['id'], $h['columnas'][1]['id']]];

        $r = $this->patchJson("/api/hojas/{$h['hoja']['id']}/celdas", ['cambios' => [], 'formatos' => [
            ['celdas' => $celdas, 'estilo' => ['n' => true, 'f' => '#a9dfbf']],
        ]])->assertOk()->json();

        $this->assertSame(1, count($r['estilos']));
        $this->assertSame(1, HojaCelda::distinct()->count('estilo_id'));
        $this->assertSame(['f' => '#A9DFBF', 'n' => true], array_values($r['estilos'])[0]);
    }

    public function test_insertar_y_borrar_filas_no_mueve_las_celdas(): void
    {
        $h = $this->nueva();
        $f2 = $h['filas'][1]['id'];
        $this->patchJson("/api/hojas/{$h['hoja']['id']}/celdas", ['cambios' => [['f' => $f2, 'c' => $h['columnas'][0]['id'], 't' => 'texto', 'v' => 'X']]]);

        $d = $this->postJson("/api/hojas/{$h['hoja']['id']}/filas", ['despues_de' => null, 'cantidad' => 3])->assertOk()->json();   // al final
        $d = $this->postJson("/api/hojas/{$h['hoja']['id']}/filas", ['despues_de' => $h['filas'][0]['id']])->json();                 // después de la 1
        $this->assertSame(104, count($d['filas']));
        $this->assertSame($f2, $d['filas'][2]['id']);            // la fila de "X" ahora es la 3
        $this->assertSame($f2, $d['celdas'][0]['f']);              // y su celda sigue ligada a ella

        $d = $this->deleteJson("/api/hojas/{$h['hoja']['id']}/filas/{$f2}")->assertOk()->json();
        $this->assertSame([], $d['celdas']);
    }

    public function test_crear_desde_plantilla_convierte_las_formulas_a_ids(): void
    {
        $p = HojaPlantilla::where('clave', 'presupuesto')->firstOrFail();
        $h = $this->nueva(['plantilla_id' => $p->id]);

        $formulas = collect($h['celdas'])->where('t', 'formula');
        $this->assertGreaterThan(10, $formulas->count());
        $this->assertMatchesRegularExpression('/^=\[f\d+c\d+\]\*\[f\d+c\d+\]$/', $formulas->first()['fx']);
        $this->assertNotEmpty($h['estilos']);
    }

    public function test_lista_de_plantillas(): void
    {
        $claves = collect($this->getJson('/api/hoja-plantillas')->assertOk()->json())->pluck('clave')->all();
        $this->assertEqualsCanonicalizing(['incidencias_rh', 'asistencia_semanal', 'presupuesto', 'lista_empleados'], $claves);
    }

    public function test_el_lector_no_crea_ni_edita(): void
    {
        $this->actingAs(User::where('email', 'lector@demo.test')->first());
        $this->postJson('/api/libros', ['nombre' => 'X'])->assertForbidden();
        $this->getJson('/api/libros/personal')->assertForbidden();
    }

    public function test_tipo_invalido_se_rechaza(): void
    {
        $h = $this->nueva();
        $this->patchJson("/api/hojas/{$h['hoja']['id']}/celdas", ['cambios' => [
            ['f' => $h['filas'][0]['id'], 'c' => $h['columnas'][0]['id'], 't' => 'numero', 'v' => 'doce'],
        ]])->assertStatus(422)->assertJsonPath('codigo', 'CELDA_INVALIDA');
    }
}
