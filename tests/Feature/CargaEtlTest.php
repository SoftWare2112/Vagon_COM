<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Registro;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreaExcel;
use Tests\TestCase;

/** Especificación ejecutable del ETL y de las reglas confirmadas por RH. */
class CargaEtlTest extends TestCase
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

    private function subirYAplicar(UploadedFile $archivo, string $modo): array
    {
        $carga = $this->postJson('/api/cargas', ['archivo' => $archivo, 'anio' => 2026])->assertCreated()->json('data');
        $this->assertSame('LISTA', $carga['estatus'], json_encode($carga['mensajes']));

        return $this->postJson("/api/cargas/{$carga['id']}/aplicar", ['modo' => $modo])->assertOk()->json('resumen');
    }

    private function registroDe(string $nombre): Registro
    {
        return Registro::withTrashed()->whereHas('empleado', fn ($q) => $q->where('nombre_completo', $nombre))->latest('id')->firstOrFail();
    }

    public function test_primera_carga_crea_empleados_con_numero_msi_de_6_digitos(): void
    {
        $res = $this->subirYAplicar($this->excel([$this->fila('Ana López'), $this->fila('Luis Pérez')]), 'REEMPLAZAR');

        $this->assertSame(2, $res['nuevos']);
        $this->assertEqualsCanonicalizing(['MSI000001', 'MSI000002'], Empleado::pluck('numero_empleado')->all());
    }

    public function test_formato_de_octubre_se_lee_completo(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 2, ['Puesto' => 'Supervisor', 'DOM1' => 1, 'DOM2' => 1])]), 'MANTENER');
        $reg = $this->registroDe('Ana López')->load(['puesto', 'periodo', 'domingos']);

        $this->assertSame('Supervisor', $reg->puesto->nombre);
        $this->assertSame('2026-10-01', $reg->periodo->fecha_inicio->format('Y-m-d'));
        $this->assertSame('2026-10-13', $reg->periodo->fecha_fin->format('Y-m-d'));
        $this->assertSame(['2026-10-04', '2026-10-11'], $reg->domingos->map(fn ($d) => $d->fecha->format('Y-m-d'))->all());
        $this->assertSame(2, $reg->num_faltas);

        // El orden de columnas que ve la web es el del archivo de RH
        $claves = collect($this->getJson('/api/registros')->json('columnas'))->pluck('clave')->all();
        $this->assertSame(['nombre_completo', 'fecha_ingreso', 'puesto', 'prima_vacacional'], array_slice($claves, 0, 4));
        $this->assertSame('1 de octubre al 13 de octubre de 2026', collect($this->getJson('/api/registros')->json('columnas'))->last()['etiqueta']);
    }

    public function test_periodo_sin_anio_usa_el_anio_indicado_al_subir(): void
    {
        $enc = $this->encabezados();
        $enc[13] = '1 de octubre al 13 de octubre';
        $carga = $this->postJson('/api/cargas', ['archivo' => $this->excel([$this->fila('Ana López')], 'sin_anio.xlsx', $enc), 'anio' => 2027])->json('data');
        $this->assertSame('2027-10-01', $carga['periodo']['inicio']);
    }

    public function test_el_puesto_se_guarda_por_quincena(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 0, ['Puesto' => 'Asesor'])]), 'MANTENER');
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 0, ['Puesto' => 'Supervisor'])], '2q.xlsx', null, '2Q'), 'MANTENER');

        $puestos = Registro::with(['puesto', 'periodo'])->get()->sortBy('periodo.fecha_inicio')->map(fn ($r) => $r->puesto->nombre)->values()->all();
        $this->assertSame(['Asesor', 'Supervisor'], $puestos);   // la 1a quincena conserva su puesto histórico
        $this->assertSame(1, Empleado::count());
    }

    public function test_un_empleado_existente_nunca_cambia_de_numero(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López')]), 'REEMPLAZAR');
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 2), $this->fila('Nuevo Uno')]), 'REEMPLAZAR');

        $this->assertSame('MSI000001', Empleado::where('nombre_completo', 'Ana López')->value('numero_empleado'));
        $this->assertSame('MSI000002', Empleado::where('nombre_completo', 'Nuevo Uno')->value('numero_empleado'));
    }

    public function test_mismo_archivo_no_se_puede_subir_dos_veces(): void
    {
        $filas = [$this->fila('Ana López')];
        $this->subirYAplicar($this->excel($filas), 'MANTENER');

        $this->postJson('/api/cargas', ['archivo' => $this->excel($filas, 'otro_nombre.xlsx'), 'anio' => 2026])
            ->assertStatus(409)->assertJsonPath('codigo', 'ARCHIVO_DUPLICADO');
    }

    public function test_valores_se_leen_tal_cual_sin_calculos(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 0, ['Programado en Domingo' => 2, 'DOM1' => 0, 'DOM2' => null, 'PERIODO' => 'pendiente'])]), 'MANTENER');
        $reg = $this->registroDe('Ana López')->load('domingos');

        $this->assertEquals(2, $reg->programado_domingo);
        $this->assertNull($reg->domingos->firstWhere('etiqueta', 'Domingo 11')->cantidad);
        $this->assertSame('pendiente', $reg->valor_periodo);
    }

    public function test_mantener_respeta_campos_editados_en_la_web(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 0)]), 'MANTENER');
        $reg = $this->registroDe('Ana López');

        $this->patchJson("/api/registros/{$reg->id}", ['version' => $reg->version, 'valores' => ['num_faltas' => 3, 'puesto' => 'MSI']])->assertOk();
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 1, ['Detalles' => 'nuevo detalle', 'Puesto' => 'Asesor'])]), 'MANTENER');

        $reg->refresh()->load('puesto');
        $this->assertSame(3, $reg->num_faltas);            // editado en web -> se conserva
        $this->assertSame('MSI', $reg->puesto->nombre);     // puesto editado en web -> se conserva
        $this->assertSame('nuevo detalle', $reg->detalle);  // no editado -> toma el Excel
    }

    public function test_archivo_con_columnas_de_menos_no_borra_los_datos_guardados(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López')]), 'REEMPLAZAR');

        $enc = array_values(array_diff($this->encabezados(), ['Bono asistencia']));
        $carga = $this->postJson('/api/cargas', ['archivo' => $this->excel([$this->fila('Ana López', 2)], 'sin_bono.xlsx', $enc), 'anio' => 2026])->json('data');
        $this->assertContains('COLUMNA_AUSENTE', array_column($carga['mensajes'], 'codigo'));
        $this->postJson("/api/cargas/{$carga['id']}/aplicar", ['modo' => 'REEMPLAZAR'])->assertOk();

        $reg = $this->registroDe('Ana López');
        $this->assertEquals(300, $reg->bono_asistencia);   // se conservó
        $this->assertSame(2, $reg->num_faltas);            // lo que sí venía se actualizó
    }

    public function test_columna_nueva_insertada_en_una_posicion_no_altera_los_datos(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López')]), 'MANTENER');
        $antes = $this->registroDe('Ana López')->only(['prima_vacacional', 'bono_puntualidad', 'num_faltas']);

        // Sin tipo: se crea como texto (RH lo puede cambiar después)
        $col = $this->postJson('/api/columnas', ['etiqueta' => 'Bono transporte', 'despues_de' => 'bono_asistencia'])->assertCreated()->json();
        $this->assertSame('texto', $col['tipo']);

        $cols = collect($this->getJson('/api/registros')->json('columnas'))->pluck('clave')->all();
        $this->assertSame('extra:bono_transporte', $cols[array_search('bono_asistencia', $cols) + 1]);
        $this->assertSame($antes, $this->registroDe('Ana López')->only(['prima_vacacional', 'bono_puntualidad', 'num_faltas']));

        // Un Excel posterior con la columna nueva la llena
        $enc = $this->encabezados();
        array_splice($enc, 7, 0, ['Bono transporte']);
        $this->subirYAplicar($this->excel([$this->fila('Ana López', 1, ['Bono transporte' => '$250.00'])], 'con_transporte.xlsx', $enc), 'MANTENER');
        $this->assertSame('$250.00', $this->registroDe('Ana López')->valores_extra['bono_transporte']);
    }

    public function test_cambiar_tipo_de_columna_simula_y_convierte_los_valores_guardados(): void
    {
        $col = $this->postJson('/api/columnas', ['etiqueta' => 'Bono transporte'])->json();
        $enc = [...$this->encabezados(), 'Bono transporte'];
        $this->subirYAplicar($this->excel([
            $this->fila('Ana López', 0, ['Bono transporte' => '$250.00']),
            $this->fila('Luis Pérez', 0, ['Bono transporte' => 'pendiente']),
        ], 'transporte.xlsx', $enc), 'MANTENER');

        // 1) Simular: informa sin escribir
        $sim = $this->patchJson("/api/columnas/{$col['id']}/tipo", ['tipo' => 'dinero', 'simular' => true])->assertOk()->json();
        $this->assertSame([2, 1, 1], [$sim['con_valor'], $sim['convertibles'], $sim['no_convertibles']]);
        $this->assertSame('pendiente', $sim['ejemplos'][0]['valor']);
        $this->assertSame('$250.00', $this->registroDe('Ana López')->valores_extra['bono_transporte']);

        // 2) Aplicar sin forzar: se rechaza porque hay un valor no convertible
        $this->patchJson("/api/columnas/{$col['id']}/tipo", ['tipo' => 'dinero'])->assertStatus(422)->assertJsonPath('reporte.no_convertibles', 1);

        // 3) Forzar: convierte lo posible y vacía lo demás
        $this->patchJson("/api/columnas/{$col['id']}/tipo", ['tipo' => 'dinero', 'forzar' => true])->assertOk()->assertJsonPath('aplicado', true);
        $this->assertEquals(250, $this->registroDe('Ana López')->valores_extra['bono_transporte']);
        $this->assertNull($this->registroDe('Luis Pérez')->valores_extra['bono_transporte']);
        $this->assertDatabaseHas('columnas', ['id' => $col['id'], 'tipo' => 'dinero']);
    }

    public function test_columnas_del_sistema_no_cambian_de_tipo(): void
    {
        $id = \App\Models\Columna::where('clave', 'num_faltas')->value('id');
        $this->patchJson("/api/columnas/{$id}/tipo", ['tipo' => 'texto', 'simular' => true])->assertStatus(422);
    }

    public function test_reemplazar_quita_de_la_vista_solo_en_su_quincena(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López'), $this->fila('Luis Pérez')]), 'REEMPLAZAR');
        $res = $this->subirYAplicar($this->excel([$this->fila('Ana López', 2)]), 'REEMPLAZAR');

        $this->assertSame(1, $res['eliminados']);
        $this->assertSoftDeleted('registros', ['id' => $this->registroDe('Luis Pérez')->id, 'motivo_baja' => 'REEMPLAZO_CARGA']);
    }

    public function test_quincena_nueva_no_toca_la_anterior(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López'), $this->fila('Luis Pérez')]), 'REEMPLAZAR');
        $res = $this->subirYAplicar($this->excel([$this->fila('Ana López', 1)], '2q.xlsx', null, '2Q'), 'REEMPLAZAR');

        $this->assertSame(0, $res['eliminados']);
        $this->assertSame(3, Registro::count());     // 1Q: Ana + Luis; 2Q: Ana
    }

    public function test_restaurar_regresa_la_fila_a_su_quincena_original(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López'), $this->fila('Luis Pérez')]), 'MANTENER');
        $luis1Q = $this->registroDe('Luis Pérez');
        $this->deleteJson("/api/registros/{$luis1Q->id}")->assertOk();

        // Se sube la quincena siguiente
        $this->subirYAplicar($this->excel([$this->fila('Ana López')], '2q.xlsx', null, '2Q'), 'MANTENER');

        // Consultar el histórico no restaura
        $this->getJson('/api/historico')->assertOk()->assertJsonPath('data.0.nombre_completo', 'Luis Pérez');
        $this->assertSoftDeleted($luis1Q);

        $this->postJson("/api/historico/{$luis1Q->id}/restaurar")->assertOk();
        $luis1Q->refresh();
        $this->assertNull($luis1Q->deleted_at);
        $this->assertSame('2026-10-01', $luis1Q->periodo->fecha_inicio->format('Y-m-d')); // su quincena, no la última
        $this->assertTrue($luis1Q->conservar_en_cargas);
    }

    public function test_mantener_no_revive_eliminados_y_reemplazar_si(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López')]), 'MANTENER');
        $ana = $this->registroDe('Ana López');
        $this->deleteJson("/api/registros/{$ana->id}")->assertOk();

        $this->assertSame(1, $this->subirYAplicar($this->excel([$this->fila('Ana López', 1)]), 'MANTENER')['omitidos']);
        $this->assertSoftDeleted($ana);

        $this->assertSame(1, $this->subirYAplicar($this->excel([$this->fila('Ana López', 2)]), 'REEMPLAZAR')['restaurados']);
        $this->assertNotSoftDeleted($ana);
    }

    public function test_errores_reportan_celda_y_no_aplican_nada(): void
    {
        $carga = $this->postJson('/api/cargas', ['archivo' => $this->excel([$this->fila('Ana López', 0, ['Numero de faltas' => 'dos'])]), 'anio' => 2026])
            ->assertCreated()->json('data');

        $this->assertSame('CON_ERRORES', $carga['estatus']);
        $this->assertSame('H2', collect($carga['mensajes'])->firstWhere('nivel', 'ERROR')['celda']);
        $this->postJson("/api/cargas/{$carga['id']}/aplicar", ['modo' => 'REEMPLAZAR'])->assertStatus(422);
        $this->assertSame(0, Empleado::count());
    }

    public function test_archivo_que_no_es_excel_se_rechaza(): void
    {
        $falso = UploadedFile::fake()->create('reporte.odt', 10, 'application/vnd.oasis.opendocument.text');
        $this->postJson('/api/cargas', ['archivo' => $falso])->assertStatus(422)->assertJsonValidationErrors('archivo');
    }

    public function test_bloqueo_optimista_entre_dos_usuarios(): void
    {
        $this->subirYAplicar($this->excel([$this->fila('Ana López')]), 'MANTENER');
        $reg = $this->registroDe('Ana López');

        $this->patchJson("/api/registros/{$reg->id}", ['version' => $reg->version, 'valores' => ['num_faltas' => 1]])->assertOk(); // usuario A
        $this->patchJson("/api/registros/{$reg->id}", ['version' => $reg->version, 'valores' => ['num_faltas' => 2]])->assertStatus(409); // B con versión vieja
    }

    public function test_libro_con_varias_hojas_lee_solo_la_del_reporte(): void
    {
        $archivo = $this->excelVariasHojas([
            'Notas' => [['Comentarios de RH'], ['Revisar domingos de Luis']],
            'Octubre 1Q' => $this->hojaReporte([$this->fila('Ana López'), $this->fila('Luis Pérez')]),
            'Catalogo' => [['Puesto', 'Sueldo'], ['Asesor', 100]],
        ]);
        $carga = $this->postJson('/api/cargas', ['archivo' => $archivo, 'anio' => 2026])->assertCreated()->json('data');

        $this->assertSame('LISTA', $carga['estatus']);
        $this->assertSame('Octubre 1Q', $carga['hoja']);
        $this->assertSame(2, $carga['filas_total']);
        $this->assertContains('HOJAS_IGNORADAS', array_column($carga['mensajes'], 'codigo'));
    }

    public function test_un_libro_con_dos_quincenas_se_carga_hoja_por_hoja(): void
    {
        $hojas = [
            'Octubre 1Q' => $this->hojaReporte([$this->fila('Ana López')]),
            'Octubre 2Q' => $this->hojaReporte([$this->fila('Ana López', 2)], '2Q'),
        ];

        // Sin elegir: se usa la primera y se avisa que hay otra con formato de reporte
        $c1 = $this->postJson('/api/cargas', ['archivo' => $this->excelVariasHojas($hojas), 'anio' => 2026])->json('data');
        $this->assertContains('HOJA_AMBIGUA', array_column($c1['mensajes'], 'codigo'));
        $this->postJson("/api/cargas/{$c1['id']}/aplicar", ['modo' => 'MANTENER'])->assertOk();

        // El mismo archivo, otra hoja: no es duplicado
        $c2 = $this->postJson('/api/cargas', ['archivo' => $this->excelVariasHojas($hojas), 'anio' => 2026, 'hoja' => 'Octubre 2Q'])->assertCreated()->json('data');
        $this->assertSame('LISTA', $c2['estatus'], json_encode($c2['mensajes']));
        $this->postJson("/api/cargas/{$c2['id']}/aplicar", ['modo' => 'MANTENER'])->assertOk();
        $this->assertSame(2, Registro::count());

        // La misma hoja otra vez: sí es duplicado
        $this->postJson('/api/cargas', ['archivo' => $this->excelVariasHojas($hojas), 'anio' => 2026, 'hoja' => 'Octubre 2Q'])->assertStatus(409);
    }

    public function test_libro_sin_hoja_de_reporte_lista_las_hojas(): void
    {
        $carga = $this->postJson('/api/cargas', ['archivo' => $this->excelVariasHojas(['Notas' => [['x']], 'Resumen' => [['Total', 5]]]), 'anio' => 2026])
            ->assertCreated()->json('data');
        $this->assertSame('CON_ERRORES', $carga['estatus']);
        $this->assertSame('HOJA_NO_ENCONTRADA', $carga['mensajes'][0]['codigo']);
        $this->assertSame(['Notas', 'Resumen'], $carga['hojas']);
    }
}
