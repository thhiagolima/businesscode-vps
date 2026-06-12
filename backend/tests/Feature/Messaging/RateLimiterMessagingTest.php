<?php

namespace Tests\Feature\Messaging;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimiterMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
        Http::fake([
            'api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'mid']]], 200),
        ]);
        Queue::fake();
        RateLimiter::clear('tenant:1:msg:sms');
    }

    public function test_sms_rate_limit_engages_after_default_limit(): void
    {
        // Lower the limit to 2 for speed; plan field is null so config is used.
        config(['messaging.rate_limits.sms' => 2]);

        $plan   = Plan::factory()->create([
            'quiet_hours_enabled'     => false,
            'rate_limit_sms_per_min'  => null,
        ]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 1500]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:sms']);

        // Make sure the bucket is empty for this tenant
        RateLimiter::clear("tenant:{$tenant->id}:msg:sms");

        $payload = fn(int $i) => [
            'to'      => '+5521988887777',
            'content' => "msg-{$i}",
        ];

        $r1 = $this->postJson('/api/v1/messaging/sms', $payload(1));
        $r1->assertStatus(202);

        $r2 = $this->postJson('/api/v1/messaging/sms', $payload(2));
        $r2->assertStatus(202);

        $r3 = $this->postJson('/api/v1/messaging/sms', $payload(3));
        $r3->assertStatus(429)
            ->assertJson(['error' => 'RATE_LIMIT_EXCEEDED', 'channel' => 'sms']);
    }

    /**
     * Regression: the global SMS default was raised 60 → 300/min because tenants
     * sending 60-70/min were hitting our own throttle (mistaken for an Infobip 429).
     */
    public function test_sms_global_default_rate_limit_is_300_per_minute(): void
    {
        $this->assertSame(300, (int) config('messaging.rate_limits.sms'));
    }

    /**
     * Pentest: raising the tenant SMS limit must NOT loosen the fallback for a
     * user without a tenant — that path stays capped at 5/min by IP.
     */
    public function test_user_without_tenant_sms_is_capped_at_5_per_minute(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        Sanctum::actingAs($user, ['messaging:sms']);

        $payload = fn(int $i) => ['to' => '+5521988887777', 'content' => "msg-{$i}"];

        // 6th request within the window must be throttled (limit = 5/min).
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/messaging/sms', $payload($i));
        }

        $this->postJson('/api/v1/messaging/sms', $payload(6))
            ->assertStatus(429);
    }
}
