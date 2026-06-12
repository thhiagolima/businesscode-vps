<?php

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OverdueWarningNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public int $dayOfGrace,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $brand = config('business.brand_name', config('app.name'));

        $template = match (true) {
            $this->dayOfGrace <= 1 => 'emails.billing.overdue-warning-day1',
            $this->dayOfGrace >= 7 => 'emails.billing.overdue-final',
            default                => 'emails.billing.overdue-warning-retry',
        };

        $subject = match (true) {
            $this->dayOfGrace <= 1 => "Não conseguimos cobrar seu cartão — {$brand}",
            $this->dayOfGrace >= 7 => "Último aviso antes da suspensão — {$brand}",
            default                => "Nova tentativa de cobrança falhou — {$brand}",
        };

        $amountCents = max(0, -1 * (int) $this->tenant->balance_cents);

        return (new MailMessage)
            ->subject($subject)
            ->markdown($template, [
                'tenant'       => $this->tenant,
                'amountCents'  => $amountCents,
                'dayOfGrace'   => $this->dayOfGrace,
                'statementUrl' => url('/settings/saldo'),
            ]);
    }
}
