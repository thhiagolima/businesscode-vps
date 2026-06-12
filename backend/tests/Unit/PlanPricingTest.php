<?php

namespace Tests\Unit;

use App\Models\Plan;
use PHPUnit\Framework\TestCase;

class PlanPricingTest extends TestCase
{
    public function test_monthly_price_returned_when_cycle_is_monthly(): void
    {
        $plan = new Plan(['price_monthly' => 97.00, 'price_annual' => 931.20]);
        $this->assertSame(97.00, $plan->priceFor('monthly'));
    }

    public function test_annual_price_returned_when_cycle_is_annual(): void
    {
        $plan = new Plan(['price_monthly' => 97.00, 'price_annual' => 931.20]);
        $this->assertSame(931.20, $plan->priceFor('annual'));
    }

    public function test_annual_falls_back_to_20_percent_discount_when_price_annual_missing(): void
    {
        $plan = new Plan(['price_monthly' => 100.00, 'price_annual' => null]);
        $this->assertSame(960.00, $plan->priceFor('annual'));
    }

    public function test_monthly_equivalent_for_annual_cycle(): void
    {
        $plan = new Plan(['price_monthly' => 297.00, 'price_annual' => 2851.20]);
        $this->assertSame(237.60, $plan->monthlyEquivalentFor('annual'));
    }

    public function test_monthly_equivalent_for_monthly_cycle(): void
    {
        $plan = new Plan(['price_monthly' => 297.00]);
        $this->assertSame(297.00, $plan->monthlyEquivalentFor('monthly'));
    }
}
