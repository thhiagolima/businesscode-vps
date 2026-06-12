<?php

namespace Tests\Feature\Messaging;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEmailEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'webhook_secret', 'test-secret', 'string');
        Queue::fake();
    }

    private function makeEmailDispatch(string $messageId = 'email-mid-1'): MessageDispatch
    {
        $tenant = Tenant::factory()->create();
        return MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'           => $tenant->id,
            'channel'             => 'email',
            'source'              => 'api',
            'to'                  => 'user@example.com',
            'content'             => '<p>Olá</p>',
            'subject'             => 'Promo',
            'provider'            => 'infobip',
            'external_message_id' => $messageId,
            'status'              => 'delivered',
            'cost_cents'          => 1,
            'sale_cents'          => 3,
        ]);
    }

    public function test_opened_event_populates_opened_at_and_fires_email_opened(): void
    {
        $dispatch = $this->makeEmailDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId' => 'email-mid-1',
                    'event'     => 'OPENED',
                    'openedAt'  => '2026-06-05T11:00:00.000+0000',
                ]],
            ])->assertOk()->assertJson(['ok' => true, 'processed' => 1]);

        $dispatch->refresh();
        $this->assertNotNull($dispatch->opened_at);

        Queue::assertPushed(FireOutboundWebhookJob::class, function ($j) {
            return $j->event === 'email.opened'
                && ! empty($j->payload['email']['opened_at']);
        });
    }

    public function test_clicked_event_populates_first_clicked_at_and_fires_email_clicked(): void
    {
        $dispatch = $this->makeEmailDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId' => 'email-mid-1',
                    'event'     => 'CLICKED',
                    'clickedAt' => '2026-06-05T11:05:00.000+0000',
                ]],
            ])->assertOk();

        $dispatch->refresh();
        $this->assertNotNull($dispatch->first_clicked_at);
        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'email.clicked');
    }

    public function test_bounced_event_populates_bounce_type_and_fires_email_bounced(): void
    {
        $dispatch = $this->makeEmailDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId'   => 'email-mid-1',
                    'event'       => 'BOUNCED',
                    'bounceType'  => 'HARD',
                ]],
            ])->assertOk();

        $dispatch->refresh();
        $this->assertNotNull($dispatch->bounced_at);
        $this->assertEquals('HARD', $dispatch->bounce_type);
        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'email.bounced');
    }

    public function test_complaint_event_populates_complaint_at_and_fires_email_complaint(): void
    {
        $dispatch = $this->makeEmailDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId' => 'email-mid-1',
                    'event'     => 'COMPLAINT',
                ]],
            ])->assertOk();

        $dispatch->refresh();
        $this->assertNotNull($dispatch->complaint_at);
        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'email.complaint');
    }

    public function test_unsubscribe_event_populates_unsubscribed_at_and_fires_email_unsubscribed(): void
    {
        $dispatch = $this->makeEmailDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId' => 'email-mid-1',
                    'event'     => 'UNSUBSCRIBE',
                ]],
            ])->assertOk();

        $dispatch->refresh();
        $this->assertNotNull($dispatch->unsubscribed_at);
        Queue::assertPushed(FireOutboundWebhookJob::class, fn ($j) => $j->event === 'email.unsubscribed');
    }

    public function test_replayed_opened_event_is_deduped(): void
    {
        $dispatch = $this->makeEmailDispatch();

        $payload = [
            'results' => [[
                'messageId' => 'email-mid-1',
                'event'     => 'OPENED',
                'openedAt'  => '2026-06-05T11:00:00.000+0000',
            ]],
        ];

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', $payload)->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', $payload)->assertOk();

        // Outbound webhook fired exactly once across the two replays.
        Queue::assertPushed(FireOutboundWebhookJob::class, 1);
    }

    public function test_unknown_message_id_returns_zero_processed(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId' => 'does-not-exist',
                    'event'     => 'OPENED',
                ]],
            ])->assertOk()->assertJson(['ok' => true, 'processed' => 0]);

        Queue::assertNotPushed(FireOutboundWebhookJob::class);
    }

    public function test_unknown_event_name_is_ignored(): void
    {
        $dispatch = $this->makeEmailDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId' => 'email-mid-1',
                    'event'     => 'SOMETHING_NEW',
                ]],
            ])->assertOk()->assertJson(['ok' => true, 'processed' => 0]);

        $dispatch->refresh();
        $this->assertNull($dispatch->opened_at);
        Queue::assertNotPushed(FireOutboundWebhookJob::class);
    }

    public function test_email_event_payload_includes_email_block(): void
    {
        $this->makeEmailDispatch();

        $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/email-events', [
                'results' => [[
                    'messageId' => 'email-mid-1',
                    'event'     => 'OPENED',
                    'openedAt'  => '2026-06-05T11:00:00.000+0000',
                ]],
            ])->assertOk();

        Queue::assertPushed(FireOutboundWebhookJob::class, function ($j) {
            if ($j->event !== 'email.opened') return false;
            $email = $j->payload['email'] ?? null;
            return is_array($email)
                && ! empty($email['opened_at'])
                && array_key_exists('first_clicked_at', $email)
                && array_key_exists('bounce_type', $email);
        });
    }
}
