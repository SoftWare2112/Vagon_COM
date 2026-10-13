<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ComandosTest extends TestCase
{
    use RefreshDatabase;

    public function test_reportes_usuario_crea_un_administrador(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('reportes:usuario', ['email' => 'RH@Empresa.com', '--rol' => 'admin_rh', '--password' => 'ClaveSegura2026', '--nombre' => 'Ana RH'])
            ->assertSuccessful();

        $u = User::where('email', 'rh@empresa.com')->first();
        $this->assertTrue(Hash::check('ClaveSegura2026', $u->password));
        $this->assertTrue($u->tienePermiso('usuarios.gestionar'));
    }

    public function test_reportes_usuario_desbloquea_y_restablece(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::where('email', 'admin@demo.test')->update(['locked_until' => now()->addHour(), 'failed_attempts' => 3]);

        $this->artisan('reportes:usuario', ['email' => 'admin@demo.test', '--password' => 'Demo12345!'])->assertSuccessful();

        $u = User::where('email', 'admin@demo.test')->first();
        $this->assertFalse($u->estaBloqueado());
        $this->assertTrue(Hash::check('Demo12345!', $u->password));
    }

    public function test_reportes_usuario_rechaza_contrasena_debil(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->artisan('reportes:usuario', ['email' => 'x@empresa.com', '--rol' => 'lector', '--password' => '123'])->assertFailed();
    }
}
