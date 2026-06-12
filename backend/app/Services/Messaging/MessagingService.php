<?php

namespace App\Services\Messaging;

use App\Exceptions\Billing\BillingBlockedException;
use App\Exceptions\Billing\BillingSuspendedException;
use App\Exceptions\Billing\InsufficientFundsException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Jobs\FireOutboundWebhookJob;
use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Billing\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessagingService
{
    public function __construct(
        private OptOutService      $optOuts,
        private QuietHoursService  $quiet,
        private PricingService     $pricing,
        private IdempotencyService $idempotency,
        private BillingService     $billing,
    ) {}

    public function dispatch(
        Tenant $tenant,
        ?User $user,
        string $channel,
        array $payload,
        ?string $idempotencyKey,
        string $quietHoursStrategy = 'reject'
    ): MessageDispatch {
        $to = $channel === 'email'
            ? PhoneNormalizer::emailLower($payload['to'])
            : PhoneNormalizer::e164($payload['to']);
        $payload['to'] = $to;

        if ($hit = $this->idempotency->lookup($tenant->id, $idempotencyKey, $payload)) {
            return $hit;
        }

        if ($this->optOuts->isOptedOut($tenant->id, $channel, $to)) {
            $rejected = MessageDispatch::withoutGlobalScopes()->create([
                'tenant_id'                => $tenant->id,
                'user_id'                  => $user?->id,
                'channel'                  => $channel,
                'source'                   => $payload['source'] ?? 'api',
                'to'                       => $to,
                'content'                  => $payload['content'] ?? '',
                'subject'                  => $payload['subject'] ?? null,
                'from'                     => $payload['from'] ?? null,
                'provider'                 => $this->providerFor($channel, $payload),
                'status'                   => 'rejected_opt_out',
                'cost_cents'               => 0,
                'sale_cents'               => 0,
                'charged_cents'            => 0,
                'idempotency_key'          => $idempotencyKey,
                'idempotency_payload_hash' => $idempotencyKey ? $this->idempotency->hashPayload($payload) : null,
            ]);
            FireOutboundWebhookJob::dispatch($tenant->id, 'message.rejected_opt_out', $this->buildEventPayload($rejected));
            throw new RecipientOptedOutException();
        }

        $scheduledFor = null;
        if ($this->quiet->isQuietHour($tenant)) {
            if ($quietHoursStrategy === 'reject') {
                MessageDispatch::withoutGlobalScopes()->create([
                    'tenant_id'                => $tenant->id,
                    'user_id'                  => $user?->id,
                    'channel'                  => $channel,
                    'source'                   => $payload['source'] ?? 'api',
                    'to'                       => $to,
                    'content'                  => $payload['content'] ?? '',
                    'subject'                  => $payload['subject'] ?? null,
                    'from'                     => $payload['from'] ?? null,
                    'provider'                 => $this->providerFor($channel, $payload),
                    'status'                   => 'rejected_quiet_hours',
                    'cost_cents'               => 0,
                    'sale_cents'               => 0,
                    'charged_cents'            => 0,
                    'idempotency_key'          => $idempotencyKey,
                    'idempotency_payload_hash' => $idempotencyKey ? $this->idempotency->hashPayload($payload) : null,
                ]);
                throw new QuietHoursException();
            }
            $scheduledFor = $this->quiet->nextValidTime($tenant);
        }

        // Block by billing status before doing any pricing/dispatch work
        if ($tenant->billing_status === 'suspended') {
            throw new BillingSuspendedException();
        }
        if ($tenant->billing_status === 'blocked') {
            throw new BillingBlockedException();
        }

        $price      = $this->pricing->priceFor($tenant, $channel);
        $costCents  = (int) $price['cost_cents'];
        $saleCents  = (int) $price['sale_cents'];
        $costMicros = (int) ($price['cost_micros'] ?? $costCents * 1000);
        $saleMicros = (int) ($price['sale_micros'] ?? $saleCents * 1000);
        $provider   = $this->providerFor($channel, $payload);

        return DB::transaction(function () use ($tenant, $user, $channel, $payload, $idempotencyKey, $costCents, $saleCents, $costMicros, $saleMicros, $provider, $scheduledFor) {
            $dispatch = MessageDispatch::withoutGlobalScopes()->create([
                'tenant_id'                => $tenant->id,
                'user_id'                  => $user?->id,
                'channel'                  => $channel,
                'source'                   => $payload['source'] ?? 'api',
                'to'                       => $payload['to'],
                'from'                     => $payload['from'] ?? null,
                'from_name'                => $payload['from_name'] ?? null,
                'reply_to'                 => $payload['reply_to'] ?? null,
                'subject'                  => $payload['subject'] ?? null,
                'content'                  => $payload['content'] ?? '',
                'audio_url'                => $payload['audio_url'] ?? null,
                'provider'                 => $provider,
                'status'                   => 'queued',
                'cost_cents'               => $costCents,
                'sale_cents'               => $saleCents,
                'cost_micros'              => $costMicros,
                'sale_micros'              => $saleMicros,
                'charged_cents'            => 0,
                'idempotency_key'          => $idempotencyKey,
                'idempotency_payload_hash' => $idempotencyKey ? $this->idempotency->hashPayload($payload) : null,
                'unsubscribe_token'        => $channel === 'email' ? Str::random(64) : null,
                'scheduled_for'            => $scheduledFor,
                'meta'                     => $payload['meta'] ?? null,
                // Persist the variables map sent by the caller (substitution
                // already applied to subject/content by the controller).
                'variables'                => !empty($payload['variables']) ? $payload['variables'] : null,
            ]);

            $ok = $this->billing->reserve($tenant->id, $saleCents, 'message_dispatch', $dispatch->id);
            if (! $ok) {
                $fresh = $tenant->fresh();
                $available = $fresh ? (int) $fresh->availableBalanceCents() : 0;
                throw new InsufficientFundsException($saleCents, $available);
            }

            SendMessageJob::dispatch($dispatch->id)->onQueue('messaging');

            FireOutboundWebhookJob::dispatch($tenant->id, 'message.queued', $this->buildEventPayload($dispatch));

            return $dispatch;
        });
    }

    public function buildEventPayload(MessageDispatch $d): array
    {
        $payload = [
            'dispatch_id'      => $d->id,
            'channel'          => $d->channel,
            'to'               => $d->to,
            'status'           => $d->status,
            'sale_cents'       => (int) $d->sale_cents,
            'idempotency_key'  => $d->idempotency_key,
            'created_at'       => optional($d->created_at)->toIso8601String(),
            'meta'             => $d->meta,
        ];

        // Voice-specific call details (populated by Infobip delivery webhook).
        // Only included for voice channel; null on other channels.
        if ($d->channel === 'voice') {
            $payload['call'] = [
                'voice_status'           => $d->voice_status,
                'answered_at'            => optional($d->answered_at)->toIso8601String(),
                'ended_at'               => optional($d->ended_at)->toIso8601String(),
                'duration_seconds'       => $d->call_duration_seconds,
            ];
        }

        // Email engagement details (populated by Infobip email tracking webhook).
        // Only included for email channel; null on other channels.
        if ($d->channel === 'email') {
            $payload['email'] = [
                'opened_at'        => optional($d->opened_at)->toIso8601String(),
                'first_clicked_at' => optional($d->first_clicked_at)->toIso8601String(),
                'bounced_at'       => optional($d->bounced_at)->toIso8601String(),
                'bounce_type'      => $d->bounce_type,
                'complaint_at'     => optional($d->complaint_at)->toIso8601String(),
                'unsubscribed_at'  => optional($d->unsubscribed_at)->toIso8601String(),
            ];
        }

        return $payload;
    }

    private function providerFor(string $channel, array $payload): string
    {
        if ($channel === 'email' && ($payload['source'] ?? null) === 'transactional') {
            return 'laravel_mail';
        }
        return 'infobip';
    }
}
