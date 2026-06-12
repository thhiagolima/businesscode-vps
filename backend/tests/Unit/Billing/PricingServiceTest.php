<?php

namespace Tests\Unit\Billing;

use App\Models\Plan;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use App\Models\User;
use App\Services\Billing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        ServicePrice::create(['service' => 'sms', 'cost_cents' => 8, 'sale_cents' => 15]);
        ServicePrice::create(['service' => 'voice', 'cost_cents' => 40, 'sale_cents' => 80]);
    }

    public function test_uses_global_when_no_override(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(PricingService::class);
        $price = $svc->priceFor($tenant, 'sms');
        $this->assertEquals(8, $price['cost_cents']);
        $this->assertEquals(15, $price['sale_cents']);
        $this->assertEquals('global', $price['source']);
    }

    public function test_plan_override_wins_over_global(): void
    {
        $plan = Plan::factory()->create(['sale_cents_overrides' => ['sms' => 12]]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $svc = app(PricingService::class);
        $price = $svc->priceFor($tenant, 'sms');
        $this->assertEquals(12, $price['sale_cents']);
        $this->assertEquals(8, $price['cost_cents']);
        $this->assertEquals('plan_override', $price['source']);
    }

    public function test_tenant_override_wins_over_plan_and_global(): void
    {
        $plan = Plan::factory()->create(['sale_cents_overrides' => ['sms' => 12]]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        TenantServicePrice::create([
            'tenant_id'  => $tenant->id,
            'service'    => 'sms',
            'sale_cents' => 10,
            'reason'     => 'VIP',
            'created_by' => $user->id,
        ]);

        $svc = app(PricingService::class);
        $price = $svc->priceFor($tenant, 'sms');
        $this->assertEquals(10, $price['sale_cents']);
        $this->assertEquals('tenant_override', $price['source']);
    }

    public function test_throws_for_unknown_service(): void
    {
        $tenant = Tenant::factory()->create();
        $this->expectException(\InvalidArgumentException::class);
        app(PricingService::class)->priceFor($tenant, 'unknown_svc');
    }
}
