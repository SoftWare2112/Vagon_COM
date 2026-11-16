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

    public function test_lector_consulta_empleados_pero_no_los_cambia(): void
    {
        $this->actingAs(User::where('email', 'lector@demo.test')->first());
        $this->getJson('/api/empleados')->assertOk();
        $this->postJson('/api/empleados', [])->assertForbidden();
        $this->putJson('/api/nomina/empresa', [])->assertForbidden();
        $this->getJson('/api/usuarios')->assertForbidden();
    }

    public function test_los_modulos_retirados_ya_no_existen(): void
    {
        $this->actingAs(User::where('email', 'admin@demo.test')->first());
        foreach (['/api/registros', '/api/cargas', '/api/libros', '/api/historico', '/api/columnas'] as $ruta) {
            $this->getJson($ruta)->assertNotFound();
        }
        $this->assertFalse(User::where('email', 'admin@demo.test')->first()->tienePermiso('registros.ver'));
    }

    public function test_admin_cambia_permisos_de_un_rol_desde_la_web(): void
    {
        $this->actingAs(User::where('email', 'admin@demo.test')->first());
        $sup = Rol::where('nombre', 'supervisor')->first();

        $this->putJson("/api/roles/{$sup->id}/permisos", ['permisos' => ['empleados.ver', 'empleados.gestionar']])->assertOk();
        $this->assertTrue(User::where('email', 'supervisor@demo.test')->first()->tienePermiso('empleados.gestionar'));
        $this->assertFalse(User::where('email', 'supervisor@demo.test')->first()->tienePermiso('publicaciones.crear'));
        $this->putJson("/api/roles/{$sup->id}/permisos", ['permisos' => ['registros.ver']])->assertStatus(422);   // permiso retirado
    }

    public function test_admin_no_puede_quitarse_la_gestion_de_usuarios(): void
    {
        $this->actingAs(User::where('email', 'admin@demo.test')->first());
        $admin = Rol::where('nombre', 'admin_rh')->first();
        $this->putJson("/api/roles/{$admin->id}/permisos", ['permisos' => ['empleados.ver']])->assertOk();
        $this->assertTrue(User::where('email', 'admin@demo.test')->first()->tienePermiso('usuarios.gestionar'));
    }

    public function test_sin_sesion_no_hay_acceso(): void
    {
        $this->getJson('/api/empleados')->assertUnauthorized();
    }

    public function test_recuperacion_no_revela_si_el_correo_existe(): void
    {
        $a = $this->postJson('/api/auth/olvide', ['email' => 'admin@demo.test'])->json('message');
        $b = $this->postJson('/api/auth/olvide', ['email' => 'noexiste@demo.test'])->json('message');
        $this->assertSame($a, $b);
    }
}
