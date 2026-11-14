<?php

namespace Tests\Feature;

use App\Enums\EstatusCarga;
use App\Models\Carga;
use App\Models\Libro;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** v9: libros personales, pestañas, combinar, importar cualquier Excel e historial de importaciones. */
class LibrosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('local');
    }

    private function como(string $rol): User
    {
        $u = User::where('email', "{$rol}@demo.test")->firstOrFail();
        $this->actingAs($u);

        return $u;
    }

    public function test_cada_usuario_ve_solo_sus_libros_y_el_administrador_todos(): void
    {
        $this->como('supervisor');
        $libro = $this->postJson('/api/libros', ['nombre' => 'Mío'])->assertCreated()->json();
        $hojaId = $libro['hojas'][0]['id'];
        $this->assertSame(['Mío'], collect($this->getJson('/api/libros')->json())->pluck('nombre')->all());

        $this->como('lector');   // tiene hojas.ver, pero el libro no es suyo
        $this->getJson("/api/libros/{$libro['libro']['id']}")->assertNotFound();
        $this->getJson("/api/hojas/{$hojaId}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/libros')->json());

        $this->como('admin');    // control total
        $this->getJson("/api/hojas/{$hojaId}")->assertOk();
        $this->assertContains('Mío', collect($this->getJson('/api/libros?todos=1')->json())->pluck('nombre')->all());
        $this->assertNotContains('Mío', collect($this->getJson('/api/libros')->json())->pluck('nombre')->all());   // sin ?todos, solo lo suyo
        $this->patchJson("/api/hojas/{$hojaId}/celdas", ['cambios' => []])->assertOk();
    }

    public function test_mi_hoja_se_crea_una_sola_vez(): void
    {
        $this->como('supervisor');
        $a = $this->getJson('/api/libros/personal')->assertOk()->json();
        $b = $this->getJson('/api/libros/personal')->assertOk()->json();
        $this->assertSame($a['libro']['id'], $b['libro']['id']);
        $this->assertTrue($a['libro']['es_personal']);
        $this->assertSame(1, Libro::where('es_personal', true)->count());
    }

    public function test_agregar_renombrar_y_borrar_pestanas(): void
    {
        $this->como('supervisor');
        $libro = $this->postJson('/api/libros', ['nombre' => 'Varias'])->json();
        $d = $this->postJson("/api/libros/{$libro['libro']['id']}/hojas")->assertCreated()->json();
        $this->assertSame(['Hoja1', 'Hoja2'], array_column($d['hojas'], 'nombre'));

        $this->patchJson("/api/hojas/{$d['nueva']}", ['nombre' => 'Hoja1'])->assertStatus(422);   // nombre repetido
        $this->patchJson("/api/hojas/{$d['nueva']}", ['nombre' => 'Resumen'])->assertOk();
        $d = $this->deleteJson("/api/hojas/{$d['nueva']}")->assertOk()->json();
        $this->assertCount(1, $d['hojas']);
        $this->deleteJson("/api/hojas/{$d['hojas'][0]['id']}")->assertStatus(422);   // la última no se borra
    }

    public function test_combinar_y_separar_celdas(): void
    {
        $this->como('supervisor');
        $libro = $this->postJson('/api/libros', ['nombre' => 'C'])->json();
        $h = $this->getJson('/api/hojas/'.$libro['hojas'][0]['id'])->json();
        $url = "/api/hojas/{$h['hoja']['id']}";
        $rango = ['f' => $h['filas'][0]['id'], 'c' => $h['columnas'][0]['id'], 'f2' => $h['filas'][1]['id'], 'c2' => $h['columnas'][2]['id']];

        $d = $this->postJson("{$url}/combinar", $rango)->assertOk()->json();
        $this->assertCount(1, $d['combinadas']);
        $this->postJson("{$url}/combinar", [...$rango, 'f' => $h['filas'][1]['id']])->assertStatus(422);   // se encima
        $d = $this->deleteJson("{$url}/combinadas/{$d['combinadas'][0]['id']}")->assertOk()->json();
        $this->assertSame([], $d['combinadas']);
    }

    public function test_importa_cualquier_excel_con_pestanas_formulas_formato_y_combinadas(): void
    {
        $this->como('supervisor');
        $libro = new Spreadsheet;
        $a = $libro->getActiveSheet()->setTitle('Ventas');
        $a->fromArray([['Concepto', 'Cantidad', 'Precio', 'Importe'], ['Papel', 10, 45.5, '=B2*C2'], ['Tóner', 2, 1250, '=B3*C3']]);
        $a->setCellValue('D4', '=SUM(D2:D3)');
        $a->setCellValue('A6', 'Fecha');
        $a->setCellValue('B6', 46325);   // 30/10/2026 como número de serie
        $a->getStyle('B6')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $a->setCellValue('A8', '=VLOOKUP("Papel",A2:D3,4,FALSE)');   // función que el motor no tiene: se queda el valor
        $a->getStyle('A1:D1')->getFont()->setBold(true);
        $a->mergeCells('A10:D10');
        $a->setCellValue('A10', 'Nota al pie');
        $b = $libro->createSheet()->setTitle('Notas');
        $b->setCellValue('A1', 'Texto libre');
        $ruta = tempnam(sys_get_temp_dir(), 'xls').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        $r = $this->post('/api/libros/importar', ['archivo' => new UploadedFile($ruta, 'Ventas octubre.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()->json();

        $this->assertSame('Ventas octubre', $r['libro']['nombre']);
        $this->assertSame(['Ventas', 'Notas'], array_column($r['hojas'], 'nombre'));
        $this->assertSame(1, $r['importacion']['estadisticas']['formulas_a_valor']);
        $this->assertSame(3, $r['importacion']['estadisticas']['formulas']);

        $h = $this->getJson('/api/hojas/'.$r['hojas'][0]['id'])->json();
        $celdas = collect($h['celdas']);
        $this->assertSame('fecha', $celdas->firstWhere('v', '2026-10-30')['t'] ?? null);
        $this->assertTrue($celdas->contains(fn ($c) => $c['t'] === 'formula' && preg_match('/^=SUM\(\[f\d+c\d+\]:\[f\d+c\d+\]\)$/', $c['fx'])));
        $this->assertCount(1, $h['combinadas']);
        $this->assertContains(['n' => true], array_values($h['estilos']));

        $carga = Carga::sole();
        $this->assertSame(['libro', EstatusCarga::Aplicada], [$carga->tipo, $carga->estatus]);
        $this->assertSame($r['libro']['id'], $carga->libro_id);
    }

    public function test_historial_privado_quitar_restaurar_y_limpiar(): void
    {
        $sup = $this->como('supervisor');
        $libro = Libro::create(['nombre' => 'Importado', 'user_id' => $sup->id, 'origen' => 'importado']);
        $mia = Carga::create(['tipo' => 'libro', 'user_id' => $sup->id, 'libro_id' => $libro->id, 'nombre_original' => 'a.xlsx', 'ruta_archivo' => 'x', 'sha256' => str_repeat('a', 64), 'estatus' => EstatusCarga::Aplicada]);
        $admin = User::where('email', 'admin@demo.test')->first();
        $ajena = Carga::create(['tipo' => 'libro', 'user_id' => $admin->id, 'nombre_original' => 'b.xlsx', 'ruta_archivo' => 'y', 'sha256' => str_repeat('b', 64), 'estatus' => EstatusCarga::Aplicada]);

        $this->assertSame([$mia->id], collect($this->getJson('/api/cargas?tipo=libro')->json('data'))->pluck('id')->all());
        $this->deleteJson("/api/cargas/{$ajena->id}")->assertNotFound();

        $this->deleteJson("/api/cargas/{$mia->id}?con_libro=1")->assertOk();
        $this->assertSoftDeleted($mia);
        $this->assertSoftDeleted($libro);
        $this->assertSame([$mia->id], collect($this->getJson('/api/cargas?papelera=1')->json('data'))->pluck('id')->all());
        $this->postJson("/api/cargas/{$mia->id}/restaurar")->assertOk();
        $this->assertNotSoftDeleted($mia);

        $this->postJson('/api/cargas/limpiar', ['alcance' => 'todas'])->assertForbidden();
        $this->postJson('/api/cargas/limpiar', ['alcance' => 'mias'])->assertOk()->assertJsonPath('cantidad', 1);
        $this->assertNotSoftDeleted($ajena);

        $this->como('admin');
        $this->postJson('/api/cargas/limpiar', ['alcance' => 'todas'])->assertOk()->assertJsonPath('cantidad', 1);
        $this->assertSoftDeleted($ajena);
    }

    public function test_quitar_una_carga_de_nomina_no_borra_los_registros_ni_permite_subirla_otra_vez(): void
    {
        $admin = $this->como('admin');
        $carga = Carga::create(['tipo' => 'nomina', 'user_id' => $admin->id, 'nombre_original' => 'q17.xlsx', 'ruta_archivo' => 'z',
            'sha256' => str_repeat('c', 64), 'sha256_aplicado' => str_repeat('d', 64), 'estatus' => EstatusCarga::Aplicada, 'aplicada_at' => now()]);
        $this->deleteJson("/api/cargas/{$carga->id}")->assertOk();
        $this->assertSoftDeleted($carga);
        // La huella aplicada sigue en la tabla: el ETL la encuentra con withTrashed() y rechaza el duplicado
        $this->assertSame(1, Carga::withTrashed()->where('sha256_aplicado', str_repeat('d', 64))->count());
    }
}
