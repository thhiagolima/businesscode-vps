<?php

namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantSuspendedNotification extends Notification
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
            ->subject("Sua conta foi suspensa — {$brand}")
            ->markdown('emails.billing.tenant-suspended', [
                'tenant'       => $this->tenant,
                'statementUrl' => url('/settings/saldo'),
            ]);
    }
}
