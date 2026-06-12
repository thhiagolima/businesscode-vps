<?php

namespace Tests\Feature\Webhooks;

use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Jobs\FireOutboundWebhookJob;
use App\Jobs\SendMessageJob;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OptOutService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookEventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');

        $this->seed(\Database\Seeders\ServicePricesSeeder::class);

        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'mid-1']]], 200),
        ]);
        Queue::fake();
    }

    private function makeTenant(int $balance = 1500): Tenant
    {
        $plan = Plan::factory()->create(['quiet_hours_enabled' => false]);
        return Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $balance,
        ]);
    }

    public function test_sms_dispatch_fires_message_queued_webhook_event(): void
    {
        $tenant = $this->makeTenant();

        app(MessagingService::class)->dispatch(
            $tenant,
            null,
            'sms',
            ['to' => '+5521999998888', 'content' => 'hi'],
            null
        );

        Queue::assertPushed(FireOutboundWebhookJob::class, function ($job) use ($tenant) {
            return $job->tenantId === $tenant->id
                && $job->event === 'message.queued'
                && ($job->payload['channel'] ?? null) === 'sms';
        });

        // SendMessageJob also queued (downstream worker would then fire message.sent)
        Queue::assertPushed(SendMessageJob::class);
    }

    public function test_opt_out_rejection_fires_message_rejected_opt_out_event(): void
    {
        $tenant = $this->makeTenant();
        app(OptOutService::class)->add($tenant->id, 'sms', '+5521999998888', 'user_request');

        try {
            app(MessagingService::class)->dispatch(
                $tenant,
                null,
                'sms',
                ['to' => '+5521999998888', 'content' => 'hi'],
                null
            );
            $this->fail('Expected RecipientOptedOutException');
        } catch (RecipientOptedOutException $e) {
            // expected
        }

        Queue::assertPushed(FireOutboundWebhookJob::class, function ($job) use ($tenant) {
            return $job->tenantId === $tenant->id
                && $job->event === 'message.rejected_opt_out';
        });
    }

    public function test_optout_add_fires_optout_added_event_with_hash_only(): void
    {
        $tenant = $this->makeTenant();

        app(OptOutService::class)->add($tenant->id, 'sms', '+5521988887777', 'user_request');

        Queue::assertPushed(FireOutboundWebhookJob::class, function ($job) use ($tenant) {
            if ($job->event !== 'optout.added' || $job->tenantId !== $tenant->id) {
                return false;
            }
            // LGPD: payload contains hash, never the raw identifier
            return isset($job->payload['identifier_hash'])
                && ! isset($job->payload['identifier']);
        });
    }
}
