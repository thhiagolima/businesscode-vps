<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_cannot_update_other_tenant_campaign(): void
    {
        $plan    = Plan::factory()->create(['slug' => 'iso-' . uniqid()]);
        $tenantA = Tenant::factory()->create(['plan_id' => $plan->id]);
        $tenantB = Tenant::factory()->create(['plan_id' => $plan->id]);
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => 'admin']);

        $campaignB = Campaign::factory()->create([
            'tenant_id' => $tenantB->id,
            'type'      => 'sms',
            'status'    => 'draft',
        ]);

        $response = $this->actingAs($userA)->putJson("/api/v1/campaigns/{$campaignB->id}", [
            'name' => 'Hacked',
        ]);

        // Should be 403 (ensureTenantOwns) or 404 (global scope filters it out)
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Expected 403 or 404, got {$response->status()}"
        );
    }

    public function test_tenant_cannot_see_other_tenant_campaigns(): void
    {
        $plan    = Plan::factory()->create(['slug' => 'iso2-' . uniqid()]);
        $tenantA = Tenant::factory()->create(['plan_id' => $plan->id]);
        $tenantB = Tenant::factory()->create(['plan_id' => $plan->id]);
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => 'admin']);

        Campaign::factory()->create([
            'tenant_id' => $tenantB->id,
            'type'      => 'sms',
            'status'    => 'draft',
            'name'      => 'SecretCampaign',
        ]);

        $response = $this->actingAs($userA)->getJson('/api/v1/campaigns');

        $response->assertStatus(200);
        $this->assertStringNotContainsString('SecretCampaign', $response->getContent());
    }
}
