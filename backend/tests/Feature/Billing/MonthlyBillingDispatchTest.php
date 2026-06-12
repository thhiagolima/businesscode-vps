<?php

namespace Tests\Feature\Billing;

use App\Jobs\DispatchMonthlyBillingJob;
use App\Jobs\MonthlyBillingJob;
use App\Models\Plan;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonthlyBillingDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dispatcher_only_selects_tenants_with_matching_cycle_day(): void
    {
        // Freeze "today" to day 15 in São Paulo TZ
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0, 'America/Sao_Paulo'));

        $plan = Plan::factory()->create();
        $today = Tenant::factory()->create([
            'plan_id'           => $plan->id,
            'billing_cycle_day' => 15,
            'billing_status'    => 'active',
            'balance_cents'     => 0,
            'last_billing_at'   => null,
        ]);
        $tomorrow = Tenant::factory()->create([
            'plan_id'           => $plan->id,
            'billing_cycle_day' => 16,
            'billing_status'    => 'active',
            'balance_cents'     => 0,
            'last_billing_at'   => null,
        ]);

        Queue::fake();

        (new DispatchMonthlyBillingJob())->handle();

        Queue::assertPushed(MonthlyBillingJob::class, function ($j) use ($today) {
            return $j->tenantId === $today->id;
        });
        Queue::assertNotPushed(MonthlyBillingJob::class, function ($j) use ($tomorrow) {
            return $j->tenantId === $tomorrow->id;
        });
    }

    public function test_dispatcher_respects_last_billing_at_window(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0, 'America/Sao_Paulo'));

        $plan = Plan::factory()->create();
        // Already billed 5 days ago — must NOT bill again (window = 25d)
        $recent = Tenant::factory()->create([
            'plan_id'           => $plan->id,
            'billing_cycle_day' => 15,
            'billing_status'    => 'active',
            'balance_cents'     => 0,
            'last_billing_at'   => now()->subDays(5),
        ]);
        // Billed 30 days ago — should bill again
        $stale = Tenant::factory()->create([
            'plan_id'           => $plan->id,
            'billing_cycle_day' => 15,
            'billing_status'    => 'active',
            'balance_cents'     => 0,
            'last_billing_at'   => now()->subDays(30),
        ]);

        Queue::fake();
        (new DispatchMonthlyBillingJob())->handle();

        Queue::assertNotPushed(MonthlyBillingJob::class, fn($j) => $j->tenantId === $recent->id);
        Queue::assertPushed(MonthlyBillingJob::class, fn($j) => $j->tenantId === $stale->id);
    }

    public function test_blocked_tenants_are_skipped(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0, 'America/Sao_Paulo'));

        $plan = Plan::factory()->create();
        $blocked = Tenant::factory()->create([
            'plan_id'           => $plan->id,
            'billing_cycle_day' => 15,
            'billing_status'    => 'blocked',
            'balance_cents'     => 0,
            'last_billing_at'   => null,
        ]);

        Queue::fake();
        (new DispatchMonthlyBillingJob())->handle();
        Queue::assertNotPushed(MonthlyBillingJob::class, fn($j) => $j->tenantId === $blocked->id);
    }
}
