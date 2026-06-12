<?php

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CardExpiredNotification extends Notification
{
    use Queueable;

    public function __construct(public Tenant $tenant) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $brand = config('business.brand_name', config('app.name'));

        return (new MailMessage)
            ->subject("Seu cartão expirou ou foi recusado — {$brand}")
            ->markdown('emails.billing.card-expired', [
                'tenant'       => $this->tenant,
                'statementUrl' => url('/settings/saldo'),
            ]);
    }
}
