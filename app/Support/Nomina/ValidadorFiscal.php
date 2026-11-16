<?php

namespace App\Support\Nomina;

/**
 * v11 — Valida los datos fiscales del trabajador ANTES de guardarlos (error común #1 en nómina:
 * "datos fiscales incorrectos"). Revisa formato, fecha y DÍGITO VERIFICADOR de RFC, CURP y NSS.
 * Lógica pura (sin Laravel).
 */
final class ValidadorFiscal
{
    private const ESTADOS = 'AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE';

    public static function normalizar(?string $v): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', (string) $v));
    }

    /** @return string|null  mensaje de error, o null si es válido */
    public static function rfc(?string $rfc, bool $personaMoral = false): ?string
    {
        $rfc = self::normalizar($rfc);
        $letras = $personaMoral ? 3 : 4;
        if (! preg_match('/^([A-ZÑ&]{'.$letras.'})(\d{2})(\d{2})(\d{2})([A-Z\d]{2})([A\d])$/u', $rfc, $m)) {
            return $personaMoral
                ? 'El RFC de la empresa debe tener 12 caracteres: 3 letras, fecha (AAMMDD) y homoclave.'
                : 'El RFC debe tener 13 caracteres: 4 letras, fecha de nacimiento (AAMMDD) y homoclave. Ej. GODE561231GR8';
        }
        if (! self::fechaValida($m[2], $m[3], $m[4])) {
            return 'La fecha dentro del RFC no es válida (revisa los 6 números).';
        }
        if (in_array($rfc, ['XAXX010101000', 'XEXX010101000'], true)) {
            return 'El RFC genérico no se puede usar en la nómina: captura el RFC real del trabajador.';
        }
        if (self::digitoRfc($rfc) !== substr($rfc, -1)) {
            return 'El RFC no es válido: el último carácter (dígito verificador) no corresponde. Revisa la homoclave.';
        }

        return null;
    }

    public static function curp(?string $curp): ?string
    {
        $curp = self::normalizar($curp);
        if (! preg_match('/^[A-Z][AEIOUX][A-Z]{2}(\d{2})(\d{2})(\d{2})[HMX]('.self::ESTADOS.')[B-DF-HJ-NP-TV-ZÑ]{3}[A-Z\d]\d$/u', $curp, $m)) {
            return 'La CURP debe tener 18 caracteres. Ej. HEGG560427MVZRRL04';
        }
        if (! self::fechaValida($m[1], $m[2], $m[3])) {
            return 'La fecha de nacimiento dentro de la CURP no es válida.';
        }
        $tabla = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';
        $suma = 0;
        $chars = mb_str_split($curp);
        for ($i = 0; $i < 17; $i++) {
            $suma += mb_strpos($tabla, $chars[$i]) * (18 - $i);
        }
        if ((string) ((10 - $suma % 10) % 10) !== $chars[17]) {
            return 'La CURP no es válida: el último dígito (verificador) no corresponde.';
        }

        return null;
    }

    /** Número de Seguridad Social del IMSS: 11 dígitos; el último se verifica con el algoritmo de Luhn. */
    public static function nss(?string $nss): ?string
    {
        $nss = preg_replace('/\D/', '', (string) $nss);
        if (strlen($nss) !== 11) {
            return 'El Número de Seguridad Social debe tener 11 dígitos.';
        }
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $d = (int) $nss[$i] * ($i % 2 === 0 ? 1 : 2);
            $suma += $d > 9 ? $d - 9 : $d;
        }
        if ((10 - $suma % 10) % 10 !== (int) $nss[10]) {
            return 'El Número de Seguridad Social no es válido: el último dígito (verificador) no corresponde.';
        }

        return null;
    }

    /** CLABE interbancaria: 18 dígitos con dígito de control (pesos 3, 7, 1). */
    public static function clabe(?string $clabe): ?string
    {
        $clabe = preg_replace('/\D/', '', (string) $clabe);
        if (strlen($clabe) !== 18) {
            return 'La CLABE debe tener 18 dígitos.';
        }
        $pesos = [3, 7, 1];
        $suma = 0;
        for ($i = 0; $i < 17; $i++) {
            $suma += ((int) $clabe[$i] * $pesos[$i % 3]) % 10;
        }
        if ((10 - $suma % 10) % 10 !== (int) $clabe[17]) {
            return 'La CLABE no es válida: el dígito de control no corresponde.';
        }

        return null;
    }

    /** Advertencia (no error): en una persona física, RFC y CURP comparten los primeros 10 caracteres. */
    /**
     * v12: ¿el error es SOLO del dígito verificador? (el formato es correcto). Es el único caso que RH puede aceptar
     * con motivo: hay claves reales emitidas por el SAT, el IMSS o RENAPO que no siguen el algoritmo público.
     */
    public static function esErrorDeDigito(?string $mensaje): bool
    {
        return $mensaje !== null && (str_contains($mensaje, 'verificador') || str_contains($mensaje, 'dígito de control'));
    }

    public static function coherenciaRfcCurp(string $rfc, string $curp): ?string
    {
        $rfc = self::normalizar($rfc);
        $curp = self::normalizar($curp);

        return strlen($rfc) === 13 && strlen($curp) === 18 && substr($rfc, 0, 10) !== substr($curp, 0, 10)
            ? 'El RFC y la CURP no coinciden en sus primeros 10 caracteres; revisa que sean de la misma persona.'
            : null;
    }

    public static function digitoRfc(string $rfc): string
    {
        $tabla = '0123456789ABCDEFGHIJKLMN&OPQRSTUVWXYZ Ñ';
        $base = mb_substr($rfc, 0, -1);
        if (mb_strlen($base) === 11) {
            $base = ' '.$base;   // persona moral: se completa a 12 con un espacio al inicio
        }
        $suma = 0;
        foreach (mb_str_split($base) as $i => $c) {
            $suma += mb_strpos($tabla, $c) * (13 - $i);
        }
        $r = $suma % 11;

        return $r === 0 ? '0' : (11 - $r === 10 ? 'A' : (string) (11 - $r));
    }

    private static function fechaValida(string $aa, string $mm, string $dd): bool
    {
        return checkdate((int) $mm, (int) $dd, 2000 + (int) $aa) || checkdate((int) $mm, (int) $dd, 1900 + (int) $aa);
    }
}
