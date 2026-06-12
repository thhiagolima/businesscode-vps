<?php

namespace Tests\Feature\Account;

use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PricingEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_pricing_includes_all_messaging_channels(): void
    {
        ServicePrice::updateOrCreate(['service' => 'sms'],      ['cost_cents' => 5,  'sale_cents' => 10]);
        ServicePrice::updateOrCreate(['service' => 'voice'],    ['cost_cents' => 15, 'sale_cents' => 30]);
        ServicePrice::updateOrCreate(['service' => 'email'],    ['cost_cents' => 1,  'sale_cents' => 2]);
        ServicePrice::updateOrCreate(['service' => 'whatsapp'], ['cost_cents' => 20, 'sale_cents' => 40]);

        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/account/pricing');

        $response->assertOk();
        $services = collect($response->json('data'))->pluck('service')->all();

        // The "single source of truth" pricing endpoint MUST cover every channel
        // the user can run a campaign on. Otherwise frontend will fall back to
        // hard-coded prices (P0-03 divergence).
        $this->assertContains('sms',      $services);
        $this->assertContains('voice',    $services);
        $this->assertContains('email',    $services);
        $this->assertContains('whatsapp', $services, 'WhatsApp must be included so UI does not fall back to hard-coded prices');
    }
}
