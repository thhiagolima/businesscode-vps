<?php

namespace Tests\Feature\Billing;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_double_reserve_with_same_reference_does_not_double_debit(): void
    {
        // P0-01 refinement: if ProcessCampaignJob retries after a transient error,
        // the second reserve must NOT debit the tenant a second time.
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'balance_cents' => 10000, 'credit_limit_cents' => 0,
        ]);
        $billing = app(BillingService::class);

        $ok1 = $billing->reserve($tenant->id, 5000, 'campaign_dispatch', 42);
        $ok2 = $billing->reserve($tenant->id, 5000, 'campaign_dispatch', 42);

        $this->assertTrue($ok1, 'First reserve must succeed');
        $this->assertTrue($ok2, 'Second reserve with same reference must report success (idempotent)');

        // Balance must be debited exactly once.
        $this->assertSame(5000, (int) $tenant->fresh()->balance_cents);

        // Exactly one BalanceTransaction row for this campaign.
        $count = BalanceTransaction::where('tenant_id', $tenant->id)
            ->where('type', 'reserve')
            ->where('reference_type', 'campaign_dispatch')
            ->where('reference_id', 42)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_reserve_different_references_each_debit(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'balance_cents' => 10000, 'credit_limit_cents' => 0,
        ]);
        $billing = app(BillingService::class);

        $billing->reserve($tenant->id, 3000, 'campaign_dispatch', 1);
        $billing->reserve($tenant->id, 3000, 'campaign_dispatch', 2);

        $this->assertSame(4000, (int) $tenant->fresh()->balance_cents);
    }
}
