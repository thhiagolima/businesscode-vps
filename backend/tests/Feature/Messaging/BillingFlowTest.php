<?php

namespace Tests\Feature\Messaging;

use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(SettingsService::class);
        $settings->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        $settings->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');

        $this->seed(\Database\Seeders\ServicePricesSeeder::class);

        Queue::fake();
    }

    /**
     * @param int $balanceCents starting balance in cents (default 150 == 10 SMS @ 15c)
     */
    private function actAs(int $balanceCents = 150): User
    {
        $plan = Plan::factory()->create(['quiet_hours_enabled' => false]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => $balanceCents]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:sms']);
        return $user;
    }

    public function test_reserve_then_success_charges(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response([
                'messages' => [['messageId' => 'mid-ok', 'status' => ['name' => 'PENDING_ACCEPTED']]],
            ], 200),
        ]);

        $user = $this->actAs(balanceCents: 150);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521988887777',
            'content' => 'Hi',
        ]);

        $resp->assertStatus(202)->assertJsonPath('reserved_cents', 8);
        $dispatchId = $resp->json('dispatch_id');

        // Reserve happened: balance decremented (150 - 8 = 142 com SMS 8c)
        $this->assertSame(142, $user->tenant->fresh()->balance_cents);

        $dispatch = MessageDispatch::withoutGlobalScopes()->find($dispatchId);
        $this->assertSame('queued', $dispatch->status);
        $this->assertSame(0, (int) $dispatch->charged_cents);

        // Run the job manually
        (new SendMessageJob($dispatchId))->handle(app(InfobipService::class));

        $dispatch->refresh();
        $this->assertSame('sent', $dispatch->status);
        $this->assertSame(8, (int) $dispatch->charged_cents);

        // Balance unchanged after job — already debited during reserve
        $this->assertSame(142, $user->tenant->fresh()->balance_cents);
    }

    public function test_reserve_then_failure_releases(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response([
                'requestError' => ['serviceException' => ['text' => 'Invalid destination']],
            ], 400),
        ]);

        $user = $this->actAs(balanceCents: 150);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521988887777',
            'content' => 'Hi',
        ]);

        $resp->assertStatus(202);
        $dispatchId = $resp->json('dispatch_id');

        $this->assertSame(142, $user->tenant->fresh()->balance_cents);

        (new SendMessageJob($dispatchId))->handle(app(InfobipService::class));

        $dispatch = MessageDispatch::withoutGlobalScopes()->find($dispatchId);
        $this->assertSame('failed', $dispatch->status);
        $this->assertSame(0, (int) $dispatch->charged_cents);

        // Balance restored
        $this->assertSame(150, $user->tenant->fresh()->balance_cents);
    }

    public function test_idempotency_does_not_double_charge(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response([
                'messages' => [['messageId' => 'mid-idem']],
            ], 200),
        ]);

        $user = $this->actAs(balanceCents: 150);

        $payload = ['to' => '+5521988887777', 'content' => 'Idem'];

        $first = $this->withHeaders(['Idempotency-Key' => 'billing-flow-1'])
            ->postJson('/api/v1/messaging/sms', $payload);
        $first->assertStatus(202);

        $second = $this->withHeaders(['Idempotency-Key' => 'billing-flow-1'])
            ->postJson('/api/v1/messaging/sms', $payload);
        $second->assertStatus(202);

        $this->assertSame($first->json('dispatch_id'), $second->json('dispatch_id'));
        $this->assertSame(142, $user->tenant->fresh()->balance_cents);

        $count = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->count();
        $this->assertSame(1, $count);
    }
}
