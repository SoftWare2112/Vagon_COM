<?php

namespace Tests\Feature;

use App\Models\NominaAcumulado;
use App\Models\PeriodoNomina;
use App\Models\Puesto;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** v12: captura → pre-nómina → autorización (solo lectura), acumulados, reapertura, historial, reportes e indicadores. */
class NominaPeriodoTest extends TestCase
{
    use RefreshDatabase;

    private int $empleado;

    private PeriodoNomina $q22;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@demo.test')->firstOrFail());
        $this->empleado = $this->postJson('/api/empleados', [
            'nombres' => 'Gloria', 'apellido_paterno' => 'Hernández', 'apellido_materno' => 'García',
            'rfc' => 'HEGG560427AB3', 'curp' => 'HEGG560427MVZRRL04', 'nss' => '12345678903', 'codigo_postal' => '06600',
            'fecha_ingreso' => '2026-02-01', 'puesto_id' => Puesto::where('nombre', 'Generalista')->value('id'),
            'salario_mensual' => 11250, 'tipo_contrato' => 'indeterminado',
        ])->assertCreated()->json('id');
        $this->postJson('/api/nomina/periodos/generar', ['tipo' => 'quincenal', 'ejercicio' => 2026])->assertOk();
        $this->q22 = PeriodoNomina::where(['tipo' => 'quincenal', 'ejercicio' => 2026, 'numero' => 22])->firstOrFail();   // 16–30 nov
    }

    private function calcularYAutorizar(PeriodoNomina $p): void
    {
        $this->postJson("/api/nomina/periodos/{$p->id}/calcular")->assertOk();
        $this->postJson("/api/nomina/periodos/{$p->id}/autorizar", ['confirmar' => true])->assertOk();
    }

    public function test_captura_calcula_y_autoriza_la_quincena(): void
    {
        $c = $this->getJson("/api/nomina/periodos/{$this->q22->id}/captura")->assertOk()->json();
        $this->assertCount(1, $c['filas']);
        $this->assertSame('abierto', $c['periodo']['estado']);

        $this->putJson("/api/nomina/periodos/{$this->q22->id}/incidencias", ['filas' => [['empleado_id' => $this->empleado, 'domingos' => 1]]])->assertOk();
        $r = $this->postJson("/api/nomina/periodos/{$this->q22->id}/calcular")->assertOk()->json();
        $this->assertSame('calculado', $r['captura']['periodo']['estado']);
        $neto = $r['captura']['filas'][0]['recibo']['neto'];
        $this->assertGreaterThan(5917.72, $neto);   // los dos bonos + 1 domingo

        // Cambiar una incidencia después de calcular obliga a recalcular
        $this->putJson("/api/nomina/periodos/{$this->q22->id}/incidencias", ['filas' => [['empleado_id' => $this->empleado, 'domingos' => 0]]])
            ->assertOk()->assertJsonPath('pendiente_recalcular', true);
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/autorizar", ['confirmar' => true])->assertStatus(409);
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/calcular")->assertOk()->assertJsonPath('captura.filas.0.recibo.neto', 5917.72);

        $this->postJson("/api/nomina/periodos/{$this->q22->id}/autorizar")->assertStatus(422);   // falta confirmar
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/autorizar", ['confirmar' => true])->assertOk()
            ->assertJsonPath('captura.periodo.estado', 'autorizado');

        // Solo lectura
        $this->putJson("/api/nomina/periodos/{$this->q22->id}/incidencias", ['filas' => [['empleado_id' => $this->empleado, 'faltas' => 1]]])->assertStatus(409);
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/calcular")->assertStatus(409);

        // Acumulados anuales
        $this->assertSame(5625.0, NominaAcumulado::where(['empleado_id' => $this->empleado, 'ejercicio' => 2026, 'clave' => 'P001'])->value('importe'));
        $this->assertSame(500.0, NominaAcumulado::where(['empleado_id' => $this->empleado, 'clave' => 'P003'])->value('importe'));
    }

    public function test_sobre_recibo_historial_e_integridad(): void
    {
        $this->calcularYAutorizar($this->q22);
        $lista = $this->getJson("/api/nomina/recibos?empleado_id={$this->empleado}")->assertOk()->json();
        $this->assertCount(1, $lista);
        $recibo = $this->getJson("/api/nomina/recibos/{$lista[0]['id']}")->assertOk()->json();
        $this->assertSame('HEGG560427AB3', $recibo['rfc']);
        $this->assertSame('ok', $recibo['integridad']);
        $this->assertSame('desactivado', $recibo['cfdi']['estado']);   // timbrado apagado por defecto

        $h = collect($this->getJson('/api/nomina/historial?ejercicio=2026&tipo=quincenal')->assertOk()->json())->firstWhere('numero', 22);
        $this->assertSame('autorizado', $h['estado']);
        $this->assertSame(1, $h['recibos']);

        // Un cambio hecho directamente en la base de datos se detecta
        DB::table('nomina_recibos')->where('id', $lista[0]['id'])->update(['neto' => 9999]);
        $this->getJson("/api/nomina/recibos/{$lista[0]['id']}")->assertJsonPath('integridad', 'alterado');
        $this->postJson('/api/nomina/reconstruir', ['ejercicio' => 2026])->assertOk()->assertJsonCount(1, 'alterados');
    }

    public function test_solo_se_reabre_el_ultimo_periodo_sin_timbrar(): void
    {
        $q21 = PeriodoNomina::where(['tipo' => 'quincenal', 'ejercicio' => 2026, 'numero' => 21])->firstOrFail();
        $this->calcularYAutorizar($q21);
        $this->calcularYAutorizar($this->q22);

        $this->postJson("/api/nomina/periodos/{$q21->id}/reabrir", ['motivo' => 'Se capturó mal una falta'])->assertStatus(409);
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/reabrir", ['motivo' => 'corto'])->assertStatus(422);
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/reabrir", ['motivo' => 'Faltó capturar un domingo trabajado'])->assertOk()
            ->assertJsonPath('captura.periodo.estado', 'calculado');
        $this->assertSame(1, NominaAcumulado::where(['empleado_id' => $this->empleado, 'clave' => 'P001'])->value('periodos'));   // solo la Q21

        // Un recibo timbrado ya no se puede reabrir
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/autorizar", ['confirmar' => true])->assertOk();
        DB::table('nomina_recibos')->where('periodo_id', $this->q22->id)->update(['uuid' => '6F9619FF-8B86-D011-B42D-00C04FC964FF']);
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/reabrir", ['motivo' => 'Faltó capturar un domingo trabajado'])->assertStatus(409);
    }

    public function test_no_se_autoriza_si_quedo_una_pre_nomina_anterior(): void
    {
        $q21 = PeriodoNomina::where(['tipo' => 'quincenal', 'ejercicio' => 2026, 'numero' => 21])->firstOrFail();
        $this->postJson("/api/nomina/periodos/{$q21->id}/calcular")->assertOk();
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/calcular")->assertOk();
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/autorizar", ['confirmar' => true])->assertStatus(409)->assertJsonPath('codigo', 'PERIODO_ANTERIOR');
    }

    public function test_reportes_e_indicadores(): void
    {
        $this->putJson("/api/nomina/periodos/{$this->q22->id}/incidencias", ['filas' => [['empleado_id' => $this->empleado, 'domingos' => 2, 'retardos' => 1]]]);
        $this->calcularYAutorizar($this->q22);

        $raya = $this->getJson("/api/nomina/reportes/lista-de-raya/{$this->q22->id}")->assertOk()->json();
        $this->assertSame(['P001', 'P003', 'P005', 'D001', 'D002'], array_column($raya['columnas'], 'clave'));   // con retardo no hay bono de puntualidad
        $acum = $this->getJson('/api/nomina/reportes/acumulados?ejercicio=2026&tipo=quincenal')->assertOk()->json();
        $this->assertSame(5625.0, collect($acum['filas'])->firstWhere('clave', 'P001')['total']);

        $ind = $this->getJson('/api/indicadores?ejercicio=2026&agrupar=quincena')->assertOk()->json();
        $this->assertCount(24, $ind['serie']);
        $nov2 = collect($ind['serie'])->firstWhere('clave', '2026-11-2');
        $this->assertSame(2, $nov2['domingos']);
        $this->assertSame(1, $nov2['retardos']);
        $this->assertSame(500.0, $nov2['bonos']['P003']);
        $this->assertSame(0.0, $nov2['bonos']['P002']);
        $this->assertSame(1, collect($ind['serie'])->firstWhere('clave', '2026-02-1')['altas']);
        $this->assertCount(12, $this->getJson('/api/indicadores?ejercicio=2026&agrupar=mes')->json('serie'));
    }

    public function test_trayectoria_y_validacion_aceptada_con_motivo(): void
    {
        $this->postJson("/api/empleados/{$this->empleado}/salario", ['salario_mensual' => 12000, 'desde' => '2026-12-01', 'motivo' => 'Ajuste'])->assertOk();
        $this->postJson("/api/empleados/{$this->empleado}/baja", ['fecha' => '2026-12-10', 'motivo' => 'Renuncia voluntaria'])->assertOk();
        $e = $this->postJson("/api/empleados/{$this->empleado}/reactivar", ['fecha' => '2026-12-20'])->assertOk()->json();
        $this->assertSame(['reingreso', 'baja', 'salario', 'alta'], array_column($e['movimientos'], 'tipo'));

        $datos = ['nombres' => 'Ana', 'apellido_paterno' => 'Ruiz', 'rfc' => 'HEGG560427AB4', 'nss' => '12345678911',
            'fecha_ingreso' => '2026-03-01', 'puesto_id' => Puesto::where('nombre', 'Generalista')->value('id'), 'salario_mensual' => 11250, 'tipo_contrato' => 'indeterminado'];
        $this->postJson('/api/empleados', $datos)->assertStatus(422)->assertJsonValidationErrors('rfc');
        $this->postJson('/api/empleados', [...$datos, 'omitir_validacion' => ['rfc'], 'motivo_omision' => 'x'])->assertStatus(422)->assertJsonValidationErrors('motivo_omision');
        $nuevo = $this->postJson('/api/empleados', [...$datos, 'omitir_validacion' => ['rfc'], 'motivo_omision' => 'Coincide con la Constancia de Situación Fiscal'])
            ->assertCreated()->json();
        $this->assertSame('Coincide con la Constancia de Situación Fiscal', $nuevo['validaciones_omitidas']['rfc']['motivo']);
        // El formato nunca se omite
        $this->postJson('/api/empleados', [...$datos, 'rfc' => 'ABC', 'omitir_validacion' => ['rfc'], 'motivo_omision' => 'Coincide con la Constancia'])
            ->assertStatus(422)->assertJsonValidationErrors('rfc');
    }

    public function test_permisos_de_la_nomina(): void
    {
        $this->actingAs(User::where('email', 'lector@demo.test')->firstOrFail());
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/calcular")->assertForbidden();
        $this->getJson('/api/nomina/historial?ejercicio=2026&tipo=quincenal')->assertForbidden();
        $this->getJson('/api/indicadores?ejercicio=2026')->assertOk()->assertJsonPath('serie.0.neto', null);   // sin montos de nómina

        $this->actingAs(User::where('email', 'supervisor@demo.test')->firstOrFail());
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/calcular")->assertOk();
        $this->postJson("/api/nomina/periodos/{$this->q22->id}/autorizar", ['confirmar' => true])->assertForbidden();
    }
}
