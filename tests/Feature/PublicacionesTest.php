<?php

namespace Tests\Feature;

use App\Models\Publicacion;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** v10: publicaciones — audiencia, borradores, adjuntos, videos por enlace, trabajos, lecturas y moderación. */
class PublicacionesTest extends TestCase
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

    private function publicar(array $datos): array
    {
        return $this->postJson('/api/publicaciones', ['estado' => 'publicada', ...$datos])->assertCreated()->json();
    }

    public function test_texto_para_todos_lo_ve_el_lector_y_queda_como_leido(): void
    {
        $this->como('supervisor');
        $p = $this->publicar(['tipo' => 'texto', 'titulo' => 'Cambio de horario', 'cuerpo' => "A partir del lunes **entramos a las 8**.\n- Traer gafete", 'categoria' => 'Avisos', 'etiquetas' => ['Horario', 'horario']]);
        $this->assertSame(['horario'], $p['etiquetas']);

        $this->como('lector');
        $this->assertSame(1, $this->getJson('/api/publicaciones/sin-leer')->json('total'));
        $lista = $this->getJson('/api/publicaciones')->assertOk()->json('data');
        $this->assertSame('Cambio de horario', $lista[0]['titulo']);
        $this->assertFalse($lista[0]['leida']);
        $this->assertArrayNotHasKey('lecturas', $lista[0]);   // el lector no ve las métricas

        $this->getJson("/api/publicaciones/{$p['id']}")->assertOk()->assertJsonPath('leida', true);   // abrirla la marca como vista
        $this->assertSame(0, $this->getJson('/api/publicaciones/sin-leer')->json('total'));
        $this->patchJson("/api/publicaciones/{$p['id']}", ['titulo' => 'Hackeado'])->assertForbidden();
        $this->postJson('/api/publicaciones', ['tipo' => 'texto', 'titulo' => 'No', 'cuerpo' => 'x'])->assertForbidden();

        $this->como('supervisor');
        $this->assertSame([['id' => User::where('email', 'lector@demo.test')->value('id'), 'nombre' => User::where('email', 'lector@demo.test')->value('name')]],
            collect($this->getJson("/api/publicaciones/{$p['id']}/lecturas")->json())->map(fn ($l) => ['id' => $l['id'], 'nombre' => $l['nombre']])->all());
    }

    public function test_audiencia_por_rol_y_borradores_privados(): void
    {
        $this->como('admin');
        $soloSup = $this->publicar(['tipo' => 'texto', 'titulo' => 'Para supervisores', 'cuerpo' => 'x', 'audiencia' => 'roles', 'audiencia_ids' => ['supervisor']]);
        $borrador = $this->postJson('/api/publicaciones', ['tipo' => 'texto', 'titulo' => 'Borrador', 'cuerpo' => 'x'])->assertCreated()->json();
        $this->postJson('/api/publicaciones', ['tipo' => 'texto', 'titulo' => 'Sin nadie', 'cuerpo' => 'x', 'audiencia' => 'roles', 'audiencia_ids' => []])->assertStatus(422);

        $this->como('lector');
        $this->getJson("/api/publicaciones/{$soloSup['id']}")->assertNotFound();
        $this->getJson("/api/publicaciones/{$borrador['id']}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/publicaciones')->json('data'));

        $this->como('supervisor');
        $this->getJson("/api/publicaciones/{$soloSup['id']}")->assertOk();
        $this->getJson("/api/publicaciones/{$borrador['id']}")->assertNotFound();
    }

    public function test_caducada_ya_no_se_ve(): void
    {
        $this->como('admin');
        $p = $this->publicar(['tipo' => 'texto', 'titulo' => 'Temporal', 'cuerpo' => 'x', 'caduca_at' => now()->format('Y-m-d')]);
        Publicacion::whereKey($p['id'])->update(['caduca_at' => now()->subMinute()]);
        $this->como('lector');
        $this->getJson("/api/publicaciones/{$p['id']}")->assertNotFound();
    }

    public function test_imagenes_y_documentos_con_permiso_de_descarga(): void
    {
        $this->como('supervisor');
        $p = $this->post('/api/publicaciones', [
            'tipo' => 'documentos', 'titulo' => 'Reglamento', 'estado' => 'publicada', 'permite_descarga' => '0',
            'adjuntos' => [UploadedFile::fake()->create('reglamento.pdf', 120, 'application/pdf'), UploadedFile::fake()->image('organigrama.png', 900, 600)],
        ], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertCount(2, $p['adjuntos']);
        [$pdf, $png] = $p['adjuntos'];

        $this->post('/api/publicaciones', ['tipo' => 'documentos', 'titulo' => 'Malo', 'adjuntos' => [UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')]],
            ['Accept' => 'application/json'])->assertStatus(422);

        $this->como('lector');
        $this->get($pdf['url'])->assertOk();                          // vista previa sí
        $this->get($pdf['url'].'?descargar=1')->assertForbidden();    // descarga no
        $this->get($png['miniatura'])->assertOk();
    }

    public function test_video_solo_por_enlace(): void
    {
        $this->como('supervisor');
        $p = $this->publicar(['tipo' => 'video', 'titulo' => 'Capacitación', 'video_url' => 'https://youtu.be/dQw4w9WgXcQ']);
        $this->assertSame('youtube', $p['video']['proveedor']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $p['video']['incrustar']);
        $this->postJson('/api/publicaciones', ['tipo' => 'video', 'titulo' => 'Malo', 'video_url' => 'javascript:alert(1)'])->assertStatus(422);
        $this->postJson('/api/publicaciones', ['tipo' => 'video', 'titulo' => 'Sin enlace'])->assertStatus(422);
    }

    public function test_moderador_fija_y_marca_oficial(): void
    {
        $this->como('supervisor');
        $p = $this->publicar(['tipo' => 'texto', 'titulo' => 'Aviso', 'cuerpo' => 'x', 'fijada' => true]);
        $this->assertFalse($p['fijada']);   // el supervisor no puede fijar
        $this->postJson("/api/publicaciones/{$p['id']}/moderar", ['fijada' => true])->assertForbidden();

        $this->como('admin');
        $this->postJson("/api/publicaciones/{$p['id']}/moderar", ['fijada' => true, 'oficial' => true])->assertOk()
            ->assertJsonPath('fijada', true)->assertJsonPath('oficial', true);
        $this->deleteJson("/api/publicaciones/{$p['id']}")->assertOk();   // el moderador puede eliminar la de otro
        $this->assertSoftDeleted('publicaciones', ['id' => $p['id']]);
    }
}
