<?php

namespace Tests\Feature\Admin;

use App\Models\BalanceTransaction;
use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'superadmin'): User
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
    }

    public function test_stats_returns_aggregates_structure(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);

        $resp = $this->getJson('/api/v1/admin/billing/stats');

        $resp->assertStatus(200)->assertJsonStructure([
            'data' => [
                'total_balance_brl',
                'total_owed_brl',
                'tenants_by_status',
                'tenants_by_billing_status',
                'mrr_last_30d',
                'margin_last_30d',
                'overdue_recovery_rate_30d',
                'window_days',
            ],
        ]);
    }

    public function test_stats_sums_positive_balances_and_negative_debts(): void
    {
        $plan = Plan::factory()->create();
        Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 10000]); // R$ 100,00
        Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 5000]);  // R$ 50,00
        Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => -300]);  // owes R$ 3,00

        Sanctum::actingAs($this->admin(), ['*']);
        $resp = $this->getJson('/api/v1/admin/billing/stats');
        $resp->assertStatus(200);

        // The admin's own tenant also has a balance from factory (could be anywhere 0..1000),
        // so we only assert lower bounds + the owed amount.
        $totalBalance = (float) $resp->json('data.total_balance_brl');
        $this->assertGreaterThanOrEqual(150.0, $totalBalance);
        $this->assertSame(3.0, (float) $resp->json('data.total_owed_brl'));
    }

    public function test_stats_computes_mrr_and_margin_for_last_30d(): void
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 0]);

        // MRR: monthly_charge of 5000c within window
        BalanceTransaction::create([
            'tenant_id'           => $tenant->id,
            'type'                => 'monthly_charge',
            'amount_cents'        => 5000,
            'balance_after_cents' => 5000,
            'reference_type'      => 'monthly_billing',
            'reference_id'        => 1,
            'description'         => 'Cobrança mensal',
        ]);

        // Margin: dispatch sent with charged 50, cost 20 => margin 30
        MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'     => $tenant->id,
            'channel'       => 'sms',
            'source'        => 'api',
            'to'            => '+5521988887777',
            'content'       => 'x',
            'provider'      => 'infobip',
            'status'        => 'sent',
            'cost_cents'    => 20,
            'sale_cents'    => 50,
            'charged_cents' => 50,
        ]);

        Sanctum::actingAs($this->admin(), ['*']);
        $resp = $this->getJson('/api/v1/admin/billing/stats');

        $this->assertSame(50.0, (float) $resp->json('data.mrr_last_30d'));
        $this->assertSame(0.30, (float) $resp->json('data.margin_last_30d'));
    }

    public function test_regular_user_cannot_access_stats(): void
    {
        Sanctum::actingAs($this->admin('user'), ['*']);
        $this->getJson('/api/v1/admin/billing/stats')->assertStatus(403);
    }
}
