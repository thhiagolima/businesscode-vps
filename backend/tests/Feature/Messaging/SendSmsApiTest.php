<?php

namespace Tests\Feature\Messaging;

use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\OptOutService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SendSmsApiTest extends TestCase
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Plan with quiet_hours_enabled = false so most tests don't trip quiet-hours logic.
     */
    private function actAs(array $abilities = ['messaging:sms'], int $balanceCents = 1500, bool $quietEnabled = false): User
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => $quietEnabled,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $balanceCents,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, $abilities);
        return $user;
    }

    public function test_happy_path_returns_202(): void
    {
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521999998888',
            'content' => 'Olá mundo',
        ]);

        $resp->assertStatus(202)
            ->assertJsonStructure(['dispatch_id', 'status', 'reserved_cents', '_links' => ['status']])
            ->assertJson([
                'status'         => 'queued',
                'reserved_cents' => 8,
            ]);

        // SMS venda 8c → 1500 - 8 = 1492
        $this->assertSame(1492, $user->tenant->fresh()->balance_cents);
        Queue::assertPushed(SendMessageJob::class);
    }

    public function test_requires_auth(): void
    {
        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521999998888',
            'content' => 'Olá',
        ]);

        $resp->assertStatus(401);
    }

    public function test_requires_correct_ability(): void
    {
        // Token lacks `messaging:sms` ability
        $this->actAs(['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521999998888',
            'content' => 'Olá',
        ]);

        $resp->assertStatus(403)
            ->assertJson(['error' => 'INSUFFICIENT_TOKEN_ABILITY', 'required' => 'messaging:sms']);
    }

    public function test_validates_payload(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/messaging/sms', ['to' => '', 'content' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to', 'content']);

        $this->postJson('/api/v1/messaging/sms', ['content' => 'no destination'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    public function test_rejects_opt_out(): void
    {
        $user = $this->actAs();
        app(OptOutService::class)->add($user->tenant_id, 'sms', '+5521999998888', 'user_request');

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521999998888',
            'content' => 'Olá',
        ]);

        $resp->assertStatus(422)
            ->assertJson(['error' => 'RECIPIENT_OPTED_OUT']);

        // No funds charged
        $this->assertSame(1500, $user->tenant->fresh()->balance_cents);
        Queue::assertNotPushed(SendMessageJob::class);
    }

    public function test_insufficient_credits(): void
    {
        $this->actAs(balanceCents: 0);

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521999998888',
            'content' => 'Olá',
        ]);

        $resp->assertStatus(402)
            ->assertJson([
                'error'     => 'INSUFFICIENT_FUNDS',
                'required'  => 8,
                'available' => 0,
            ]);
    }

    public function test_idempotency_replay(): void
    {
        $user = $this->actAs();
        $payload = [
            'to'      => '+5521999998888',
            'content' => 'idem-payload',
        ];

        $first = $this->withHeaders(['Idempotency-Key' => 'sms-key-1'])
            ->postJson('/api/v1/messaging/sms', $payload);
        $first->assertStatus(202);
        $firstId = $first->json('dispatch_id');

        $second = $this->withHeaders(['Idempotency-Key' => 'sms-key-1'])
            ->postJson('/api/v1/messaging/sms', $payload);
        $second->assertStatus(202);

        $this->assertSame($firstId, $second->json('dispatch_id'));
        $this->assertSame(
            1,
            MessageDispatch::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->count()
        );
        // Funds debited only once (SMS 8c → 1500 - 8 = 1492)
        $this->assertSame(1492, $user->tenant->fresh()->balance_cents);
    }

    public function test_idempotency_key_reuse_different_payload_returns_409(): void
    {
        $this->actAs();

        $this->withHeaders(['Idempotency-Key' => 'sms-key-conflict'])
            ->postJson('/api/v1/messaging/sms', [
                'to'      => '+5521999998888',
                'content' => 'first payload',
            ])->assertStatus(202);

        $resp = $this->withHeaders(['Idempotency-Key' => 'sms-key-conflict'])
            ->postJson('/api/v1/messaging/sms', [
                'to'      => '+5521999998888',
                'content' => 'DIFFERENT payload',
            ]);

        $resp->assertStatus(409)
            ->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSE']);
    }

    public function test_quiet_hours_rejected_returns_422(): void
    {
        $user = $this->actAs(quietEnabled: true);
        Carbon::setTestNow(Carbon::create(2026, 5, 26, 23, 30, 0, 'America/Sao_Paulo'));

        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to'      => '+5521999998888',
            'content' => 'Olá',
        ]);

        $resp->assertStatus(422)
            ->assertJson(['error' => 'QUIET_HOURS']);

        // No funds charged on quiet-hours reject
        $this->assertSame(1500, $user->tenant->fresh()->balance_cents);
    }
}
