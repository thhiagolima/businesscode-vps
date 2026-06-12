<?php

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CardMissingNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public int $amountCents,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $brand = config('business.brand_name', config('app.name'));

        return (new MailMessage)
            ->subject("Cadastre um cartão para cobrança mensal — {$brand}")
            ->markdown('emails.billing.card-missing', [
                'tenant'       => $this->tenant,
                'amountCents'  => $this->amountCents,
                'statementUrl' => url('/settings/saldo'),
            ]);
    }
}
