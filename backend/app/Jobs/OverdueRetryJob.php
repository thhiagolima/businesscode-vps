<?php

namespace App\Jobs;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Notifications\Billing\OverdueWarningNotification;
use App\Notifications\Billing\TenantSuspendedNotification;
use App\Services\MercadoPagoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OverdueRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $tenantId)
    {
        $this->onQueue('billing');
    }

    public function handle(MercadoPagoService $mp): void
    {
        DB::transaction(function () use ($mp) {
            $tenant = Tenant::lockForUpdate()->find($this->tenantId);
            if (! $tenant || ! in_array($tenant->billing_status, ['grace', 'suspended'], true)) {
                return;
            }

            $graceDays = (int) config('billing.overdue_grace_days', 7);

            // diffInDays returns a float; cast to int for inclusive day count
            $daysOverdue = $tenant->overdue_since
                ? (int) abs(now()->diffInDays($tenant->overdue_since))
                : 0;

            // Past grace window → block
            if ($daysOverdue > $graceDays) {
                $tenant->update(['billing_status' => 'blocked']);
                $tenant->notify(new TenantSuspendedNotification($tenant));
                return;
            }

            // Within grace window → retry charging the card
            if ($tenant->balance_cents < 0) {
                $debt = abs((int) $tenant->balance_cents);
                $result = $mp->chargeStoredCard($tenant, $debt, 'overdue_retry');

                if ($result['ok'] ?? false) {
                    $tenant->increment('balance_cents', $debt);
                    $tenant->refresh();
                    $mpPaymentId = $result['mp_payment_id'] ?? null;
                    $cycleKey = now('America/Sao_Paulo')->format('Ym');
                    try {
                        BalanceTransaction::create([
                            'tenant_id'           => $tenant->id,
                            'type'                => 'monthly_charge',
                            'amount_cents'        => $debt,
                            'balance_after_cents' => $tenant->balance_cents,
                            'reference_type'      => 'monthly_billing',
                            'reference_id'        => is_numeric($mpPaymentId) ? (int) $mpPaymentId : 0,
                            'monthly_cycle_key'   => $cycleKey,
                            'description'         => 'Retentativa de cobrança em atraso',
                            'meta'                => ['mp_payment_id' => $mpPaymentId, 'cycle' => $cycleKey],
                        ]);
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Same-cycle duplicate (e.g. monthly job already charged + this retry races).
                        // Roll back the balance bump and bail — the prior row already accounted for it.
                        if ($e->getCode() === '23000') {
                            Log::channel('campaign')->warning('billing.overdue_charge_already_applied', [
                                'tenant_id'         => $tenant->id,
                                'monthly_cycle_key' => $cycleKey,
                                'mp_payment_id'     => $mpPaymentId,
                            ]);
                            $tenant->decrement('balance_cents', $debt);
                            $tenant->refresh();
                            return;
                        }
                        throw $e;
                    }
                    $tenant->update([
                        'billing_status'   => 'active',
                        'overdue_since'    => null,
                        'overdue_attempts' => 0,
                    ]);
                } else {
                    $tenant->increment('overdue_attempts');
                    $tenant->notify(new OverdueWarningNotification($tenant, max(1, $daysOverdue)));
                }
            }
        });
    }
}
