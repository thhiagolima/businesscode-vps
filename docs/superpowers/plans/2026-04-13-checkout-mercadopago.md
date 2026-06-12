# Checkout Mercado Pago — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement full checkout flow with Mercado Pago Transparent Checkout (card/PIX/boleto), recurring subscriptions, one-time credit purchases, and coupon system — working both in the dashboard and public sales page.

**Architecture:** Laravel backend with `MercadoPagoService` handling all MP API calls. Vue 3 frontend with shared `CheckoutForm.vue` component reused across dashboard and public checkout pages. Webhook endpoint processes payment notifications and activates subscriptions/credits.

**Tech Stack:** Laravel 12, `mercadopago/dx-php` SDK, Vue 3 + TypeScript, Pinia, MercadoPago.js (frontend tokenization), Tailwind CSS (public pages), Tabler UI (dashboard).

**Spec:** `docs/superpowers/specs/2026-04-13-checkout-mercadopago-design.md`

---

## File Map

### Backend — New Files
- `backend/database/migrations/2026_04_13_000001_create_subscriptions_table.php`
- `backend/database/migrations/2026_04_13_000002_create_payments_table.php`
- `backend/database/migrations/2026_04_13_000003_create_coupons_table.php`
- `backend/app/Models/Subscription.php`
- `backend/app/Models/Payment.php`
- `backend/app/Models/Coupon.php`
- `backend/app/Services/MercadoPagoService.php`
- `backend/app/Http/Controllers/API/V1/SubscriptionController.php`
- `backend/app/Http/Controllers/API/V1/PaymentController.php`
- `backend/app/Http/Controllers/API/V1/CheckoutController.php`
- `backend/app/Http/Controllers/API/V1/WebhookController.php`
- `backend/app/Http/Controllers/API/V1/Admin/CouponController.php`
- `backend/app/Http/Requests/CreateSubscriptionRequest.php`
- `backend/app/Http/Requests/CreatePixPaymentRequest.php`
- `backend/app/Http/Requests/CreateBoletoPaymentRequest.php`
- `backend/app/Http/Requests/PurchaseCreditsRequest.php`
- `backend/app/Http/Requests/ValidateCouponRequest.php`
- `backend/app/Http/Requests/StoreCouponRequest.php`
- `backend/app/Jobs/ProcessMercadoPagoWebhook.php`
- `backend/tests/Unit/MercadoPagoServiceTest.php`
- `backend/tests/Feature/SubscriptionTest.php`
- `backend/tests/Feature/PaymentTest.php`
- `backend/tests/Feature/CouponTest.php`
- `backend/tests/Feature/WebhookTest.php`

### Backend — Modified Files
- `backend/.env` — add MP_PUBLIC_KEY, MP_ACCESS_TOKEN, MP_WEBHOOK_SECRET, CREDIT_UNIT_PRICE, CREDIT_MIN_PURCHASE
- `backend/config/services.php` — add mercadopago config block
- `backend/routes/api.php` — add checkout, subscription, payment, webhook, coupon routes
- `backend/app/Models/Tenant.php` — add subscriptions() and payments() relationships

### Frontend — New Files
- `frontend/src/stores/checkout.ts`
- `frontend/src/pages/checkout/CheckoutPage.vue`
- `frontend/src/pages/checkout/CheckoutThankYou.vue`
- `frontend/src/pages/checkout/PricingPlans.vue`
- `frontend/src/pages/settings/CreditPurchase.vue`
- `frontend/src/components/checkout/CheckoutForm.vue`
- `frontend/src/components/checkout/OrderSummary.vue`
- `frontend/src/components/checkout/PixPayment.vue`
- `frontend/src/components/checkout/BoletoPayment.vue`
- `frontend/src/components/checkout/PaymentTabs.vue`

### Frontend — Modified Files
- `frontend/index.html` — add MercadoPago.js SDK script
- `frontend/src/router/index.ts` — add checkout, pricing, credit routes
- `frontend/src/pages/settings/Plans.vue` — replace WhatsApp link with checkout navigation, add "Comprar Créditos" button
- `frontend/src/App.vue` — handle public checkout layout (no sidebar)

---

## Task 1: Backend — Migrations

**Files:**
- Create: `backend/database/migrations/2026_04_13_000001_create_subscriptions_table.php`
- Create: `backend/database/migrations/2026_04_13_000002_create_payments_table.php`
- Create: `backend/database/migrations/2026_04_13_000003_create_coupons_table.php`

- [ ] **Step 1: Create subscriptions migration**

```php
<?php
// backend/database/migrations/2026_04_13_000001_create_subscriptions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('mp_subscription_id')->nullable()->index();
            $table->string('mp_payer_id')->nullable();
            $table->enum('status', ['pending', 'authorized', 'active', 'paused', 'cancelled'])->default('pending')->index();
            $table->enum('payment_method', ['credit_card', 'pix', 'boleto'])->default('credit_card');
            $table->date('current_period_start')->nullable();
            $table->date('current_period_end')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
```

- [ ] **Step 2: Create payments migration**

```php
<?php
// backend/database/migrations/2026_04_13_000002_create_payments_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mp_payment_id')->unique();
            $table->enum('type', ['subscription', 'credit_purchase'])->default('subscription');
            $table->enum('status', ['pending', 'approved', 'rejected', 'refunded', 'cancelled'])->default('pending')->index();
            $table->enum('payment_method', ['credit_card', 'pix', 'boleto'])->default('credit_card');
            $table->decimal('amount', 10, 2);
            $table->decimal('net_amount', 10, 2)->nullable();
            $table->unsignedInteger('credits_purchased')->nullable();
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_qr_code_base64')->nullable();
            $table->timestamp('pix_expiration')->nullable();
            $table->string('boleto_url')->nullable();
            $table->string('boleto_barcode')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
```

- [ ] **Step 3: Create coupons migration**

Note: this migration must run BEFORE subscriptions (which references coupon_id). Rename the subscriptions migration to `_000002` and this to `_000001`, or remove the FK constraint from subscriptions. Simplest: create coupons first.

Rename files:
- `2026_04_13_000001_create_coupons_table.php`
- `2026_04_13_000002_create_subscriptions_table.php`
- `2026_04_13_000003_create_payments_table.php`

```php
<?php
// backend/database/migrations/2026_04_13_000001_create_coupons_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('discount_type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('discount_value', 10, 2);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
```

- [ ] **Step 4: Run migrations**

Run: `cd /c/xampp/htdocs/new_saas/backend && php artisan migrate`
Expected: 3 tables created (coupons, subscriptions, payments)

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_04_13_*
git commit -m "feat(checkout): add subscriptions, payments, coupons migrations"
```

---

## Task 2: Backend — Models

**Files:**
- Create: `backend/app/Models/Subscription.php`
- Create: `backend/app/Models/Payment.php`
- Create: `backend/app/Models/Coupon.php`
- Modify: `backend/app/Models/Tenant.php`

- [ ] **Step 1: Create Subscription model**

```php
<?php
// backend/app/Models/Subscription.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'mp_subscription_id',
        'mp_payer_id',
        'status',
        'payment_method',
        'current_period_start',
        'current_period_end',
        'price',
        'discount_amount',
        'coupon_id',
        'cancelled_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'current_period_start' => 'date',
        'current_period_end' => 'date',
        'cancelled_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'authorized']);
    }
}
```

- [ ] **Step 2: Create Payment model**

```php
<?php
// backend/app/Models/Payment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'mp_payment_id',
        'type',
        'status',
        'payment_method',
        'amount',
        'net_amount',
        'credits_purchased',
        'pix_qr_code',
        'pix_qr_code_base64',
        'pix_expiration',
        'boleto_url',
        'boleto_barcode',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'credits_purchased' => 'integer',
        'pix_expiration' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
```

- [ ] **Step 3: Create Coupon model**

```php
<?php
// backend/app/Models/Coupon.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'max_uses',
        'times_used',
        'valid_from',
        'valid_until',
        'active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_uses' => 'integer',
        'times_used' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'active' => 'boolean',
    ];

    public function isValid(): bool
    {
        if (!$this->active) return false;
        if ($this->max_uses !== null && $this->times_used >= $this->max_uses) return false;
        if ($this->valid_from && now()->lt($this->valid_from)) return false;
        if ($this->valid_until && now()->gt($this->valid_until->endOfDay())) return false;
        return true;
    }

    public function calculateDiscount(float $price): float
    {
        if ($this->discount_type === 'percentage') {
            return round($price * ($this->discount_value / 100), 2);
        }
        return min($this->discount_value, $price);
    }
}
```

- [ ] **Step 4: Add relationships to Tenant model**

In `backend/app/Models/Tenant.php`, add these methods after the existing `enabledChannels()`:

```php
public function subscriptions()
{
    return $this->hasMany(Subscription::class);
}

public function activeSubscription()
{
    return $this->hasOne(Subscription::class)->whereIn('status', ['active', 'authorized'])->latest();
}

public function payments()
{
    return $this->hasMany(Payment::class);
}
```

- [ ] **Step 5: Commit**

```bash
git add backend/app/Models/Subscription.php backend/app/Models/Payment.php backend/app/Models/Coupon.php backend/app/Models/Tenant.php
git commit -m "feat(checkout): add Subscription, Payment, Coupon models with relationships"
```

---

## Task 3: Backend — Environment & Config

**Files:**
- Modify: `backend/.env`
- Modify: `backend/config/services.php`

- [ ] **Step 1: Add env variables to .env**

Append to the end of `backend/.env`:

```
# Mercado Pago
MP_PUBLIC_KEY=APP_USR-your-public-key
MP_ACCESS_TOKEN=APP_USR-your-access-token
MP_WEBHOOK_SECRET=your-webhook-secret

# Credit Purchase
CREDIT_UNIT_PRICE=0.08
CREDIT_MIN_PURCHASE=100
```

- [ ] **Step 2: Add mercadopago config to services.php**

In `backend/config/services.php`, add inside the return array:

```php
'mercadopago' => [
    'public_key' => env('MP_PUBLIC_KEY'),
    'access_token' => env('MP_ACCESS_TOKEN'),
    'webhook_secret' => env('MP_WEBHOOK_SECRET'),
],

