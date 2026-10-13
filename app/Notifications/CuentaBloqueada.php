<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso al dueño de la cuenta cuando se bloquea por intentos fallidos.
 * Si no fue él, sabe que alguien intenta entrar; si fue él, tiene el camino para recuperarla.
 */
class CuentaBloqueada extends Notification
{
    use Queueable;

    public function __construct(private readonly string $hasta, private readonly ?string $ip) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Tu cuenta se bloqueó temporalmente')
            ->greeting('Hola '.$notifiable->name)
            ->line("Detectamos varios intentos fallidos de inicio de sesión. Por seguridad, tu cuenta quedó bloqueada hasta las {$this->hasta}.")
            ->line('Origen del último intento: '.($this->ip ?? 'desconocido').'.')
            ->line('Si fuiste tú, espera a que termine el bloqueo o restablece tu contraseña (eso también desbloquea la cuenta).')
            ->action('Restablecer contraseña', config('app.frontend_url').'/olvide')
            ->line('Si no fuiste tú, avisa al Administrador (RH).');
    }
}
