<?php

namespace App\Services\Cfdi;

/**
 * v14 — Proveedor de certificación (PAC). La plataforma arma y sella el XML; el PAC lo valida contra las
 * reglas del SAT, le agrega el Timbre Fiscal Digital (UUID) y lo reporta al SAT.
 */
interface ProveedorTimbrado
{
    /** simulado | pruebas | produccion */
    public function modo(): string;

    public function nombre(): string;

    /** @return array{ok: bool, mensaje: string} */
    public function probar(): array;

    /** Recibe el XML sellado y devuelve el XML timbrado (con tfd:TimbreFiscalDigital). Lanza CfdiException si el PAC lo rechaza. */
    public function timbrar(string $xmlSellado): string;

    /**
     * @param  array{rfc: string, uuid: string, motivo: string, folio_sustitucion: ?string, cer_b64: string, key_b64: string, password: string}  $p
     * @return array{estado: string, mensaje: string, acuse: ?string}
     */
    public function cancelar(array $p): array;
}
