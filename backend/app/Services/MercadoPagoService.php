<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoService
{
    private PaymentClient $paymentClient;
    private PreApprovalClient $preApprovalClient;

    public function __construct(private ?BillingService $billing = null)
    {
        MercadoPagoConfig::setAccessToken(config('services.mercadopago.access_token'));
        $this->paymentClient = new PaymentClient();
        $this->preApprovalClient = new PreApprovalClient();
        // Allow manual instantiation in legacy contexts; resolve from container when missing.
        $this->billing = $this->billing ?? app(BillingService::class);
    }

    public function createSubscription(Plan $plan, string $cardToken, string $payerEmail, ?Coupon $coupon = null, string $billingCycle = 'monthly'): array
    {
        $price = $plan->priceFor($billingCycle);
        $discount = $coupon ? $coupon->calculateDiscount($price) : 0;
        $finalPrice = max(0, $price - $discount);

        $frequencyType = $billingCycle === 'annual' ? 'months' : 'months';
        $frequency = $billingCycle === 'annual' ? 12 : 1;

        $preApproval = $this->preApprovalClient->create([
            'preapproval_plan_id' => null,
            'reason' => "Assinatura {$plan->name} (" . ($billingCycle === 'annual' ? 'anual' : 'mensal') . ") - " . config('business.company_name', 'BusinessCode'),
            'external_reference' => 'plan_' . $plan->id . '_' . $billingCycle,
            'payer_email' => $payerEmail,
            'card_token_id' => $cardToken,
            'auto_recurring' => [
                'frequency' => $frequency,
                'frequency_type' => $frequencyType,
                'transaction_amount' => $finalPrice,
                'currency_id' => 'BRL',
            ],
            'back_url' => config('app.frontend_url', config('app.url')) . '/checkout/thank-you',
            // Do NOT force `status: authorized` — let MP decide based on card auth (P0-24).
            // The local Subscription.status is derived from the real $preApproval->status
            // returned by MP, then confirmed by webhook (preapproval / payment).
        ]);

        return [
            'mp_subscription_id' => $preApproval->id,
            'mp_payer_id' => $preApproval->payer_id ?? null,
            'status' => $preApproval->status,
            'price' => $finalPrice,
            'discount' => $discount,
            'billing_cycle' => $billingCycle,
        ];
    }

    public function createPixPayment(float $amount, string $description, string $payerEmail): array
    {
        $payment = $this->paymentClient->create([
            'transaction_amount' => $amount,
            'description' => $description,
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => $payerEmail,
            ],
        ]);

        $pointOfInteraction = $payment->point_of_interaction;
        $transactionData = $pointOfInteraction->transaction_data ?? null;

        return [
            'mp_payment_id' => (string) $payment->id,
            'status' => $payment->status,
            'qr_code' => $transactionData->qr_code ?? null,
            'qr_code_base64' => $transactionData->qr_code_base64 ?? null,
            'expiration' => $transactionData->expiration_date ?? now()->addMinutes(30)->toIso8601String(),
        ];
    }

    public function createBoletoPayment(float $amount, string $description, string $payerEmail, string $payerDoc): array
    {
        $docType = strlen(preg_replace('/\D/', '', $payerDoc)) <= 11 ? 'CPF' : 'CNPJ';

        $payment = $this->paymentClient->create([
            'transaction_amount' => $amount,
            'description' => $description,
            'payment_method_id' => 'bolbradesco',
            'payer' => [
                'email' => $payerEmail,
                'identification' => [
                    'type' => $docType,
                    'number' => preg_replace('/\D/', '', $payerDoc),
                ],
                'first_name' => 'Cliente',
                'last_name' => 'BusinessCode',
            ],
        ]);

        $transactionDetails = $payment->transaction_details ?? null;

        return [
            'mp_payment_id' => (string) $payment->id,
            'status' => $payment->status,
            'boleto_url' => $transactionDetails->external_resource_url ?? null,
            'barcode' => $payment->barcode->content ?? null,
        ];
    }

    public function cancelSubscription(string $mpSubscriptionId): bool
    {
        try {
            $this->preApprovalClient->update($mpSubscriptionId, ['status' => 'cancelled']);
            return true;
        } catch (\Exception $e) {
            Log::error('[MercadoPago] cancel subscription failed', [
                'mp_subscription_id' => $mpSubscriptionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function processWebhook(string $type, string $dataId, string $requestId): void
    {
        // Idempotency check
        $exists = DB::table('webhook_logs')->where('request_id', $requestId)->exists();
        if ($exists) {
            Log::info('[MercadoPago] webhook already processed, skipping', ['request_id' => $requestId]);
            return;
        }

        // Log the webhook before processing
        DB::table('webhook_logs')->insert([
            'request_id' => $requestId,
            'type' => $type,
            'data_id' => $dataId,
            'status' => 'processed',
            'created_at' => now(),
        ]);

        // Mercado Pago emits several topic names for subscriptions depending on the
        // integration (with/without associated plan) and API version. Normalize them all
        // to our two handlers. The `payment` topic is also fired for the actual charge of
        // a subscription (MP requires the payments topic to be active alongside the
        // subscription topics), so a recurring charge arrives BOTH as a regular `payment`
        // and as a `subscription_authorized_payment`.
        switch ($type) {
            case 'payment':
                $this->processPaymentWebhook($dataId);
                break;

            case 'subscription_preapproval':
            case 'preapproval':
            case 'subscription':
                $this->processSubscriptionWebhook($dataId);
                break;

            case 'subscription_authorized_payment':
                $this->processAuthorizedPaymentWebhook($dataId);
                break;
        }
    }

    private function processPaymentWebhook(string $mpPaymentId): void
    {
        $mpPayment = $this->paymentClient->get((int) $mpPaymentId);
        if (!$mpPayment) return;

        $payment = Payment::where('mp_payment_id', $mpPaymentId)->first();
        if (!$payment) {
            Log::warning('[MercadoPago] webhook payment not found locally', ['mp_payment_id' => $mpPaymentId]);
            return;
        }

        $oldStatus = $payment->status;
        $newStatus = $this->mapPaymentStatus($mpPayment->status);

        if ($oldStatus === $newStatus) return;

        DB::transaction(function () use ($payment, $newStatus, $mpPayment) {
            $payment->update([
                'status' => $newStatus,
                'net_amount' => $mpPayment->transaction_details->net_received_amount ?? $payment->net_amount,
                'paid_at' => $newStatus === 'approved' ? now() : $payment->paid_at,
            ]);

            if ($newStatus === 'approved') {
                $this->activatePayment($payment);
            }
        });

        Log::info('[MercadoPago] payment webhook processed', [
            'mp_payment_id' => $mpPaymentId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'tenant_id' => $payment->tenant_id,
        ]);

        \App\Models\AuditLog::record('payment.webhook_processed', 'Payment', $payment->id, [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'mp_payment_id' => $mpPaymentId,
        ], null, $payment->tenant_id);
    }

    private function processSubscriptionWebhook(string $mpSubscriptionId): void
    {
        // Fetch via REST (not the SDK) so the handler is unit-testable with Http::fake and
        // consistent with processAuthorizedPaymentWebhook. Endpoint: GET /preapproval/{id}.
        $resp = $this->client()->get("/preapproval/{$mpSubscriptionId}");
        if (! $resp->successful()) {
            Log::channel($this->logChannel())->warning('mp.preapproval_fetch_failed', [
                'mp_subscription_id' => $mpSubscriptionId,
                'status'             => $resp->status(),
            ]);
            return;
        }

        $mpStatus = $resp->json('status');
        if (! $mpStatus) return;

        $subscription = Subscription::where('mp_subscription_id', $mpSubscriptionId)->first();
        if (!$subscription) return;

        $newStatus = match ($mpStatus) {
            'authorized' => 'active',
            'paused' => 'paused',
            'cancelled' => 'cancelled',
            default => $subscription->status,
        };

        $oldStatus = $subscription->status;
        $subscription->update(['status' => $newStatus]);

        if ($oldStatus !== $newStatus) {
            \App\Models\AuditLog::record('subscription.webhook_status_change', 'Subscription', $subscription->id, [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'mp_subscription_id' => $mpSubscriptionId,
            ], null, $subscription->tenant_id);
        }

        $tenant = $subscription->tenant;
        if ($newStatus === 'cancelled') {
            $freePlan = Plan::where('price_monthly', 0)->first();
            if ($freePlan) {
                $tenant->update(['plan_id' => $freePlan->id]);
            }
            $subscription->update(['cancelled_at' => now()]);
        } elseif ($newStatus === 'paused') {
            $tenant->update(['status' => 'suspended']);
        } elseif ($newStatus === 'active') {
            // Bring the tenant onto the subscribed plan and credit the plan's included
            // balance. This is the async counterpart of SubscriptionController::store —
            // a card subscription that came back `pending` (3DS / async auth) is only
            // confirmed here, so without this the user would pay and get no plan + no
            // included balance until the next MonthlyBillingJob run.
            $plan = $subscription->plan;
            $tenant->update([
                'plan_id' => $plan?->id ?? $tenant->plan_id,
                'status'  => 'active',
            ]);

            $includedCents = (int) ($plan->included_balance_cents ?? 0);
            if ($plan && $includedCents > 0) {
                // Idempotent on (tenant, 'recharge', 'subscription', subscription_id):
                // shares the exact reference key used by the synchronous path, so the
                // balance is credited exactly once across sync + webhook.
                $this->billing->recharge(
                    $tenant->id,
                    $includedCents,
                    'subscription',
                    $subscription->id,
                    "Saldo incluído no plano {$plan->name}"
                );
            }
        }
    }

    /**
     * Handle a `subscription_authorized_payment` webhook — Mercado Pago's confirmation
     * that it auto-charged the subscriber's card for a new billing period.
     *
     * The notification's data.id is an *authorized payment* id, fetched via
     * GET /authorized_payments/{id}. Its `preapproval_id` links back to our local
     * Subscription. We use the REST helper (not the SDK) so the flow is unit-testable
     * with Http::fake.
     */
    private function processAuthorizedPaymentWebhook(string $authorizedPaymentId): void
    {
        $resp = $this->client()->get("/authorized_payments/{$authorizedPaymentId}");
        if (! $resp->successful()) {
            Log::channel($this->logChannel())->warning('mp.authorized_payment_fetch_failed', [
                'authorized_payment_id' => $authorizedPaymentId,
                'status'                => $resp->status(),
            ]);
            return;
        }

        $body          = $resp->json();
        $preapprovalId = $body['preapproval_id'] ?? null;
        $status        = $body['status'] ?? $body['payment']['status'] ?? null;

        if (! $preapprovalId) {
            Log::channel($this->logChannel())->info('mp.authorized_payment_no_preapproval', [
                'authorized_payment_id' => $authorizedPaymentId,
            ]);
            return;
        }

        $subscription = Subscription::where('mp_subscription_id', $preapprovalId)->first();
        if (! $subscription) {
            Log::warning('[MercadoPago] authorized payment for unknown subscription', [
                'authorized_payment_id' => $authorizedPaymentId,
                'preapproval_id'        => $preapprovalId,
            ]);
            return;
        }

        // Only act on an effectively approved recurring charge.
        if (! in_array($status, ['approved', 'accredited', 'processed'], true)) {
            Log::channel($this->logChannel())->info('mp.authorized_payment_not_approved', [
                'authorized_payment_id' => $authorizedPaymentId,
                'subscription_id'       => $subscription->id,
                'status'                => $status,
            ]);
            return;
        }

        // Roll the local billing period forward and keep the subscription active.
        // Period credit of the plan's included balance is owned by MonthlyBillingJob
        // (single source of truth per cycle) — we do NOT credit here to avoid a
        // double-credit. This handler keeps status/period in sync and records the
        // charge for the tenant's payment history.
        $cycle     = $subscription->billing_cycle ?? 'monthly';
        $periodEnd = $cycle === 'annual' ? now()->addYear() : now()->addMonth();
        $subscription->update([
            'status'               => 'active',
            'current_period_start' => now(),
            'current_period_end'   => $periodEnd,
        ]);

        $mpPaymentId = isset($body['payment']['id'])
            ? (string) $body['payment']['id']
            : (string) $authorizedPaymentId;

        // Record the recurring charge for visibility in /payments. Unique on mp_payment_id
        // keeps replays from inserting duplicates.
        Payment::firstOrCreate(
            ['mp_payment_id' => $mpPaymentId],
            [
                'tenant_id'       => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'type'            => 'subscription',
                'status'          => 'approved',
                'payment_method'  => 'credit_card',
                'amount'          => $subscription->price,
                'paid_at'         => now(),
            ]
        );

        \App\Models\AuditLog::record('subscription.recurring_charge', 'Subscription', $subscription->id, [
            'authorized_payment_id' => $authorizedPaymentId,
            'mp_payment_id'         => $mpPaymentId,
            'period_end'            => $periodEnd->toDateString(),
        ], null, $subscription->tenant_id);
    }

    private function activatePayment(Payment $payment): void
    {
        $tenant = Tenant::find($payment->tenant_id);
        if (!$tenant) return;

        // payment->credits_purchased is now expressed in CENTS (Phase 6+).
        // Verify payment amount matches expected (amount in REAIS, cents -> R$ / 100).
        // Compare in INTEGER CENTS to avoid float-rounding false negatives — IEEE-754
        // can render R$ 10.30 as 10.299999… which used to be hidden by the 0.01 epsilon
        // but is brittle for higher-precision pricing (e.g. R$ 0.07/SMS × n).
        if ($payment->type === 'credit_purchase' && $payment->credits_purchased) {
            $expectedCents = (int) $payment->credits_purchased;
            $paidCents     = (int) round(((float) $payment->amount) * 100);
            if ($paidCents !== $expectedCents) {
                Log::channel(array_key_exists('mp', (array) config('logging.channels', [])) ? 'mp' : 'stack')
                    ->warning('mp.amount_mismatch', [
                        'payment_id'     => $payment->id,
                        'paid_cents'     => $paidCents,
                        'expected_cents' => $expectedCents,
                        'tenant_id'      => $payment->tenant_id,
                    ]);
                Log::error('[MercadoPago] payment amount mismatch', [
                    'payment_id'     => $payment->id,
                    'expected_cents' => $expectedCents,
                    'paid_cents'     => $paidCents,
                ]);
                return;
            }
        }

        if ($payment->type === 'credit_purchase' && $payment->credits_purchased) {
            $this->billing->recharge(
                $tenant->id,
                (int) $payment->credits_purchased,
                'credit_purchase',
                $payment->id,
                "Compra de saldo (payment #{$payment->id})"
            );
        } elseif ($payment->type === 'subscription' && $payment->subscription_id) {
            $subscription = $payment->subscription;
            if ($subscription) {
                $subscription->update(['status' => 'active']);
                $plan = $subscription->plan;
                $tenant->update([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                ]);

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
            }
        }
    }

    private function mapPaymentStatus(string $mpStatus): string
    {
        return match ($mpStatus) {
            'approved' => 'approved',
            'pending', 'in_process', 'authorized' => 'pending',
            'rejected' => 'rejected',
            'refunded' => 'refunded',
            'cancelled', 'charged_back' => 'cancelled',
            default => 'pending',
        };
    }

    /**
     * Build an authenticated HTTP client for the Mercado Pago REST API.
     * Used for endpoints not covered by the official SDK (card_tokens, payments with stored card).
     */
    protected function client(): PendingRequest
    {
        return Http::withToken((string) config('services.mercadopago.access_token'))
            ->acceptJson()
            ->baseUrl('https://api.mercadopago.com')
            ->timeout(15);
    }

    /**
     * Create a one-time card_token from a stored customer card.
     * Required by /v1/payments when paying with a saved card without user interaction.
     */
    public function createTokenFromStoredCard(string $customerId, string $cardId): ?string
    {
        $channel = $this->logChannel();
        $resp = $this->client()->post('/v1/card_tokens', [
            'customer_id' => $customerId,
            'card_id'     => $cardId,
        ]);

        if (! $resp->successful()) {
            Log::channel($channel)->warning('mp.card_token_failed', [
                'customer_id' => $customerId,
                'card_id'     => $cardId,
                'status'      => $resp->status(),
                'body'        => $resp->body(),
            ]);
            return null;
        }

        return $resp->json('id');
    }

    /**
     * Read the canonical payment_method_id (card brand, e.g. "visa", "master") of a
     * stored card. /v1/payments requires this field for card charges — passing the
     * generic literal "credit_card" is rejected by MP. Returns null if it can't be read.
     */
    public function getStoredCardPaymentMethodId(string $customerId, string $cardId): ?string
    {
        $resp = $this->client()->get("/v1/customers/{$customerId}/cards/{$cardId}");
        if (! $resp->successful()) {
            Log::channel($this->logChannel())->warning('mp.stored_card_fetch_failed', [
                'customer_id' => $customerId,
                'card_id'     => $cardId,
                'status'      => $resp->status(),
            ]);
            return null;
        }

        // The card resource exposes the brand under payment_method.id (with .name as a
        // human label). Accept either so we degrade gracefully across API revisions.
        $id = $resp->json('payment_method.id') ?? $resp->json('payment_method.name');
        return $id ? (string) $id : null;
    }

    /**
     * Charge a tenant's stored card without user interaction (monthly billing / overdue retry).
     *
     * @return array{ok:bool, mp_payment_id:?string, mp_status?:string, error:?string}
     */
    public function chargeStoredCard(Tenant $tenant, int $amountCents, string $description): array
    {
        if (! $tenant->mp_customer_id || ! $tenant->mp_default_card_id) {
            return ['ok' => false, 'error' => 'NO_STORED_CARD', 'mp_payment_id' => null];
        }

        $token = $this->createTokenFromStoredCard($tenant->mp_customer_id, $tenant->mp_default_card_id);
        if (! $token) {
            return ['ok' => false, 'error' => 'CARD_TOKEN_FAILED', 'mp_payment_id' => null];
        }

        $paymentMethodId = $this->getStoredCardPaymentMethodId($tenant->mp_customer_id, $tenant->mp_default_card_id);
        if (! $paymentMethodId) {
            return ['ok' => false, 'error' => 'PAYMENT_METHOD_UNRESOLVED', 'mp_payment_id' => null];
        }

        $externalRef = 'monthly:' . $tenant->id . ':' . now()->format('Ym');

        $resp = $this->client()->post('/v1/payments', [
            'transaction_amount' => $amountCents / 100,
            'description'        => $description,
            'payment_method_id'  => $paymentMethodId,
            'payer'              => ['type' => 'customer', 'id' => $tenant->mp_customer_id],
            'token'              => $token,
            'installments'       => 1,
            'external_reference' => $externalRef,
            'notification_url'   => url('/api/v1/webhooks/mercadopago'),
            'metadata'           => ['tenant_id' => $tenant->id, 'billing_kind' => $description],
        ]);

        if (! $resp->successful()) {
            return [
                'ok'            => false,
                'error'         => $resp->json('message') ?? "MP_API_HTTP_{$resp->status()}",
                'mp_payment_id' => null,
            ];
        }

        $body = $resp->json();
        $status = $body['status'] ?? 'unknown';

        return [
            'ok'            => $status === 'approved',
            'mp_payment_id' => isset($body['id']) ? (string) $body['id'] : null,
            'mp_status'     => $status,
            'error'         => $status === 'approved' ? null : ($body['status_detail'] ?? 'rejected'),
        ];
    }

    /**
     * Pick a log channel that exists (fallback to default if 'mp' is not configured).
     */
    private function logChannel(): string
    {
        return array_key_exists('mp', (array) config('logging.channels', [])) ? 'mp' : 'campaign';
    }

    /**
     * Find or create an MP customer for the given email.
     *
     * Returns the MP customer_id, or null if MP rejects the call. Used during
     * checkout when the user opts to save their card for future billing.
     */
    public function findOrCreateCustomer(string $email, ?string $firstName = null): ?string
    {
        $channel = $this->logChannel();

        // 1) Search for an existing customer with this email.
        $searchResp = $this->client()->get('/v1/customers/search', ['email' => $email]);
        if ($searchResp->successful()) {
            $results = $searchResp->json('results', []);
            if (is_array($results) && !empty($results) && isset($results[0]['id'])) {
                return (string) $results[0]['id'];
            }
        } else {
            Log::channel($channel)->info('mp.customer_search_failed', [
                'email'  => $email,
                'status' => $searchResp->status(),
            ]);
        }

        // 2) Create a new customer.
        $createResp = $this->client()->post('/v1/customers', array_filter([
            'email'      => $email,
            'first_name' => $firstName,
        ]));

        if (! $createResp->successful()) {
            Log::channel($channel)->warning('mp.customer_create_failed', [
                'email'  => $email,
                'status' => $createResp->status(),
                'body'   => $createResp->body(),
            ]);
            return null;
        }

        $id = $createResp->json('id');
        return $id ? (string) $id : null;
    }

    /**
     * Persist a card token on an existing MP customer so we can charge it later.
     * Returns the saved card_id, or null on failure.
     */
    public function saveCardOnCustomer(string $customerId, string $cardToken): ?string
    {
        $channel = $this->logChannel();

        $resp = $this->client()->post("/v1/customers/{$customerId}/cards", [
            'token' => $cardToken,
        ]);

        if (! $resp->successful()) {
            Log::channel($channel)->warning('mp.card_save_failed', [
                'customer_id' => $customerId,
                'status'      => $resp->status(),
                'body'        => $resp->body(),
            ]);
            return null;
        }

        $id = $resp->json('id');
        return $id ? (string) $id : null;
    }

    /**
     * True when the configured MP credentials are still the documented placeholders
     * (e.g. `APP_USR-your-access-token`, `your-webhook-secret`). Used to emit an
     * unambiguous diagnostic instead of a generic 401 / signature failure when the
     * integration was never actually configured.
     */
    public static function placeholderCredentials(): bool
    {
        $token  = (string) config('services.mercadopago.access_token');
        $secret = (string) config('services.mercadopago.webhook_secret');

        return $token === '' || $secret === ''
            || str_contains($token, 'your-')
            || str_contains($secret, 'your-');
    }

    public static function validateWebhookSignature(string $xSignature, string $xRequestId, string $dataId): bool
    {
        $secret = config('services.mercadopago.webhook_secret');
        if (!$secret) return false;

        // Distinct, actionable log: the #1 cause of "every webhook 401s" is shipping the
        // .env placeholder secret. Surface it explicitly so it isn't mistaken for a real
        // signature attack / MP misconfiguration.
        if (str_contains((string) $secret, 'your-')) {
            Log::warning('[MercadoPago] webhook secret is still the .env placeholder — set a real MP_WEBHOOK_SECRET');
            return false;
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';
        if (!$ts || !$v1) return false;

        // Reject webhooks older than 5 minutes
        if (abs(time() - (int) $ts) > 300) {
            Log::warning('[MercadoPago] webhook timestamp too old', ['ts' => $ts, 'age_seconds' => abs(time() - (int) $ts)]);
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $calculated = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($calculated, $v1);
    }
}