'credits' => [
    'unit_price' => (float) env('CREDIT_UNIT_PRICE', 0.08),
    'min_purchase' => (int) env('CREDIT_MIN_PURCHASE', 100),
],
```

- [ ] **Step 3: Install Mercado Pago SDK**

Run: `cd /c/xampp/htdocs/new_saas/backend && composer require mercadopago/dx-php`

- [ ] **Step 4: Commit**

```bash
git add backend/.env backend/config/services.php backend/composer.json backend/composer.lock
git commit -m "feat(checkout): add Mercado Pago SDK and config"
```

---

## Task 4: Backend — MercadoPagoService

**Files:**
- Create: `backend/app/Services/MercadoPagoService.php`

- [ ] **Step 1: Create the service**

```php
<?php
// backend/app/Services/MercadoPagoService.php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoService
{
    private PaymentClient $paymentClient;
    private PreApprovalClient $preApprovalClient;

    public function __construct()
    {
        MercadoPagoConfig::setAccessToken(config('services.mercadopago.access_token'));
        $this->paymentClient = new PaymentClient();
        $this->preApprovalClient = new PreApprovalClient();
    }

    /**
     * Create a recurring subscription (credit card only).
     */
    public function createSubscription(Plan $plan, string $cardToken, string $payerEmail, ?Coupon $coupon = null): array
    {
        $price = (float) $plan->price_monthly;
        $discount = $coupon ? $coupon->calculateDiscount($price) : 0;
        $finalPrice = max(0, $price - $discount);

        $preApproval = $this->preApprovalClient->create([
            'preapproval_plan_id' => null,
            'reason' => "Assinatura {$plan->name} - BusinessCode",
            'external_reference' => 'plan_' . $plan->id,
            'payer_email' => $payerEmail,
            'card_token_id' => $cardToken,
            'auto_recurring' => [
                'frequency' => 1,
                'frequency_type' => 'months',
                'transaction_amount' => $finalPrice,
                'currency_id' => 'BRL',
            ],
            'back_url' => config('app.frontend_url', config('app.url')) . '/checkout/thank-you',
            'status' => 'authorized',
        ]);

        return [
            'mp_subscription_id' => $preApproval->id,
            'mp_payer_id' => $preApproval->payer_id ?? null,
            'status' => $preApproval->status,
            'price' => $finalPrice,
            'discount' => $discount,
        ];
    }

    /**
     * Create a PIX payment (one-time).
     */
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

    /**
     * Create a Boleto payment (one-time).
     */
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

    /**
     * Cancel a subscription on Mercado Pago.
     */
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

    /**
     * Process a webhook notification from Mercado Pago.
     */
    public function processWebhook(string $type, string $dataId): void
    {
        if ($type === 'payment') {
            $this->processPaymentWebhook($dataId);
        } elseif ($type === 'subscription_preapproval') {
            $this->processSubscriptionWebhook($dataId);
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
    }

    private function processSubscriptionWebhook(string $mpSubscriptionId): void
    {
        $mpSub = $this->preApprovalClient->get($mpSubscriptionId);
        if (!$mpSub) return;

        $subscription = Subscription::where('mp_subscription_id', $mpSubscriptionId)->first();
        if (!$subscription) return;

        $newStatus = match ($mpSub->status) {
            'authorized' => 'active',
            'paused' => 'paused',
            'cancelled' => 'cancelled',
            default => $subscription->status,
        };

        $subscription->update(['status' => $newStatus]);

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
            $tenant->update(['status' => 'active']);
        }
    }

    private function activatePayment(Payment $payment): void
    {
        $tenant = Tenant::lockForUpdate()->find($payment->tenant_id);
        if (!$tenant) return;

        if ($payment->type === 'credit_purchase' && $payment->credits_purchased) {
            $newBalance = (int) $tenant->credits_balance + $payment->credits_purchased;
            $tenant->update(['credits_balance' => $newBalance]);

            DB::table('credit_transactions')->insert([
                'tenant_id' => $tenant->id,
                'type' => 'credit',
                'amount' => $payment->credits_purchased,
                'balance_after' => $newBalance,
                'reference_type' => 'credit_purchase',
                'reference_id' => $payment->id,
                'description' => "Compra de {$payment->credits_purchased} créditos",
                'meta' => json_encode(['payment_id' => $payment->id]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ($payment->type === 'subscription' && $payment->subscription_id) {
            $subscription = $payment->subscription;
            if ($subscription) {
                $subscription->update(['status' => 'active']);
                $plan = $subscription->plan;
                $tenant->update([
                    'plan_id' => $plan->id,
                    'status' => 'active',
                ]);

                // Credit the plan's included credits
                $newBalance = (int) $tenant->credits_balance + $plan->credits_included;
                $tenant->update(['credits_balance' => $newBalance]);

                DB::table('credit_transactions')->insert([
                    'tenant_id' => $tenant->id,
                    'type' => 'credit',
                    'amount' => $plan->credits_included,
                    'balance_after' => $newBalance,
                    'reference_type' => 'subscription',
                    'reference_id' => $subscription->id,
                    'description' => "Créditos do plano {$plan->name}",
                    'meta' => json_encode(['subscription_id' => $subscription->id]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
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
     * Validate webhook signature.
     */
    public static function validateWebhookSignature(string $xSignature, string $xRequestId, string $dataId): bool
    {
        $secret = config('services.mercadopago.webhook_secret');
        if (!$secret) return false;

        // Parse x-signature header: "ts=...,v1=..."
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

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $calculated = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($calculated, $v1);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Services/MercadoPagoService.php
git commit -m "feat(checkout): add MercadoPagoService with subscription, PIX, boleto, webhooks"
```

---

## Task 5: Backend — FormRequests

**Files:**
- Create: `backend/app/Http/Requests/CreateSubscriptionRequest.php`
- Create: `backend/app/Http/Requests/CreatePixPaymentRequest.php`
- Create: `backend/app/Http/Requests/CreateBoletoPaymentRequest.php`
- Create: `backend/app/Http/Requests/PurchaseCreditsRequest.php`
- Create: `backend/app/Http/Requests/ValidateCouponRequest.php`
- Create: `backend/app/Http/Requests/StoreCouponRequest.php`

- [ ] **Step 1: Create all FormRequests**

```php
<?php
// backend/app/Http/Requests/CreateSubscriptionRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'exists:plans,id'],
            'card_token' => ['required', 'string'],
            'payer_email' => ['required', 'email', 'max:255'],
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
```

```php
<?php
// backend/app/Http/Requests/CreatePixPaymentRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePixPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'plan_id' => ['required_without:credits_amount', 'nullable', 'exists:plans,id'],
            'credits_amount' => ['required_without:plan_id', 'nullable', 'integer', 'min:' . config('services.credits.min_purchase', 100)],
            'payer_email' => ['required', 'email', 'max:255'],
        ];
    }
}
```

```php
<?php
// backend/app/Http/Requests/CreateBoletoPaymentRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateBoletoPaymentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'plan_id' => ['required_without:credits_amount', 'nullable', 'exists:plans,id'],
            'credits_amount' => ['required_without:plan_id', 'nullable', 'integer', 'min:' . config('services.credits.min_purchase', 100)],
            'payer_email' => ['required', 'email', 'max:255'],
            'payer_document' => ['required', 'string', 'min:11', 'max:18'],
        ];
    }
}
```

```php
<?php
// backend/app/Http/Requests/PurchaseCreditsRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PurchaseCreditsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'credits_amount' => ['required', 'integer', 'min:' . config('services.credits.min_purchase', 100)],
            'payment_method' => ['required', 'in:credit_card,pix,boleto'],
            'card_token' => ['required_if:payment_method,credit_card', 'nullable', 'string'],
            'payer_email' => ['required', 'email', 'max:255'],
            'payer_document' => ['required_if:payment_method,boleto', 'nullable', 'string', 'min:11', 'max:18'],
        ];
    }
}
```

```php
<?php
// backend/app/Http/Requests/ValidateCouponRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCouponRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'plan_id' => ['nullable', 'exists:plans,id'],
        ];
    }
}
```

```php
<?php
// backend/app/Http/Requests/StoreCouponRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $couponId = $this->route('id');
        return [
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code' . ($couponId ? ',' . $couponId : '')],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'discount_value' => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Http/Requests/CreateSubscriptionRequest.php backend/app/Http/Requests/CreatePixPaymentRequest.php backend/app/Http/Requests/CreateBoletoPaymentRequest.php backend/app/Http/Requests/PurchaseCreditsRequest.php backend/app/Http/Requests/ValidateCouponRequest.php backend/app/Http/Requests/StoreCouponRequest.php
git commit -m "feat(checkout): add FormRequest validations for all checkout endpoints"
```

---

## Task 6: Backend — Controllers

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/CheckoutController.php`
- Create: `backend/app/Http/Controllers/API/V1/SubscriptionController.php`
- Create: `backend/app/Http/Controllers/API/V1/PaymentController.php`
- Create: `backend/app/Http/Controllers/API/V1/WebhookController.php`
- Create: `backend/app/Http/Controllers/API/V1/Admin/CouponController.php`

- [ ] **Step 1: Create CheckoutController**

```php
<?php
// backend/app/Http/Controllers/API/V1/CheckoutController.php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateCouponRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;
use App\Models\Plan;

class CheckoutController extends Controller
{
    public function validateCoupon(ValidateCouponRequest $request)
    {
        $coupon = Coupon::where('code', strtoupper($request->code))->first();

        if (!$coupon || !$coupon->isValid()) {
            return ApiResponse::error('Cupom inválido ou expirado.', [], 422);
        }

        $discount = null;
        if ($request->plan_id) {
            $plan = Plan::find($request->plan_id);
            if ($plan) {
                $discount = $coupon->calculateDiscount((float) $plan->price_monthly);
            }
        }

        return ApiResponse::success([
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
            ],
            'calculated_discount' => $discount,
        ]);
    }

    public function config()
    {
        return ApiResponse::success([
            'mp_public_key' => config('services.mercadopago.public_key'),
            'credit_unit_price' => config('services.credits.unit_price'),
            'credit_min_purchase' => config('services.credits.min_purchase'),
        ]);
    }
}
```

- [ ] **Step 2: Create SubscriptionController**

```php
<?php
// backend/app/Http/Controllers/API/V1/SubscriptionController.php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSubscriptionRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    public function __construct(private MercadoPagoService $mpService) {}

    public function store(CreateSubscriptionRequest $request)
    {
        $plan = Plan::findOrFail($request->plan_id);
        $tenant = $request->user()->tenant;

        if (!$tenant) {
            return ApiResponse::error('Tenant não encontrado.', [], 404);
        }

        // Check for existing active subscription
        $existing = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'authorized', 'pending'])
            ->first();

        if ($existing) {
            return ApiResponse::error('Já existe uma assinatura ativa. Cancele antes de assinar outro plano.', [], 422);
        }

        $coupon = null;
        if ($request->coupon_code) {
            $coupon = Coupon::where('code', strtoupper($request->coupon_code))->first();
            if (!$coupon || !$coupon->isValid()) {
                return ApiResponse::error('Cupom inválido ou expirado.', [], 422);
            }
        }

        try {
            $result = $this->mpService->createSubscription($plan, $request->card_token, $request->payer_email, $coupon);

            $subscription = DB::transaction(function () use ($tenant, $plan, $result, $coupon) {
                if ($coupon) {
                    $coupon->increment('times_used');
                }

                return Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'mp_subscription_id' => $result['mp_subscription_id'],
                    'mp_payer_id' => $result['mp_payer_id'],
                    'status' => $result['status'] === 'authorized' ? 'active' : 'pending',
                    'payment_method' => 'credit_card',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                    'price' => $result['price'],
                    'discount_amount' => $result['discount'],
                    'coupon_id' => $coupon?->id,
                ]);
            });

            // If immediately authorized, activate the plan
            if ($subscription->status === 'active') {
                $tenant->update(['plan_id' => $plan->id, 'status' => 'active']);
                $newBalance = (int) $tenant->credits_balance + $plan->credits_included;
                $tenant->update(['credits_balance' => $newBalance]);

                DB::table('credit_transactions')->insert([
                    'tenant_id' => $tenant->id,
                    'type' => 'credit',
                    'amount' => $plan->credits_included,
                    'balance_after' => $newBalance,
                    'reference_type' => 'subscription',
                    'reference_id' => $subscription->id,
                    'description' => "Créditos do plano {$plan->name}",
                    'meta' => json_encode([]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return ApiResponse::success($subscription->load('plan'), 'Assinatura criada com sucesso.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] subscription creation failed', [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);
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

        return ApiResponse::success($subscription, 'Assinatura cancelada.');
    }
}
```

- [ ] **Step 3: Create PaymentController**

```php
<?php
// backend/app/Http/Controllers/API/V1/PaymentController.php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBoletoPaymentRequest;
use App\Http\Requests\CreatePixPaymentRequest;
use App\Http\Requests\PurchaseCreditsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\MercadoPagoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private MercadoPagoService $mpService) {}

    public function pix(CreatePixPaymentRequest $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $isCredits = (bool) $request->credits_amount;
        $amount = $isCredits
            ? $request->credits_amount * config('services.credits.unit_price')
            : (float) Plan::findOrFail($request->plan_id)->price_monthly;

        $description = $isCredits
            ? "Compra de {$request->credits_amount} créditos - BusinessCode"
            : 'Assinatura ' . Plan::find($request->plan_id)->name . ' - BusinessCode';

        try {
            $result = $this->mpService->createPixPayment($amount, $description, $request->payer_email);

            $subscription = null;
            if (!$isCredits) {
                $plan = Plan::findOrFail($request->plan_id);
                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'pending',
                    'payment_method' => 'pix',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                    'price' => $amount,
                ]);
            }

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription?->id,
                'mp_payment_id' => $result['mp_payment_id'],
                'type' => $isCredits ? 'credit_purchase' : 'subscription',
                'status' => $result['status'] === 'approved' ? 'approved' : 'pending',
                'payment_method' => 'pix',
                'amount' => $amount,
                'credits_purchased' => $isCredits ? $request->credits_amount : null,
                'pix_qr_code' => $result['qr_code'],
                'pix_qr_code_base64' => $result['qr_code_base64'],
                'pix_expiration' => $result['expiration'],
            ]);

            return ApiResponse::success([
                'payment_id' => $payment->id,
                'mp_payment_id' => $payment->mp_payment_id,
                'status' => $payment->status,
                'pix_qr_code' => $payment->pix_qr_code,
                'pix_qr_code_base64' => $payment->pix_qr_code_base64,
                'pix_expiration' => $payment->pix_expiration,
            ], 'PIX gerado com sucesso.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] PIX creation failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao gerar PIX. Tente novamente.', [], 500);
        }
    }

    public function boleto(CreateBoletoPaymentRequest $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $isCredits = (bool) $request->credits_amount;
        $amount = $isCredits
            ? $request->credits_amount * config('services.credits.unit_price')
            : (float) Plan::findOrFail($request->plan_id)->price_monthly;

        $description = $isCredits
            ? "Compra de {$request->credits_amount} créditos - BusinessCode"
            : 'Assinatura ' . Plan::find($request->plan_id)->name . ' - BusinessCode';

        try {
            $result = $this->mpService->createBoletoPayment($amount, $description, $request->payer_email, $request->payer_document);

            $subscription = null;
            if (!$isCredits) {
                $plan = Plan::findOrFail($request->plan_id);
                $subscription = Subscription::create([
                    'tenant_id' => $tenant->id,
                    'plan_id' => $plan->id,
                    'status' => 'pending',
                    'payment_method' => 'boleto',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addMonth(),
                    'price' => $amount,
                ]);
            }

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription?->id,
                'mp_payment_id' => $result['mp_payment_id'],
                'type' => $isCredits ? 'credit_purchase' : 'subscription',
                'status' => 'pending',
                'payment_method' => 'boleto',
                'amount' => $amount,
                'credits_purchased' => $isCredits ? $request->credits_amount : null,
                'boleto_url' => $result['boleto_url'],
                'boleto_barcode' => $result['barcode'],
            ]);

            return ApiResponse::success([
                'payment_id' => $payment->id,
                'status' => $payment->status,
                'boleto_url' => $payment->boleto_url,
                'boleto_barcode' => $payment->boleto_barcode,
            ], 'Boleto gerado com sucesso.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] Boleto creation failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao gerar boleto. Tente novamente.', [], 500);
        }
    }

    public function credits(PurchaseCreditsRequest $request)
    {
        $tenant = $request->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $amount = $request->credits_amount * config('services.credits.unit_price');
        $description = "Compra de {$request->credits_amount} créditos - BusinessCode";

        try {
            if ($request->payment_method === 'pix') {
                $result = $this->mpService->createPixPayment($amount, $description, $request->payer_email);
                $payment = Payment::create([
                    'tenant_id' => $tenant->id,
                    'mp_payment_id' => $result['mp_payment_id'],
                    'type' => 'credit_purchase',
                    'status' => $result['status'] === 'approved' ? 'approved' : 'pending',
                    'payment_method' => 'pix',
                    'amount' => $amount,
                    'credits_purchased' => $request->credits_amount,
                    'pix_qr_code' => $result['qr_code'],
                    'pix_qr_code_base64' => $result['qr_code_base64'],
                    'pix_expiration' => $result['expiration'],
                ]);

                return ApiResponse::success([
                    'payment_id' => $payment->id,
                    'status' => $payment->status,
                    'pix_qr_code' => $payment->pix_qr_code,
                    'pix_qr_code_base64' => $payment->pix_qr_code_base64,
                    'pix_expiration' => $payment->pix_expiration,
                ], 'PIX gerado.', [], 201);
            }

            if ($request->payment_method === 'boleto') {
                $result = $this->mpService->createBoletoPayment($amount, $description, $request->payer_email, $request->payer_document);
                $payment = Payment::create([
                    'tenant_id' => $tenant->id,
                    'mp_payment_id' => $result['mp_payment_id'],
                    'type' => 'credit_purchase',
                    'status' => 'pending',
                    'payment_method' => 'boleto',
                    'amount' => $amount,
                    'credits_purchased' => $request->credits_amount,
                    'boleto_url' => $result['boleto_url'],
                    'boleto_barcode' => $result['barcode'],
                ]);

                return ApiResponse::success([
                    'payment_id' => $payment->id,
                    'status' => $payment->status,
                    'boleto_url' => $payment->boleto_url,
                    'boleto_barcode' => $payment->boleto_barcode,
                ], 'Boleto gerado.', [], 201);
            }

            // credit_card — one-time payment
            $mpResult = (new \MercadoPago\Client\Payment\PaymentClient())->create([
                'transaction_amount' => $amount,
                'description' => $description,
                'token' => $request->card_token,
                'installments' => 1,
                'payment_method_id' => 'visa', // MP auto-detects from token
                'payer' => ['email' => $request->payer_email],
            ]);

            $payment = Payment::create([
                'tenant_id' => $tenant->id,
                'mp_payment_id' => (string) $mpResult->id,
                'type' => 'credit_purchase',
                'status' => $mpResult->status === 'approved' ? 'approved' : 'pending',
                'payment_method' => 'credit_card',
                'amount' => $amount,
                'credits_purchased' => $request->credits_amount,
                'paid_at' => $mpResult->status === 'approved' ? now() : null,
            ]);

            // If immediately approved, add credits
            if ($payment->status === 'approved') {
                DB::transaction(function () use ($tenant, $payment, $request) {
                    $locked = \App\Models\Tenant::lockForUpdate()->find($tenant->id);
                    $newBalance = (int) $locked->credits_balance + $request->credits_amount;
                    $locked->update(['credits_balance' => $newBalance]);

                    DB::table('credit_transactions')->insert([
                        'tenant_id' => $locked->id,
                        'type' => 'credit',
                        'amount' => $request->credits_amount,
                        'balance_after' => $newBalance,
                        'reference_type' => 'credit_purchase',
                        'reference_id' => $payment->id,
                        'description' => "Compra de {$request->credits_amount} créditos",
                        'meta' => json_encode(['payment_id' => $payment->id]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            }

            return ApiResponse::success([
                'payment_id' => $payment->id,
                'status' => $payment->status,
            ], 'Pagamento processado.', [], 201);

        } catch (\Exception $e) {
            Log::error('[MercadoPago] credit purchase failed', ['tenant_id' => $tenant->id, 'error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao processar pagamento. Tente novamente.', [], 500);
        }
    }

    public function index()
    {
        $tenant = request()->user()->tenant;
        if (!$tenant) return ApiResponse::error('Tenant não encontrado.', [], 404);

        $payments = Payment::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return ApiResponse::paginated($payments);
    }

    public function status(int $id)
    {
        $tenant = request()->user()->tenant;
        $payment = Payment::where('id', $id)->where('tenant_id', $tenant->id)->firstOrFail();

        return ApiResponse::success([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'paid_at' => $payment->paid_at,
        ]);
    }
}
```

- [ ] **Step 4: Create WebhookController**

```php
<?php
// backend/app/Http/Controllers/API/V1/WebhookController.php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function mercadopago(Request $request)
    {
        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId = $request->input('data.id', '');

        if (!MercadoPagoService::validateWebhookSignature($xSignature, $xRequestId, (string) $dataId)) {
            Log::warning('[MercadoPago] webhook invalid signature', [
                'x_signature' => $xSignature,
                'data_id' => $dataId,
            ]);
            return response()->json(['status' => 'invalid signature'], 401);
        }

        $type = $request->input('type', '');

        Log::info('[MercadoPago] webhook received', ['type' => $type, 'data_id' => $dataId]);

        try {
            $mpService = app(MercadoPagoService::class);
            $mpService->processWebhook($type, (string) $dataId);
        } catch (\Exception $e) {
            Log::error('[MercadoPago] webhook processing error', [
                'type' => $type,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
            ]);
        }

        // Always return 200 to MP
        return response()->json(['status' => 'ok'], 200);
    }
}
```

- [ ] **Step 5: Create Admin CouponController**

```php
<?php
// backend/app/Http/Controllers/API/V1/Admin/CouponController.php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCouponRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;

class CouponController extends Controller
{
    public function index()
    {
        return ApiResponse::success(Coupon::orderBy('created_at', 'desc')->get());
    }

    public function store(StoreCouponRequest $request)
    {
        $data = $request->validated();
        $data['code'] = strtoupper($data['code']);
        $coupon = Coupon::create($data);
        return ApiResponse::success($coupon, 'Cupom criado.', [], 201);
    }

    public function update(StoreCouponRequest $request, int $id)
    {
        $coupon = Coupon::findOrFail($id);
        $data = $request->validated();
        $data['code'] = strtoupper($data['code']);
        $coupon->update($data);
        return ApiResponse::success($coupon, 'Cupom atualizado.');
    }

    public function destroy(int $id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->delete();
        return ApiResponse::success([], 'Cupom removido.');
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/CheckoutController.php backend/app/Http/Controllers/API/V1/SubscriptionController.php backend/app/Http/Controllers/API/V1/PaymentController.php backend/app/Http/Controllers/API/V1/WebhookController.php backend/app/Http/Controllers/API/V1/Admin/CouponController.php
git commit -m "feat(checkout): add Checkout, Subscription, Payment, Webhook, Coupon controllers"
```

---

## Task 7: Backend — Routes

**Files:**
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Add checkout routes to api.php**

Add these route groups to the existing `api.php`. Place the webhook route OUTSIDE the auth middleware group (it's public). Place the checkout config and validate-coupon as public. Place the rest inside the existing `auth:sanctum` group.

After the existing public plans route `Route::get('/plans', ...)`, add:

```php
// Checkout public routes
Route::post('/checkout/validate-coupon', [\App\Http\Controllers\API\V1\CheckoutController::class, 'validateCoupon']);
Route::get('/checkout/config', [\App\Http\Controllers\API\V1\CheckoutController::class, 'config']);

// Mercado Pago webhook (no auth, validated by signature)
Route::post('/webhooks/mercadopago', [\App\Http\Controllers\API\V1\WebhookController::class, 'mercadopago']);
```

Inside the existing `auth:sanctum` middleware group, add:

```php
// Subscriptions
Route::post('/subscriptions', [\App\Http\Controllers\API\V1\SubscriptionController::class, 'store']);
Route::get('/subscriptions/current', [\App\Http\Controllers\API\V1\SubscriptionController::class, 'current']);
Route::post('/subscriptions/cancel', [\App\Http\Controllers\API\V1\SubscriptionController::class, 'cancel']);

// Payments
Route::post('/payments/pix', [\App\Http\Controllers\API\V1\PaymentController::class, 'pix']);
Route::post('/payments/boleto', [\App\Http\Controllers\API\V1\PaymentController::class, 'boleto']);
Route::post('/payments/credits', [\App\Http\Controllers\API\V1\PaymentController::class, 'credits']);
Route::get('/payments', [\App\Http\Controllers\API\V1\PaymentController::class, 'index']);
Route::get('/payments/{id}/status', [\App\Http\Controllers\API\V1\PaymentController::class, 'status']);
```

Inside the existing admin middleware group, add:

```php
// Coupons (admin)
Route::get('/admin/coupons', [\App\Http\Controllers\API\V1\Admin\CouponController::class, 'index']);
Route::post('/admin/coupons', [\App\Http\Controllers\API\V1\Admin\CouponController::class, 'store']);
Route::put('/admin/coupons/{id}', [\App\Http\Controllers\API\V1\Admin\CouponController::class, 'update']);
Route::delete('/admin/coupons/{id}', [\App\Http\Controllers\API\V1\Admin\CouponController::class, 'destroy']);
```

- [ ] **Step 2: Verify routes**

Run: `cd /c/xampp/htdocs/new_saas/backend && php artisan route:list --path=checkout && php artisan route:list --path=subscription && php artisan route:list --path=payment && php artisan route:list --path=webhook && php artisan route:list --path=coupon`

Expected: all new routes listed.

- [ ] **Step 3: Commit**

```bash
git add backend/routes/api.php
git commit -m "feat(checkout): register checkout, subscription, payment, webhook, coupon routes"
```

---

## Task 8: Frontend — Checkout Store

**Files:**
- Create: `frontend/src/stores/checkout.ts`

- [ ] **Step 1: Create the Pinia store**

```typescript
// frontend/src/stores/checkout.ts

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

export interface CheckoutConfig {
  mp_public_key: string
  credit_unit_price: number
  credit_min_purchase: number
}

export interface CouponData {
  id: number
  code: string
  discount_type: 'percentage' | 'fixed'
  discount_value: number
}

export interface PixData {
  payment_id: number
  mp_payment_id: string
  status: string
  pix_qr_code: string
  pix_qr_code_base64: string
  pix_expiration: string
}

export interface BoletoData {
  payment_id: number
  status: string
  boleto_url: string
  boleto_barcode: string
}

export const useCheckoutStore = defineStore('checkout', () => {
  const { get, post } = useApi()

  const config = ref<CheckoutConfig | null>(null)
  const coupon = ref<CouponData | null>(null)
  const calculatedDiscount = ref<number>(0)
  const paymentStatus = ref<'idle' | 'processing' | 'success' | 'error'>('idle')
  const errorMessage = ref<string>('')
  const pixData = ref<PixData | null>(null)
  const boletoData = ref<BoletoData | null>(null)
  const pollingInterval = ref<ReturnType<typeof setInterval> | null>(null)

  async function fetchConfig() {
    if (config.value) return config.value
    config.value = await get<CheckoutConfig>('/checkout/config')
    return config.value
  }

  async function validateCoupon(code: string, planId?: number): Promise<boolean> {
    try {
      const res = await post<{ coupon: CouponData; calculated_discount: number | null }>('/checkout/validate-coupon', {
        code,
        plan_id: planId,
      })
      coupon.value = res.coupon
      calculatedDiscount.value = res.calculated_discount ?? 0
      return true
    } catch (e: any) {
      coupon.value = null
      calculatedDiscount.value = 0
      errorMessage.value = e?.response?.data?.message || 'Cupom inválido.'
      return false
    }
  }

  function clearCoupon() {
    coupon.value = null
    calculatedDiscount.value = 0
  }

  async function createSubscription(planId: number, cardToken: string, payerEmail: string, couponCode?: string) {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const res = await post<any>('/subscriptions', {
        plan_id: planId,
        card_token: cardToken,
        payer_email: payerEmail,
        coupon_code: couponCode,
      })
      paymentStatus.value = 'success'
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao processar pagamento.'
      throw e
    }
  }

  async function createPixPayment(planId: number | null, payerEmail: string, creditsAmount?: number) {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const payload: Record<string, unknown> = { payer_email: payerEmail }
      if (creditsAmount) {
        payload.credits_amount = creditsAmount
      } else {
        payload.plan_id = planId
      }
      const res = await post<PixData>('/payments/pix', payload)
      pixData.value = res
      paymentStatus.value = 'idle'
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao gerar PIX.'
      throw e
    }
  }

  async function createBoletoPayment(planId: number | null, payerEmail: string, payerDocument: string, creditsAmount?: number) {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const payload: Record<string, unknown> = { payer_email: payerEmail, payer_document: payerDocument }
      if (creditsAmount) {
        payload.credits_amount = creditsAmount
      } else {
        payload.plan_id = planId
      }
      const res = await post<BoletoData>('/payments/boleto', payload)
      boletoData.value = res
      paymentStatus.value = 'idle'
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao gerar boleto.'
      throw e
    }
  }

  async function purchaseCredits(amount: number, method: string, payerEmail: string, cardToken?: string, payerDocument?: string) {
    paymentStatus.value = 'processing'
    errorMessage.value = ''
    try {
      const res = await post<any>('/payments/credits', {
        credits_amount: amount,
        payment_method: method,
        card_token: cardToken,
        payer_email: payerEmail,
        payer_document: payerDocument,
      })

      if (res.pix_qr_code) {
        pixData.value = res
      } else if (res.boleto_url) {
        boletoData.value = res
      } else {
        paymentStatus.value = 'success'
      }
      return res
    } catch (e: any) {
      paymentStatus.value = 'error'
      errorMessage.value = e?.response?.data?.message || 'Erro ao processar pagamento.'
      throw e
    }
  }

  async function pollPaymentStatus(paymentId: number, onApproved: () => void) {
    let failures = 0
    stopPolling()
    pollingInterval.value = setInterval(async () => {
      try {
        const res = await get<{ status: string }>(`/payments/${paymentId}/status`)
        if (res.status === 'approved') {
          stopPolling()
          paymentStatus.value = 'success'
          onApproved()
        }
        failures = 0
      } catch {
        failures++
        if (failures >= 3) {
          stopPolling()
          errorMessage.value = 'Não foi possível verificar o status do pagamento.'
        }
      }
    }, 5000)
  }

  function stopPolling() {
    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
    }
  }

  function reset() {
    coupon.value = null
    calculatedDiscount.value = 0
    paymentStatus.value = 'idle'
    errorMessage.value = ''
    pixData.value = null
    boletoData.value = null
    stopPolling()
  }

  return {
    config, coupon, calculatedDiscount, paymentStatus, errorMessage, pixData, boletoData,
    fetchConfig, validateCoupon, clearCoupon,
    createSubscription, createPixPayment, createBoletoPayment, purchaseCredits,
    pollPaymentStatus, stopPolling, reset,
  }
})
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/stores/checkout.ts
git commit -m "feat(checkout): add useCheckoutStore with MP payment flows and polling"
```

---

## Task 9: Frontend — MercadoPago.js Setup & index.html

**Files:**
- Modify: `frontend/index.html`

- [ ] **Step 1: Add MercadoPago.js SDK to index.html**

Add before the closing `</head>` tag:

```html
<script src="https://sdk.mercadopago.com/js/v2"></script>
```

- [ ] **Step 2: Commit**

```bash
git add frontend/index.html
git commit -m "feat(checkout): add MercadoPago.js SDK to index.html"
```

---

## Task 10: Frontend — Checkout Components

**Files:**
- Create: `frontend/src/components/checkout/OrderSummary.vue`
- Create: `frontend/src/components/checkout/PixPayment.vue`
- Create: `frontend/src/components/checkout/BoletoPayment.vue`
- Create: `frontend/src/components/checkout/CheckoutForm.vue`

- [ ] **Step 1: Create OrderSummary.vue**

```vue
<!-- frontend/src/components/checkout/OrderSummary.vue -->
<template>
  <div class="bc-order-summary">
    <h3 style="font-size:1.15rem;font-weight:700;margin-bottom:1.5rem">Resumo do Pedido</h3>

    <div style="border-bottom:1px solid rgba(255,255,255,0.06);padding-bottom:1.25rem;margin-bottom:1.25rem">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
          <div style="font-weight:700;font-size:1.05rem">{{ title }}</div>
          <div style="font-size:.82rem;color:var(--bc-text-muted)">{{ subtitle }}</div>
        </div>
        <span style="font-weight:800;font-size:1.1rem;font-family:'JetBrains Mono',monospace">R${{ formatPrice(basePrice) }}</span>
      </div>

      <!-- Coupon -->
      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">Cupom de desconto</label>
        <div class="d-flex gap-2">
          <input
            v-model="couponInput"
            type="text"
            class="form-control form-control-sm"
            placeholder="Digite o código"
            :disabled="!!appliedCoupon"
            style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;font-size:.85rem"
          />
          <button
            v-if="!appliedCoupon"
            class="btn btn-sm btn-outline-secondary"
            style="border-radius:8px;white-space:nowrap"
            :disabled="!couponInput.trim() || validating"
            @click="applyCoupon"
          >
            {{ validating ? '...' : 'Aplicar' }}
          </button>
          <button
            v-else
            class="btn btn-sm btn-outline-danger"
            style="border-radius:8px;white-space:nowrap"
            @click="removeCoupon"
          >
            Remover
          </button>
        </div>
        <div v-if="couponError" style="color:#ef4444;font-size:.78rem;margin-top:.3rem">{{ couponError }}</div>
      </div>
    </div>

    <!-- Totals -->
    <div style="display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.25rem">
      <div class="d-flex justify-content-between" style="font-size:.9rem;color:var(--bc-text-muted)">
        <span>Subtotal</span>
        <span>R${{ formatPrice(basePrice) }}</span>
      </div>
      <div v-if="discount > 0" class="d-flex justify-content-between" style="font-size:.9rem;color:#10b981">
        <span>Desconto</span>
        <span>-R${{ formatPrice(discount) }}</span>
      </div>
      <div class="d-flex justify-content-between align-items-center" style="padding-top:.6rem;border-top:1px solid rgba(255,255,255,0.06)">
        <span style="font-weight:700;font-size:1rem">Total</span>
        <span style="font-weight:800;font-size:1.6rem;font-family:'JetBrains Mono',monospace;letter-spacing:-.02em">R${{ formatPrice(total) }}</span>
      </div>
    </div>

    <!-- Submit button -->
    <button
      class="btn btn-primary w-100"
      style="border-radius:10px;padding:.85rem;font-weight:700;font-size:.95rem"
      :disabled="processing"
      @click="$emit('submit')"
    >
      <i v-if="!processing" class="ti ti-lock me-2" style="font-size:1rem"></i>
      <span v-if="processing" class="spinner-border spinner-border-sm me-2"></span>
      {{ processing ? 'Processando...' : 'Finalizar Compra' }}
    </button>

    <p style="text-align:center;font-size:.72rem;color:var(--bc-text-muted);margin-top:.8rem;padding:0 .5rem">
      Ao clicar em "Finalizar Compra", você concorda com nossos
      <a href="/terms" style="color:#0064ff">Termos de Uso</a> e
      <a href="/privacy" style="color:#0064ff">Política de Privacidade</a>.
    </p>

    <!-- Trust badges -->
    <div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:1.5rem;opacity:.4">
      <div style="display:flex;align-items:center;gap:.3rem">
        <i class="ti ti-shield-check" style="font-size:.85rem"></i>
        <span style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em">SSL</span>
      </div>
      <div style="width:1px;height:14px;background:rgba(255,255,255,0.15)"></div>
      <div style="display:flex;align-items:center;gap:.3rem">
        <i class="ti ti-credit-card" style="font-size:.85rem"></i>
        <span style="font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em">Mercado Pago</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useCheckoutStore } from '@/stores/checkout'

const props = defineProps<{
  title: string
  subtitle: string
  basePrice: number
  planId?: number
  processing: boolean
}>()

defineEmits<{ submit: [] }>()

const store = useCheckoutStore()
const couponInput = ref('')
const validating = ref(false)
const couponError = ref('')

const appliedCoupon = computed(() => store.coupon)
const discount = computed(() => store.calculatedDiscount)
const total = computed(() => Math.max(0, props.basePrice - discount.value))

async function applyCoupon() {
  couponError.value = ''
  validating.value = true
  const valid = await store.validateCoupon(couponInput.value, props.planId)
  validating.value = false
  if (!valid) {
    couponError.value = store.errorMessage || 'Cupom inválido.'
  }
}

function removeCoupon() {
  store.clearCoupon()
  couponInput.value = ''
  couponError.value = ''
}

function formatPrice(v: number) {
  return v.toFixed(2).replace('.', ',')
}
</script>

<style scoped>
.bc-order-summary {
  background: var(--bc-gray, rgba(22, 27, 69, 0.6));
  border: 1px solid rgba(255,255,255,0.05);
  border-radius: 16px;
  padding: 28px 24px;
  position: sticky;
  top: 100px;
}
</style>
```

- [ ] **Step 2: Create PixPayment.vue**

```vue
<!-- frontend/src/components/checkout/PixPayment.vue -->
<template>
  <div class="text-center">
    <!-- QR Code -->
    <div v-if="pixData" style="background:white;border-radius:12px;padding:20px;display:inline-block;margin-bottom:1rem">
      <img :src="'data:image/png;base64,' + pixData.pix_qr_code_base64" alt="QR Code PIX" style="width:200px;height:200px" />
    </div>

    <!-- Copy code -->
    <div v-if="pixData?.pix_qr_code" class="mb-3">
      <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">Código PIX (copia e cola)</label>
      <div class="d-flex gap-2">
        <input
          :value="pixData.pix_qr_code"
          readonly
          class="form-control form-control-sm"
          style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;font-size:.75rem;font-family:'JetBrains Mono',monospace"
        />
        <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;white-space:nowrap" @click="copyCode">
          <i class="ti ti-copy me-1"></i>{{ copied ? 'Copiado!' : 'Copiar' }}
        </button>
      </div>
    </div>

    <!-- Timer -->
    <div v-if="remaining > 0" style="font-size:.85rem;color:var(--bc-text-muted)">
      <i class="ti ti-clock me-1"></i>Expira em {{ formatTime(remaining) }}
    </div>
    <div v-else-if="pixData" style="font-size:.85rem;color:#ef4444">
      <i class="ti ti-alert-circle me-1"></i>QR Code expirado. Gere um novo.
    </div>

    <!-- Status -->
    <div v-if="polling" class="mt-3" style="font-size:.85rem;color:var(--bc-text-muted)">
      <span class="spinner-border spinner-border-sm me-2"></span>Aguardando pagamento...
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useCheckoutStore, type PixData } from '@/stores/checkout'

const props = defineProps<{ pixData: PixData | null }>()
const emit = defineEmits<{ approved: [] }>()

const store = useCheckoutStore()
const copied = ref(false)
const remaining = ref(0)
const polling = ref(false)
let timer: ReturnType<typeof setInterval> | null = null

function copyCode() {
  if (!props.pixData?.pix_qr_code) return
  navigator.clipboard.writeText(props.pixData.pix_qr_code)
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

function formatTime(seconds: number) {
  const m = Math.floor(seconds / 60)
  const s = seconds % 60
  return `${m}:${s.toString().padStart(2, '0')}`
}

function startTimer() {
  if (!props.pixData?.pix_expiration) return
  const expiration = new Date(props.pixData.pix_expiration).getTime()

  timer = setInterval(() => {
    const now = Date.now()
    remaining.value = Math.max(0, Math.floor((expiration - now) / 1000))
    if (remaining.value <= 0 && timer) {
      clearInterval(timer)
      store.stopPolling()
      polling.value = false
    }
  }, 1000)
}

function startPolling() {
  if (!props.pixData?.payment_id) return
  polling.value = true
  store.pollPaymentStatus(props.pixData.payment_id, () => {
    polling.value = false
    emit('approved')
  })
}

watch(() => props.pixData, (val) => {
  if (val) {
    startTimer()
    startPolling()
  }
}, { immediate: true })

onUnmounted(() => {
  if (timer) clearInterval(timer)
  store.stopPolling()
})
</script>
```

- [ ] **Step 3: Create BoletoPayment.vue**

```vue
<!-- frontend/src/components/checkout/BoletoPayment.vue -->
<template>
  <div>
    <!-- Before generation: CPF/CNPJ form -->
    <div v-if="!boletoData">
      <div class="mb-3">
        <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">CPF ou CNPJ</label>
        <input
          v-model="document"
          type="text"
          class="form-control"
          placeholder="000.000.000-00"
          style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:.75rem 1rem"
        />
      </div>
      <button
        class="btn btn-primary w-100"
        style="border-radius:10px;padding:.75rem;font-weight:700"
        :disabled="!document.trim() || generating"
        @click="$emit('generate', document)"
      >
        <span v-if="generating" class="spinner-border spinner-border-sm me-2"></span>
        {{ generating ? 'Gerando...' : 'Gerar Boleto' }}
      </button>
    </div>

    <!-- After generation -->
    <div v-else class="text-center">
      <div style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.15);border-radius:12px;padding:1.5rem;margin-bottom:1rem">
        <i class="ti ti-file-invoice" style="font-size:2rem;color:#10b981;display:block;margin-bottom:.5rem"></i>
        <div style="font-weight:700;margin-bottom:.25rem">Boleto gerado!</div>
        <div style="font-size:.82rem;color:var(--bc-text-muted)">O pagamento pode levar 1-3 dias úteis para ser confirmado.</div>
      </div>

      <!-- Barcode -->
      <div v-if="boletoData.boleto_barcode" class="mb-3">
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--bc-text-muted);display:block;margin-bottom:.4rem">Linha digitável</label>
        <div class="d-flex gap-2">
          <input
            :value="boletoData.boleto_barcode"
            readonly
            class="form-control form-control-sm"
            style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:8px;font-size:.72rem;font-family:'JetBrains Mono',monospace"
          />
          <button class="btn btn-sm btn-outline-primary" style="border-radius:8px;white-space:nowrap" @click="copyBarcode">
            <i class="ti ti-copy me-1"></i>{{ copied ? 'Copiado!' : 'Copiar' }}
          </button>
        </div>
      </div>

      <!-- Open PDF -->
      <a
        v-if="boletoData.boleto_url"
        :href="boletoData.boleto_url"
        target="_blank"
        class="btn btn-outline-primary w-100"
        style="border-radius:10px;padding:.7rem"
      >
        <i class="ti ti-external-link me-1"></i>Abrir Boleto (PDF)
      </a>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { type BoletoData } from '@/stores/checkout'

defineProps<{
  boletoData: BoletoData | null
  generating: boolean
}>()

defineEmits<{ generate: [document: string] }>()

const document = ref('')
const copied = ref(false)

function copyBarcode() {
  // Access props via template — this function is used in template @click
  const el = (globalThis.document as Document).querySelector('.bc-barcode-input') as HTMLInputElement
  if (el) navigator.clipboard.writeText(el.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}
</script>
```

- [ ] **Step 4: Create CheckoutForm.vue**

```vue
<!-- frontend/src/components/checkout/CheckoutForm.vue -->
<template>
  <div>
    <!-- Payment method tabs -->
    <div class="d-flex gap-2 mb-4">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        class="btn flex-fill"
        :class="activeTab === tab.id ? 'btn-primary' : 'btn-outline-secondary'"
        style="border-radius:10px;padding:.6rem;font-size:.85rem;font-weight:600"
        @click="activeTab = tab.id"
      >
        <i :class="tab.icon" class="me-1"></i>{{ tab.label }}
      </button>
    </div>

    <!-- Credit Card Tab -->
    <div v-if="activeTab === 'credit_card'" class="bc-checkout-section">
      <div class="d-flex align-items-center gap-2 mb-4">
        <i class="ti ti-credit-card" style="color:#0064ff;font-size:1.2rem"></i>
        <h3 style="font-size:1.05rem;font-weight:700;margin:0">Cartão de Crédito</h3>
      </div>

      <div class="row g-3">
        <div class="col-12">
          <label class="bc-label">Número do cartão</label>
          <input v-model="card.number" type="text" class="form-control bc-input" placeholder="0000 0000 0000 0000" maxlength="19" @input="formatCardNumber" />
        </div>
        <div class="col-6">
          <label class="bc-label">Validade</label>
          <input v-model="card.expiry" type="text" class="form-control bc-input" placeholder="MM/AA" maxlength="5" @input="formatExpiry" />
        </div>
        <div class="col-6">
          <label class="bc-label">CVC</label>
          <input v-model="card.cvc" type="text" class="form-control bc-input" placeholder="123" maxlength="4" />
        </div>
        <div class="col-12">
          <label class="bc-label">Nome no cartão</label>
          <input v-model="card.name" type="text" class="form-control bc-input" placeholder="Nome como está no cartão" />
        </div>
      </div>
    </div>

    <!-- PIX Tab -->
    <div v-if="activeTab === 'pix'" class="bc-checkout-section">
      <div class="d-flex align-items-center gap-2 mb-4">
        <i class="ti ti-qrcode" style="color:#0064ff;font-size:1.2rem"></i>
        <h3 style="font-size:1.05rem;font-weight:700;margin:0">PIX</h3>
      </div>

      <div v-if="!pixData" style="text-align:center;padding:1rem 0">
        <p style="font-size:.9rem;color:var(--bc-text-muted);margin-bottom:1rem">
          Clique em "Finalizar Compra" para gerar o QR Code PIX.
        </p>
        <p style="font-size:.78rem;color:var(--bc-text-muted)">
          <i class="ti ti-clock me-1"></i>Pagamento confirmado em segundos
        </p>
      </div>

      <PixPayment v-else :pix-data="pixData" @approved="$emit('success')" />
    </div>

    <!-- Boleto Tab -->
    <div v-if="activeTab === 'boleto'" class="bc-checkout-section">
      <div class="d-flex align-items-center gap-2 mb-4">
        <i class="ti ti-file-invoice" style="color:#0064ff;font-size:1.2rem"></i>
        <h3 style="font-size:1.05rem;font-weight:700;margin:0">Boleto Bancário</h3>
      </div>

      <BoletoPayment
        :boleto-data="boletoData"
        :generating="store.paymentStatus === 'processing'"
        @generate="(doc) => $emit('boleto-generate', doc)"
      />
    </div>

    <!-- Error -->
    <div v-if="store.errorMessage" class="mt-3" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.15);border-radius:10px;padding:.75rem 1rem">
      <div style="font-size:.85rem;color:#ef4444">
        <i class="ti ti-alert-circle me-1"></i>{{ store.errorMessage }}
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useCheckoutStore, type PixData, type BoletoData } from '@/stores/checkout'
import PixPayment from './PixPayment.vue'
import BoletoPayment from './BoletoPayment.vue'

defineProps<{
  pixData: PixData | null
  boletoData: BoletoData | null
}>()

defineEmits<{
  success: []
  'boleto-generate': [document: string]
}>()

const store = useCheckoutStore()

const activeTab = ref<'credit_card' | 'pix' | 'boleto'>('credit_card')

const card = ref({
  number: '',
  expiry: '',
  cvc: '',
  name: '',
})

const tabs = [
  { id: 'credit_card' as const, label: 'Cartão', icon: 'ti ti-credit-card' },
  { id: 'pix' as const, label: 'PIX', icon: 'ti ti-qrcode' },
  { id: 'boleto' as const, label: 'Boleto', icon: 'ti ti-file-invoice' },
]

function formatCardNumber() {
  card.value.number = card.value.number
    .replace(/\D/g, '')
    .replace(/(\d{4})(?=\d)/g, '$1 ')
    .slice(0, 19)
}

function formatExpiry() {
  let v = card.value.expiry.replace(/\D/g, '')
  if (v.length >= 2) v = v.slice(0, 2) + '/' + v.slice(2)
  card.value.expiry = v.slice(0, 5)
}

function getActiveTab() {
  return activeTab.value
}

function getCardData() {
  return card.value
}

defineExpose({ getActiveTab, getCardData })
</script>

<style scoped>
.bc-checkout-section {
  background: var(--bc-gray, rgba(22, 27, 69, 0.4));
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  padding: 24px;
}
.bc-label {
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--bc-text-muted);
  display: block;
  margin-bottom: .35rem;
}
.bc-input {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  border-radius: 10px;
  padding: .75rem 1rem;
  color: var(--bc-text);
  font-size: .9rem;
}
.bc-input:focus {
  border-color: rgba(0,100,255,0.3);
  box-shadow: 0 0 0 3px rgba(0,100,255,0.08);
}
</style>
```

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/checkout/
git commit -m "feat(checkout): add CheckoutForm, OrderSummary, PixPayment, BoletoPayment components"
```

---

## Task 11: Frontend — Checkout Pages

**Files:**
- Create: `frontend/src/pages/checkout/CheckoutPage.vue`
- Create: `frontend/src/pages/checkout/CheckoutThankYou.vue`
- Create: `frontend/src/pages/checkout/PricingPlans.vue`
- Create: `frontend/src/pages/settings/CreditPurchase.vue`

- [ ] **Step 1: Create CheckoutPage.vue**

```vue
<!-- frontend/src/pages/checkout/CheckoutPage.vue -->
<template>
  <div :class="isPublic ? '' : ''">
    <div style="max-width:1100px;margin:0 auto" :style="isPublic ? 'padding: 2rem 1rem' : ''">
      <div class="mb-4">
        <h2 style="font-size:1.6rem;font-weight:800;letter-spacing:-.03em;margin-bottom:.3rem">Checkout</h2>
        <p style="font-size:.9rem;color:var(--bc-text-muted)">Complete sua assinatura para desbloquear o plano.</p>
      </div>

      <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

      <div v-else-if="plan" class="row g-4">
        <div class="col-12 col-lg-7">
          <CheckoutForm
            ref="checkoutFormRef"
            :pix-data="store.pixData"
            :boleto-data="store.boletoData"
            @success="onPaymentSuccess"
            @boleto-generate="onBoletoGenerate"
          />
        </div>
        <div class="col-12 col-lg-5">
          <OrderSummary
            :title="plan.name"
            :subtitle="'Cobrança mensal'"
            :base-price="Number(plan.price_monthly)"
            :plan-id="plan.id"
            :processing="store.paymentStatus === 'processing'"
            @submit="handleSubmit"
          />
        </div>
      </div>

      <div v-else class="text-center py-5">
        <p style="color:var(--bc-text-muted)">Plano não encontrado.</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'
import { useCheckoutStore } from '@/stores/checkout'
import { useAuthStore } from '@/stores/auth'
import CheckoutForm from '@/components/checkout/CheckoutForm.vue'
import OrderSummary from '@/components/checkout/OrderSummary.vue'

const route = useRoute()
const router = useRouter()
const { get } = useApi()
const store = useCheckoutStore()
const auth = useAuthStore()

const plan = ref<any>(null)
const loading = ref(true)
const checkoutFormRef = ref<InstanceType<typeof CheckoutForm> | null>(null)

const isPublic = computed(() => route.path.startsWith('/plans'))

onMounted(async () => {
  store.reset()
  await store.fetchConfig()

  try {
    const slug = route.params.planSlug as string
    const plans = await get<any[]>('/plans')
    const list = Array.isArray(plans) ? plans : ((plans as any)?.data ?? [])
    plan.value = list.find((p: any) => p.slug === slug) ?? null
  } catch {
    plan.value = null
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  store.stopPolling()
})

async function handleSubmit() {
  if (!plan.value || !checkoutFormRef.value) return

  const tab = checkoutFormRef.value.getActiveTab()
  const email = auth.user?.email ?? ''

  if (tab === 'credit_card') {
    await handleCardPayment(email)
  } else if (tab === 'pix') {
    await handlePixPayment(email)
  }
  // boleto is handled via @boleto-generate event
}

async function handleCardPayment(email: string) {
  if (!plan.value || !checkoutFormRef.value || !store.config) return

  const cardData = checkoutFormRef.value.getCardData()

  // Tokenize with MercadoPago.js
  const mp = new (window as any).MercadoPago(store.config.mp_public_key)
  const cardForm = mp.fields.createCardToken({
    cardNumber: cardData.number.replace(/\s/g, ''),
    cardholderName: cardData.name,
    cardExpirationMonth: cardData.expiry.split('/')[0],
    cardExpirationYear: '20' + cardData.expiry.split('/')[1],
    securityCode: cardData.cvc,
  })

  try {
    const tokenResult = await cardForm
    const couponCode = store.coupon?.code
    await store.createSubscription(plan.value.id, tokenResult.id, email, couponCode)
    onPaymentSuccess()
  } catch {
    // error is already in store.errorMessage
  }
}

async function handlePixPayment(email: string) {
  if (!plan.value) return
  try {
    await store.createPixPayment(plan.value.id, email)
    // PixPayment component handles polling and emits 'approved'
  } catch {
    // error in store
  }
}

async function onBoletoGenerate(document: string) {
  if (!plan.value) return
  const email = auth.user?.email ?? ''
  try {
    await store.createBoletoPayment(plan.value.id, email, document)
  } catch {
    // error in store
  }
}

function onPaymentSuccess() {
  auth.refreshUser()
  router.push({
    path: '/checkout/thank-you',
    query: { plan: plan.value?.name },
  })
}
</script>
```

- [ ] **Step 2: Create CheckoutThankYou.vue**

```vue
<!-- frontend/src/pages/checkout/CheckoutThankYou.vue -->
<template>
  <div style="min-height:80vh;display:flex;align-items:center;justify-content:center;padding:2rem">
    <div style="max-width:700px;width:100%;text-align:center">
      <!-- Success icon -->
      <div style="width:80px;height:80px;border-radius:50%;background:rgba(16,185,129,0.1);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1.5rem">
        <i class="ti ti-circle-check" style="font-size:2.5rem;color:#10b981"></i>
      </div>

      <h1 style="font-size:2rem;font-weight:800;letter-spacing:-.03em;margin-bottom:.5rem">
        Bem-vindo ao plano {{ planName }}!
      </h1>
      <p style="font-size:1rem;color:var(--bc-text-muted);max-width:500px;margin:0 auto 2rem">
        Sua assinatura está ativa. Você desbloqueou todos os recursos do plano.
      </p>

      <!-- Next steps cards -->
      <div class="row g-3" style="text-align:left;margin-top:2rem">
        <div class="col-12 col-md-4">
          <router-link to="/campaigns/new" class="bc-next-card">
            <i class="ti ti-rocket" style="font-size:1.5rem;color:#0064ff;margin-bottom:.75rem;display:block"></i>
            <div style="font-weight:700;margin-bottom:.25rem">Criar Campanha</div>
            <div style="font-size:.82rem;color:var(--bc-text-muted)">Lance sua primeira campanha multi-canal.</div>
          </router-link>
        </div>
        <div class="col-12 col-md-4">
          <router-link to="/contacts" class="bc-next-card">
            <i class="ti ti-users-plus" style="font-size:1.5rem;color:#0064ff;margin-bottom:.75rem;display:block"></i>
            <div style="font-weight:700;margin-bottom:.25rem">Importar Contatos</div>
            <div style="font-size:.82rem;color:var(--bc-text-muted)">Sincronize seu CRM ou importe via CSV.</div>
          </router-link>
        </div>
        <div class="col-12 col-md-4">
          <router-link to="/dashboard" class="bc-next-card">
            <i class="ti ti-layout-dashboard" style="font-size:1.5rem;color:#0064ff;margin-bottom:.75rem;display:block"></i>
            <div style="font-weight:700;margin-bottom:.25rem">Ver Dashboard</div>
            <div style="font-size:.82rem;color:var(--bc-text-muted)">Monitore métricas e performance.</div>
          </router-link>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const planName = computed(() => route.query.plan as string || 'Pro')
</script>

<style scoped>
.bc-next-card {
  display: block;
  background: var(--bc-gray, rgba(22, 27, 69, 0.4));
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 14px;
  padding: 20px;
  text-decoration: none;
  color: inherit;
  transition: all .2s;
  height: 100%;
}
.bc-next-card:hover {
  transform: translateY(-2px);
  border-color: rgba(0,100,255,0.2);
  box-shadow: 0 4px 16px rgba(0,0,0,.2);
}
</style>
```

- [ ] **Step 3: Create PricingPlans.vue**

```vue
<!-- frontend/src/pages/checkout/PricingPlans.vue -->
<template>
  <div style="min-height:100vh;background:#080c25;color:#dee0ff;padding-bottom:4rem">
    <!-- Navbar -->
    <nav style="position:fixed;top:0;width:100%;z-index:50;background:rgba(15,23,42,0.6);backdrop-filter:blur(16px);display:flex;justify-content:space-between;align-items:center;padding:0 2rem;height:64px">
      <div style="display:flex;align-items:center;gap:2rem">
        <span style="font-size:1.3rem;font-weight:800;letter-spacing:-.04em;color:white">BusinessCode</span>
        <div style="display:flex;gap:1.5rem">
          <router-link v-if="isAuthenticated" to="/dashboard" style="color:rgba(148,163,184,1);font-weight:500;text-decoration:none">Dashboard</router-link>
          <a href="#" style="color:white;font-weight:700;text-decoration:none;border-bottom:2px solid #3b82f6;padding-bottom:2px">Planos</a>
        </div>
      </div>
      <div>
        <router-link v-if="!isAuthenticated" to="/login" class="btn btn-sm btn-primary" style="border-radius:8px">Entrar</router-link>
      </div>
    </nav>

    <main style="padding-top:8rem;padding-left:1.5rem;padding-right:1.5rem;max-width:1200px;margin:0 auto">
      <!-- Hero -->
      <header style="text-align:center;margin-bottom:3rem">
        <h1 style="font-size:2.8rem;font-weight:800;letter-spacing:-.04em;margin-bottom:1rem;color:white">
          O Plano Ideal para seu <span style="color:#b3c5ff">Crescimento</span>
        </h1>
        <p style="font-size:1.1rem;color:rgba(194,198,216,1);max-width:600px;margin:0 auto">
          Infraestrutura escalável para marketing moderno. Escolha o plano que acompanha seu momento.
        </p>

        <!-- Toggle -->
        <div style="display:flex;align-items:center;justify-content:center;gap:1rem;margin-top:2rem">
          <span style="font-size:.9rem;font-weight:600;color:rgba(194,198,216,1)">Mensal</span>
          <button @click="annual = !annual" style="position:relative;width:56px;height:32px;background:#242842;border-radius:999px;padding:4px;border:none;cursor:pointer">
            <div :style="{ transform: annual ? 'translateX(24px)' : 'translateX(0)', transition: 'transform .2s', width: '24px', height: '24px', background: '#0064ff', borderRadius: '999px' }"></div>
          </button>
          <div style="display:flex;align-items:center;gap:.5rem">
            <span style="font-size:.9rem;font-weight:600;color:white">Anual</span>
            <span style="font-size:.65rem;font-weight:800;background:#0566d9;color:white;padding:2px 8px;border-radius:999px;text-transform:uppercase;letter-spacing:.05em">-20%</span>
          </div>
        </div>
      </header>

      <!-- Plans grid -->
      <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

      <div v-else style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:2rem;align-items:stretch">
        <div
          v-for="plan in plans"
          :key="plan.id"
          style="background:rgba(22,27,69,0.6);backdrop-filter:blur(12px);border-radius:16px;padding:2rem;display:flex;flex-direction:column;position:relative;transition:all .2s"
          :style="plan.slug === 'pro' ? 'border:2px solid #0064ff;box-shadow:0 0 32px rgba(0,100,255,0.15);transform:scale(1.02)' : 'border:1px solid rgba(255,255,255,0.06)'"
        >
          <div v-if="plan.slug === 'pro'" style="position:absolute;top:-12px;left:50%;transform:translateX(-50%);background:#0064ff;color:white;font-size:.65rem;font-weight:800;padding:3px 14px;border-radius:999px;text-transform:uppercase;letter-spacing:.08em">
            Mais Popular
          </div>

          <div style="margin-bottom:1.5rem">
            <h3 style="font-size:1.3rem;font-weight:700;color:white;margin-bottom:.25rem">{{ plan.name }}</h3>
            <p style="font-size:.85rem;color:rgba(194,198,216,1)">{{ planDescription(plan) }}</p>
          </div>

          <div style="margin-bottom:1.5rem">
            <span v-if="plan.price_monthly > 0" style="display:flex;align-items:baseline;gap:.25rem">
              <span style="font-size:.9rem;color:rgba(194,198,216,1)">R$</span>
              <span style="font-size:2.5rem;font-weight:800;color:white;letter-spacing:-.02em">{{ displayPrice(plan) }}</span>
              <span style="font-size:.85rem;color:rgba(194,198,216,1)">/mês</span>
            </span>
            <span v-else style="font-size:2.5rem;font-weight:800;color:white">Grátis</span>
            <div v-if="annual && plan.price_monthly > 0" style="font-size:.75rem;color:#b3c5ff;font-weight:700;margin-top:.25rem">
              Cobrado anualmente
            </div>
          </div>

          <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:.6rem;margin-bottom:1.5rem;flex:1">
            <li v-for="feat in planFeatures(plan)" :key="feat" style="display:flex;align-items:center;gap:.5rem;font-size:.88rem">
              <i class="ti ti-check" style="color:#0064ff;font-size:.9rem"></i>
              <span>{{ feat }}</span>
            </li>
          </ul>

          <button
            v-if="plan.slug === 'enterprise'"
            class="btn btn-outline-secondary w-100"
            style="border-radius:10px;padding:.7rem"
            @click="contactSales"
          >
            Falar com Vendas
          </button>
          <button
            v-else-if="plan.price_monthly === 0"
            class="btn btn-outline-secondary w-100"
            style="border-radius:10px;padding:.7rem"
            disabled
          >
            Plano Atual
          </button>
          <router-link
            v-else
            :to="isAuthenticated ? `/plans/checkout/${plan.slug}` : `/login?redirect=/plans/checkout/${plan.slug}`"
            class="btn w-100"
            :class="plan.slug === 'pro' ? 'btn-primary' : 'btn-outline-primary'"
            style="border-radius:10px;padding:.7rem;font-weight:700"
          >
            Escolher Plano
          </router-link>
        </div>
      </div>
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import axios from 'axios'

const auth = useAuthStore()
const isAuthenticated = computed(() => auth.isAuthenticated)

const plans = ref<any[]>([])
const loading = ref(true)
const annual = ref(false)

onMounted(async () => {
  try {
    const resp = await axios.get('/api/v1/plans')
    const data = resp.data
    plans.value = Array.isArray(data) ? data : (data?.data ?? [])
  } catch {
    plans.value = []
  } finally {
    loading.value = false
  }
})

function displayPrice(plan: any) {
  const price = Number(plan.price_monthly)
  if (annual.value) return Math.floor(price * 0.8)
  return Math.floor(price)
}

function planDescription(plan: any) {
  const map: Record<string, string> = {
    starter: 'Ideal para começar.',
    pro: 'Motor de alta performance.',
    enterprise: 'Solução personalizada.',
  }
  return map[plan.slug] || ''
}

function planFeatures(plan: any) {
  const feats: string[] = []
  feats.push(`${plan.max_contacts?.toLocaleString('pt-BR') || '∞'} contatos`)
  feats.push(`${plan.credits_included?.toLocaleString('pt-BR')} créditos/mês`)

  const f = typeof plan.features === 'string' ? JSON.parse(plan.features) : (plan.features ?? {})
  const channels = f.channels ?? []
  if (channels.length) feats.push(`Canais: ${channels.join(', ')}`)
  if (f.ai_chatbot_enabled) feats.push('Chatbot IA')
  if (f.funnels_enabled) feats.push('Funis automáticos')
  if (f.api_access) feats.push('Acesso à API')
  return feats
}

function contactSales() {
  window.open('https://wa.me/5511999999999?text=Olá! Quero saber sobre o plano Enterprise', '_blank')
}
</script>
```

- [ ] **Step 4: Create CreditPurchase.vue**

```vue
<!-- frontend/src/pages/settings/CreditPurchase.vue -->
<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <div>
        <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-.03em;margin:0">Comprar Créditos</h2>
        <span style="font-size:.85rem;color:var(--bc-text-muted)">Adicione créditos extras à sua conta</span>
      </div>
    </div>

    <!-- Current balance -->
    <div class="mb-4 p-3 d-flex align-items-center gap-3" style="background:rgba(0,100,255,0.04);border:1px solid rgba(0,100,255,0.1);border-radius:12px">
      <i class="ti ti-coins" style="color:#0064ff;font-size:1.3rem"></i>
      <div>
        <div style="font-size:.82rem;color:var(--bc-text-muted)">Saldo atual</div>
        <div style="font-weight:800;font-size:1.1rem;font-family:'JetBrains Mono',monospace">{{ currentBalance.toLocaleString('pt-BR') }} créditos</div>
      </div>
    </div>

    <div v-if="configLoading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

    <div v-else class="row g-4">
      <div class="col-12 col-lg-7">
        <!-- Amount input -->
        <div class="mb-4" style="background:var(--bc-gray);border:1px solid rgba(255,255,255,0.06);border-radius:14px;padding:24px">
          <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--bc-text-muted);display:block;margin-bottom:.5rem">Quantidade de créditos</label>
          <input
            v-model.number="creditsAmount"
            type="number"
            :min="minPurchase"
            step="100"
            class="form-control mb-2"
            style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:.75rem 1rem;font-size:1.2rem;font-weight:700;font-family:'JetBrains Mono',monospace"
          />
          <div v-if="creditsAmount < minPurchase" style="font-size:.78rem;color:#ef4444">Mínimo de {{ minPurchase }} créditos</div>
          <div style="font-size:.85rem;color:var(--bc-text-muted);margin-top:.5rem">
            Valor unitário: R${{ unitPrice.toFixed(2).replace('.', ',') }} por crédito
          </div>
        </div>

        <!-- Payment form -->
        <CheckoutForm
          ref="checkoutFormRef"
          :pix-data="store.pixData"
          :boleto-data="store.boletoData"
          @success="onPaymentSuccess"
          @boleto-generate="onBoletoGenerate"
        />
      </div>

      <div class="col-12 col-lg-5">
        <OrderSummary
          title="Compra de Créditos"
          :subtitle="`${creditsAmount} créditos`"
          :base-price="totalPrice"
          :processing="store.paymentStatus === 'processing'"
          @submit="handleSubmit"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useCheckoutStore } from '@/stores/checkout'
import { useAuthStore } from '@/stores/auth'
import CheckoutForm from '@/components/checkout/CheckoutForm.vue'
import OrderSummary from '@/components/checkout/OrderSummary.vue'

const router = useRouter()
const store = useCheckoutStore()
const auth = useAuthStore()

const checkoutFormRef = ref<InstanceType<typeof CheckoutForm> | null>(null)
const creditsAmount = ref(500)
const configLoading = ref(true)

const currentBalance = computed(() => auth.user?.tenant?.credits_balance ?? 0)
const unitPrice = computed(() => store.config?.credit_unit_price ?? 0.08)
const minPurchase = computed(() => store.config?.credit_min_purchase ?? 100)
const totalPrice = computed(() => creditsAmount.value * unitPrice.value)

onMounted(async () => {
  store.reset()
  await store.fetchConfig()
  creditsAmount.value = minPurchase.value
  configLoading.value = false
})

async function handleSubmit() {
  if (creditsAmount.value < minPurchase.value || !checkoutFormRef.value) return

  const tab = checkoutFormRef.value.getActiveTab()
  const email = auth.user?.email ?? ''

  if (tab === 'credit_card') {
    const cardData = checkoutFormRef.value.getCardData()
    const mp = new (window as any).MercadoPago(store.config!.mp_public_key)
    try {
      const tokenResult = await mp.fields.createCardToken({
        cardNumber: cardData.number.replace(/\s/g, ''),
        cardholderName: cardData.name,
        cardExpirationMonth: cardData.expiry.split('/')[0],
        cardExpirationYear: '20' + cardData.expiry.split('/')[1],
        securityCode: cardData.cvc,
      })
      await store.purchaseCredits(creditsAmount.value, 'credit_card', email, tokenResult.id)
      onPaymentSuccess()
    } catch {
      // error in store
    }
  } else if (tab === 'pix') {
    await store.purchaseCredits(creditsAmount.value, 'pix', email)
  }
  // boleto handled via event
}

async function onBoletoGenerate(document: string) {
  const email = auth.user?.email ?? ''
  await store.purchaseCredits(creditsAmount.value, 'boleto', email, undefined, document)
}

function onPaymentSuccess() {
  auth.refreshUser()
  router.push({ path: '/checkout/thank-you', query: { plan: 'Créditos' } })
}
</script>
```

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/checkout/ frontend/src/pages/settings/CreditPurchase.vue
git commit -m "feat(checkout): add CheckoutPage, ThankYou, PricingPlans, CreditPurchase pages"
```

---

## Task 12: Frontend — Routes & Layout Integration

**Files:**
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/App.vue`
- Modify: `frontend/src/pages/settings/Plans.vue`

- [ ] **Step 1: Add routes to router/index.ts**

Add these routes BEFORE the catch-all `/:pathMatch(.*)*` route:

```typescript
{
  path: '/plans',
  component: () => import('@/pages/checkout/PricingPlans.vue'),
  meta: { public: true, title: 'Planos' },
},
{
  path: '/plans/checkout/:planSlug',
  component: () => import('@/pages/checkout/CheckoutPage.vue'),
  meta: { title: 'Checkout' },
},
{
  path: '/settings/checkout/:planSlug',
  component: () => import('@/pages/checkout/CheckoutPage.vue'),
  meta: { title: 'Checkout' },
},
{
  path: '/settings/credits',
  component: () => import('@/pages/settings/CreditPurchase.vue'),
  meta: { title: 'Comprar Créditos' },
},
{
  path: '/checkout/thank-you',
  component: () => import('@/pages/checkout/CheckoutThankYou.vue'),
  meta: { title: 'Assinatura Confirmada' },
},
```

- [ ] **Step 2: Update App.vue for public checkout layout**

In `frontend/src/App.vue`, update the template to handle the public checkout route (which needs auth but no sidebar):

Replace the existing template with:

```vue
<template>
  <AppLayout v-if="showLayout" />
  <RouterView v-else />
</template>

<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import AppLayout from '@/components/layout/AppLayout.vue'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const showLayout = computed(() => {
  if (route.meta?.public) return false
  // Public checkout and pricing have their own layout
  if (route.path.startsWith('/plans')) return false
  if (route.path === '/checkout/thank-you' && route.query.from === 'public') return false
  return true
})

onMounted(async () => {
  if (auth.isAuthenticated) {
    await auth.refreshUser()
  }
})
</script>
```

- [ ] **Step 3: Update Plans.vue — replace WhatsApp link with checkout button**

In `frontend/src/pages/settings/Plans.vue`, replace the WhatsApp link button:

Replace:
```vue
          <a v-else :href="`https://wa.me/5511999999999?text=Olá! Quero fazer upgrade para o plano ${plan.name}`" target="_blank" class="btn btn-primary w-100" style="border-radius:10px">
            <i class="ti ti-brand-whatsapp me-1"></i> Falar com vendas
          </a>
```

With:
```vue
          <router-link v-else :to="`/settings/checkout/${plan.slug}`" class="btn btn-primary w-100" style="border-radius:10px">
            <i class="ti ti-arrow-up-right me-1"></i> Fazer Upgrade
          </router-link>
```

Also add a "Comprar Créditos" button after the plans grid. After the closing `</div>` of the plans grid row, before the credits info div, add:

```vue
    <!-- Buy credits button -->
    <div class="text-center mt-4">
      <router-link to="/settings/credits" class="btn btn-outline-primary" style="border-radius:10px;padding:.6rem 2rem">
        <i class="ti ti-coins me-2"></i>Comprar Créditos
      </router-link>
    </div>
```

- [ ] **Step 4: Update router guard for login redirect**

In `frontend/src/router/index.ts`, update the `beforeEach` guard to support the `redirect` query param:

Replace the existing guard:
```typescript
router.beforeEach((to, _from, next) => {
  const auth = useAuthStore()
  if (!to.meta.public && !auth.isAuthenticated) {
    next('/login')
  } else if (to.meta.superadmin && auth.user?.role !== 'superadmin') {
    next('/dashboard')
  } else if (to.path === '/login' && auth.isAuthenticated) {
    next('/dashboard')
  } else {
    next()
  }
})
```

With:
```typescript
router.beforeEach((to, _from, next) => {
  const auth = useAuthStore()
  if (!to.meta.public && !auth.isAuthenticated) {
    next({ path: '/login', query: { redirect: to.fullPath } })
  } else if (to.meta.superadmin && auth.user?.role !== 'superadmin') {
    next('/dashboard')
  } else if (to.path === '/login' && auth.isAuthenticated) {
    const redirect = to.query.redirect as string
    next(redirect || '/dashboard')
  } else {
    next()
  }
})
```

- [ ] **Step 5: Commit**

```bash
git add frontend/src/router/index.ts frontend/src/App.vue frontend/src/pages/settings/Plans.vue
git commit -m "feat(checkout): integrate checkout routes, update Plans.vue with upgrade buttons"
```

---

## Task 13: Backend — Tests

**Files:**
- Create: `backend/tests/Feature/CouponTest.php`
- Create: `backend/tests/Feature/WebhookTest.php`

- [ ] **Step 1: Create CouponTest**

```php
<?php
// backend/tests/Feature/CouponTest.php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_valid_coupon()
    {
        $coupon = Coupon::create([
            'code' => 'SAVE20',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'price_monthly' => 79.00,
            'credits_included' => 5000, 'max_contacts' => 10000, 'max_campaigns' => 100,
            'overage_rate_sms' => 0.01, 'overage_rate_voice' => 0.03,
            'overage_rate_email' => 0.005, 'overage_rate_ai' => 0.08,
        ]);

        $response = $this->postJson('/api/v1/checkout/validate-coupon', [
            'code' => 'SAVE20',
            'plan_id' => $plan->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.coupon.code', 'SAVE20')
            ->assertJsonPath('data.calculated_discount', 15.80);
    }

    public function test_validate_expired_coupon_returns_error()
    {
        Coupon::create([
            'code' => 'EXPIRED',
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'valid_from' => now()->subMonth(),
            'valid_until' => now()->subDay(),
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/checkout/validate-coupon', [
            'code' => 'EXPIRED',
        ]);

        $response->assertStatus(422);
    }

    public function test_validate_maxed_out_coupon_returns_error()
    {
        Coupon::create([
            'code' => 'MAXED',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'valid_from' => now()->subDay(),
            'max_uses' => 5,
            'times_used' => 5,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/checkout/validate-coupon', [
            'code' => 'MAXED',
        ]);

        $response->assertStatus(422);
    }

    public function test_coupon_calculate_percentage_discount()
    {
        $coupon = new Coupon(['discount_type' => 'percentage', 'discount_value' => 25]);
        $this->assertEquals(24.75, $coupon->calculateDiscount(99.00));
    }

    public function test_coupon_calculate_fixed_discount_capped_at_price()
    {
        $coupon = new Coupon(['discount_type' => 'fixed', 'discount_value' => 100]);
        $this->assertEquals(49.00, $coupon->calculateDiscount(49.00));
    }
}
```

- [ ] **Step 2: Create WebhookTest**

```php
<?php
// backend/tests/Feature/WebhookTest.php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_invalid_signature()
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '12345'],
        ], [
            'x-signature' => 'ts=123,v1=invalid',
            'x-request-id' => 'req-1',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_returns_200_even_for_unknown_payment()
    {
        config(['services.mercadopago.webhook_secret' => 'test-secret']);

        $dataId = '99999';
        $ts = time();
        $manifest = "id:{$dataId};request-id:req-1;ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'test-secret');

        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => 'req-1',
        ]);

        // Should return 200 even if payment not found locally
        $response->assertOk();
    }
}
```

- [ ] **Step 3: Run tests**

Run: `cd /c/xampp/htdocs/new_saas/backend && php artisan test --filter=CouponTest && php artisan test --filter=WebhookTest`
Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add backend/tests/Feature/CouponTest.php backend/tests/Feature/WebhookTest.php
git commit -m "test(checkout): add coupon validation and webhook signature tests"
```

---

## Task 14: Final Verification

- [ ] **Step 1: Run all backend tests**

Run: `cd /c/xampp/htdocs/new_saas/backend && php artisan test`
Expected: all tests pass.

- [ ] **Step 2: Verify frontend compiles**

Run: `cd /c/xampp/htdocs/new_saas/frontend && npx vue-tsc --noEmit 2>&1 | head -30`
Expected: no type errors (or only pre-existing ones).

- [ ] **Step 3: Run dev server and test**

Run: `cd /c/xampp/htdocs/new_saas/frontend && npm run dev`

Test in browser:
1. Navigate to `/plans` — pricing page loads with plan cards
2. Click "Escolher Plano" — redirects to login if not authenticated
3. Log in → navigate to `/settings/plans` — "Fazer Upgrade" button shows
4. Click upgrade → checkout page loads with form + order summary
5. Navigate to `/settings/credits` — credit purchase page loads
6. Test coupon validation

- [ ] **Step 4: Final commit with all changes**

```bash
git add -A
git status
git commit -m "feat(checkout): complete Mercado Pago checkout integration"
```
