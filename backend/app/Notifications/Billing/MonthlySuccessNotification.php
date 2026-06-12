<?php

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlySuccessNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public int $amountCents,
        public int $newBalanceCents,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $brand = config('business.brand_name', config('app.name'));

        return (new MailMessage)
            ->subject("Cobrança mensal realizada — {$brand}")
            ->markdown('emails.billing.monthly-success', [
                'tenant'          => $this->tenant,
                'amountCents'     => $this->amountCents,
                'newBalanceCents' => $this->newBalanceCents,
                'statementUrl'    => url('/settings/saldo'),
            ]);
    }
}
