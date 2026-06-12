<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\OutboundWebhook;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Observability + resilience guards for the outbound webhook job.
 *
 * Context: tenant 1 (Pedro / betleads.io) silently stopped receiving call.*
 * events because the job no-ops when nothing matches — with zero logging it was
 * invisible. These tests lock in (1) a high-signal log when a tenant HAS active
 * webhooks but none subscribed to the fired event (the actionable misconfig
 * that hid the bug), and (2) a retry backoff so transient DB deadlocks during
 * bulk-send storms don't burn all attempts instantly and drop the event.
 */
class OutboundWebhookObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_logs_when_active_webhook_exists_but_none_subscribed_to_event(): void
    {
        $tenant = Tenant::factory()->create();
        OutboundWebhook::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'url'       => 'https://example.com/hook',
            'secret'    => str_repeat('a', 32),
            'events'    => ['message.sent'], // subscribed, but NOT to call.answered
            'is_active' => true,
        ]);

        Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
        Log::shouldReceive('notice')->once()->withArgs(function ($msg, $ctx) use ($tenant) {
            return $msg === 'outbound_webhook.event_no_subscriber'
                && $ctx['event'] === 'call.answered'
                && (int) $ctx['tenant_id'] === (int) $tenant->id;
        });

        (new FireOutboundWebhookJob($tenant->id, 'call.answered', ['dispatch_id' => 1]))->handle();
    }

    public function test_job_has_spaced_backoff_to_survive_transient_deadlocks(): void
    {
        $job = new FireOutboundWebhookJob(1, 'call.answered', []);

        $this->assertNotEmpty($job->backoff, 'Job must define a backoff so retries are spaced.');
        $this->assertSame([5, 15, 30], $job->backoff);
    }
}
