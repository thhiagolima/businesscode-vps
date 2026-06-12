<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MercadoPagoChargeStoredCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.access_token' => 'TEST-token-xyz']);
    }

    private function tenantWithCard(): Tenant
    {
        $plan = Plan::factory()->create();
        return Tenant::factory()->create([
            'plan_id'             => $plan->id,
            'mp_customer_id'      => 'cust_abc',
            'mp_default_card_id'  => 'card_xyz',
        ]);
    }

    public function test_success_path_returns_ok_and_payment_id(): void
    {
        Http::fake([
            '*/v1/customers/*/cards/*' => Http::response(['id' => 'card_xyz', 'payment_method' => ['id' => 'visa']], 200),
            '*/v1/card_tokens'         => Http::response(['id' => 'tok-success'], 200),
            '*/v1/payments'            => Http::response(['id' => 1234, 'status' => 'approved'], 200),
        ]);

        $tenant = $this->tenantWithCard();
        $mp = app(MercadoPagoService::class);

        $result = $mp->chargeStoredCard($tenant, 5000, 'monthly_charge');

        $this->assertTrue($result['ok']);
        $this->assertSame('1234', $result['mp_payment_id']);
        $this->assertSame('approved', $result['mp_status']);

        // Validate it sent the token AND the resolved brand (not the literal 'credit_card').
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/payments') &&
                ($request->data()['token'] ?? null) === 'tok-success' &&
                ($request->data()['payment_method_id'] ?? null) === 'visa';
        });
    }

    public function test_rejection_returns_ok_false_with_detail(): void
    {
        Http::fake([
            '*/v1/customers/*/cards/*' => Http::response(['id' => 'card_xyz', 'payment_method' => ['id' => 'master']], 200),
            '*/v1/card_tokens'         => Http::response(['id' => 'tok-fail'], 200),
            '*/v1/payments'            => Http::response([
                'id' => 4321, 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount',
            ], 200),
        ]);

        $tenant = $this->tenantWithCard();
        $mp = app(MercadoPagoService::class);

        $result = $mp->chargeStoredCard($tenant, 9999, 'monthly_charge');

        $this->assertFalse($result['ok']);
        $this->assertSame('4321', $result['mp_payment_id']);
        $this->assertSame('cc_rejected_insufficient_amount', $result['error']);
    }

    public function test_unresolvable_payment_method_short_circuits(): void
    {
        // Card resource fetch fails → cannot resolve the brand → must NOT post a charge
        // with the invalid literal payment_method_id.
        Http::fake([
            '*/v1/card_tokens'         => Http::response(['id' => 'tok-ok'], 200),
            '*/v1/customers/*/cards/*' => Http::response(['error' => 'not_found'], 404),
        ]);

        $tenant = $this->tenantWithCard();
        $mp = app(MercadoPagoService::class);

        $result = $mp->chargeStoredCard($tenant, 5000, 'monthly_charge');

        $this->assertFalse($result['ok']);
        $this->assertSame('PAYMENT_METHOD_UNRESOLVED', $result['error']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v1/payments'));
    }

    public function test_card_token_failure_short_circuits(): void
    {
        Http::fake([
            '*/v1/card_tokens' => Http::response(['error' => 'invalid_card'], 500),
        ]);

        $tenant = $this->tenantWithCard();
        $mp = app(MercadoPagoService::class);

        $result = $mp->chargeStoredCard($tenant, 5000, 'monthly_charge');

        $this->assertFalse($result['ok']);
        $this->assertSame('CARD_TOKEN_FAILED', $result['error']);
        $this->assertNull($result['mp_payment_id']);
    }

    public function test_no_stored_card_short_circuits_without_http_calls(): void
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create([
            'plan_id'             => $plan->id,
            'mp_customer_id'      => null,
            'mp_default_card_id'  => null,
        ]);

        Http::fake();
        $mp = app(MercadoPagoService::class);
        $result = $mp->chargeStoredCard($tenant, 5000, 'monthly_charge');

        $this->assertFalse($result['ok']);
        $this->assertSame('NO_STORED_CARD', $result['error']);
        Http::assertNothingSent();
    }
}
