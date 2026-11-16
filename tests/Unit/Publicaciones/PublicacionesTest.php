<?php

namespace Tests\Unit\Publicaciones;

use App\Support\Publicaciones\Audiencia;
use App\Support\Publicaciones\EnlaceVideo;
use PHPUnit\Framework\TestCase;

/** v10: reglas puras de Publicaciones — enlaces de video, audiencia. */
class PublicacionesTest extends TestCase
{
    public function test_reconoce_enlaces_de_youtube(): void
    {
        foreach ([
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://youtube.com/shorts/dQw4w9WgXcQ',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
        ] as $url) {
            $this->assertSame(['proveedor' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'incrustar' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'], EnlaceVideo::analizar($url), $url);
        }
    }

    public function test_reconoce_vimeo_drive_y_microsoft(): void
    {
        $this->assertSame('https://player.vimeo.com/video/123456789', EnlaceVideo::analizar('https://vimeo.com/123456789')['incrustar']);
        $this->assertSame('https://drive.google.com/file/d/1AbCdEfGhIjKlMn/preview', EnlaceVideo::analizar('https://drive.google.com/file/d/1AbCdEfGhIjKlMn/view?usp=sharing')['incrustar']);
        $this->assertSame(['proveedor' => 'microsoft', 'id' => null, 'incrustar' => null], EnlaceVideo::analizar('https://empresa.sharepoint.com/sites/rh/video.mp4'));
        $this->assertSame('teams', EnlaceVideo::analizar('https://teams.microsoft.com/l/meetup-join/abc')['proveedor']);
        $this->assertSame('otro', EnlaceVideo::analizar('https://ejemplo.com/capacitacion')['proveedor']);
    }

    public function test_rechaza_enlaces_inseguros_o_rotos(): void
    {
        foreach (['http://youtube.com/watch?v=dQw4w9WgXcQ', 'javascript:alert(1)', 'no es un enlace', 'https://youtube.com/watch?v=corto', 'ftp://videos.com/a.mp4'] as $url) {
            $this->assertNull(EnlaceVideo::analizar($url), $url);
        }
    }

    public function test_audiencia(): void
    {
        $p = ['audiencia' => 'roles', 'audiencia_ids' => ['supervisor'], 'estado' => 'publicada', 'user_id' => 1];
        $this->assertTrue(Audiencia::puedeVer($p, 5, 'supervisor', false));
        $this->assertFalse(Audiencia::puedeVer($p, 5, 'lector', false));
        $this->assertTrue(Audiencia::puedeVer($p, 1, 'lector', false));            // el autor siempre
        $this->assertTrue(Audiencia::puedeVer($p, 9, 'lector', true));             // el moderador siempre
        $this->assertFalse(Audiencia::puedeVer([...$p, 'estado' => 'borrador'], 5, 'supervisor', false));
        $this->assertFalse(Audiencia::puedeVer([...$p, 'caduca_at' => new \DateTimeImmutable('-1 day')], 5, 'supervisor', false));
        $u = ['audiencia' => 'usuarios', 'audiencia_ids' => [5, 7], 'estado' => 'publicada', 'user_id' => 1];
        $this->assertTrue(Audiencia::puedeVer($u, 7, null, false));
        $this->assertFalse(Audiencia::puedeVer($u, 6, 'admin_rh', false));
        $this->assertTrue(Audiencia::puedeVer(['audiencia' => 'todos', 'audiencia_ids' => null, 'estado' => 'publicada', 'user_id' => 1], 3, null, false));
    }

    public function test_normalizar_audiencia(): void
    {
        $this->assertSame(['todos', null], Audiencia::normalizar('todos', ['x']));
        $this->assertSame(['roles', ['lector', 'supervisor']], Audiencia::normalizar('roles', ['lector', 'supervisor', 'lector', '']));
        $this->assertSame(['usuarios', [3, 8]], Audiencia::normalizar('usuarios', ['3', 8, 0]));
        $this->assertNull(Audiencia::normalizar('roles', []));
        $this->assertNull(Audiencia::normalizar('grupo', ['a']));
    }
}
