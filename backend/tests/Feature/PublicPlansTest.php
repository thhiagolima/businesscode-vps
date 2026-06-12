<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_visitor_can_fetch_plans(): void
    {
        Plan::factory()->create([
            'slug' => 'starter',
            'price_monthly' => 97.00,
            'price_annual' => 931.20,
        ]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.slug', 'starter');
        $this->assertEqualsWithDelta(97.00, $response->json('data.0.price_monthly'), 0.001);
        $this->assertEqualsWithDelta(931.20, $response->json('data.0.price_annual'), 0.001);
    }

    public function test_identity_endpoint_returns_config_values(): void
    {
        config([
            'business.company_name' => 'Acme',
            'business.sales_whatsapp' => '5511988887777',
            'business.company_cnpj' => '12.345.678/0001-90',
        ]);

        $response = $this->getJson('/api/v1/public/identity');

        $response->assertStatus(200)
            ->assertJsonPath('company_name', 'Acme')
            ->assertJsonPath('sales_whatsapp', '5511988887777')
            ->assertJsonPath('cnpj', '12.345.678/0001-90');
    }

    public function test_stats_endpoint_reports_no_data_when_empty(): void
    {
        $response = $this->getJson('/api/v1/public/stats');

        $response->assertStatus(200)
            ->assertJsonPath('has_data', false)
            ->assertJsonPath('delivery_rate', null);
    }

    public function test_index_excludes_unlisted_plans(): void
    {
        Plan::factory()->create(['slug' => 'starter', 'price_monthly' => 89.00, 'listed' => true]);
        Plan::factory()->create(['slug' => 'custom-pedro', 'price_monthly' => 0.00, 'listed' => false]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertStatus(200);
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('starter', $slugs);
        $this->assertNotContains('custom-pedro', $slugs);
    }

    public function test_pricing_endpoint_returns_sale_prices_in_brl(): void
    {
        \App\Models\ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8]);
        \App\Models\ServicePrice::create(['service' => 'email', 'cost_cents' => 0, 'sale_cents' => 2]);

        $response = $this->getJson('/api/v1/public/pricing');

        $response->assertStatus(200);
        $sms = collect($response->json('data'))->firstWhere('service', 'sms');
        $this->assertSame(8, $sms['sale_cents']);
        $this->assertSame('SMS', $sms['label']);
    }

    public function test_pricing_endpoint_never_leaks_cost_cents(): void
    {
        \App\Models\ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8]);

        $response = $this->getJson('/api/v1/public/pricing');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('cost_cents', $row);
            $this->assertArrayNotHasKey('cost_micros', $row);
            $this->assertArrayNotHasKey('margin', $row);
        }
    }

    public function test_pricing_endpoint_only_exposes_public_channels(): void
    {
        \App\Models\ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8]);
        \App\Models\ServicePrice::create(['service' => 'whatsapp_utility', 'cost_cents' => 4, 'sale_cents' => 8]);

        $response = $this->getJson('/api/v1/public/pricing');

        $services = collect($response->json('data'))->pluck('service')->all();
        $this->assertContains('sms', $services);
        $this->assertNotContains('whatsapp_utility', $services);
    }
}
