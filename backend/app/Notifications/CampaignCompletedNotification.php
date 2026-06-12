<?php

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class CampaignCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(public Campaign $campaign) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $c = $this->campaign;
        $status = $c->status === 'completed' ? 'concluída' : 'falhou';

        return (new MailMessage)
            ->subject("Campanha {$status}: {$c->name}")
            ->greeting("Olá, {$notifiable->name}!")
            ->line("Sua campanha \"{$c->name}\" foi {$status}.")
            ->line("Enviados: {$c->sent_count} | Falhas: {$c->failed_count}")
            ->action('Ver detalhes', url("/campaigns/{$c->id}"))
            ->line('BusinessCode - Sua plataforma de campanhas');
    }
}
