<?php

namespace Tests\Feature\Account;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
    }

    private function makeUser(array $tenantOverrides = []): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(array_merge([
            'plan_id'            => $plan->id,
            'balance_cents'      => 12345,
            'credit_limit_cents' => 5000,
            'billing_status'     => 'active',
        ], $tenantOverrides));
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_balance_returns_current_tenant_state(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->getJson('/api/v1/account/balance');

        $resp->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'balance_cents', 'balance_brl',
                    'credit_limit_cents', 'credit_limit_brl',
                    'available_cents', 'available_brl',
                    'billing_status', 'billing_cycle_day', 'last_billing_at',
                ],
            ])
            ->assertJsonPath('data.balance_cents', 12345)
            ->assertJsonPath('data.credit_limit_cents', 5000)
            ->assertJsonPath('data.available_cents', 17345)
            ->assertJsonPath('data.billing_status', 'active');

        $this->assertSame('R$ 123,45', $resp->json('data.balance_brl'));
        $this->assertSame('R$ 50,00', $resp->json('data.credit_limit_brl'));
        $this->assertSame('R$ 173,45', $resp->json('data.available_brl'));
    }

    public function test_balance_requires_auth(): void
    {
        $this->getJson('/api/v1/account/balance')->assertStatus(401);
    }

    public function test_pricing_returns_5_services_in_brl(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->getJson('/api/v1/account/pricing');

        $resp->assertStatus(200);
        $rows = $resp->json('data');
        $this->assertCount(5, $rows);

        $services = collect($rows)->pluck('service')->all();
        $this->assertEqualsCanonicalizing(
            ['sms', 'voice', 'email', 'ai_generation', 'audio_tts'],
            $services
        );

        foreach ($rows as $row) {
            $this->assertArrayHasKey('sale_cents', $row);
            $this->assertArrayHasKey('sale_brl', $row);
            $this->assertArrayHasKey('source', $row);
            // cost_cents MUST NOT be exposed
            $this->assertArrayNotHasKey('cost_cents', $row);
            $this->assertStringStartsWith('R$', $row['sale_brl']);
        }
    }

    public function test_pricing_reflects_tenant_override(): void
    {
        $user = $this->makeUser();

        // Override SMS price for this tenant to 99 cents
        TenantServicePrice::create([
            'tenant_id' => $user->tenant_id,
            'service'   => 'sms',
            'sale_cents'=> 99,
        ]);

        Sanctum::actingAs($user);
        $resp = $this->getJson('/api/v1/account/pricing');
        $resp->assertStatus(200);

        $sms = collect($resp->json('data'))->firstWhere('service', 'sms');
        $this->assertNotNull($sms);
        $this->assertSame(99, $sms['sale_cents']);
        $this->assertSame('tenant_override', $sms['source']);
        $this->assertSame('R$ 0,99', $sms['sale_brl']);
    }
}
