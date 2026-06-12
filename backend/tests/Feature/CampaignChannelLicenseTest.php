<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignChannelLicenseTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithChannels(array $enabledChannels): array
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        foreach (['sms', 'voice', 'email', 'whatsapp'] as $channel) {
            TenantChannel::create([
                'tenant_id' => $tenant->id, 'channel' => $channel,
                'status'    => in_array($channel, $enabledChannels, true) ? 'enabled' : 'disabled',
                'config'    => [],
            ]);
        }
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        return [$tenant, $user, $list];
    }

    public function test_store_rejects_campaign_for_disabled_channel(): void
    {
        [, $user, $list] = $this->makeUserWithChannels(['sms']); // voice disabled
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/campaigns', [
            'name'            => 'voice campaign on a tenant without voice',
            'type'            => 'voice',
            'content'         => 'hi',
            'contact_list_id' => $list->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Campaign::withoutGlobalScopes()->count(), 'No campaign should be created');
    }

    public function test_store_accepts_campaign_for_enabled_channel(): void
    {
        [, $user, $list] = $this->makeUserWithChannels(['sms']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/campaigns', [
            'name'            => 'sms campaign',
            'type'            => 'sms',
            'content'         => 'hi',
            'contact_list_id' => $list->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, Campaign::withoutGlobalScopes()->count());
    }

    public function test_update_rejects_changing_type_to_disabled_channel(): void
    {
        [$tenant, $user, $list] = $this->makeUserWithChannels(['sms']);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'sms',
            'status' => 'draft', 'content' => 'oi', 'contact_list_id' => $list->id,
        ])->save();

        Sanctum::actingAs($user);
        $response = $this->putJson("/api/v1/campaigns/{$campaign->id}", [
            'type' => 'voice',
        ]);

        $response->assertStatus(422);
        $this->assertSame('sms', $campaign->fresh()->type);
    }
}
