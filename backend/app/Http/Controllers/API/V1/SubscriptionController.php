<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSubscriptionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function __construct(
        private MercadoPagoService $mpService,
        private BillingService $billing,
    ) {}

    public function store(CreateSubscriptionRequest $request)
    {
        $plan = Plan::findOrFail($request->plan_id);
        $tenant = $request->user()->tenant;
        $billingCycle = $request->input('billing_cycle', 'monthly');

        if (!$tenant) {
            return ApiResponse::error('Tenant não encontrado.', [], 404);
        }

        $pendingTimeoutHours = (int) config('business.subscription_pending_timeout_hours', 24);

        // Pending subscriptions older than the timeout are considered abandoned and do not block retry.
        $existing = Subscription::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($pendingTimeoutHours) {
                $q->whereIn('status', ['active', 'authorized'])
                  ->orWhere(function ($q2) use ($pendingTimeoutHours) {
                      $q2->where('status', 'pending')
                         ->where('created_at', '>=', now()->subHours($pendingTimeoutHours));
                  });
            })
            ->first();

        if ($existing) {
            return ApiResponse::error('Já existe uma assinatura ativa. Cancele antes de assinar outro plano.', [], 422);
        }

        // Auto-expire abandoned pending subscriptions so they stop blocking the tenant.
        Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subHours($pendingTimeoutHours))
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $coupon = null;
        if ($request->coupon_code) {
            $coupon = Coupon::where('code', strtoupper($request->coupon_code))->lockForUpdate()->first();
            if (!$coupon || !$coupon->isValid()) {
                return ApiResponse::error('Cupom inválido ou expirado.', [], 422);
            }

            // Check per-tenant usage
            $alreadyUsed = DB::table('coupon_usages')
                ->where('coupon_id', $coupon->id)
                ->where('tenant_id', $tenant->id)
                ->exists();
            if ($alreadyUsed) {
                return ApiResponse::error('Este cupom já foi utilizado pela sua conta.', [], 422);
            }
        }

        try {
            $result = $this->mpService->createSubscription($plan, $request->card_token, $request->payer_email, $coupon, $billingCycle);

            $subscription = DB::transaction(function () use ($tenant, $plan, $result, $coupon, $billingCycle) {
                if ($coupon) {
                    $coupon->increment('times_used');
                    DB::table('coupon_usages')->insert([
                        'coupon_id' => $coupon->id,
                        'tenant_id' => $tenant->id,
                        'created_at' => now(),
                    ]);
                }

                $periodEnd = $billingCycle === 'annual' ? now()->addYear() : now()->addMonth();

                return Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => $billingCycle,
                    'mp_subscription_id' => $result['mp_subscription_id'],
                    'mp_payer_id' => $result['mp_payer_id'],
                    'status' => $result['status'] === 'authorized' ? 'active' : 'pending',
                    'payment_method' => 'credit_card',
                    'current_period_start' => now(),
                    'current_period_end' => $periodEnd,
                    'price' => $result['price'],
                    'discount_amount' => $result['discount'],
                    'coupon_id' => $coupon?->id,
                ]);
            });

            if ($subscription->status === 'active') {
                $tenant->update(['plan_id' => $plan->id, 'status' => 'active']);

                $includedCents = (int) ($plan->included_balance_cents ?? 0);
                if ($includedCents > 0) {
                    $this->billing->recharge(
                        $tenant->id,
                        $includedCents,
                        'subscription',
                        $subscription->id,
                        "Saldo incluído no plano {$plan->name}"
                    );
                }

                // Optional: save card for future monthly debit of overage.
                // Defensive — never let MP errors here fail the checkout.
                if ((bool) $request->input('save_card_for_billing', false)) {
                    try {
                        // NOTE: a preapproval's `payer_id` is NOT a Customers-API customer_id
                        // and cannot be used with /v1/customers/{id}/cards. Always resolve a
                        // real customer via the Customers API before saving the card.
                        $customerId = $tenant->mp_customer_id
                            ?: $this->mpService->findOrCreateCustomer($request->payer_email);

                        $cardId = $customerId
                            ? $this->mpService->saveCardOnCustomer($customerId, $request->card_token)
                            : null;

                        if ($customerId && $cardId) {
                            $tenant->update([
                                'mp_customer_id'     => $customerId,
                                'mp_default_card_id' => $cardId,
                            ]);
                        } else {
                            Log::warning('[MercadoPago] save_card_for_billing requested but MP did not return reusable card data', [
                                'tenant_id'   => $tenant->id,
                                'customer_id' => $customerId,
                                'card_id'     => $cardId,
                            ]);
                        }
                    } catch (\Throwable $cardError) {
                        Log::warning('[MercadoPago] save_card_for_billing failed (ignored)', [
                            'tenant_id' => $tenant->id,
                            'error'     => $cardError->getMessage(),
                        ]);
                    }
                }
            }

            \App\Models\AuditLog::record('subscription.created', 'Subscription', $subscription->id, [
                'plan_id'       => $plan->id,
                'billing_cycle' => $billingCycle,
                'status'        => $subscription->status,
                'coupon_id'     => $coupon?->id,
            ], null, $tenant->id);

            return ApiResponse::success($subscription->load('plan'), 'Assinatura criada com sucesso.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] subscription creation failed', [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
            \App\Models\AuditLog::record('subscription.creation_failed', 'Plan', $plan->id, [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ], null, $tenant->id);
            return ApiResponse::error('Erro ao processar pagamento. Tente novamente.', [], 500);
        }
    }

    public function current()
    {
        $tenant = request()->user()->tenant;
        if (!$tenant) {
            return ApiResponse::error('Tenant não encontrado.', [], 404);
        }

        $subscription = Subscription::with('plan', 'coupon')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'authorized'])
            ->latest()
            ->first();

        return ApiResponse::success($subscription);
    }

    public function cancel()
    {
        $tenant = request()->user()->tenant;
        if (!$tenant) {
            return ApiResponse::error('Tenant não encontrado.', [], 404);
        }

        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'authorized'])
            ->latest()
            ->first();

        if (!$subscription) {
            return ApiResponse::error('Nenhuma assinatura ativa encontrada.', [], 404);
        }

        if ($subscription->mp_subscription_id) {
            $this->mpService->cancelSubscription($subscription->mp_subscription_id);
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $freePlan = Plan::where('price_monthly', 0)->first();
        if ($freePlan) {
            $tenant->update(['plan_id' => $freePlan->id]);
        }

        \App\Models\AuditLog::record('subscription.cancelled', 'Subscription', $subscription->id, [
            'reverted_to_plan_id' => $freePlan?->id,
        ], null, $tenant->id);

        return ApiResponse::success($subscription, 'Assinatura cancelada.');
    }
}
