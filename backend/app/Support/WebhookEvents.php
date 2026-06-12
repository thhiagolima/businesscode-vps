<?php
namespace App\Support;

class WebhookEvents
{
    public const ALL = [
        'message.queued',
        'message.sent',
        'message.delivered',
        'message.failed',
        'message.rejected_opt_out',
        // Voice call lifecycle (channel = voice)
        'call.answered',
        'call.completed',
        'call.failed',
        // Email engagement (channel = email)
        'email.opened',
        'email.clicked',
        'email.bounced',
        'email.complaint',
        'email.unsubscribed',
        'billing.recharged',
        'billing.charged',
        'billing.low_balance',
        'billing.suspended',
        'billing.reactivated',
        'optout.added',
        'optout.removed',
        'webhook.test', // special event used by the Test button
    ];

    public static function isValid(string $event): bool
    {
        return in_array($event, self::ALL, true);
    }
}
