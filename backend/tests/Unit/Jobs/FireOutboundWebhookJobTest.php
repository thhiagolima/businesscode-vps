<?php

namespace Tests\Unit\Jobs;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\OutboundWebhook;
use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FireOutboundWebhookJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebhook(array $overrides = []): OutboundWebhook
    {
        $tenant = Tenant::factory()->create();

        $w = new OutboundWebhook();
        $w->tenant_id = $tenant->id;
        $w->url       = $overrides['url']    ?? 'https://example.com/webhook';
        $w->events    = $overrides['events'] ?? ['message.sent', 'webhook.test'];
        $w->is_active = $overrides['is_active'] ?? true;
        $w->secret    = $overrides['secret'] ?? 'super-secret-key-1234567890';
        $w->save();

        return $w;
    }

    public function test_fires_and_logs_delivery_with_correct_signature(): void
    {
        $webhook = $this->makeWebhook();

        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        (new FireOutboundWebhookJob(
            tenantId: $webhook->tenant_id,
            event:    'message.sent',
            payload:  ['dispatch_id' => 99, 'channel' => 'sms']
        ))->handle();

        $delivery = WebhookDelivery::withoutGlobalScopes()
            ->where('outbound_webhook_id', $webhook->id)
            ->first();

        $this->assertNotNull($delivery, 'Expected a WebhookDelivery row');
        $this->assertSame(200, (int) $delivery->response_status);
        $this->assertSame('message.sent', $delivery->event);
        $this->assertNotNull($delivery->duration_ms);
        $this->assertSame($webhook->tenant_id, $delivery->tenant_id);

        // Verify HMAC SHA-256 signature was sent
        Http::assertSent(function ($request) use ($webhook) {
            $body = $request->body();
            $expected = hash_hmac('sha256', $body, $webhook->secret);
            return $request['event'] === 'message.sent'
                && $request->header('X-Webhook-Signature')[0] === $expected;
        });
    }

    public function test_logs_delivery_on_connection_failure(): void
    {
        $webhook = $this->makeWebhook();

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('connect timeout');
        });

        (new FireOutboundWebhookJob(
            tenantId: $webhook->tenant_id,
            event:    'webhook.test',
            payload:  ['hello' => 'world']
        ))->handle();

        $delivery = WebhookDelivery::withoutGlobalScopes()
            ->where('outbound_webhook_id', $webhook->id)
            ->first();

        $this->assertNotNull($delivery);
        $this->assertNull($delivery->response_status);
        $this->assertStringContainsString('connect timeout', (string) $delivery->error_message);
    }

    public function test_skips_inactive_webhooks(): void
    {
        $webhook = $this->makeWebhook(['is_active' => false]);

        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        (new FireOutboundWebhookJob(
            tenantId: $webhook->tenant_id,
            event:    'message.sent',
            payload:  []
        ))->handle();

        $this->assertSame(
            0,
            WebhookDelivery::withoutGlobalScopes()->where('outbound_webhook_id', $webhook->id)->count()
        );
        Http::assertNothingSent();
    }

    public function test_filters_by_subscribed_events(): void
    {
        $webhook = $this->makeWebhook(['events' => ['message.delivered']]);

        Http::fake([
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);

        (new FireOutboundWebhookJob(
            tenantId: $webhook->tenant_id,
            event:    'message.sent',
            payload:  []
        ))->handle();

        $this->assertSame(
            0,
            WebhookDelivery::withoutGlobalScopes()->where('outbound_webhook_id', $webhook->id)->count()
        );
        Http::assertNothingSent();
    }
}
