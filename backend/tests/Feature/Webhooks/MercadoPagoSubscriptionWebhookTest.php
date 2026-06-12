<?php

namespace Tests\Feature\Webhooks;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the subscription side of the MP webhook flow (C1 + M3 + M4):
 *  - `subscription_preapproval` confirms a pending card subscription → plan + included balance
 *  - `subscription_authorized_payment` records a recurring charge and rolls the period forward
 *
 * Both reach Mercado Pago via the REST helper (Http::fake-able), not the dx-php SDK.
 */
class MercadoPagoSubscriptionWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.mercadopago.webhook_secret' => 'unit-test-secret',
            'services.mercadopago.access_token'   => 'TEST-token-xyz',
        ]);
    }

    private function signedHeaders(string $dataId, string $requestId = 'req-1'): array
    {
        $ts = (string) time();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'unit-test-secret');
        return [
            'x-signature'  => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ];
    }

    public function test_preapproval_authorized_activates_subscription_and_credits_included_balance(): void
    {
        Http::fake([
            '*/preapproval/sub_1' => Http::response(['id' => 'sub_1', 'status' => 'authorized'], 200),
        ]);

        $plan   = Plan::factory()->create(['price_monthly' => 49.90, 'included_balance_cents' => 1000]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 0, 'status' => 'suspended']);
        $sub    = Subscription::create([
            'tenant_id'          => $tenant->id,
            'plan_id'            => $plan->id,
            'billing_cycle'      => 'monthly',
            'status'             => 'pending',
            'payment_method'     => 'credit_card',
            'price'              => 49.90,
            'mp_subscription_id' => 'sub_1',
        ]);

        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'subscription_preapproval',
            'data' => ['id' => 'sub_1'],
        ], $this->signedHeaders('sub_1', 'req-A'));

        // C1: the topic is no longer rejected with 400.
        $response->assertStatus(200);

        $sub->refresh();
        $tenant->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame('active', $tenant->status);
        $this->assertSame($plan->id, $tenant->plan_id);
        // M3: included balance credited.
        $this->assertSame(1000, $tenant->balance_cents);

        // Idempotency: a redelivery (fresh request_id) must NOT double-credit.
        $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'subscription_preapproval',
            'data' => ['id' => 'sub_1'],
        ], $this->signedHeaders('sub_1', 'req-B'))->assertStatus(200);

        $tenant->refresh();
        $this->assertSame(1000, $tenant->balance_cents);
    }

    public function test_authorized_payment_records_recurring_charge_and_rolls_period(): void
    {
        Http::fake([
            '*/authorized_payments/ap_1' => Http::response([
                'id'             => 'ap_1',
                'preapproval_id' => 'sub_2',
                'status'         => 'approved',
                'payment'        => ['id' => 9999, 'status' => 'approved'],
            ], 200),
        ]);

        $plan   = Plan::factory()->create(['price_monthly' => 49.90, 'included_balance_cents' => 1000]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $sub    = Subscription::create([
            'tenant_id'          => $tenant->id,
            'plan_id'            => $plan->id,
            'billing_cycle'      => 'monthly',
            'status'             => 'active',
            'payment_method'     => 'credit_card',
            'price'              => 49.90,
            'mp_subscription_id' => 'sub_2',
            'current_period_end' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'subscription_authorized_payment',
            'data' => ['id' => 'ap_1'],
        ], $this->signedHeaders('ap_1', 'req-C'));

        $response->assertStatus(200);

        // M4: the recurring charge is recorded for history.
        $payment = Payment::where('mp_payment_id', '9999')->first();
        $this->assertNotNull($payment);
        $this->assertSame('subscription', $payment->type);
        $this->assertSame('approved', $payment->status);
        $this->assertSame($sub->id, $payment->subscription_id);

        // Period rolled forward into the future.
        $sub->refresh();
        $this->assertTrue($sub->current_period_end->isFuture());
    }

    public function test_unsupported_type_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'totally_made_up_topic',
            'data' => ['id' => 'x'],
        ], $this->signedHeaders('x'));

        $response->assertStatus(400);
    }
}
