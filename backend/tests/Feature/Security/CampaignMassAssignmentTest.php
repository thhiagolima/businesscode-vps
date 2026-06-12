<?php

namespace Tests\Feature\Security;

use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_tenant_id_is_ignored_on_store(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        $userA = (new User())->forceFill([
            'name' => 'UA', 'email' => 'ua@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenantA->id, 'role' => 'admin',
        ]);
        $userA->save();
        TenantChannel::create(['tenant_id' => $tenantA->id, 'channel' => 'sms', 'status' => 'enabled', 'config' => []]);
        $list = ContactList::create(['tenant_id' => $tenantA->id, 'name' => 'L']);
        Sanctum::actingAs($userA);

        // Attacker passes another tenant_id in the body.
        $response = $this->postJson('/api/v1/campaigns', [
            'tenant_id'       => $tenantB->id,
            'name'            => 'evil',
            'type'            => 'sms',
            'content'         => 'oi',
            'contact_list_id' => $list->id,
        ]);

        $response->assertStatus(201);

        $campaign = Campaign::withoutGlobalScopes()->where('name', 'evil')->first();
        $this->assertNotNull($campaign);
        $this->assertSame($tenantA->id, (int) $campaign->tenant_id, 'tenant_id must be forced to the auth user');
    }

    public function test_payload_tenant_id_is_ignored_on_update(): void
    {
        $tenantA = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        $userA = (new User())->forceFill([
            'name' => 'UA', 'email' => 'ua@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenantA->id, 'role' => 'admin',
        ]);
        $userA->save();
        TenantChannel::create(['tenant_id' => $tenantA->id, 'channel' => 'sms', 'status' => 'enabled', 'config' => []]);
        $list = ContactList::create(['tenant_id' => $tenantA->id, 'name' => 'L']);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenantA->id, 'name' => 'original', 'type' => 'sms',
            'status' => 'draft', 'content' => 'oi', 'contact_list_id' => $list->id,
        ])->save();

        Sanctum::actingAs($userA);

        $response = $this->putJson("/api/v1/campaigns/{$campaign->id}", [
            'tenant_id' => $tenantB->id,
            'name'      => 'updated',
        ]);

        $response->assertOk();
        $this->assertSame($tenantA->id, (int) $campaign->fresh()->tenant_id);
    }
}
