<?php

namespace App\Support\Cfdi;

/**
 * v14 — Certificado de Sello Digital (CSD) del emisor: el par .cer / .key que el SAT entrega a la empresa.
 *
 *  - El .cer viene en DER (binario). NoCertificado = número de serie del certificado (20 dígitos, el SAT lo
 *    codifica como texto ASCII dentro del serial hexadecimal).
 *  - El .key viene en DER, PKCS#8 cifrado con la contraseña del CSD. Se envuelve en PEM para que OpenSSL lo abra.
 *  - El sello del CFDI 4.0 es la firma RSA-SHA256 de la cadena original, en base 64.
 *
 * Clase pura: no toca la base de datos ni el disco.
 */
final class Csd
{
    private \OpenSSLCertificate $cert;

    private ?\OpenSSLAsymmetricKey $llave = null;

    private array $datos;

    private function __construct(private readonly string $cerDer)
    {
        $pem = self::derAPem($cerDer, 'CERTIFICATE');
        $cert = @openssl_x509_read($pem);
        if ($cert === false) {
            throw new CfdiException('El archivo .cer no es un certificado válido (se espera el archivo DER que entrega el SAT).', 'CER_INVALIDO');
        }
        $this->cert = $cert;
        $this->datos = openssl_x509_parse($cert, false) ?: [];
    }

    public static function desdeArchivos(string $cerDer, ?string $keyDer = null, ?string $password = null): self
    {
        // Se aceptan también archivos ya convertidos a PEM.
        if (str_contains($cerDer, '-----BEGIN CERTIFICATE-----')) {
            $cerDer = self::pemADer($cerDer);
        }
        $csd = new self($cerDer);
        if ($keyDer !== null) {
            $csd->abrirLlave($keyDer, (string) $password);
        }

        return $csd;
    }

    private function abrirLlave(string $keyDer, string $password): void
    {
        $pem = str_contains($keyDer, '-----BEGIN') ? $keyDer : self::derAPem($keyDer, 'ENCRYPTED PRIVATE KEY');
        $llave = @openssl_pkey_get_private($pem, $password);
        if ($llave === false) {
            while (openssl_error_string() !== false) {
                // limpia la cola de errores de OpenSSL
            }
            throw new CfdiException('No se pudo abrir el archivo .key: la contraseña es incorrecta o el archivo no corresponde a una llave privada.', 'KEY_INVALIDA');
        }
        if (! openssl_x509_check_private_key($this->cert, $llave)) {
            throw new CfdiException('La llave privada (.key) no corresponde al certificado (.cer).', 'PAR_NO_COINCIDE');
        }
        $this->llave = $llave;
    }

    /** Número de certificado (20 dígitos) tal como va en el atributo NoCertificado. */
    public function noCertificado(): string
    {
        $hex = $this->datos['serialNumberHex'] ?? '';
        if ($hex !== '' && ctype_xdigit($hex) && strlen($hex) % 2 === 0) {
            $texto = hex2bin($hex);
            if ($texto !== false && ctype_digit($texto)) {
                return $texto;
            }
        }

        return (string) ($this->datos['serialNumber'] ?? '');
    }

    /** RFC del titular. En personas morales el SAT pone «RFC_EMPRESA / RFC_REPRESENTANTE». */
    public function rfc(): string
    {
        $uid = $this->datos['subject']['x500UniqueIdentifier'] ?? '';
        $uid = is_array($uid) ? (string) reset($uid) : (string) $uid;

        return strtoupper(trim(explode('/', $uid)[0]));
    }

    public function titular(): string
    {
        $n = $this->datos['subject']['name'] ?? $this->datos['subject']['commonName'] ?? '';

        return is_array($n) ? (string) reset($n) : (string) $n;
    }

    public function vigenteDesde(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$this->datos['validFrom_time_t']));
    }

    public function vigenteHasta(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@'.$this->datos['validTo_time_t']));
    }

    public function vigente(?\DateTimeInterface $en = null): bool
    {
        $t = ($en ?? new \DateTimeImmutable)->getTimestamp();

        return $t >= $this->datos['validFrom_time_t'] && $t <= $this->datos['validTo_time_t'];
    }

    /**
     * La e.firma (FIEL) no sirve para sellar CFDI. El CSD solo trae «Digital Signature, Non Repudiation»;
     * la e.firma además permite cifrado («Data Encipherment» / «Key Agreement»).
     */
    public function esEfirma(): bool
    {
        $uso = (string) ($this->datos['extensions']['keyUsage'] ?? '');

        return str_contains($uso, 'Data Encipherment') || str_contains($uso, 'Key Agreement');
    }

    /** Contenido del atributo Certificado: el .cer en base 64, sin saltos de línea. */
    public function certificadoBase64(): string
    {
        return base64_encode($this->cerDer);
    }

    public function tieneLlave(): bool
    {
        return $this->llave !== null;
    }

    /** Sello = base64( RSA-SHA256( cadena original ) ). */
    public function sellar(string $cadenaOriginal): string
    {
        if (! $this->llave) {
            throw new CfdiException('Falta la llave privada del CSD para sellar.', 'SIN_LLAVE');
        }
        if (! openssl_sign($cadenaOriginal, $firma, $this->llave, OPENSSL_ALGO_SHA256)) {
            throw new CfdiException('OpenSSL no pudo firmar la cadena original.', 'FIRMA_FALLO');
        }

        return base64_encode($firma);
    }

    /** Verifica un sello con la llave pública del certificado. */
    public function verificar(string $cadenaOriginal, string $selloBase64): bool
    {
        return openssl_verify($cadenaOriginal, base64_decode($selloBase64), $this->cert, OPENSSL_ALGO_SHA256) === 1;
    }

    public function resumen(): array
    {
        return ['rfc' => $this->rfc(), 'titular' => $this->titular(), 'no_certificado' => $this->noCertificado(),
            'vigente_desde' => $this->vigenteDesde()->format('Y-m-d H:i:s'), 'vigente_hasta' => $this->vigenteHasta()->format('Y-m-d H:i:s'),
            'vigente' => $this->vigente(), 'es_efirma' => $this->esEfirma()];
    }

    private static function derAPem(string $der, string $tipo): string
    {
        return "-----BEGIN {$tipo}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$tipo}-----\n";
    }

    private static function pemADer(string $pem): string
    {
        $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem);

        return (string) base64_decode($b64);
    }
}
