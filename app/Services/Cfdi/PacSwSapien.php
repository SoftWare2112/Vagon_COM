<?php

namespace App\Services\Cfdi;

use App\Support\Cfdi\CfdiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * v14 — SW Sapien (SmarterWeb), PAC con API REST. No requiere la extensión SOAP de PHP.
 *
 * Rutas tomadas del SDK oficial de PHP (github.com/lunasoft/sw-sdk-php):
 *  - Token:       POST {url}/v2/security/authenticate   JSON {user, password}  → data.token
 *  - Timbrado:    POST {url}/cfdi33/stamp/v4            multipart «xml», Bearer → data.cfdi (XML timbrado), data.uuid…
 *                 (la ruta conserva «cfdi33» por compatibilidad; acepta CFDI 4.0)
 *  - Cancelación: POST {url}/cfdi33/cancel/csd          JSON {rfc, uuid, motivo, foliosustitucion, b64Cer, b64Key, password}
 * Pruebas: https://services.test.sw.com.mx · Producción: https://services.sw.com.mx
 * En pruebas el timbre no tiene validez fiscal; el SAT no lo conoce.
 */
class PacSwSapien implements ProveedorTimbrado
{
    public const URLS = ['pruebas' => 'https://services.test.sw.com.mx', 'produccion' => 'https://services.sw.com.mx'];

    public function __construct(private readonly string $usuario, private readonly string $password, private readonly string $ambiente = 'pruebas') {}

    public function modo(): string
    {
        return $this->ambiente === 'produccion' ? 'produccion' : 'pruebas';
    }

    public function nombre(): string
    {
        return 'SW Sapien ('.($this->modo() === 'produccion' ? 'producción' : 'pruebas').')';
    }

    private function url(): string
    {
        return self::URLS[$this->modo()];
    }

    public function probar(): array
    {
        try {
            Cache::forget($this->claveCache());
            $this->token();

            return ['ok' => true, 'mensaje' => 'Conexión correcta con '.$this->nombre().': usuario y contraseña aceptados.'];
        } catch (CfdiException $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }
    }

    public function timbrar(string $xmlSellado): string
    {
        $r = $this->llamar(fn () => Http::withToken($this->token())->timeout(90)
            ->attach('xml', $xmlSellado, 'cfdi.xml', ['Content-Type' => 'text/xml'])
            ->post($this->url().'/cfdi33/stamp/v4'));
        $json = $r->json() ?? [];
        if (($json['status'] ?? '') === 'success' && ! empty($json['data']['cfdi'])) {
            return $json['data']['cfdi'];
        }
        $mensaje = trim(($json['message'] ?? 'Respuesta inesperada del PAC (HTTP '.$r->status().')').' '.($json['messageDetail'] ?? ''));
        if ($r->status() === 401) {
            Cache::forget($this->claveCache());
        }
        throw new CfdiException('El PAC rechazó el comprobante: '.$mensaje, str_contains($mensaje, '307') ? 'PAC_DUPLICADO' : 'PAC_RECHAZO', ['respuesta' => $json]);
    }

    public function cancelar(array $p): array
    {
        $r = $this->llamar(fn () => Http::withToken($this->token())->timeout(90)->asJson()->post($this->url().'/cfdi33/cancel/csd', [
            'rfc' => $p['rfc'], 'uuid' => $p['uuid'], 'motivo' => $p['motivo'], 'foliosustitucion' => $p['folio_sustitucion'] ?: null,
            'b64Cer' => $p['cer_b64'], 'b64Key' => $p['key_b64'], 'password' => $p['password'],
        ]));
        $json = $r->json() ?? [];
        if (($json['status'] ?? '') === 'success') {
            $codigo = is_array($json['data']['uuid'] ?? null) ? (string) reset($json['data']['uuid']) : '';

            return ['estado' => 'cancelado', 'mensaje' => 'Solicitud de cancelación aceptada'.($codigo ? " (código {$codigo})" : '').'.',
                'acuse' => $json['data']['acuse'] ?? null];
        }
        throw new CfdiException('El PAC no aceptó la cancelación: '.trim(($json['message'] ?? 'HTTP '.$r->status()).' '.($json['messageDetail'] ?? '')), 'PAC_CANCELACION', ['respuesta' => $json]);
    }

    private function token(): string
    {
        return Cache::remember($this->claveCache(), now()->addMinutes(90), function () {
            if ($this->usuario === '' || $this->password === '') {
                throw new CfdiException('Falta el usuario o la contraseña del PAC (Configuración > Timbrado).', 'PAC_SIN_CREDENCIALES');
            }
            $r = $this->llamar(fn () => Http::timeout(30)->asJson()->post($this->url().'/v2/security/authenticate', ['user' => $this->usuario, 'password' => $this->password]));
            $token = $r->json('data.token');
            if (! $token) {
                throw new CfdiException('El PAC no aceptó el usuario y la contraseña: '.($r->json('message') ?? 'HTTP '.$r->status()), 'PAC_AUTENTICACION');
            }

            return $token;
        });
    }

    private function claveCache(): string
    {
        return 'cfdi:sw:token:'.$this->modo().':'.sha1($this->usuario);
    }

    private function llamar(callable $peticion): Response
    {
        try {
            return $peticion();
        } catch (ConnectionException $e) {
            throw new CfdiException('No hay conexión con el PAC ('.$this->url().'). Revise la conexión a internet o el firewall del servidor.', 'PAC_SIN_CONEXION');
        }
    }
}
