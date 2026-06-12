<?php

namespace Tests\Feature\Billing;

use App\Jobs\OverdueRetryJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OverdueDunningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.access_token' => 'TEST-token-xyz']);
        config(['billing.overdue_grace_days' => 7]);
        Notification::fake();
    }

    private function makeOverdueTenant(int $debtCents, int $daysOverdue, string $status = 'grace'): Tenant
    {
        $plan = Plan::factory()->create();
        return Tenant::factory()->create([
            'plan_id'             => $plan->id,
            'balance_cents'       => -$debtCents,
            'billing_status'      => $status,
            'overdue_since'       => now()->subDays($daysOverdue),
            'overdue_attempts'    => 0,
            'mp_customer_id'      => 'cust_overdue',
            'mp_default_card_id'  => 'card_overdue',
        ]);
    }

    public function test_retry_within_grace_succeeds_clears_overdue(): void
    {
        $tenant = $this->makeOverdueTenant(debtCents: 5000, daysOverdue: 3);

        Http::fake([
            '*/v1/customers/*/cards/*' => Http::response(['payment_method' => ['id' => 'visa']], 200),
            '*/v1/card_tokens' => Http::response(['id' => 'tok-1'], 200),
            '*/v1/payments'    => Http::response([
                'id' => 99001, 'status' => 'approved',
            ], 200),
        ]);

        (new OverdueRetryJob($tenant->id))->handle(app(MercadoPagoService::class));

        $tenant->refresh();
        $this->assertSame('active', $tenant->billing_status);
        $this->assertSame(0, (int) $tenant->balance_cents);
        $this->assertNull($tenant->overdue_since);
        $this->assertSame(0, (int) $tenant->overdue_attempts);
    }

    public function test_retry_within_grace_failure_increments_attempts_and_stays_grace(): void
    {
        $tenant = $this->makeOverdueTenant(debtCents: 5000, daysOverdue: 3);

        Http::fake([
            '*/v1/customers/*/cards/*' => Http::response(['payment_method' => ['id' => 'visa']], 200),
            '*/v1/card_tokens' => Http::response(['id' => 'tok-2'], 200),
            '*/v1/payments'    => Http::response([
                'id' => 99002, 'status' => 'rejected', 'status_detail' => 'insufficient_funds',
            ], 200),
        ]);

        (new OverdueRetryJob($tenant->id))->handle(app(MercadoPagoService::class));

        $tenant->refresh();
        $this->assertSame('grace', $tenant->billing_status);
        $this->assertSame(1, (int) $tenant->overdue_attempts);
        $this->assertSame(-5000, (int) $tenant->balance_cents);
    }

    public function test_retry_past_grace_window_blocks_tenant(): void
    {
        // 8 days overdue, grace=7 → past window → block
        $tenant = $this->makeOverdueTenant(debtCents: 5000, daysOverdue: 8);

        // No HTTP calls expected; tenant gets blocked before any charge attempt
        Http::fake();

        (new OverdueRetryJob($tenant->id))->handle(app(MercadoPagoService::class));

        $tenant->refresh();
        $this->assertSame('blocked', $tenant->billing_status);
        // Sanity: HTTP should not have been called
        Http::assertNothingSent();
    }

    public function test_active_tenant_is_skipped_by_retry(): void
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create([
            'plan_id'         => $plan->id,
            'billing_status'  => 'active',
            'balance_cents'   => 100,
            'overdue_since'   => null,
        ]);

        Http::fake();
        (new OverdueRetryJob($tenant->id))->handle(app(MercadoPagoService::class));

        Http::assertNothingSent();
        $tenant->refresh();
        $this->assertSame('active', $tenant->billing_status);
        $this->assertSame(100, (int) $tenant->balance_cents);
    }
}
