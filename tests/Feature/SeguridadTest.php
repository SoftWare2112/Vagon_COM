<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Notifications\CuentaBloqueada;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SeguridadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seed(DatabaseSeeder::class);
        // Sanctum solo abre sesión para peticiones que vienen del dominio del frontend
        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/']);
    }

    public function test_login_correcto(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'admin@demo.test', 'password' => 'Demo12345!'])
            ->assertOk()->assertJsonPath('user.rol', 'admin_rh');
    }

    public function test_bloqueo_tras_intentos_fallidos_y_aviso_por_correo(): void
    {
        $max = config('reportes.seguridad.max_intentos');
        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'lector@demo.test', 'password' => 'mal'])->assertStatus(422);
        }
        $this->postJson('/api/auth/login', ['email' => 'lector@demo.test', 'password' => 'Demo12345!'])
            ->assertStatus(423)->assertJsonPath('codigo', 'CUENTA_BLOQUEADA');
        Notification::assertSentTo(User::where('email', 'lector@demo.test')->first(), CuentaBloqueada::class);
    }

    public function test_lector_puede_ver_y_exportar_pero_no_editar_ni_subir(): void
    {
        $this->actingAs(User::where('email', 'lector@demo.test')->first());
        $this->getJson('/api/registros')->assertOk();
        $this->postJson('/api/empleados', [])->assertForbidden();
        $this->postJson('/api/columnas', [])->assertForbidden();
        $this->postJson('/api/cargas', [])->assertForbidden();
        $this->getJson('/api/historico')->assertForbidden();
    }

    public function test_supervisor_edita_ve_historico_pero_no_restaura_ni_gestiona_usuarios(): void
    {
        $sup = User::where('email', 'supervisor@demo.test')->first();
        $this->assertTrue($sup->tienePermiso('registros.editar'));
        $this->actingAs($sup);
        $this->getJson('/api/historico')->assertOk();
        $this->postJson('/api/historico/1/restaurar')->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
    }

    public function test_admin_cambia_permisos_de_un_rol_desde_la_web(): void
    {
        $this->actingAs(User::where('email', 'admin@demo.test')->first());
        $sup = Rol::where('nombre', 'supervisor')->first();

        $this->putJson("/api/roles/{$sup->id}/permisos", ['permisos' => ['registros.ver', 'historico.ver', 'historico.restaurar']])->assertOk();
        $this->assertTrue(User::where('email', 'supervisor@demo.test')->first()->tienePermiso('historico.restaurar'));
        $this->assertFalse(User::where('email', 'supervisor@demo.test')->first()->tienePermiso('registros.editar'));
    }

    public function test_admin_no_puede_quitarse_la_gestion_de_usuarios(): void
    {
        $this->actingAs(User::where('email', 'admin@demo.test')->first());
        $admin = Rol::where('nombre', 'admin_rh')->first();
        $this->putJson("/api/roles/{$admin->id}/permisos", ['permisos' => ['registros.ver']])->assertOk();
        $this->assertTrue(User::where('email', 'admin@demo.test')->first()->tienePermiso('usuarios.gestionar'));
    }

    public function test_sin_sesion_no_hay_acceso(): void
    {
        $this->getJson('/api/registros')->assertUnauthorized();
    }

    public function test_recuperacion_no_revela_si_el_correo_existe(): void
    {
        $a = $this->postJson('/api/auth/olvide', ['email' => 'admin@demo.test'])->json('message');
        $b = $this->postJson('/api/auth/olvide', ['email' => 'noexiste@demo.test'])->json('message');
        $this->assertSame($a, $b);
    }
}
