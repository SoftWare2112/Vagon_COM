<?php

namespace Tests\Feature;

use App\Mail\ReciboNominaMail;
use App\Models\Empresa;
use App\Models\NominaAcumulado;
use App\Models\NominaIncidencia;
use App\Models\PeriodoNomina;
use App\Models\Puesto;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** v13: vacaciones y prima vacacional, nómina de aguinaldo, cálculo anual de ISR, recibos en PDF y por correo. */
class NominaV13Test extends TestCase
{
    use RefreshDatabase;

    private int $empleado;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Carbon::setTestNow('2026-11-17 10:00:00');   // las vacaciones y los avisos dependen de «hoy»
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@demo.test')->firstOrFail());
        $this->empleado = $this->postJson('/api/empleados', [
            'nombres' => 'Gloria', 'apellido_paterno' => 'Hernández', 'apellido_materno' => 'García', 'email' => 'gloria@example.com',
            'rfc' => 'HEGG560427AB3', 'curp' => 'HEGG560427MVZRRL04', 'nss' => '12345678903', 'codigo_postal' => '06600',
            'fecha_ingreso' => '2025-06-02', 'puesto_id' => Puesto::where('nombre', 'Generalista')->value('id'),
            'salario_mensual' => 11250, 'tipo_contrato' => 'indeterminado',
        ])->assertCreated()->json('id');
        $this->postJson('/api/nomina/periodos/generar', ['tipo' => 'quincenal', 'ejercicio' => 2026])->assertOk();
    }

    private function q(int $n): PeriodoNomina
    {
        return PeriodoNomina::where(['tipo' => 'quincenal', 'ejercicio' => 2026, 'numero' => $n])->firstOrFail();
    }

    private function cerrar(PeriodoNomina $p): array
    {
        $r = $this->postJson("/api/nomina/periodos/{$p->id}/calcular")->assertOk()->json();
        $this->postJson("/api/nomina/periodos/{$p->id}/autorizar", ['confirmar' => true])->assertOk();

        return $r;
    }

    public function test_vacaciones_generan_prima_vacacional_en_su_quincena(): void
    {
        $s = $this->getJson("/api/empleados/{$this->empleado}/vacaciones")->assertOk()->json();
        $this->assertSame(12, $s['disponibles']);   // cumplió su primer año el 02/06/2026

        $this->postJson("/api/empleados/{$this->empleado}/vacaciones", ['desde' => '2026-11-17', 'hasta' => '2026-11-22', 'dias' => 5])
            ->assertCreated()->assertJsonPath('disponibles', 7);
        $this->postJson("/api/empleados/{$this->empleado}/vacaciones", ['desde' => '2026-11-20', 'hasta' => '2026-11-21', 'dias' => 2])
            ->assertStatus(422)->assertJsonPath('codigo', 'TRASLAPE');
        $this->postJson("/api/empleados/{$this->empleado}/vacaciones", ['desde' => '2026-12-01', 'hasta' => '2026-12-12', 'dias' => 8])
            ->assertStatus(422)->assertJsonPath('codigo', 'SIN_SALDO');

        $q22 = $this->q(22);
        $this->assertSame(5, $this->getJson("/api/nomina/periodos/{$q22->id}/captura")->json('filas.0.vacaciones'));
        $r = $this->cerrar($q22);
        $recibo = $this->getJson('/api/nomina/recibos/'.$r['captura']['filas'][0]['recibo']['id'])->json();
        $pv = collect($recibo['calculo']['percepciones'])->firstWhere('clave', 'P006');
        $this->assertSame(468.75, $pv['importe']);   // 5 días × $375 × 25 %
        $this->assertSame(468.75, $pv['exento']);

        // Su quincena ya está autorizada: no se puede cancelar
        $id = $this->getJson("/api/empleados/{$this->empleado}/vacaciones")->json('registros.0.id');
        $this->deleteJson("/api/vacaciones/{$id}")->assertStatus(409);
    }

    public function test_nomina_de_aguinaldo_con_art_174_y_art_96(): void
    {
        $p = $this->postJson('/api/nomina/aguinaldo', ['ejercicio' => 2026, 'fecha_pago' => '2026-12-15'])->assertCreated()->json();
        $this->assertSame('especial', $p['tipo']);
        $this->assertSame('aguinaldo', $p['clase']);
        $this->postJson('/api/nomina/aguinaldo', ['ejercicio' => 2026, 'fecha_pago' => '2026-12-18'])->assertStatus(409);
        $this->putJson("/api/nomina/periodos/{$p['id']}/incidencias", ['filas' => [['empleado_id' => $this->empleado, 'faltas' => 1]]])->assertStatus(422);

        $this->postJson("/api/nomina/periodos/{$p['id']}/calcular")->assertOk()->assertJsonPath('captura.filas.0.recibo.neto', 5395.92);   // art. 174

        $empresa = Empresa::actual();
        $empresa->update(['metodo_isr_extraordinario' => 'art96']);
        $this->postJson("/api/nomina/periodos/{$p['id']}/calcular")->assertOk()->assertJsonPath('captura.filas.0.recibo.neto', 5349.43);   // art. 96

        $this->postJson("/api/nomina/periodos/{$p['id']}/autorizar", ['confirmar' => true])->assertOk();
        $this->assertSame(3519.3, NominaAcumulado::where(['empleado_id' => $this->empleado, 'clave' => 'P007'])->value('exento'));
        $this->assertSame(1, collect($this->getJson('/api/nomina/historial?ejercicio=2026&tipo=especial')->json())->count());
    }

    public function test_calculo_anual_se_aplica_en_una_quincena_abierta_y_se_puede_quitar(): void
    {
        $this->cerrar($this->q(21));
        $this->cerrar($this->q(22));
        $r = $this->postJson('/api/nomina/ajuste-anual/calcular', ['ejercicio' => 2026])->assertOk()->json();
        $fila = $r['filas'][0];
        $this->assertTrue($fila['aplica']);
        $this->assertGreaterThan(0, $r['periodos_sin_autorizar']);   // avisa que faltan quincenas por autorizar
        $this->assertContains($fila['resultado'], ['a_cargo', 'a_favor']);

        // Quien declara por su cuenta queda fuera
        $this->patchJson("/api/nomina/ajuste-anual/{$fila['id']}", ['declara_por_su_cuenta' => true])->assertOk()->assertJsonPath('filas.0.aplica', false);
        $this->patchJson("/api/nomina/ajuste-anual/{$fila['id']}", ['declara_por_su_cuenta' => false])->assertOk()->assertJsonPath('filas.0.aplica', true);

        $q23 = $this->q(23);
        $this->postJson('/api/nomina/ajuste-anual/aplicar', ['ejercicio' => 2026, 'periodo_id' => $q23->id])->assertOk()->assertJsonPath('aplicados', 1);
        $clave = $fila['resultado'] === 'a_cargo' ? 'D003' : 'O001';
        $capturados = NominaIncidencia::where(['periodo_id' => $q23->id, 'empleado_id' => $this->empleado])->value('capturados');
        $this->assertSame(round(abs($fila['diferencia']), 2), (float) $capturados[$clave]);
        $c = $this->getJson("/api/nomina/periodos/{$q23->id}/captura")->json();
        $this->assertContains($clave, array_column($c['columnas'], 'clave'));   // la columna aparece porque tiene importe

        $this->postJson('/api/nomina/ajuste-anual/quitar', ['ejercicio' => 2026])->assertOk()->assertJsonPath('filas.0.estado', 'calculado');
        $this->assertArrayNotHasKey($clave, (array) NominaIncidencia::where(['periodo_id' => $q23->id, 'empleado_id' => $this->empleado])->value('capturados'));
    }

    public function test_recibos_en_pdf_y_por_correo(): void
    {
        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $this->markTestSkipped('Instala barryvdh/laravel-dompdf para probar los PDF.');
        }
        Mail::fake();
        $r = $this->cerrar($this->q(22));
        $id = $r['captura']['filas'][0]['recibo']['id'];
        $pdf = $this->get("/api/nomina/recibos/{$id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/api/nomina/periodos/'.$this->q(22)->id.'/recibos-pdf')->assertOk();

        $this->postJson('/api/nomina/periodos/'.$this->q(22)->id.'/enviar-recibos')->assertOk()->assertJsonPath('enviados', 1);
        Mail::assertSent(ReciboNominaMail::class, fn ($m) => $m->hasTo('gloria@example.com'));
        $this->getJson('/api/nomina/periodos/'.$this->q(22)->id.'/envios')->assertOk()->assertJsonPath("{$id}.estado", 'enviado');
    }

    public function test_no_se_envian_pre_nominas_y_el_lector_no_envia(): void
    {
        $q22 = $this->q(22);
        $this->postJson("/api/nomina/periodos/{$q22->id}/calcular")->assertOk();
        $this->postJson("/api/nomina/periodos/{$q22->id}/enviar-recibos")->assertStatus(409);
        $this->actingAs(User::where('email', 'lector@demo.test')->firstOrFail());
        $this->postJson("/api/nomina/periodos/{$q22->id}/enviar-recibos")->assertForbidden();
        $this->postJson('/api/nomina/ajuste-anual/calcular', ['ejercicio' => 2026])->assertForbidden();
    }
}
