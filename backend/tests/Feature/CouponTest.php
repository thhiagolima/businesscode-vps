<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_valid_coupon()
    {
        $coupon = Coupon::create([
            'code' => 'SAVE20',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'valid_from' => now()->subDay(),
            'active' => true,
        ]);

        $plan = Plan::create([
            'name' => 'Pro', 'slug' => 'pro', 'price_monthly' => 79.00,
            'included_balance_cents' => 75000, 'max_contacts' => 10000, 'max_campaigns' => 100,
        ]);

        $response = $this->postJson('/api/v1/checkout/validate-coupon', [
            'code' => 'SAVE20',
            'plan_id' => $plan->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.coupon.code', 'SAVE20')
            ->assertJsonPath('data.calculated_discount', 15.80);
    }

    public function test_validate_expired_coupon_returns_error()
    {
        Coupon::create([
            'code' => 'EXPIRED',
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'valid_from' => now()->subMonth(),
            'valid_until' => now()->subDay(),
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/checkout/validate-coupon', [
            'code' => 'EXPIRED',
        ]);

        $response->assertStatus(422);
    }

    public function test_validate_maxed_out_coupon_returns_error()
    {
        Coupon::create([
            'code' => 'MAXED',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'valid_from' => now()->subDay(),
            'max_uses' => 5,
            'times_used' => 5,
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/checkout/validate-coupon', [
            'code' => 'MAXED',
        ]);

        $response->assertStatus(422);
    }

    public function test_coupon_calculate_percentage_discount()
    {
        $coupon = new Coupon(['discount_type' => 'percentage', 'discount_value' => 25]);
        $this->assertEquals(24.75, $coupon->calculateDiscount(99.00));
    }

    public function test_coupon_calculate_fixed_discount_capped_at_price()
    {
        $coupon = new Coupon(['discount_type' => 'fixed', 'discount_value' => 100]);
        $this->assertEquals(49.00, $coupon->calculateDiscount(49.00));
    }
}
