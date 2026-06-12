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

class CampaignCancelResetTest extends TestCase
{
    use RefreshDatabase;

    private function setupCampaign(string $status): array
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'sms', 'status' => 'enabled', 'config' => []]);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'sms', 'status' => $status,
            'content' => 'oi', 'contact_list_id' => $list->id,
            'sent_count' => 3, 'failed_count' => 7,
            'scheduled_at' => $status === 'scheduled' ? now()->addDay() : null,
        ])->save();
        return [$tenant, $user, $campaign];
    }

    public function test_cancel_moves_scheduled_to_draft_and_clears_schedule(): void
    {
        [, $user, $campaign] = $this->setupCampaign('scheduled');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/cancel")->assertOk();

        $fresh = $campaign->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->scheduled_at);
    }

    public function test_cancel_rejects_completed_campaign(): void
    {
        [, $user, $campaign] = $this->setupCampaign('completed');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/cancel")->assertStatus(422);
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_reset_moves_failed_to_draft_and_zeroes_counters(): void
    {
        [, $user, $campaign] = $this->setupCampaign('failed');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/reset")->assertOk();

        $fresh = $campaign->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertSame(0, (int) $fresh->sent_count);
        $this->assertSame(0, (int) $fresh->failed_count);
    }

    public function test_reset_rejects_non_failed_campaign(): void
    {
        [, $user, $campaign] = $this->setupCampaign('draft');
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/campaigns/{$campaign->id}/reset")->assertStatus(422);
    }
}
