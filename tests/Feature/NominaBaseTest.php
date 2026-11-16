<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\PeriodoNomina;
use App\Models\Puesto;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v11: expediente de empleados, configuración de nómina y simulación del cálculo. */
class NominaBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@demo.test')->firstOrFail());
    }

    private function datos(array $cambios = []): array
    {
        return [
            'nombres' => 'Gloria', 'apellido_paterno' => 'Hernández', 'apellido_materno' => 'García',
            'rfc' => 'HEGG560427AB3', 'curp' => 'HEGG560427MVZRRL04', 'nss' => '12345678903', 'codigo_postal' => '06600',
            'fecha_ingreso' => '2026-02-01', 'puesto_id' => Puesto::where('nombre', 'Generalista')->value('id'),
            'salario_mensual' => 11250, 'tipo_contrato' => 'prueba', 'contrato_fin' => '2026-05-01', 'jornada_horas' => 10, 'dias_descanso' => [1, 2, 3],
            ...$cambios,
        ];
    }

    public function test_la_migracion_deja_la_empresa_conceptos_y_tablas(): void
    {
        $this->getJson('/api/nomina/empresa')->assertOk()->assertJsonPath('nombre', 'Vagón COM')->assertJsonPath('divisor_salario_diario', fn ($v) => (float) $v === 30.0);
        $claves = collect($this->getJson('/api/nomina/conceptos')->json())->pluck('clave')->all();
        $this->assertSame(['P001', 'P002', 'P003', 'P004', 'P005', 'D001', 'D002'], $claves);
        $tablas = collect($this->getJson('/api/nomina/tablas')->json());
        $this->assertTrue($tablas->where('tipo', 'uma')->firstWhere('vigente', true)['datos']['diaria'] == 117.31);
    }

    public function test_alta_valida_datos_fiscales_y_crea_historial(): void
    {
        $this->postJson('/api/empleados', $this->datos(['rfc' => 'HEGG560427AB4']))->assertStatus(422)->assertJsonValidationErrors('rfc');
        $this->postJson('/api/empleados', $this->datos(['nss' => '12345678901']))->assertStatus(422)->assertJsonValidationErrors('nss');

        $e = $this->postJson('/api/empleados', $this->datos(['rfc' => 'hegg-560427-ab3']))->assertCreated()->json();
        $this->assertSame('GLORIA HERNÁNDEZ GARCÍA', $e['nombre']);
        $this->assertSame('HEGG560427AB3', $e['rfc']);
        $this->assertMatchesRegularExpression('/^VC\d{4}$/', $e['numero_empleado']);
        $this->assertSame(375.0, $e['calculos']['salario_diario']);
        $this->assertSame(393.49, $e['calculos']['sdi']);
        $this->assertCount(1, $e['contratos']);
        $this->assertCount(1, $e['salarios']);

        // El mismo RFC no puede pertenecer a otra persona
        $this->postJson('/api/empleados', $this->datos(['curp' => null, 'nss' => '12345678911']))->assertStatus(422)->assertJsonValidationErrors('rfc');
    }

    public function test_simula_la_quincena_con_los_valores_reales(): void
    {
        $e = $this->postJson('/api/empleados', $this->datos())->json();
        $q1 = $this->getJson("/api/empleados/{$e['id']}/simular?fecha=2026-11-10")->assertOk()->json();
        $this->assertSame(5311.72, $q1['totales']['neto']);
        $this->assertFalse($q1['periodo']['guardado']);   // calendario aún no generado: se arma en memoria

        $q2 = $this->getJson("/api/empleados/{$e['id']}/simular?fecha=2026-11-20&domingos=2")->json();
        $this->assertSame(['P001', 'P002', 'P003', 'P005'], array_column($q2['percepciones'], 'clave'));
    }

    public function test_contratos_salarios_baja_y_reactivacion(): void
    {
        $e = $this->postJson('/api/empleados', $this->datos())->json();
        $d = $this->postJson("/api/empleados/{$e['id']}/contrato", ['tipo' => 'temporal', 'inicio' => '2026-05-02', 'fin' => '2026-08-01'])->assertOk()->json();
        $this->assertSame('temporal', $d['tipo_contrato']);
        $this->assertSame('2026-05-01', $d['contratos'][1]['fin']);   // el anterior se cerró el día previo
        $this->postJson("/api/empleados/{$e['id']}/contrato", ['tipo' => 'temporal', 'inicio' => '2026-09-01'])->assertStatus(422);   // falta la fecha de fin

        $d = $this->postJson("/api/empleados/{$e['id']}/salario", ['salario_mensual' => 12000, 'desde' => '2026-10-01', 'motivo' => 'Ajuste anual'])->assertOk()->json();
        $this->assertSame(12000.0, (float) $d['salario_mensual']);
        $this->assertCount(2, $d['salarios']);
        $this->patchJson("/api/empleados/{$e['id']}", ['salario_mensual' => 1])->assertStatus(422);   // el salario tiene su propio movimiento

        $this->postJson("/api/empleados/{$e['id']}/baja", ['fecha' => '2026-11-15', 'motivo' => 'Renuncia voluntaria'])->assertOk()->assertJsonPath('estado', 'baja');
        $this->assertSame([], $this->getJson('/api/empleados')->json());
        $this->assertCount(1, $this->getJson('/api/empleados?estado=baja')->json());
        $this->postJson("/api/empleados/{$e['id']}/reactivar")->assertOk()->assertJsonPath('estado', 'activo');
    }

    public function test_configuracion_de_conceptos_tablas_y_periodos(): void
    {
        $p3 = collect($this->getJson('/api/nomina/conceptos')->json())->firstWhere('clave', 'P003');
        $this->patchJson("/api/nomina/conceptos/{$p3['id']}", ['regla' => ['solo_fin_de_mes' => true, 'antiguedad_meses_min' => 3, 'frecuencia' => 'unico']])->assertOk()
            ->assertJsonPath('regla.frecuencia', 'unico');
        $isr = collect($this->getJson('/api/nomina/conceptos')->json())->firstWhere('clave', 'D002');
        $this->deleteJson("/api/nomina/conceptos/{$isr['id']}")->assertStatus(422);   // del sistema
        $this->postJson('/api/nomina/conceptos', ['clave' => 'P006', 'nombre' => 'Vales de despensa', 'tipo' => 'percepcion', 'metodo' => 'fijo', 'monto' => 300, 'clave_sat' => '029'])->assertCreated();

        $this->postJson('/api/nomina/tablas', ['tipo' => 'uma', 'vigente_desde' => '2027-02-01', 'datos' => ['diaria' => 121.0, 'mensual' => 3678.4]])->assertCreated();
        $this->postJson('/api/nomina/tablas', ['tipo' => 'isr_quincenal', 'vigente_desde' => '2027-01-01', 'datos' => ['renglones' => [[100, 0, 1], [0.01, 0, 2]]]])->assertStatus(422);

        $this->postJson('/api/nomina/periodos/generar', ['tipo' => 'quincenal', 'ejercicio' => 2026])->assertOk()->assertJsonPath('nuevos', 24);
        $this->postJson('/api/nomina/periodos/generar', ['tipo' => 'quincenal', 'ejercicio' => 2026])->assertOk()->assertJsonPath('nuevos', 0);
        $this->assertSame(24, PeriodoNomina::count());
        $this->assertCount(24, $this->getJson('/api/nomina/periodos?tipo=quincenal&ejercicio=2026')->json());
    }

    public function test_alertas_de_contrato_e_incompletos(): void
    {
        $e = $this->postJson('/api/empleados', $this->datos(['fecha_ingreso' => now()->subMonths(3)->addDays(5)->toDateString(), 'contrato_fin' => now()->addDays(5)->toDateString()]))->json();
        Empleado::whereKey($e['id'])->update(['curp' => null]);
        $tipos = collect($this->getJson('/api/empleados/alertas')->json())->pluck('tipo')->all();
        $this->assertContains('contrato', $tipos);
        $this->assertContains('tres_meses', $tipos);
        $this->assertContains('incompleto', $tipos);
    }

    public function test_comando_limpia_datos_de_prueba_sin_tocar_los_reales(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $real = $this->postJson('/api/empleados', $this->datos())->json();
        Empleado::create(['numero_empleado' => 'MSI000001', 'nombre_completo' => 'Empleado Ficticio', 'fecha_ingreso' => '2025-01-01', 'origen' => 'excel']);
        $this->artisan('reportes:limpiar-datos-prueba', ['--forzar' => true])->assertSuccessful();
        $this->assertSame([$real['id']], Empleado::pluck('id')->all());
    }
}
