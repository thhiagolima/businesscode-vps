<?php

namespace App\Services\Billing;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\AuditLog;
use App\Models\BalanceTransaction;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
    public function __construct(private PricingService $pricing) {}

    /**
     * Reserve cents from tenant balance. Respects credit_limit_cents (allows going negative).
     * Returns true on success, false if would exceed available funds (balance + credit_limit).
     */
    public function reserve(int $tenantId, int $amountCents, string $referenceType, int $referenceId): bool
    {
        $result = DB::transaction(function () use ($tenantId, $amountCents, $referenceType, $referenceId) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) {
                return ['ok' => false];
            }

            // Idempotency: a reserve for the same (tenant, type, reference_type, reference_id)
            // must debit exactly once even if invoked twice (retry, race, replay).
            // Required because ProcessCampaignJob may be re-dispatched on transient errors
            // and must not double-debit the tenant.
            $existing = BalanceTransaction::where('tenant_id', $tenantId)
                ->where('type', 'reserve')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                Log::channel('campaign')->info('billing.reserve.idempotent_skip', compact('tenantId', 'referenceType', 'referenceId'));
                return ['ok' => true, 'idempotent' => true];
            }

            $balanceBefore = (int) $tenant->balance_cents;
            $creditLimit   = (int) $tenant->credit_limit_cents;
            $available     = $balanceBefore + $creditLimit;
            if ($available < $amountCents) {
                return ['ok' => false];
            }

            $tenant->decrement('balance_cents', $amountCents);
            $tenant->refresh();

            BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'reserve',
                'amount_cents'        => -$amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'description'         => "Reserve {$referenceType} #{$referenceId}",
            ]);

            Log::channel('campaign')->info('billing.reserve', compact('tenantId', 'amountCents', 'referenceType', 'referenceId'));
            AuditLog::record('billing.reserve', 'Tenant', $tenantId, [
                'amount_cents'   => -$amountCents,
                'balance_after'  => (int) $tenant->balance_cents,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ], null, $tenantId);

            return [
                'ok'             => true,
                'balance_before' => $balanceBefore,
                'balance_after'  => (int) $tenant->balance_cents,
                'credit_limit'   => $creditLimit,
            ];
        });

        if (! ($result['ok'] ?? false)) {
            return false;
        }

        // Idempotent replay: nothing else to do (no double-debit, no low-balance event).
        if (! empty($result['idempotent'])) {
            return true;
        }

        // Low-balance event with 24h debounce per tenant.
        $balanceBefore = (int) ($result['balance_before'] ?? 0);
        $balanceAfter  = (int) ($result['balance_after']  ?? 0);
        $creditLimit   = (int) ($result['credit_limit']   ?? 0);

        $threshold = $creditLimit > 0
            ? (int) floor($creditLimit * 0.20)
            : 1000; // R$ 10,00

        if ($balanceBefore > $threshold && $balanceAfter <= $threshold) {
            $cacheKey = "webhook:billing.low_balance:tenant:{$tenantId}";
            if (Cache::add($cacheKey, 1, now()->addHours(24))) {
                FireOutboundWebhookJob::dispatch($tenantId, 'billing.low_balance', [
                    'balance_cents'      => $balanceAfter,
                    'credit_limit_cents' => $creditLimit,
                    'threshold_cents'    => $threshold,
                ]);
            }
        }

        return true;
    }

    public function release(int $tenantId, int $amountCents, string $referenceType, int $referenceId): void
    {
        if ($amountCents <= 0) {
            return;
        }

        DB::transaction(function () use ($tenantId, $amountCents, $referenceType, $referenceId) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) {
                return;
            }

            $tenant->increment('balance_cents', $amountCents);
            $tenant->refresh();

            BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'release',
                'amount_cents'        => $amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'description'         => "Release {$referenceType} #{$referenceId}",
            ]);

            Log::channel('campaign')->info('billing.release', compact('tenantId', 'amountCents', 'referenceType', 'referenceId'));
            AuditLog::record('billing.release', 'Tenant', $tenantId, [
                'amount_cents'   => $amountCents,
                'balance_after'  => (int) $tenant->balance_cents,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ], null, $tenantId);
        });
    }

    public function recharge(int $tenantId, int $amountCents, string $referenceType, int $referenceId, string $description = ''): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("recharge() requires positive amount_cents, got {$amountCents}");
        }
        $balanceAfter = DB::transaction(function () use ($tenantId, $amountCents, $referenceType, $referenceId, $description) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) {
                return null;
            }

            // Idempotency: a recharge for the same (tenant, type, reference_type, reference_id)
            // must credit exactly once, regardless of how many times this method is invoked.
            // Required because PaymentController::credits (synchronous) and the MercadoPago
            // webhook can both fire recharge for the same Payment row (P0-05 double-credit).
            $existing = BalanceTransaction::where('tenant_id', $tenantId)
                ->where('type', 'recharge')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                Log::channel('campaign')->info('billing.recharge.idempotent_skip', compact('tenantId', 'referenceType', 'referenceId'));
                return null;
            }

            $tenant->increment('balance_cents', $amountCents);
            $tenant->refresh();

            BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'recharge',
                'amount_cents'        => $amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'description'         => $description !== '' ? $description : "Recharge {$referenceType} #{$referenceId}",
            ]);

            Log::channel('campaign')->info('billing.recharge', compact('tenantId', 'amountCents', 'referenceType', 'referenceId'));
            AuditLog::record('billing.recharge', 'Tenant', $tenantId, [
                'amount_cents'   => $amountCents,
                'balance_after'  => (int) $tenant->balance_cents,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ], null, $tenantId);

            return (int) $tenant->balance_cents;
        });

        if ($balanceAfter === null) {
            return;
        }

        // Reset low-balance debounce so future drops fire the event again.
        Cache::forget("webhook:billing.low_balance:tenant:{$tenantId}");

        FireOutboundWebhookJob::dispatch($tenantId, 'billing.recharged', [
            'amount_cents'        => $amountCents,
            'balance_after_cents' => $balanceAfter,
            'reference_type'      => $referenceType,
            'reference_id'        => $referenceId,
        ]);
    }

    public function manualAdjustment(int $tenantId, int $amountCents, string $reason, int $adminUserId): BalanceTransaction
    {
        $tx = DB::transaction(function () use ($tenantId, $amountCents, $reason, $adminUserId) {
            $tenant = Tenant::lockForUpdate()->findOrFail($tenantId);
            $tenant->increment('balance_cents', $amountCents);
            $tenant->refresh();

            // reference_id precisa ser único por transação manual para não
            // colidir com bt_idempotency_unique. O admin que executou fica em
            // executed_by_user_id (coluna dedicada). Combinamos microtime (μs)
            // × 1000 + jitter de 3 dígitos — chance de colisão desprezível
            // (lockForUpdate já serializa chamadas no mesmo tenant).
            $uniqueRefId = (int) (microtime(true) * 1_000_000) * 1_000 + random_int(0, 999);

            $tx = BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'manual_adjustment',
                'amount_cents'        => $amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => 'admin_action',
                'reference_id'        => $uniqueRefId,
                'executed_by_user_id' => $adminUserId,
                'description'         => $reason,
            ]);

            Log::channel('campaign')->info('billing.manual_adjustment', compact('tenantId', 'amountCents', 'reason', 'adminUserId'));
            AuditLog::record('billing.manual_adjustment', 'Tenant', $tenantId, [
                'amount_cents'  => $amountCents,
                'balance_after' => (int) $tenant->balance_cents,
                'reason'        => $reason,
            ], $adminUserId, $tenantId);
            return $tx;
        });

        if ($amountCents < 0) {
            FireOutboundWebhookJob::dispatch($tenantId, 'billing.charged', [
                'amount_cents'        => $amountCents,
                'balance_after_cents' => (int) $tx->balance_after_cents,
                'reason'              => $reason,
            ]);
        }

        return $tx;
    }

    /**
     * Estimate if a campaign can be dispatched given current tenant funds.
     * Returns an info array used by controllers and the scheduled-dispatch command.
     */
    public function lockCampaignIfInsufficient(Campaign $campaign): array
    {
        $tenant = Tenant::withoutGlobalScopes()->find($campaign->tenant_id);
        if (! $tenant) {
            return [
                'locked'         => true,
                'required'       => 0,
                'available'      => 0,
                'unit_cents'     => 0,
                'contacts'       => 0,
                'possible_sends' => 0,
                'missing_cents'  => 0,
            ];
        }

        $unitCents = (int) ($this->pricing->priceFor($tenant, (string) $campaign->type)['sale_cents'] ?? 0);

        $contacts = (int) $campaign->estimated_contacts;
        if ($contacts === 0 && $campaign->contact_list_id) {
            $contacts = Contact::withoutGlobalScopes()
                ->where('contact_list_id', $campaign->contact_list_id)
                ->count();
        }
        $contacts = max(0, $contacts);

        $required  = $unitCents * $contacts;
        $available = $tenant->availableBalanceCents();
        $possible  = $unitCents > 0 ? intdiv(max(0, $available), $unitCents) : 0;

        return [
            'locked'         => $available < $required,
            'required'       => $required,
            'available'      => $available,
            'unit_cents'     => $unitCents,
            'contacts'       => $contacts,
            'possible_sends' => $possible,
            'missing_cents'  => max(0, $required - $available),
        ];
    }
}
