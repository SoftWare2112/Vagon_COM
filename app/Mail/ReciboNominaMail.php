<?php

namespace App\Mail;

use App\Models\Empresa;
use App\Models\NominaRecibo;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** v13 — Correo con el recibo de nómina en PDF adjunto. */
class ReciboNominaMail extends Mailable
{
    public function __construct(private readonly NominaRecibo $recibo, private readonly string $pdf, private readonly string $archivo) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tu recibo de nómina · '.$this->recibo->periodo->etiqueta());
    }

    public function content(): Content
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $empresa = Empresa::actual();
        $nombre = $e(explode(' ', trim((string) $this->recibo->empleado?->nombres))[0] ?? $this->recibo->nombre);

        return new Content(htmlString: '<div style="font-family:Arial,sans-serif;font-size:14px;color:#1d2228">'
            ."<p>Hola {$nombre}:</p>"
            .'<p>Te enviamos tu recibo de nómina de <b>'.$e($this->recibo->periodo->etiqueta()).'</b>. '
            .'Neto pagado: <b>$'.number_format($this->recibo->neto, 2).'</b>.</p>'
            .'<p>El recibo está adjunto en PDF. Si encuentras un error, avisa a Recursos Humanos.</p>'
            .'<p style="color:#5b636e;font-size:12px">'.$e($empresa->razon_social ?: $empresa->nombre).' · Este es un correo automático, no lo respondas.</p></div>');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->archivo)->withMime('application/pdf')];
    }
}
