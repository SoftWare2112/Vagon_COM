<?php

namespace Tests\Unit\Nomina;

use App\Support\Nomina\ValidadorFiscal as V;
use PHPUnit\Framework\TestCase;

/** v11: datos fiscales del trabajador (RFC, CURP, NSS, CLABE) con dígito verificador. */
class ValidadorFiscalTest extends TestCase
{
    public function test_rfc(): void
    {
        foreach (['GODE561231GR8', 'CACX7605101P8', 'mofy900516nl1', 'GODE-561231-GR8'] as $ok) {
            $this->assertNull(V::rfc($ok), $ok);
        }
        $this->assertNull(V::rfc('WMT970714R10', true));   // persona moral
        $this->assertStringContainsString('13 caracteres', V::rfc('GODE561231'));
        $this->assertStringContainsString('fecha', V::rfc('GODE561331GR8'));
        $this->assertStringContainsString('verificador', V::rfc('GODE561231GR9'));
        $this->assertStringContainsString('genérico', V::rfc('XAXX010101000'));
    }

    public function test_curp(): void
    {
        $this->assertNull(V::curp('HEGG560427MVZRRL04'));
        $this->assertStringContainsString('18 caracteres', V::curp('HEGG560427'));
        $this->assertStringContainsString('verificador', V::curp('HEGG560427MVZRRL05'));
        $this->assertStringContainsString('18 caracteres', V::curp('HEGG560427MZZRRL04'));   // estado inexistente
    }

    public function test_nss_y_clabe(): void
    {
        $this->assertNull(V::nss('12345678903'));
        $this->assertStringContainsString('11 dígitos', V::nss('1234'));
        $this->assertStringContainsString('verificador', V::nss('12345678901'));
        $this->assertNull(V::clabe('002010077777777771'));
        $this->assertStringContainsString('control', V::clabe('002010077777777772'));
    }

    public function test_coherencia_rfc_curp(): void
    {
        $this->assertNull(V::coherenciaRfcCurp('HEGG560427AB3', 'HEGG560427MVZRRL04'));
        $this->assertNull(V::rfc('HEGG560427AB3'));
        $this->assertNotNull(V::coherenciaRfcCurp('GODE561231GR8', 'HEGG560427MVZRRL04'));
    }
}
