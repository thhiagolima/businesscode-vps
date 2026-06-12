<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class LowCreditsNotification extends Notification
{
    use Queueable;

    public function __construct(public int $balance) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Créditos baixos - BusinessCode')
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Seu saldo de créditos está em **{$this->balance}**.")
            ->line('Adicione mais créditos para continuar enviando campanhas.')
            ->action('Gerenciar créditos', url('/reports/credits'))
            ->line('BusinessCode - Sua plataforma de campanhas');
    }
}
