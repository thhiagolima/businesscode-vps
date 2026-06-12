<?php

namespace Tests\Unit\Billing;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_decrements_balance_and_records_transaction(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 1000, 'credit_limit_cents' => 0]);
        $svc = app(BillingService::class);

        $ok = $svc->reserve($tenant->id, 150, 'message_dispatch', 1);
        $this->assertTrue($ok);
        $this->assertEquals(850, $tenant->fresh()->balance_cents);
        $this->assertDatabaseHas('balance_transactions', [
            'tenant_id' => $tenant->id, 'type' => 'reserve', 'amount_cents' => -150,
        ]);
    }

    public function test_reserve_respects_credit_limit(): void
    {
        $tenant = Tenant::factory()->create([
            'balance_cents' => 100, 'credit_limit_cents' => 500,
        ]);
        $svc = app(BillingService::class);

        $this->assertTrue($svc->reserve($tenant->id, 600, 'msg', 1));
        $this->assertEquals(-500, $tenant->fresh()->balance_cents);

        $this->assertFalse($svc->reserve($tenant->id, 1, 'msg', 2));
        $this->assertEquals(-500, $tenant->fresh()->balance_cents);
    }

    public function test_reserve_returns_false_for_insufficient(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 50, 'credit_limit_cents' => 0]);
        $svc = app(BillingService::class);

        $this->assertFalse($svc->reserve($tenant->id, 100, 'msg', 1));
        $this->assertEquals(50, $tenant->fresh()->balance_cents);
        $this->assertDatabaseMissing('balance_transactions', [
            'tenant_id' => $tenant->id, 'type' => 'reserve',
        ]);
    }

    public function test_release_restores_balance(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 850, 'credit_limit_cents' => 0]);
        BalanceTransaction::create([
            'tenant_id' => $tenant->id, 'type' => 'reserve',
            'amount_cents' => -150, 'balance_after_cents' => 850,
            'reference_type' => 'msg', 'reference_id' => 1,
        ]);

        $svc = app(BillingService::class);
        $svc->release($tenant->id, 150, 'msg', 1);

        $this->assertEquals(1000, $tenant->fresh()->balance_cents);
        $this->assertDatabaseHas('balance_transactions', [
            'tenant_id' => $tenant->id, 'type' => 'release', 'amount_cents' => 150,
        ]);
    }

    public function test_recharge_adds_balance(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 100, 'credit_limit_cents' => 0]);
        $svc = app(BillingService::class);
        $svc->recharge($tenant->id, 5000, 'mp_payment', 12345, 'MP recharge');
        $this->assertEquals(5100, $tenant->fresh()->balance_cents);
        $this->assertDatabaseHas('balance_transactions', [
            'tenant_id' => $tenant->id, 'type' => 'recharge', 'amount_cents' => 5000,
        ]);
    }
}
