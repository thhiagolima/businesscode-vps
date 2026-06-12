<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Models\User;
use App\Services\CampaignStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantAndUser(): array
    {
        $plan = Plan::factory()->create(['slug' => 'test-' . uniqid(), 'included_balance_cents' => 1500]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 1500]);
        TenantChannel::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'channel' => 'sms',   'status' => 'enabled', 'config' => []]);
        TenantChannel::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'channel' => 'voice', 'status' => 'enabled', 'config' => []]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        return [$tenant, $user];
    }

    public function test_voice_campaign_requires_audio_url(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::factory()->create([
            'tenant_id'       => $tenant->id,
            'type'            => 'voice',
            'content'         => 'Some script text',
            'audio_url'       => null,
            'contact_list_id' => null,
            'settings'        => ['adhoc_phones' => ['+5511999999999']],
            'status'          => 'draft',
        ]);

        $machine = new CampaignStateMachine();
        $errors  = $machine->assertCanDispatch($campaign);

        $this->assertNotEmpty($errors);
        $this->assertTrue(
            collect($errors)->contains(fn($e) => str_contains($e, 'udio')),
            'Expected error about audio requirement. Got: ' . implode(', ', $errors)
        );
    }

    public function test_sms_campaign_allows_text_content(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::factory()->create([
            'tenant_id' => $tenant->id,
            'type'      => 'sms',
            'content'   => 'Hello world',
            'status'    => 'draft',
            'settings'  => ['adhoc_phones' => ['+5511999999999']],
        ]);

        $machine = new CampaignStateMachine();
        $errors  = $machine->assertCanDispatch($campaign);

        $this->assertEmpty($errors, 'SMS with content should have no errors. Got: ' . implode(', ', $errors));
    }

    public function test_cannot_dispatch_without_contacts(): void
    {
        [$tenant, $user] = $this->createTenantAndUser();
        $campaign = Campaign::factory()->create([
            'tenant_id'       => $tenant->id,
            'type'            => 'sms',
            'content'         => 'Hello',
            'contact_list_id' => null,
            'settings'        => [],
            'status'          => 'draft',
        ]);

        $machine = new CampaignStateMachine();
        $errors  = $machine->assertCanDispatch($campaign);

        $this->assertNotEmpty($errors);
    }
}
