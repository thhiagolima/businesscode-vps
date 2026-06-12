<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BlockedTenantCannotSendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
        Http::fake();
        Queue::fake();
    }

    private function actAsWithStatus(string $billingStatus): User
    {
        $plan = Plan::factory()->create(['quiet_hours_enabled' => false]);
        $tenant = Tenant::factory()->create([
            'plan_id'        => $plan->id,
            'balance_cents'  => 10000,
            'billing_status' => $billingStatus,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:sms']);
        return $user;
    }

    public function test_suspended_returns_402_billing_suspended(): void
    {
        $this->actAsWithStatus('suspended');

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521988887777', 'content' => 'Hi',
        ]);

        $resp->assertStatus(402)->assertJson(['error' => 'BILLING_SUSPENDED']);
    }

    public function test_blocked_returns_402_billing_blocked(): void
    {
        $this->actAsWithStatus('blocked');

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521988887777', 'content' => 'Hi',
        ]);

        $resp->assertStatus(402)->assertJson(['error' => 'BILLING_BLOCKED']);
    }

    public function test_active_with_funds_succeeds(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'mid']]], 200),
        ]);

        $this->actAsWithStatus('active');

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521988887777', 'content' => 'Hi',
        ]);

        $resp->assertStatus(202);
    }

    public function test_grace_status_still_allows_send_when_funds_available(): void
    {
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'mid']]], 200),
        ]);

        $this->actAsWithStatus('grace');

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521988887777', 'content' => 'Hi',
        ]);

        // Grace must NOT block sends — only suspended/blocked do.
        $resp->assertStatus(202);
    }
}
