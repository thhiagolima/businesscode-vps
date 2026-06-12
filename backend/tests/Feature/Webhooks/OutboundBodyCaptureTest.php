<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\OutboundWebhook;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract guard for the outbound webhook wire format. A partner CRM (Pedro /
 * betleads.io) reported receiving an empty "{}" body; this proves our side
 * actually serializes a populated JSON payload with Content-Type
 * application/json, so an empty body at the receiver is a parsing problem on
 * the receiver, not a missing serialization on ours.
 */
class OutboundBodyCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbound_webhook_sends_populated_json_body(): void
    {
        $tenant = Tenant::factory()->create();
        OutboundWebhook::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'url'       => 'https://example.com/hook',
            'events'    => ['call.answered'],
            'is_active' => true,
            'secret'    => 'sek',
        ]);

        Http::fake(['example.com/*' => Http::response('ok', 200)]);

        (new FireOutboundWebhookJob($tenant->id, 'call.answered', [
            'dispatch_id'     => 2018,
            'channel'         => 'voice',
            'status'          => 'delivered',
            'idempotency_key' => 'uuid-abc',
            'call'            => ['voice_status' => 'DELIVERED_TO_HANDSET', 'duration_seconds' => 12],
        ]))->handle();

        Http::assertSent(function ($request) {
            $this->assertSame('application/json', $request->header('Content-Type')[0]);

            $raw = $request->body();
            $this->assertNotSame('{}', $raw, 'Outbound body must never be empty');

            $decoded = json_decode($raw, true);
            $this->assertSame('call.answered', $decoded['event']);
            $this->assertNotEmpty($decoded['timestamp']);
            $this->assertSame(2018, $decoded['data']['dispatch_id']);
            $this->assertSame('uuid-abc', $decoded['data']['idempotency_key']);
            $this->assertSame('delivered', $decoded['data']['status']);
            $this->assertSame(12, $decoded['data']['call']['duration_seconds']);

            return true;
        });
    }
}
