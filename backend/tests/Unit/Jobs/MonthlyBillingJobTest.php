<?php

namespace Tests\Unit\Jobs;

use App\Jobs\MonthlyBillingJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Notifications\Billing\OverdueWarningNotification;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MonthlyBillingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_credits_plan_included_balance(): void
    {
        $plan = Plan::factory()->create(['included_balance_cents' => 5000]);
        $tenant = Tenant::factory()->create([
            'plan_id'           => $plan->id,
            'balance_cents'     => 100,
            'billing_cycle_day' => now()->day,
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldNotReceive('chargeStoredCard');

        (new MonthlyBillingJob($tenant->id))->handle($mp);

        $this->assertEquals(5100, $tenant->fresh()->balance_cents);
        $this->assertDatabaseHas('balance_transactions', [
            'tenant_id'    => $tenant->id,
            'type'         => 'credit',
            'amount_cents' => 5000,
        ]);
    }

    public function test_charges_card_when_negative(): void
    {
        Notification::fake();

        $plan = Plan::factory()->create(['included_balance_cents' => 0]);
        $tenant = Tenant::factory()->create([
            'plan_id'            => $plan->id,
            'balance_cents'     => -500,
            'mp_customer_id'    => 'cust_1',
            'mp_default_card_id' => 'card_1',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldReceive('chargeStoredCard')
            ->once()
            ->andReturn(['ok' => true, 'mp_payment_id' => 'pay_xyz', 'mp_status' => 'approved', 'error' => null]);

        (new MonthlyBillingJob($tenant->id))->handle($mp);

        $fresh = $tenant->fresh();
        $this->assertEquals(0, $fresh->balance_cents);
        $this->assertEquals('active', $fresh->billing_status);
    }

    public function test_enters_grace_on_card_rejection(): void
    {
        Notification::fake();

        $plan = Plan::factory()->create(['included_balance_cents' => 0]);
        $tenant = Tenant::factory()->create([
            'plan_id'            => $plan->id,
            'balance_cents'      => -500,
            'mp_customer_id'     => 'cust_1',
            'mp_default_card_id' => 'card_1',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldReceive('chargeStoredCard')
            ->once()
            ->andReturn(['ok' => false, 'error' => 'rejected', 'mp_payment_id' => null]);

        (new MonthlyBillingJob($tenant->id))->handle($mp);

        $tenant->refresh();
        $this->assertEquals('grace', $tenant->billing_status);
        $this->assertNotNull($tenant->overdue_since);
        $this->assertEquals(1, $tenant->overdue_attempts);
        Notification::assertSentTo($tenant, OverdueWarningNotification::class);
    }
}
