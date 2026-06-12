<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PersonaApprovedNotification extends Notification
{
    use Queueable;

    public function __construct(public string $status, public ?string $reason = null) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->greeting("Olá, {$notifiable->name}!");

        if ($this->status === 'approved') {
            $mail->subject('Persona IA aprovada - BusinessCode')
                ->line('Sua persona de chatbot foi **aprovada** pelo administrador!')
                ->line('Agora você pode ativar o chatbot nas configurações.')
                ->action('Configurar chatbot', url('/chatbot/settings'));
        } else {
            $mail->subject('Persona IA rejeitada - BusinessCode')
                ->line('Sua persona de chatbot foi **rejeitada** pelo administrador.')
                ->line("Motivo: {$this->reason}")
                ->line('Ajuste as configurações e envie novamente para aprovação.')
                ->action('Editar persona', url('/chatbot/settings'));
        }

        return $mail->line('BusinessCode - Sua plataforma de campanhas');
    }
}
