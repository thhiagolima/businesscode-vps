<?php

namespace Tests\Feature\Messaging;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookVoiceCallTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'webhook_secret', 'test-secret', 'string');
        Queue::fake();
    }

    private function makeVoiceDispatch(): MessageDispatch
    {
        $tenant = Tenant::factory()->create();
        return MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'           => $tenant->id,
            'channel'             => 'voice',
            'source'              => 'api',
            'to'                  => '+5521980194445',
            'content'             => 'Olá',
            'provider'            => 'infobip',
            'external_message_id' => 'voice-mid-1',
            'status'              => 'sent',
            'cost_cents'          => 40,
            'sale_cents'          => 80,
            'sent_at'             => now()->subMinute(),
        ]);
    }

    public function test_voice_delivered_populates_answered_ended_duration_voice_status(): void
    {
        $dispatch = $this->makeVoiceDispatch();

        $payload = [
            'results' => [[
                'messageId'             => 'voice-mid-1',
                'status'                => [
                    'groupId'   => 3,
                    'groupName' => 'DELIVERED',
                    'name'      => 'DELIVERED_TO_HANDSET',
                ],
                'answerTime'            => '2026-05-28T10:00:00.000+0000',
                'endTime'               => '2026-05-28T10:00:25.000+0000',
                'callDurationInSeconds' => 25,
            ]],
        ];

        $resp = $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', $payload);

        $resp->assertOk()->assertJson(['ok' => true, 'processed' => 1]);

        $dispatch->refresh();
        $this->assertEquals('delivered', $dispatch->status);
        $this->assertNotNull($dispatch->delivered_at);
        $this->assertNotNull($dispatch->answered_at);
        $this->assertNotNull($dispatch->ended_at);
        $this->assertEquals(25, $dispatch->call_duration_seconds);
        $this->assertEquals('DELIVERED_TO_HANDSET', $dispatch->voice_status);
    }

    public function test_voice_failed_captures_voice_status_without_call_timing(): void
    {
        $dispatch = $this->makeVoiceDispatch();

        $payload = [
            'results' => [[
                'messageId' => 'voice-mid-1',
                'status'    => [
                    'groupId'   => 2,
                    'groupName' => 'UNDELIVERABLE',
                    'name'      => 'NO_ANSWER',
                ],
                // No answerTime / endTime / duration — call was never answered.
            ]],
        ];

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', $payload)
            ->assertOk();

        $dispatch->refresh();
        $this->assertEquals('failed', $dispatch->status);
        $this->assertEquals('NO_ANSWER', $dispatch->voice_status);
        $this->assertNull($dispatch->answered_at);
        $this->assertNull($dispatch->ended_at);
        $this->assertNull($dispatch->call_duration_seconds);
    }

    public function test_voice_event_payload_includes_call_details(): void
    {
        $dispatch = $this->makeVoiceDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId'             => 'voice-mid-1',
                    'status'                => ['groupId' => 3, 'groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET'],
                    'answerTime'            => '2026-05-28T10:00:00.000+0000',
                    'endTime'               => '2026-05-28T10:00:25.000+0000',
                    'callDurationInSeconds' => 25,
                ]],
            ])->assertOk();

        Queue::assertPushed(FireOutboundWebhookJob::class, function ($job) {
            // Payload shape is the third constructor argument.
            $reflection = new \ReflectionObject($job);
            $payload = $reflection->getProperty('payload')->getValue($job);
            return ($job->event ?? null) === 'message.delivered'
                && isset($payload['call'])
                && $payload['call']['voice_status'] === 'DELIVERED_TO_HANDSET'
                && $payload['call']['duration_seconds'] === 25;
        });
    }

    public function test_sms_dispatch_does_not_get_voice_fields_polluted(): void
    {
        $tenant = Tenant::factory()->create();
        $smsDispatch = MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'           => $tenant->id,
            'channel'             => 'sms',
            'source'              => 'api',
            'to'                  => '+5521980194445',
            'content'             => 'oi',
            'provider'            => 'infobip',
            'external_message_id' => 'sms-mid-1',
            'status'              => 'sent',
            'cost_cents'          => 8,
            'sale_cents'          => 15,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId'             => 'sms-mid-1',
                    'status'                => ['groupId' => 3, 'groupName' => 'DELIVERED'],
                    // Even if Infobip sent these by mistake, SMS dispatch must ignore them
                    'answerTime'            => '2026-05-28T10:00:00.000+0000',
                    'callDurationInSeconds' => 30,
                ]],
            ])->assertOk();

        $smsDispatch->refresh();
        $this->assertEquals('delivered', $smsDispatch->status);
        $this->assertNull($smsDispatch->answered_at, 'SMS must not get answered_at populated');
        $this->assertNull($smsDispatch->call_duration_seconds);
        $this->assertNull($smsDispatch->voice_status);
    }
}
