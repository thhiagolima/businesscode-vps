<?php

namespace Tests\Feature\Reports;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditsExtractTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserAndTenant(int $balance = 100000): array
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => $balance,
        ]);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        return [$tenant, $user];
    }

    public function test_credits_summary_returns_correct_totals(): void
    {
        [$tenant, $user] = $this->makeUserAndTenant();
        $billing = app(BillingService::class);

        // Real billing operations the service actually creates.
        $billing->recharge($tenant->id, 5000, 'credit_purchase', 1);   // +5000
        $billing->reserve($tenant->id, 300, 'campaign_dispatch', 10);  // -300
        $billing->release($tenant->id, 100, 'campaign_refund', 10);    // +100

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/reports/credits');

        $response->assertOk();
        $summary = $response->json('summary');
        $this->assertNotNull($summary, 'summary block must be present');

        // 30-day totals must reflect actual money flow.
        $this->assertSame(300, (int) $summary['total_debited_30d'],
            'total_debited_30d must sum reserves/debits (300)');
        $this->assertSame(5100, (int) $summary['total_credited_30d'],
            'total_credited_30d must sum recharges + releases (5000 + 100)');
        $this->assertSame(3, (int) $summary['transactions_count']);
    }

    public function test_credits_summary_does_not_leak_other_tenant_totals(): void
    {
        [$tenantA, $userA] = $this->makeUserAndTenant();
        [$tenantB, ] = $this->makeUserAndTenant(); // separate tenant
        $billing = app(BillingService::class);

        $billing->recharge($tenantA->id, 1000, 'credit_purchase', 1);
        $billing->recharge($tenantB->id, 9999, 'credit_purchase', 1);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/v1/reports/credits');

        $response->assertOk();
        $this->assertSame(1000, (int) $response->json('summary.total_credited_30d'),
            'Tenant A must not see tenant B totals');
    }
}
