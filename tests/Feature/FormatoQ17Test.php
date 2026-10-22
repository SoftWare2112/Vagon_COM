<?php

namespace Tests\Feature;

use App\Models\Columna;
use App\Models\Empleado;
use App\Models\Periodo;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreaExcel;
use Tests\TestCase;
use Tests\Unit\Etl\Fixtures\Q17;

/** v5: el archivo real de RH (INCIDENCIAS_Q17.xlsx) de punta a punta, con la base de datos. */
class FormatoQ17Test extends TestCase
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

    private function q17(?string $encabezadoPeriodo = null, string $nombre = 'INCIDENCIAS_Q17.xlsx', array $previas = Q17::PREVIAS): UploadedFile
    {
        $enc = Q17::ENCABEZADOS;
        if ($encabezadoPeriodo !== null) {
            $enc['Q'] = $encabezadoPeriodo;
        }

        return $this->excelQ17($previas, $enc, Q17::FILAS, $nombre);
    }

    public function test_el_archivo_tal_cual_explica_que_el_periodo_abarca_88_dias(): void
    {
        $carga = $this->postJson('/api/cargas', ['archivo' => $this->q17(), 'anio' => 2026])->assertCreated()->json('data');

        $this->assertSame('CON_ERRORES', $carga['estatus']);
        $error = collect($carga['mensajes'])->firstWhere('nivel', 'ERROR');
        $this->assertSame('PERIODO_INVALIDO', $error['codigo']);
        $this->assertStringContainsString('88 días', $error['mensaje']);
    }

    public function test_con_las_fechas_escritas_en_el_asistente_carga_el_periodo_17(): void
    {
        $carga = $this->postJson('/api/cargas', [
            'archivo' => $this->q17(), 'anio' => 2026, 'periodo_inicio' => '01/10/2026', 'periodo_fin' => '13/10/2026',
        ])->assertCreated()->json('data');

        $this->assertSame('LISTA', $carga['estatus'], json_encode($carga['mensajes'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(24, $carga['filas_total']);
        $this->assertSame(17, $carga['periodo']['numero']);
        $this->assertFalse($carga['requiere_confirmacion']);

        $this->postJson("/api/cargas/{$carga['id']}/aplicar", ['modo' => 'MANTENER'])->assertOk();

        $periodo = Periodo::sole();
        $this->assertSame([2026, 17, 'Quincenal'], [$periodo->ejercicio, $periodo->numero, $periodo->tipo]);
        $this->assertSame('1 de octubre al 13 de octubre de 2026', $periodo->etiqueta);
        $this->assertSame(24, Empleado::count());
        $this->assertTrue(Empleado::where('nombre_completo', 'Alejandro Martínez Salgado')->exists());   // sin "1. "
    }

    public function test_el_mismo_periodo_con_otras_fechas_es_error(): void
    {
        $c = $this->postJson('/api/cargas', ['archivo' => $this->q17('1 de octubre al 13 de octubre'), 'anio' => 2026])->json('data');
        $this->postJson("/api/cargas/{$c['id']}/aplicar", ['modo' => 'MANTENER'])->assertOk();

        // Periodo 17 otra vez, pero el encabezado dice 2 al 14 (error de captura)
        $carga = $this->postJson('/api/cargas', ['archivo' => $this->q17('2 de octubre al 14 de octubre', 'q17_corregido.xlsx'), 'anio' => 2026])->json('data');
        $this->assertContains('PERIODO_FECHAS_DISTINTAS', array_column($carga['mensajes'], 'codigo'));
        $this->assertSame('CON_ERRORES', $carga['estatus']);
    }

    public function test_las_mismas_fechas_con_otro_numero_de_periodo_es_error(): void
    {
        $c = $this->postJson('/api/cargas', ['archivo' => $this->q17('1 de octubre al 13 de octubre'), 'anio' => 2026])->json('data');
        $this->postJson("/api/cargas/{$c['id']}/aplicar", ['modo' => 'MANTENER'])->assertOk();

        $previas = Q17::PREVIAS;
        $previas[2] = [null, 'Periodo:', 18];
        $carga = $this->postJson('/api/cargas', ['archivo' => $this->q17('1 de octubre al 13 de octubre', 'q18.xlsx', $previas), 'anio' => 2026])->json('data');
        $this->assertContains('PERIODO_NUMERO_DISTINTO', array_column($carga['mensajes'], 'codigo'));
    }

    public function test_el_pdf_se_rechaza_con_un_mensaje_claro(): void
    {
        $pdf = UploadedFile::fake()->create('INCIDENCIAS_Q17.pdf', 130, 'application/pdf');
        $this->postJson('/api/cargas', ['archivo' => $pdf, 'anio' => 2026])
            ->assertStatus(422)
            ->assertJsonValidationErrors('archivo');
        $this->assertStringContainsString('Excel original', $this->postJson('/api/cargas', ['archivo' => $pdf])->json('errors.archivo.0'));
    }

    public function test_asignar_una_columna_no_reconocida_la_guarda_como_alias(): void
    {
        $faltas = Columna::where('clave', 'num_faltas')->firstOrFail();
        $this->postJson("/api/columnas/{$faltas->id}/alias", ['alias' => 'Inasistencias Q'])->assertOk();

        $this->assertContains('Inasistencias Q', $faltas->fresh()->alias);

        // Un encabezado que ya es de otra columna no se puede asignar
        $this->postJson("/api/columnas/{$faltas->id}/alias", ['alias' => 'Bono asistencia'])->assertStatus(422);
    }
}
