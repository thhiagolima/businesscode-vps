<?php

namespace Tests\Feature\Auth;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ServicePricesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * P0R-04 — tokens Sanctum têm expiração padrão de 24h (1440 min). Era null
 * (imortal) o que permitia takeover permanente após XSS. O operador pode
 * voltar a "imortal" só para tokens server-to-server via
 * SANCTUM_EXPIRATION_MINUTES=0.
 */
class SanctumExpirationConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_sanctum_expiration_defaults_to_24h(): void
    {
        $this->assertSame(1440, config('sanctum.expiration'),
            'tokens must default to 24h expiration (P0R-04)');
    }

    public function test_token_within_24h_still_authenticates(): void
    {
        $plan = Plan::factory()->create(['quiet_hours_enabled' => false]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 1000]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Token emitido há 23h ainda autentica (dentro da janela de 24h).
        Carbon::setTestNow(now()->subHours(23));
        $plain = $user->createToken('within-window', ['messaging:sms'])->plainTextToken;
        Carbon::setTestNow();

        $this->seed(ServicePricesSeeder::class);
        app(\App\Services\SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(\App\Services\SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        Http::fake(['api.infobip.com/sms/3/messages' => Http::response(['messages' => [['messageId' => 'mid']]], 200)]);
        Queue::fake();

        $resp = $this->withHeader('Authorization', "Bearer {$plain}")
            ->postJson('/api/v1/messaging/sms', [
                'to' => '+5521980194445', 'content' => 'still works',
            ]);

        $resp->assertStatus(202);
    }

    public function test_token_older_than_24h_is_rejected(): void
    {
        $user = User::factory()->create();

        // Token emitido há 25h deve ser rejeitado pelo middleware de expiração.
        Carbon::setTestNow(now()->subHours(25));
        $plain = $user->createToken('stale', ['messaging:sms'])->plainTextToken;
        Carbon::setTestNow();

        $resp = $this->withHeader('Authorization', "Bearer {$plain}")
            ->getJson('/api/v1/auth/me');
        $resp->assertStatus(401);
    }

    public function test_token_with_explicit_past_expires_at_is_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('explicit-expire', ['messaging:sms'], now()->subHour());
        $plain = $token->plainTextToken;

        // Sanctum's check on expires_at is authoritative when it's not NULL
        $foundExpired = PersonalAccessToken::findToken($plain);
        $this->assertTrue(
            $foundExpired === null || $foundExpired->expires_at?->isPast(),
            'token with past expires_at must be rejected at lookup time'
        );

        $resp = $this->withHeader('Authorization', "Bearer {$plain}")
            ->getJson('/api/v1/auth/me');
        $resp->assertStatus(401);
    }
}
