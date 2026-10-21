<?php

namespace Tests\Feature;

use App\Models\Periodo;
use App\Models\Registro;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Feature\Concerns\CreaExcel;
use Tests\TestCase;
use Tests\Unit\Etl\Fixtures\Q17Final;

/**
 * v6 con el archivo definitivo de RH: número de nómina, continuidad entre quincenas, aviso de
 * quincena ya cargada, exportación e impresión con la misma estructura, y datos de las gráficas.
 */
class ReporteRhTest extends TestCase
{
    use CreaExcel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@demo.test')->first());
    }

    /** Quincena 17 (1-13 oct) o 18 (14-27 oct) con el número de nómina empezando en $desde. */
    private function archivo(int $periodo = 17, int $desde = 200, string $nombre = 'INCIDENCIAS_Q17.xlsx', array $cambios = []): UploadedFile
    {
        $grid = Q17Final::libro(cambios: $cambios);
        $grid[2][1] = $periodo;
        if ($periodo === 18) {
            [$grid[3][13], $grid[3][14], $grid[3][15]] = ['Domingo 18', 'Domingo 25', '14 de Octubre AL 27 de Octubre'];
        }
        for ($i = 4, $n = count($grid); $i < $n; $i++) {
            $grid[$i][0] = $desde + $i - 4;
        }

        return $this->excelCuadricula($grid, $nombre);
    }

    private function subir(UploadedFile $f): array
    {
        return $this->postJson('/api/cargas', ['archivo' => $f, 'anio' => 2026])->assertCreated()->json('data');
    }

    private function aplicar(array $carga, bool $confirmar = false): void
    {
        $this->assertSame('LISTA', $carga['estatus'], json_encode($carga['mensajes'], JSON_UNESCAPED_UNICODE));
        $this->postJson("/api/cargas/{$carga['id']}/aplicar", ['modo' => 'MANTENER', 'confirmar' => $confirmar])->assertOk();
    }

    public function test_guarda_el_numero_de_nomina_y_la_estructura_del_archivo(): void
    {
        $carga = $this->subir($this->archivo());
        $this->assertFalse($carga['requiere_confirmacion']);
        $this->aplicar($carga);

        $this->assertSame(range(200, 223), Registro::orderBy('numero_nomina')->pluck('numero_nomina')->all());

        $formato = Periodo::sole()->formato;
        $this->assertSame('Incidencias para pago de nomina', $formato['titulo']);
        $this->assertSame(['clave' => 'numero_nomina', 'etiqueta' => '#'], $formato['columnas'][0]);
        $this->assertSame(['clave' => null, 'etiqueta' => ''], $formato['columnas'][4]);              // columna E sin título
        $this->assertSame('domingo:2026-10-04', $formato['columnas'][13]['clave']);
        $this->assertSame(['clave' => 'valor_periodo', 'etiqueta' => '1 de Octubre AL 13 de Octubre'], $formato['columnas'][15]);
    }

    public function test_la_siguiente_quincena_continua_el_numero_de_nomina(): void
    {
        $this->aplicar($this->subir($this->archivo()));

        // 224 = 223 + 1: sin aviso
        $bien = $this->subir($this->archivo(18, 224, 'INCIDENCIAS_Q18.xlsx'));
        $this->assertNotContains('NOMINA_CONTINUIDAD', array_column($bien['mensajes'], 'codigo'));
        $this->postJson("/api/cargas/{$bien['id']}/descartar")->assertOk();

        // 231: se avisa y hay que confirmar
        $mal = $this->subir($this->archivo(18, 231, 'INCIDENCIAS_Q18_b.xlsx'));
        $aviso = collect($mal['mensajes'])->firstWhere('codigo', 'NOMINA_CONTINUIDAD');
        $this->assertStringContainsString('terminó en el número de nómina 223', $aviso['mensaje']);
        $this->assertStringContainsString('se esperaba 224', $aviso['mensaje']);
        $this->assertTrue($mal['requiere_confirmacion']);
    }

    public function test_volver_a_subir_la_misma_quincena_pide_confirmar(): void
    {
        $this->aplicar($this->subir($this->archivo()));

        $otra = $this->subir($this->archivo(17, 200, 'INCIDENCIAS_Q17_v2.xlsx', [0 => ['J' => 2]]));
        $this->assertContains('PERIODO_YA_CARGADO', array_column($otra['mensajes'], 'codigo'));
        $this->postJson("/api/cargas/{$otra['id']}/aplicar", ['modo' => 'MANTENER'])->assertStatus(422);
        $this->aplicar($otra, confirmar: true);
    }

    public function test_impresion_trae_toda_la_tabla_con_el_encabezado_de_rh(): void
    {
        $this->aplicar($this->subir($this->archivo()));

        $r = $this->getJson('/api/registros/impresion')->assertOk()->json();
        $this->assertSame(['tipo' => 'Quincenal', 'ejercicio' => 2026, 'numero' => 17], array_intersect_key($r['encabezado'], array_flip(['tipo', 'ejercicio', 'numero'])));
        $this->assertSame('Incidencias para pago de nomina', $r['encabezado']['titulo']);
        $this->assertSame(['#', 'Nombre Completo', 'PUESTO', 'F. DE INGRESO'], array_slice(array_column($r['columnas'], 'etiqueta'), 0, 4));
        $this->assertCount(24, $r['filas']);                       // todas, sin paginar
        $this->assertSame([200, 'Alejandro Martínez Salgado'], array_slice($r['filas'][0], 0, 2));   // orden de RH
    }

    public function test_excel_exportado_tiene_la_estructura_de_rh(): void
    {
        $this->aplicar($this->subir($this->archivo()));

        $informe = $this->postJson('/api/exportaciones', ['formato' => 'EXCEL'])->assertCreated()->json();
        $ruta = Storage::disk('local')->path('exportaciones/'.$informe['nombre']);
        $hoja = IOFactory::load($ruta)->getActiveSheet();

        $this->assertSame(['Tipo Periodo:', 'Quincenal'], [$hoja->getCell('A1')->getValue(), $hoja->getCell('B1')->getValue()]);
        $this->assertSame(17, (int) $hoja->getCell('B3')->getValue());
        $this->assertSame('Incidencias para pago de nomina', $hoja->getCell('D1')->getValue());
        $this->assertSame('#', $hoja->getCell('A4')->getValue());
        $this->assertSame('1 de Octubre AL 13 de Octubre', $hoja->getCell('P4')->getValue());
        $this->assertSame(200, (int) $hoja->getCell('A5')->getValue());
        $this->assertSame('Alejandro Martínez Salgado', $hoja->getCell('B5')->getValue());
    }

    public function test_el_excel_exportado_se_puede_volver_a_subir(): void
    {
        $this->aplicar($this->subir($this->archivo()));
        $informe = $this->postJson('/api/exportaciones', ['formato' => 'EXCEL'])->json();
        $ruta = Storage::disk('local')->path('exportaciones/'.$informe['nombre']);

        $archivo = new UploadedFile($ruta, 'reexportado.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $carga = $this->subir($archivo);

        $this->assertSame('LISTA', $carga['estatus'], json_encode($carga['mensajes'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(17, $carga['periodo']['numero']);
        $this->assertSame(24, $carga['filas_total']);
    }

    public function test_estadisticas_de_la_quincena(): void
    {
        $this->aplicar($this->subir($this->archivo()));

        $e = $this->getJson('/api/estadisticas')->assertOk()->json();
        $this->assertSame(24, $e['kpis']['empleados']);
        $this->assertSame(6, $e['kpis']['faltas_total']);       // 1 + 1 + 1 + 3 en el archivo
        $this->assertSame(4, $e['kpis']['con_faltas']);
        $this->assertEquals(16.7, $e['kpis']['pct_con_faltas']);
        $this->assertSame('Gabriel Jiménez Rocha', $e['top_faltas'][0]['nombre']);
        $this->assertSame('P17', $e['serie'][0]['corto']);
        $this->assertSame('Asesor MSI', $e['por_puesto'][0]['puesto']);
    }
}
