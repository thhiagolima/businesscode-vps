<?php

namespace Tests\Feature\Billing;

use App\Jobs\SendMessageJob;
use App\Models\BalanceTransaction;
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

/**
 * E2E: send via HTTP API → reserve from balance → success or failure path.
 * Validates that BalanceTransaction rows are written and balance ends up
 * where the spec promises.
 */
class BalanceFlowTest extends TestCase
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

    private function tenantAndUser(int $balanceCents): array
    {
        $plan = Plan::factory()->create(['quiet_hours_enabled' => false]);
        $tenant = Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $balanceCents,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:sms']);
        return [$tenant, $user];
    }

    public function test_send_sms_via_api_decrements_balance_and_writes_reserve_tx(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'mid']]], 200),
        ]);

        [$tenant, $user] = $this->tenantAndUser(150);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521988887777', 'content' => 'Hi',
        ]);

        $resp->assertStatus(202)->assertJsonPath('reserved_cents', 8);
        $this->assertSame(142, (int) $tenant->fresh()->balance_cents);

        $tx = BalanceTransaction::where('tenant_id', $tenant->id)
            ->where('type', 'reserve')
            ->first();
        $this->assertNotNull($tx);
        $this->assertSame(-8, (int) $tx->amount_cents);
        $this->assertSame(142, (int) $tx->balance_after_cents);
    }

    public function test_provider_failure_releases_funds_and_writes_release_tx(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response([
                'requestError' => ['serviceException' => ['text' => 'rejected']],
            ], 400),
        ]);

        [$tenant, $user] = $this->tenantAndUser(150);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521988887777', 'content' => 'Hi',
        ]);
        $resp->assertStatus(202);
        $dispatchId = $resp->json('dispatch_id');

        // Run job
        (new SendMessageJob($dispatchId))->handle(app(InfobipService::class));

        $this->assertSame(150, (int) $tenant->fresh()->balance_cents);

        $release = BalanceTransaction::where('tenant_id', $tenant->id)
            ->where('type', 'release')->first();
        $this->assertNotNull($release);
        $this->assertSame(8, (int) $release->amount_cents);
    }

    public function test_send_when_balance_insufficient_returns_402(): void
    {
        Http::fake();

        [$tenant, $user] = $this->tenantAndUser(0);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521988887777', 'content' => 'Hi',
        ]);

        $resp->assertStatus(402)
            ->assertJson(['error' => 'INSUFFICIENT_FUNDS', 'required' => 8, 'available' => 0]);

        // No reserve tx written
        $this->assertSame(0, BalanceTransaction::where('tenant_id', $tenant->id)->count());
    }
}
