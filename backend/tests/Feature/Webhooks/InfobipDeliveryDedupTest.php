<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\MessageDispatch;
use App\Models\OutboundWebhook;
use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class InfobipDeliveryDedupTest extends TestCase
{
    use RefreshDatabase;

    private function seedSecret(): void
    {
        Setting::updateOrCreate(
            ['tenant_id' => null, 'group' => 'infobip', 'key' => 'webhook_secret'],
            ['value' => 'sec', 'type' => 'string']
        );
    }

    private function makeDispatch(int $tenantId, string $externalId): MessageDispatch
    {
        $d = new MessageDispatch();
        $d->forceFill([
            'tenant_id' => $tenantId, 'channel' => 'sms', 'source' => 'api',
            'to' => '+5511999990001', 'content' => 'oi', 'provider' => 'infobip',
            'status' => 'sent', 'external_message_id' => $externalId,
            'cost_cents' => 0, 'sale_cents' => 0, 'charged_cents' => 0,
        ]);
        $d->save();
        return $d;
    }

    public function test_replayed_delivery_does_not_overwrite_delivered_at_or_refire_outbound(): void
    {
        $this->seedSecret();
        Bus::fake([FireOutboundWebhookJob::class]);

        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        OutboundWebhook::forceCreate([
            'tenant_id' => $tenant->id, 'url' => 'https://example.com/hook',
            'events' => ['message.delivered'], 'is_active' => true, 'secret' => str_repeat('a', 32),
        ]);
        $dispatch = $this->makeDispatch($tenant->id, 'msg-123');

        $payload = ['results' => [[
            'messageId' => 'msg-123',
            'status' => ['groupId' => 3, 'groupName' => 'DELIVERED'],
        ]]];

        // First delivery webhook.
        $this->postJson('/api/v1/webhooks/infobip/delivery', $payload, ['Authorization' => 'Bearer sec'])
            ->assertOk();

        $first = $dispatch->fresh();
        $this->assertSame('delivered', $first->status);
        $this->assertNotNull($first->delivered_at);
        $firstDeliveredAt = $first->delivered_at;

        // Re-send same delivery (replay / Infobip retry after timeout).
        sleep(1); // ensure timestamps would diverge if NOT deduped.
        $this->postJson('/api/v1/webhooks/infobip/delivery', $payload, ['Authorization' => 'Bearer sec'])
            ->assertOk();

        $second = $dispatch->fresh();
        $this->assertSame('delivered', $second->status);
        $this->assertEquals(
            $firstDeliveredAt->toIso8601String(),
            $second->delivered_at->toIso8601String(),
            'delivered_at must NOT be overwritten on replay'
        );

        // Outbound webhook fired exactly once across the two replays.
        Bus::assertDispatchedTimes(FireOutboundWebhookJob::class, 1);
    }
}
