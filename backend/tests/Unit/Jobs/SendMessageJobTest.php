<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMessageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(SettingsService::class);
        $settings->upsertGlobal('infobip', 'api_key', 'test-key', 'string');
        $settings->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
    }

    /**
     * @param int $balanceCents balance already pre-decremented (simulating BillingService::reserve).
     * Default 135c = 150c starting - 15c (1 SMS reserve).
     */
    private function makeTenant(int $balanceCents = 135): Tenant
    {
        $plan = Plan::factory()->create();
        return Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $balanceCents,
        ]);
    }

    private function makeQueuedDispatch(Tenant $tenant, array $overrides = []): MessageDispatch
    {
        return MessageDispatch::withoutGlobalScopes()->create(array_merge([
            'tenant_id'     => $tenant->id,
            'channel'       => 'sms',
            'source'        => 'api',
            'to'            => '+5521999998888',
            'from'          => 'InfoSMS',
            'content'       => 'Olá mundo',
            'provider'      => 'infobip',
            'status'        => 'queued',
            'cost_cents'    => 8,
            'sale_cents'    => 15,
            'charged_cents' => 0,
        ], $overrides));
    }

    public function test_sms_success_marks_sent_and_charges(): void
    {
        Http::fake([
            '*/sms/3/messages' => Http::response([
                'messages' => [['messageId' => 'msg-abc-123', 'status' => ['name' => 'PENDING_ACCEPTED']]],
            ], 200),
        ]);

        $tenant   = $this->makeTenant(balanceCents: 135);
        $dispatch = $this->makeQueuedDispatch($tenant);

        (new SendMessageJob($dispatch->id))->handle(app(\App\Services\Infobip\InfobipService::class));

        $dispatch->refresh();
        $this->assertEquals('sent', $dispatch->status);
        $this->assertEquals('msg-abc-123', $dispatch->external_message_id);
        $this->assertEquals(15, (int) $dispatch->charged_cents);
        $this->assertNotNull($dispatch->sent_at);

        // Balance NOT changed (funds were already reserved upstream)
        $this->assertEquals(135, (int) $tenant->fresh()->balance_cents);
    }

    public function test_sms_definitive_failure_releases_credits(): void
    {
        Http::fake([
            '*/sms/3/messages' => Http::response([
                'requestError' => ['serviceException' => ['text' => 'Invalid destination']],
            ], 400),
        ]);

        $tenant   = $this->makeTenant(balanceCents: 135);
        $dispatch = $this->makeQueuedDispatch($tenant);

        (new SendMessageJob($dispatch->id))->handle(app(\App\Services\Infobip\InfobipService::class));

        $dispatch->refresh();
        $this->assertEquals('failed', $dispatch->status);
        $this->assertEquals('PROVIDER_ERROR', $dispatch->error_code);
        $this->assertEquals(0, (int) $dispatch->charged_cents);
        $this->assertNotNull($dispatch->failed_at);

        // Balance restored from 135 -> 150 (released)
        $this->assertEquals(150, (int) $tenant->fresh()->balance_cents);
    }

    public function test_sms_5xx_throws_transient_for_retry(): void
    {
        Http::fake([
            '*/sms/3/messages' => Http::response(['error' => 'server down'], 503),
        ]);

        $tenant   = $this->makeTenant(balanceCents: 135);
        $dispatch = $this->makeQueuedDispatch($tenant);

        $this->expectException(\App\Exceptions\Messaging\TransientProviderException::class);

        (new SendMessageJob($dispatch->id))->handle(app(\App\Services\Infobip\InfobipService::class));
    }

    public function test_sms_5xx_does_not_release_credits_until_final_attempt(): void
    {
        Http::fake([
            '*/sms/3/messages' => Http::response(['error' => 'server down'], 503),
        ]);

        $tenant   = $this->makeTenant(balanceCents: 135);
        $dispatch = $this->makeQueuedDispatch($tenant);

        try {
            (new SendMessageJob($dispatch->id))->handle(app(\App\Services\Infobip\InfobipService::class));
        } catch (\App\Exceptions\Messaging\TransientProviderException) {
            // expected — first attempt should rethrow for queue retry
        }

        $dispatch->refresh();
        $this->assertNotEquals('failed', $dispatch->status, '5xx should not mark failed on first attempt');
        $this->assertEquals(135, (int) $tenant->fresh()->balance_cents, 'funds should not be released on transient error');
    }

    public function test_already_processed_dispatch_is_skipped(): void
    {
        Http::fake();

        $tenant   = $this->makeTenant(balanceCents: 135);
        $dispatch = $this->makeQueuedDispatch($tenant, [
            'status'              => 'sent',
            'external_message_id' => 'msg-old',
            'charged_cents'       => 15,
        ]);

        (new SendMessageJob($dispatch->id))->handle(app(\App\Services\Infobip\InfobipService::class));

        Http::assertNothingSent();

        $dispatch->refresh();
        $this->assertEquals('sent', $dispatch->status);
        $this->assertEquals('msg-old', $dispatch->external_message_id);
        $this->assertEquals(135, (int) $tenant->fresh()->balance_cents);
    }
}
