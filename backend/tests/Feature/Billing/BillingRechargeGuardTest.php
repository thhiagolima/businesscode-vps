<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BillingRechargeGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_recharge_rejects_zero_amount(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 5000,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(BillingService::class)->recharge($tenant->id, 0, 'payment', 1);
    }

    public function test_recharge_rejects_negative_amount(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 5000,
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(BillingService::class)->recharge($tenant->id, -1000, 'payment', 1);
    }

    public function test_recharge_balance_unchanged_after_rejected_negative(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 5000,
        ]);

        try {
            app(BillingService::class)->recharge($tenant->id, -1000, 'payment', 1);
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertSame(5000, (int) $tenant->fresh()->balance_cents);
    }

    public function test_recharge_accepts_positive_amount(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 5000,
        ]);

        app(BillingService::class)->recharge($tenant->id, 1000, 'payment', 1);

        $this->assertSame(6000, (int) $tenant->fresh()->balance_cents);
    }
}
