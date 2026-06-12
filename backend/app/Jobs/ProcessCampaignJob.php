<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Services\Billing\BillingService;
use App\Services\Billing\PricingService;
use App\Services\CampaignStateMachine;
use App\Services\Messaging\QuietHoursService;
use App\Services\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class ProcessCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300; // Coordinator only — no longer needs 1 hour

    public function __construct(
        public int $campaignId,
        public int $tenantId
    ) {
        $this->onConnection('campaigns')->onQueue('campaigns');
    }

    public function middleware(): array
    {
        return [
            new \App\Jobs\Middleware\SetTenantContext($this->tenantId),
            // Prevents two ProcessCampaignJob workers running for the same campaign concurrently.
            // dontRelease() = if another worker holds the lock, just drop this attempt (the running one is enough).
            (new WithoutOverlapping("campaign:{$this->campaignId}"))->expireAfter(600)->dontRelease(),
        ];
    }

    public function handle(
        CampaignStateMachine $machine,
        BillingService $billing,
        PricingService $pricing,
        SettingsService $settings
    ): void {
        $campaign = Campaign::withoutGlobalScopes()
            ->where('id', $this->campaignId)
            ->where('tenant_id', $this->tenantId)
            ->firstOrFail();

        // LGPD / Procon compliance: never dispatch outside the tenant's allowed window.
        // Reschedule the campaign to the next valid time instead of running now.
        $tenantModel = \App\Models\Tenant::withoutGlobalScopes()->find($this->tenantId);
        if ($tenantModel) {
            $quietHours = app(QuietHoursService::class);
            if ($quietHours->isQuietHour($tenantModel)) {
                $nextValid = $quietHours->nextValidTime($tenantModel);
                Log::channel('campaign')->info('campaign.dispatch.quiet_hours_deferred', [
                    'campaign_id' => $campaign->id,
                    'reschedule_to' => $nextValid->toIso8601String(),
                ]);
                // Move back to a state that allows scheduling.
                if ($campaign->status === 'processing') {
                    $machine->transition($campaign, 'draft');
                }
                $campaign->update(['scheduled_at' => $nextValid]);
                $machine->transition($campaign, 'scheduled');
                return;
            }
        }

        // Idempotent transition to running. If the job is re-run after a transient failure,
        // do NOT reset counters that may already reflect sends in flight from the prior run.
        if ($campaign->status !== 'running') {
            $machine->transition($campaign, 'running');
            $campaign->update(['sent_count' => 0, 'failed_count' => 0]);
        }

        $campaignSettings = $campaign->settings ?? [];
        $adhocPhones = $campaignSettings['adhoc_phones'] ?? [];
        $batchJobs = [];
        $chunkSize = 100;
        $contactCount = 0;

        if (!empty($adhocPhones)) {
            // Split adhoc phones into chunks
            $contactCount = count($adhocPhones);
            foreach (array_chunk($adhocPhones, $chunkSize) as $chunk) {
                $batchJobs[] = new SendCampaignBatchJob(
                    $this->campaignId, $this->tenantId, [], $chunk
                );
            }
        } else {
            // Split contact list into chunks of IDs
            $contactIds = Contact::withoutGlobalScopes()
                ->where('tenant_id', $this->tenantId)
                ->where('contact_list_id', $campaign->contact_list_id)
                ->where('status', 'active')
                ->pluck('id')
                ->toArray();

            $contactCount = count($contactIds);
            foreach (array_chunk($contactIds, $chunkSize) as $chunk) {
                $batchJobs[] = new SendCampaignBatchJob(
                    $this->campaignId, $this->tenantId, $chunk, []
                );
            }
        }

        if (empty($batchJobs)) {
            $machine->transition($campaign, 'completed');
            return;
        }

        // PRE-DEBIT: reserve the full estimated cost BEFORE dispatching batches.
        // - Idempotent: reserve() skips if already debited for this campaign (retry-safe).
        // - Insufficient funds: campaign transitions to 'failed', no batch is dispatched,
        //   no message is sent (no provider cost, no compliance issue).
        // - Snapshot of unit_cents is persisted in settings so admin price changes during
        //   the batch don't affect this campaign's cost.
        $tenant    = \App\Models\Tenant::withoutGlobalScopes()->find($this->tenantId);
        $unitCents = $tenant ? (int) ($pricing->priceFor($tenant, (string) $campaign->type)['sale_cents'] ?? 0) : 0;
        $reserveCents = $unitCents * $contactCount;

        if ($reserveCents > 0) {
            $ok = $billing->reserve(
                $campaign->tenant_id,
                $reserveCents,
                'campaign_dispatch',
                $campaign->id
            );
            if (! $ok) {
                Log::channel('campaign')->warning('campaign.dispatch.insufficient_funds', [
                    'campaign_id'   => $campaign->id,
                    'required'      => $reserveCents,
                ]);
                $machine->transition($campaign, 'failed');
                return;
            }
        }

        // Persist pricing snapshot so .then()/.catch() callbacks see a stable unit cost.
        // Stored both as indexable columns (preferred for reports/audit) and inside
        // settings JSON for backward compatibility with code paths that still read from it.
        $campaign->forceFill([
            'unit_cents_at_dispatch' => $unitCents,
            'reserved_cents'         => $reserveCents,
            'settings' => array_merge($campaignSettings, [
                'unit_cents_at_dispatch' => $unitCents,
                'reserved_cents'         => $reserveCents,
            ]),
        ])->save();

        // Dispatch batch
        $batch = Bus::batch($batchJobs)
            ->name("campaign-{$this->campaignId}")
            ->onQueue('campaigns')
            ->then(function () use ($campaign, $machine, $billing) {
                // P0R-07: refund calculation under pessimistic lock + idempotency key.
                // Antes: lia sent_count fora de transação — race entre 2 callbacks
                // (cancel + then ou retry de batch) podia liberar 2x.
                // Agora: tudo numa transação curta, com refund_done flag em settings.
                \Illuminate\Support\Facades\DB::transaction(function () use ($campaign, $billing) {
                    $fresh = \App\Models\Campaign::withoutGlobalScopes()
                        ->where('id', $campaign->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $fresh) return;
                    $snapshot = $fresh->settings ?? [];
                    if (! empty($snapshot['refund_done'])) {
                        return; // refund já feito por outra rota (cancel/catch)
                    }
                    $unitCents = (int) ($fresh->unit_cents_at_dispatch ?? $snapshot['unit_cents_at_dispatch'] ?? 0);
                    $reserved  = (int) ($fresh->reserved_cents ?? $snapshot['reserved_cents'] ?? 0);
                    $consumed  = $unitCents * max(0, (int) $fresh->sent_count);
                    $refund    = max(0, $reserved - $consumed);
                    if ($refund > 0) {
                        $billing->release(
                            $fresh->tenant_id,
                            $refund,
                            'campaign_dispatch_refund',
                            $fresh->id
                        );
                    }
                    $fresh->forceFill([
                        'settings' => array_merge($snapshot, ['refund_done' => true]),
                    ])->save();
                });
                $campaign->refresh();
                $machine->transition($campaign, 'completed');

                // Notifications
                try {
                    $admin = \App\Models\User::where('tenant_id', $campaign->tenant_id)->first();
                    if ($admin) {
                        $admin->notify(new \App\Notifications\CampaignCompletedNotification($campaign));

                        $balanceCents = (int) ($campaign->tenant?->balance_cents ?? 0);
                        if ($balanceCents > 0 && $balanceCents < 10000) {
                            $admin->notify(new \App\Notifications\LowCreditsNotification($balanceCents));
                        }
                    }
                } catch (\Throwable $e) {
                    Log::channel('campaign')->warning('notification.failed', ['error' => $e->getMessage()]);
                }
            })
            ->catch(function (\Throwable $e) use ($campaign, $machine, $billing) {
                // P0R-07: mesma lógica idempotente sob lock do then().
                \Illuminate\Support\Facades\DB::transaction(function () use ($campaign, $billing) {
                    $fresh = \App\Models\Campaign::withoutGlobalScopes()
                        ->where('id', $campaign->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $fresh) return;
                    $snapshot = $fresh->settings ?? [];
                    if (! empty($snapshot['refund_done'])) return;
                    $unitCents = (int) ($fresh->unit_cents_at_dispatch ?? $snapshot['unit_cents_at_dispatch'] ?? 0);
                    $reserved  = (int) ($fresh->reserved_cents ?? $snapshot['reserved_cents'] ?? 0);
                    $consumed  = $unitCents * max(0, (int) $fresh->sent_count);
                    $refund    = max(0, $reserved - $consumed);
                    if ($refund > 0) {
                        $billing->release(
                            $fresh->tenant_id,
                            $refund,
                            'campaign_dispatch_refund',
                            $fresh->id
                        );
                    }
                    $fresh->forceFill([
                        'settings' => array_merge($snapshot, ['refund_done' => true]),
                    ])->save();
                });
                $campaign->refresh();
                $machine->transition($campaign, 'failed');
                Log::channel('campaign')->error('campaign.batch.failed', [
                    'campaign_id' => $campaign->id, 'error' => $e->getMessage(),
                ]);
            })
            ->dispatch();

        Log::channel('campaign')->info('campaign.batch.dispatched', [
            'campaign_id' => $campaign->id,
            'batch_id'    => $batch->id,
            'jobs_count'  => count($batchJobs),
            'reserved'    => $reserveCents,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('campaign')->error('campaign.failed', [
            'campaign_id' => $this->campaignId,
            'error'       => $e->getMessage(),
        ]);
        Campaign::withoutGlobalScopes()
            ->where('id', $this->campaignId)
            ->update(['status' => 'failed']);
    }
}
