<?php

namespace Tests\Feature\Messaging;

use App\Models\CampaignDispatch;
use App\Models\Tenant;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookDeliveryRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'webhook_secret', 'test-secret', 'string');
    }

    public function test_webhook_updates_campaign_dispatch_when_no_message_dispatch_match(): void
    {
        $tenant = Tenant::factory()->create();
        $dispatch = CampaignDispatch::factory()->create([
            'tenant_id' => $tenant->id,
            'external_message_id' => 'msg-camp-1',
            'status' => 'sent',
        ]);

        $resp = $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId' => 'msg-camp-1',
                    'status' => ['groupId' => 3, 'groupName' => 'DELIVERED'],
                ]],
            ]);

        $resp->assertOk()->assertJson(['ok' => true, 'processed' => 1]);
        $this->assertEquals('delivered', $dispatch->fresh()->status);
    }
}
