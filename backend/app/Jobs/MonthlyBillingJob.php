<?php

namespace App\Jobs;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Notifications\Billing\CardMissingNotification;
use App\Notifications\Billing\MonthlySuccessNotification;
use App\Notifications\Billing\OverdueWarningNotification;
use App\Services\MercadoPagoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonthlyBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 600, 3600];

    public function __construct(public int $tenantId)
    {
        $this->onQueue('billing');
    }

    public function handle(MercadoPagoService $mp): void
    {
        $statusChange = DB::transaction(function () use ($mp) {
            $tenant = Tenant::lockForUpdate()->find($this->tenantId);
            if (! $tenant) {
                return null;
            }
            $previousStatus = $tenant->billing_status;

            // 1) Credit included balance from plan
            $plan = $tenant->plan;
            if ($plan && (int) $plan->included_balance_cents > 0) {
                $included = (int) $plan->included_balance_cents;
                $tenant->increment('balance_cents', $included);
                $tenant->refresh();
                BalanceTransaction::create([
                    'tenant_id'           => $tenant->id,
                    'type'                => 'credit',
                    'amount_cents'        => $included,
                    'balance_after_cents' => $tenant->balance_cents,
                    'reference_type'      => 'monthly_plan_credit',
                    'reference_id'        => $plan->id,
                    'description'         => 'Crédito mensal do plano',
                ]);
            }

            // 2) If still negative, charge stored card
            if ($tenant->balance_cents < 0) {
                $debt = abs((int) $tenant->balance_cents);

                if (! $tenant->mp_customer_id || ! $tenant->mp_default_card_id) {
                    $tenant->notify(new CardMissingNotification($tenant, $debt));
                    Log::channel('campaign')->warning('billing.no_card', [
                        'tenant_id' => $tenant->id,
                        'debt_cents' => $debt,
                    ]);
                    $tenant->update(['last_billing_at' => now()]);
                    return;
                }

                $result = $mp->chargeStoredCard($tenant, $debt, 'monthly_overdue');

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
                            'description'         => 'Cobrança mensal automática',
                            'meta'                => ['mp_payment_id' => $mpPaymentId, 'cycle' => $cycleKey],
                        ]);
                    } catch (\Illuminate\Database\QueryException $e) {
                        // SQLSTATE 23000 = integrity constraint violation (duplicate UNIQUE).
                        // Means the (tenant, monthly_billing, YYYYMM) row already exists →
                        // a previous run of this cycle already applied the charge. Skip silently.
                        if ($e->getCode() === '23000') {
                            Log::channel('campaign')->warning('billing.monthly_charge_already_applied', [
                                'tenant_id'         => $tenant->id,
                                'monthly_cycle_key' => $cycleKey,
                                'mp_payment_id'     => $mpPaymentId,
                            ]);
                            // Rollback the balance increment we just did, since the original
                            // charge already credited it.
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
                    $tenant->notify(new MonthlySuccessNotification($tenant, $debt, $tenant->balance_cents));
                } else {
                    $tenant->update([
                        'billing_status'   => 'grace',
                        'overdue_since'    => now(),
                        'overdue_attempts' => 1,
                    ]);
                    $tenant->notify(new OverdueWarningNotification($tenant, 1));
                }
            }

            $tenant->update(['last_billing_at' => now()]);

            $tenant->refresh();
            return [
                'tenant_id' => $tenant->id,
                'previous'  => $previousStatus,
                'current'   => $tenant->billing_status,
            ];
        });

        if (! $statusChange) {
            return;
        }

        $previous = $statusChange['previous'];
        $current  = $statusChange['current'];

        if ($previous === $current) {
            return;
        }

        if (in_array($current, ['grace', 'suspended', 'blocked'], true)) {
            FireOutboundWebhookJob::dispatch($statusChange['tenant_id'], 'billing.suspended', [
                'previous_status' => $previous,
                'current_status'  => $current,
            ]);
        } elseif ($current === 'active' && in_array($previous, ['grace', 'suspended', 'blocked'], true)) {
            FireOutboundWebhookJob::dispatch($statusChange['tenant_id'], 'billing.reactivated', [
                'previous_status' => $previous,
                'current_status'  => $current,
            ]);
        }
    }
}
