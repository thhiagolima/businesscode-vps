<?php

namespace Tests\Feature\Messaging;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookCallEventsTest extends TestCase
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
            'external_message_id' => 'voice-mid-call',
            'status'              => 'sent',
            'cost_cents'          => 40,
            'sale_cents'          => 80,
            'sent_at'             => now()->subMinute(),
        ]);
    }

    public function test_voice_delivered_fires_call_answered_and_call_completed(): void
    {
        $dispatch = $this->makeVoiceDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId'             => 'voice-mid-call',
                    'status'                => ['groupId' => 3, 'groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET'],
                    'answerTime'            => '2026-05-28T10:00:00.000+0000',
                    'endTime'               => '2026-05-28T10:00:25.000+0000',
                    'callDurationInSeconds' => 25,
                ]],
            ])->assertOk();

        // message.delivered + call.answered + call.completed
        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'message.delivered');
        Queue::assertPushed(FireOutboundWebhookJob::class, function ($j) {
            return $j->event === 'call.answered'
                && ($j->payload['call']['voice_status'] ?? null) === 'DELIVERED_TO_HANDSET';
        });
        Queue::assertPushed(FireOutboundWebhookJob::class, function ($j) {
            return $j->event === 'call.completed'
                && ($j->payload['call']['duration_seconds'] ?? null) === 25;
        });
    }

    public function test_voice_no_answer_fires_call_failed_not_call_answered(): void
    {
        $dispatch = $this->makeVoiceDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId' => 'voice-mid-call',
                    'status'    => ['groupId' => 2, 'groupName' => 'UNDELIVERABLE', 'name' => 'NO_ANSWER'],
                ]],
            ])->assertOk();

        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'message.failed');
        Queue::assertPushed(FireOutboundWebhookJob::class, function ($j) {
            return $j->event === 'call.failed'
                && ($j->payload['call']['voice_status'] ?? null) === 'NO_ANSWER';
        });
        Queue::assertNotPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'call.answered');
        Queue::assertNotPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'call.completed');
    }

    public function test_voice_answer_metadata_in_later_report_still_fires_call_answered(): void
    {
        // Real-world Infobip voice sequencing: report #1 arrives as DELIVERED
        // without answerTime (status sent -> delivered, no call.answered yet),
        // then report #2 arrives with the SAME DELIVERED group now carrying the
        // answer/end timing. The plain status-dedup would drop report #2 because
        // status did not change, so call.answered/call.completed never fired and
        // answered_at stayed null. Simulate the post-report-#1 state directly.
        $tenant = Tenant::factory()->create();
        $dispatch = MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'           => $tenant->id,
            'channel'             => 'voice',
            'source'              => 'api',
            'to'                  => '+5521980194445',
            'content'             => 'Olá',
            'provider'            => 'infobip',
            'external_message_id' => 'voice-late-answer',
            'status'              => 'delivered',          // report #1 already applied
            'delivered_at'        => now()->subMinute(),
            'answered_at'         => null,                 // but answer timing not yet known
            'cost_cents'          => 40,
            'sale_cents'          => 80,
            'sent_at'             => now()->subMinutes(2),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId'             => 'voice-late-answer',
                    'status'                => ['groupId' => 3, 'groupName' => 'DELIVERED', 'name' => 'ANSWERED'],
                    'answerTime'            => '2026-05-28T10:00:00.000+0000',
                    'endTime'               => '2026-05-28T10:00:25.000+0000',
                    'callDurationInSeconds' => 25,
                ]],
            ])->assertOk();

        // The newly-arrived answer metadata must surface as call.answered/completed...
        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'call.answered');
        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'call.completed');
        // ...and the dispatch must actually persist the answer time.
        $this->assertNotNull($dispatch->fresh()->answered_at);
        // ...without re-firing message.delivered (status did not transition).
        Queue::assertNotPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'message.delivered');
    }

    public function test_sms_delivered_does_not_fire_call_events(): void
    {
        $tenant = Tenant::factory()->create();
        MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'           => $tenant->id,
            'channel'             => 'sms',
            'source'              => 'api',
            'to'                  => '+5521980194445',
            'content'             => 'oi',
            'provider'            => 'infobip',
            'external_message_id' => 'sms-mid-call',
            'status'              => 'sent',
            'cost_cents'          => 8,
            'sale_cents'          => 15,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId' => 'sms-mid-call',
                    'status'    => ['groupId' => 3, 'groupName' => 'DELIVERED'],
                ]],
            ])->assertOk();

        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'message.delivered');
        Queue::assertNotPushed(FireOutboundWebhookJob::class, fn ($j) => str_starts_with($j->event, 'call.'));
    }

    public function test_voice_delivered_without_answer_time_only_fires_message_delivered(): void
    {
        $dispatch = $this->makeVoiceDispatch();

        // Edge case: provider reported DELIVERED but did not include answer
        // timing — we must NOT fabricate a "call.answered" because the call
        // was never actually picked up by a human (e.g. voicemail rejected).
        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId' => 'voice-mid-call',
                    'status'    => ['groupId' => 3, 'groupName' => 'DELIVERED', 'name' => 'DELIVERED_TO_HANDSET'],
                    // No answerTime / endTime
                ]],
            ])->assertOk();

        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'message.delivered');
        Queue::assertNotPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'call.answered');
        Queue::assertNotPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'call.completed');
    }
}
