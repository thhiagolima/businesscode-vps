<?php

namespace Tests\Feature\Messaging;

use App\Jobs\FireOutboundWebhookJob;
use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * End-to-end coverage for the provider message-id capture. This is the link
 * that was missing: SendVoiceApiTest fakes the queue (job never runs) and
 * WebhookVoiceCallTrackingTest pre-seeds external_message_id by hand, so a
 * wrong extraction path in InfobipService silently produced a NULL
 * external_message_id and every voice/email status webhook stopped firing
 * because delivery reports could no longer match the dispatch.
 */
class SendMessageJobExternalIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(SettingsService::class);
        $settings->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        $settings->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        Queue::fake([FireOutboundWebhookJob::class]);
    }

    private function queuedDispatch(string $channel, array $extra = []): MessageDispatch
    {
        $tenant = Tenant::factory()->create();
        return MessageDispatch::withoutGlobalScopes()->create(array_merge([
            'tenant_id'   => $tenant->id,
            'channel'     => $channel,
            'source'      => 'api',
            'to'          => $channel === 'email' ? 'dest@example.com' : '+5521980194445',
            'content'     => 'Olá',
            'provider'    => 'infobip',
            'status'      => 'queued',
            'cost_cents'  => 40,
            'sale_cents'  => 80,
        ], $extra));
    }

    public function test_voice_send_persists_external_message_id_from_nested_envelope(): void
    {
        $dispatch = $this->queuedDispatch('voice');

        Http::fake([
            'api.infobip.com/tts/3/single' => Http::response([
                'bulkId'   => 'bulk-9',
                'messages' => [['to' => '+5521980194445', 'messageId' => 'voice-real-id']],
            ], 200),
        ]);

        (new SendMessageJob($dispatch->id))->handle(app(InfobipService::class));

        $dispatch->refresh();
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame('voice-real-id', $dispatch->external_message_id);
    }

    public function test_email_send_persists_external_message_id_from_nested_envelope(): void
    {
        $dispatch = $this->queuedDispatch('email', ['subject' => 'Hi']);

        Http::fake([
            'api.infobip.com/email/3/send' => Http::response([
                'messages' => [['to' => 'dest@example.com', 'messageId' => 'email-real-id']],
            ], 200),
        ]);

        (new SendMessageJob($dispatch->id))->handle(app(InfobipService::class));

        $dispatch->refresh();
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame('email-real-id', $dispatch->external_message_id);
    }
}
