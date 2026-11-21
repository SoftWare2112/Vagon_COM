<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\NominaRecibo;
use App\Models\PeriodoNomina;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Cfdi\PacSimulado;
use App\Services\Cfdi\TimbradoService;
use App\Support\Cfdi\DatosCfdiNomina;
use App\Support\Cfdi\NominaXml;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * v14: timbrado opcional (PAC simulado), CSD, cancelación, descarga del XML e importación de XML con conciliación.
 * Usa el CSD de pruebas del SAT (EKU9003173C9 / 12345678a), vigente hasta mayo de 2027.
 */
class CfdiV14Test extends TestCase
{
    use RefreshDatabase;

    private int $empleado;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@demo.test')->firstOrFail());
        Empresa::actual()->update(['rfc' => 'EKU9003173C9', 'razon_social' => 'Escuela Kemper Urgate', 'regimen_fiscal' => '601',
            'codigo_postal' => '42501', 'registro_patronal' => 'B5510768108']);
        $this->empleado = $this->postJson('/api/empleados', [
            'nombres' => 'Gloria', 'apellido_paterno' => 'Hernández', 'apellido_materno' => 'García',
            'rfc' => 'HEGG560427AB3', 'curp' => 'HEGG560427MVZRRL04', 'nss' => '12345678903', 'codigo_postal' => '06600',
            'fecha_ingreso' => '2025-06-02', 'puesto_id' => Puesto::where('nombre', 'Generalista')->value('id'),
            'salario_mensual' => 11250, 'tipo_contrato' => 'indeterminado',
        ])->assertCreated()->json('id');
        $this->postJson('/api/nomina/periodos/generar', ['tipo' => 'quincenal', 'ejercicio' => 2026])->assertOk();
    }

    private function q1(): PeriodoNomina
    {
        return PeriodoNomina::where(['tipo' => 'quincenal', 'ejercicio' => 2026, 'numero' => 1])->firstOrFail();
    }

    private function cerrarQ1(): NominaRecibo
    {
        $p = $this->q1();
        $this->postJson("/api/nomina/periodos/{$p->id}/calcular")->assertOk();
        $this->postJson("/api/nomina/periodos/{$p->id}/autorizar", ['confirmar' => true])->assertOk();

        return NominaRecibo::where('periodo_id', $p->id)->firstOrFail();
    }

    private function subirCsd(string $password = '12345678a')
    {
        $dir = base_path('tests/fixtures/csd');

        return $this->post('/api/nomina/cfdi/csd', [
            'cer' => UploadedFile::fake()->createWithContent('EKU9003173C9.cer', file_get_contents("{$dir}/EKU9003173C9.cer")),
            'key' => UploadedFile::fake()->createWithContent('EKU9003173C9.key', file_get_contents("{$dir}/EKU9003173C9.key")),
            'password' => $password,
        ], ['Accept' => 'application/json']);
    }

    public function test_csd_configuracion_y_timbrado_simulado(): void
    {
        $this->subirCsd('equivocada')->assertStatus(422)->assertJsonPath('codigo', 'KEY_INVALIDA');
        $this->subirCsd()->assertOk()->assertJsonPath('csd.no_certificado', '30001000000500003416')->assertJsonPath('csd.rfc', 'EKU9003173C9');
        $this->assertNull($this->getJson('/api/nomina/empresa')->json('csd_password'));   // nunca sale en el JSON

        // Falta la entidad federativa: no deja activar
        $this->putJson('/api/nomina/cfdi/configuracion', ['timbrado_activo' => true])->assertStatus(422)->assertJsonPath('codigo', 'TIMBRADO_INCOMPLETO');
        $this->putJson('/api/nomina/cfdi/configuracion', ['timbrado_activo' => true, 'clave_ent_fed' => 'CMX', 'pac' => 'simulado'])
            ->assertOk()->assertJsonPath('listo', true)->assertJsonPath('pac.modo_timbre', 'simulado');

        $recibo = $this->cerrarQ1();
        $this->getJson("/api/nomina/cfdi/recibos/{$recibo->id}/validar")->assertOk()->assertJsonPath('listo', true);
        $r = $this->postJson("/api/nomina/cfdi/periodos/{$this->q1()->id}/timbrar")->assertOk()->assertJsonPath('timbrados', 1)->json();
        $uuid = $r['resultados'][0]['uuid'];

        $json = $this->getJson("/api/nomina/recibos/{$recibo->id}")->assertOk()
            ->assertJsonPath('cfdi.estado', 'simulado')->assertJsonPath('cfdi.uuid', $uuid)->assertJsonPath('cfdi.timbre.leyenda', PacSimulado::LEYENDA)
            ->assertJsonPath('integridad', 'ok')->json();
        $this->assertSame(5311.72, $json['cfdi']['total']);
        $xml = $this->get("/api/nomina/recibos/{$recibo->id}/xml")->assertOk()->getContent();
        $this->assertStringContainsString('TimbreFiscalDigital', $xml);
        $this->assertStringContainsString('Total="5311.72"', $xml);

        // Timbrado: ya no se reabre ni se vuelve a timbrar
        $this->postJson("/api/nomina/periodos/{$this->q1()->id}/reabrir", ['motivo' => 'Prueba de reapertura'])->assertStatus(409);
        $this->postJson("/api/nomina/cfdi/recibos/{$recibo->id}/timbrar")->assertStatus(422)->assertJsonPath('codigo', 'NO_TIMBRABLE');

        // Cancelación (simulada) y bitácora
        $this->postJson("/api/nomina/cfdi/recibos/{$recibo->id}/cancelar", ['motivo' => '01'])->assertStatus(422);   // falta el folio que lo sustituye
        $this->postJson("/api/nomina/cfdi/recibos/{$recibo->id}/cancelar", ['motivo' => '02'])->assertOk()->assertJsonPath('uuid', $uuid);
        $this->assertNull($recibo->refresh()->uuid);
        $tipos = collect($this->getJson("/api/nomina/cfdi/eventos?recibo_id={$recibo->id}")->json())->pluck('tipo')->all();
        $this->assertContains('timbrado', $tipos);
        $this->assertContains('cancelacion', $tipos);
    }

    public function test_sin_timbrado_activo_no_timbra(): void
    {
        $recibo = $this->cerrarQ1();
        $this->postJson("/api/nomina/cfdi/recibos/{$recibo->id}/timbrar")->assertStatus(422)->assertJsonPath('codigo', 'NO_TIMBRABLE');
        $this->assertNull($recibo->refresh()->uuid);
    }

    public function test_un_supervisor_no_puede_timbrar(): void
    {
        $this->actingAs(User::whereHas('rol', fn ($q) => $q->where('nombre', 'supervisor'))->firstOrFail());
        $this->getJson('/api/nomina/cfdi/configuracion')->assertForbidden();
    }

    /** El departamento de nóminas timbró en su sistema: se importan sus XML y se ligan los UUID a los recibos autorizados. */
    public function test_importa_xml_y_concilia_importes(): void
    {
        $this->subirCsd()->assertOk();
        $recibo = $this->cerrarQ1();
        $empresa = Empresa::actual()->fill(['clave_ent_fed' => 'CMX']);
        $servicio = app(TimbradoService::class);
        $datos = DatosCfdiNomina::desde($servicio->contexto($empresa, $recibo))['datos'];
        $xml = (new PacSimulado)->timbrar(NominaXml::sellar(NominaXml::armar($datos), $servicio->csd($empresa)));
        $alterado = (new PacSimulado)->timbrar(NominaXml::sellar(NominaXml::armar(
            [...$datos, 'deducciones' => [...$datos['deducciones'], ['tipo' => '004', 'clave' => 'D099', 'concepto' => 'Otra', 'importe' => 100]]]
        ), $servicio->csd($empresa)));

        $archivo = fn (string $nombre, string $contenido) => UploadedFile::fake()->createWithContent($nombre, $contenido);

        // 1) Analizar: no cambia nada
        $r = $this->post('/api/nomina/cfdi/importar', ['archivos' => [$archivo('gloria.xml', $xml), $archivo('basura.xml', 'no es xml')]], ['Accept' => 'application/json'])
            ->assertOk()->json();
        $this->assertSame(['listo', 'invalido'], array_column($r['filas'], 'estado'));
        $this->assertNull($recibo->refresh()->uuid);

        // 2) Aplicar: se liga
        $this->post('/api/nomina/cfdi/importar', ['archivos' => [$archivo('gloria.xml', $xml)], 'aplicar' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('filas.0.estado', 'ligado');
        $recibo->refresh();
        $this->assertSame('importado', $recibo->cfdi_modo);
        $this->assertNotNull($recibo->uuid);
        $this->get("/api/nomina/recibos/{$recibo->id}/xml")->assertOk();

        // 3) Otra vez: ya estaba ligado. Uno con importes distintos: conflicto (el recibo ya tiene UUID)
        $this->post('/api/nomina/cfdi/importar', ['archivos' => [$archivo('gloria.xml', $xml), $archivo('alterado.xml', $alterado)]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('filas.0.estado', 'ya_ligado')->assertJsonPath('filas.1.estado', 'conflicto');

        // 4) Desligar y probar el de importes distintos: no se liga sin autorización expresa
        $this->postJson("/api/nomina/cfdi/recibos/{$recibo->id}/cancelar", ['motivo' => '02'])->assertOk()->assertJsonPath('estado', 'desligado');
        $f = $this->post('/api/nomina/cfdi/importar', ['archivos' => [$archivo('alterado.xml', $alterado)], 'aplicar' => 1], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('filas.0.estado', 'diferencia')->json('filas.0');
        $this->assertSame(-100.0, $f['diferencias'][0]['diferencia']);   // neto: el XML paga 100 menos
        $this->assertNull($recibo->refresh()->uuid);
        $this->post('/api/nomina/cfdi/importar', ['archivos' => [$archivo('alterado.xml', $alterado)], 'aplicar' => 1, 'forzar' => [$f['uuid']]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('filas.0.estado', 'ligado');

        // 5) ZIP
        $zipRuta = tempnam(sys_get_temp_dir(), 'cfdi');
        $zip = new \ZipArchive;
        $zip->open($zipRuta, \ZipArchive::OVERWRITE);
        $zip->addFromString('nominas/gloria.xml', $xml);
        $zip->close();
        $this->post('/api/nomina/cfdi/importar', ['archivos' => [$archivo('q1.zip', file_get_contents($zipRuta))]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('filas.0.estado', 'conflicto');
    }
}
