<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tokens emitidos pelo admin via TenantApiTokensController são server-to-server
 * e devem sobreviver à expiração global de 24h (P0R-04), respeitando apenas o
 * expires_at explícito quando informado. Tokens de login SPA continuam
 * limitados pelos 24h.
 *
 * A diferenciação é feita por uma "sentinel ability" oculta gravada no token
 * na criação via controller admin. O Guard re-valida tokens marcados,
 * ignorando sanctum.expiration global.
 */
class AdminLongLivedTokenTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = '__server-to-server';

    public function test_token_with_sentinel_survives_global_24h_expiration(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken('integration', ['*', self::SENTINEL])->plainTextToken;

        Carbon::setTestNow(now()->addHours(48));
        try {
            $resp = $this->withHeader('Authorization', "Bearer {$plain}")
                ->getJson('/api/v1/auth/me');
            $resp->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_token_with_sentinel_still_respects_explicit_expires_at(): void
    {
        $user = User::factory()->create();
        $plain = $user->createToken(
            'integration',
            ['campaigns.send', self::SENTINEL],
            now()->addMinutes(30),
        )->plainTextToken;

        Carbon::setTestNow(now()->addHour());
        try {
            $resp = $this->withHeader('Authorization', "Bearer {$plain}")
                ->getJson('/api/v1/auth/me');
            $resp->assertStatus(401);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_regular_login_token_still_expires_at_24h(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(now()->subHours(25));
        $plain = $user->createToken('login-spa', ['*'])->plainTextToken;
        Carbon::setTestNow();

        $resp = $this->withHeader('Authorization', "Bearer {$plain}")
            ->getJson('/api/v1/auth/me');
        $resp->assertStatus(401);
    }

    public function test_admin_controller_marks_token_with_sentinel(): void
    {
        $tenant = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $tenant->id]);

        $admin = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->postJson("/api/v1/admin/tenants/{$tenant->id}/api-tokens", [
            'user_id'   => $target->id,
            'name'      => 'erp',
            'abilities' => ['campaigns.send'],
        ]);
        $resp->assertStatus(201);

        $tokenId = $resp->json('data.id');
        $stored = PersonalAccessToken::find($tokenId);

        $this->assertContains(self::SENTINEL, $stored->abilities,
            'admin-issued token must carry the sentinel ability internally');
    }

    public function test_listing_does_not_leak_sentinel_ability(): void
    {
        $tenant = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $tenant->id]);

        $admin = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson("/api/v1/admin/tenants/{$tenant->id}/api-tokens", [
            'user_id'   => $target->id,
            'name'      => 'visible',
            'abilities' => ['campaigns.send', 'reports.read'],
        ])->assertStatus(201);

        $resp = $this->getJson("/api/v1/admin/tenants/{$tenant->id}/api-tokens");
        $resp->assertStatus(200);

        $abilities = $resp->json('data.0.abilities');
        $this->assertNotContains(self::SENTINEL, $abilities,
            'sentinel ability must be stripped from API output');
        $this->assertEqualsCanonicalizing(['campaigns.send', 'reports.read'], $abilities);
    }

    public function test_prune_expired_preserves_admin_issued_tokens_without_expires_at(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('integration', ['*', self::SENTINEL])->accessToken;
        $id = $token->id;

        Carbon::setTestNow(now()->addDays(30));
        try {
            $this->artisan('sanctum:prune-expired', ['--hours' => 0])->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertNotNull(PersonalAccessToken::find($id),
            'admin-issued token without expires_at must not be pruned by global expiration');
    }
}
