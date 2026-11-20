<?php

namespace App\Services\Nomina;

use App\Exceptions\ReglaNegocioException;
use App\Mail\ReciboNominaMail;
use App\Models\AuditLog;
use App\Models\NominaRecibo;
use App\Models\PeriodoNomina;
use App\Models\ReciboEnvio;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * v13 — Envía por correo el recibo PDF a cada empleado (al correo de su expediente) y deja bitácora.
 * Configura el correo de salida en .env (MAIL_MAILER, MAIL_HOST…). Solo recibos AUTORIZADOS.
 */
class EnvioRecibos
{
    public function __construct(private readonly RecibosPdf $pdf) {}

    /** @return array{enviados: int, sin_correo: list<string>, errores: list<string>} */
    public function periodo(PeriodoNomina $p, User $u): array
    {
        if (! $p->autorizado()) {
            throw new ReglaNegocioException('Primero autoriza la nómina: solo se envían recibos definitivos.', 409, ['codigo' => 'NO_AUTORIZADO']);
        }
        $r = ['enviados' => 0, 'sin_correo' => [], 'errores' => []];
        foreach (NominaRecibo::with(['periodo', 'empleado'])->where('periodo_id', $p->id)->orderBy('nombre')->get() as $recibo) {
            $res = $this->enviar($recibo, $u);
            match ($res['estado']) {
                'enviado' => $r['enviados']++,
                'sin_correo' => $r['sin_correo'][] = $recibo->nombre,
                default => $r['errores'][] = "{$recibo->nombre}: {$res['error']}",
            };
        }
        AuditLog::registrar('ENVIAR_RECIBOS', 'PeriodoNomina', $p->id, null, ['enviados' => $r['enviados'], 'errores' => count($r['errores'])], $u->id);

        return $r;
    }

    /** @return array{estado: string, email?: string, error?: string} */
    public function enviar(NominaRecibo $recibo, User $u): array
    {
        if ($recibo->estado !== 'autorizado') {
            throw new ReglaNegocioException('Solo se envían recibos de nóminas autorizadas.', 409, ['codigo' => 'NO_AUTORIZADO']);
        }
        $recibo->loadMissing(['periodo', 'empleado']);
        $email = $recibo->empleado?->email;
        if (! $email) {
            return ['estado' => 'sin_correo'];
        }
        try {
            $pdf = $this->pdf->generar([$recibo]);
            Mail::to($email, $recibo->nombre)->send(new ReciboNominaMail($recibo, $pdf, RecibosPdf::nombreArchivo($recibo)));
            ReciboEnvio::create(['recibo_id' => $recibo->id, 'email' => $email, 'estado' => 'enviado', 'enviado_por' => $u->id]);

            return ['estado' => 'enviado', 'email' => $email];
        } catch (ReglaNegocioException $e) {
            throw $e;   // p. ej. falta dompdf: se avisa una sola vez
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 280);
            ReciboEnvio::create(['recibo_id' => $recibo->id, 'email' => $email, 'estado' => 'error', 'error' => $error, 'enviado_por' => $u->id]);

            return ['estado' => 'error', 'email' => $email, 'error' => $error];
        }
    }
}
