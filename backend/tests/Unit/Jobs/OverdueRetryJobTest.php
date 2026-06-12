<?php

namespace Tests\Unit\Jobs;

use App\Jobs\OverdueRetryJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Notifications\Billing\TenantSuspendedNotification;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OverdueRetryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_clears_grace(): void
    {
        Notification::fake();

        $plan = Plan::factory()->create(['included_balance_cents' => 0]);
        $tenant = Tenant::factory()->create([
            'plan_id'            => $plan->id,
            'balance_cents'      => -500,
            'billing_status'     => 'grace',
            'overdue_since'      => now()->subDays(3),
            'overdue_attempts'   => 3,
            'mp_customer_id'     => 'cust',
            'mp_default_card_id' => 'card',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldReceive('chargeStoredCard')
            ->andReturn(['ok' => true, 'mp_payment_id' => 'pay', 'mp_status' => 'approved', 'error' => null]);

        (new OverdueRetryJob($tenant->id))->handle($mp);

        $tenant->refresh();
        $this->assertEquals('active', $tenant->billing_status);
        $this->assertNull($tenant->overdue_since);
        $this->assertEquals(0, $tenant->overdue_attempts);
        $this->assertEquals(0, $tenant->balance_cents);
    }

    public function test_blocks_after_8_days(): void
    {
        Notification::fake();

        $plan = Plan::factory()->create(['included_balance_cents' => 0]);
        $tenant = Tenant::factory()->create([
            'plan_id'            => $plan->id,
            'balance_cents'      => -500,
            'billing_status'     => 'grace',
            'overdue_since'      => now()->subDays(8),
            'overdue_attempts'   => 7,
            'mp_customer_id'     => 'cust',
            'mp_default_card_id' => 'card',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldNotReceive('chargeStoredCard');

        (new OverdueRetryJob($tenant->id))->handle($mp);

        $tenant->refresh();
        $this->assertEquals('blocked', $tenant->billing_status);
        Notification::assertSentTo($tenant, TenantSuspendedNotification::class);
    }
}
