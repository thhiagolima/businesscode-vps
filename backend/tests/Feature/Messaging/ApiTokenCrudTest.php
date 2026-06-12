<?php

namespace Tests\Feature\Messaging;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTokenCrudTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL = '__server-to-server';

    private function makeUser(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    /**
     * Self-serve tokens (created via /api/v1/auth/api-tokens) são server-to-server
     * e NÃO podem morrer no teto global de 24h. Isto falhava antes do fix porque o
     * controller esquecia de gravar a sentinel ability.
     */
    public function test_self_serve_token_survives_global_24h_expiration(): void
    {
        $user = $this->makeUser();

        // Cria via Sanctum real para obter um plaintext usável numa request seguinte.
        Sanctum::actingAs($user);
        $plain = $this->postJson('/api/v1/auth/api-tokens', [
            'name'      => 'ERP Integration',
            'abilities' => ['messaging:sms'],
        ])->assertStatus(201)->json('token');

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        Carbon::setTestNow(now()->addHours(48));
        try {
            $resp = $this->withHeaders([
                    'Authorization' => "Bearer {$plain}",
                    'Accept'        => 'application/json',
                ])
                ->get('/api/v1/auth/me');
            $resp->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_self_serve_store_marks_sentinel_but_hides_it(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/v1/auth/api-tokens', [
            'name'      => 'Hidden Sentinel',
            'abilities' => ['messaging:sms'],
        ])->assertStatus(201);

        // Resposta não vaza a sentinela...
        $this->assertNotContains(self::SENTINEL, $resp->json('abilities'));
        $this->assertContains('messaging:sms', $resp->json('abilities'));

        // ...mas a sentinela ESTÁ gravada no banco (é o que torna o token imortal).
        $stored = PersonalAccessToken::find($resp->json('id'));
        $this->assertContains(self::SENTINEL, $stored->abilities);
    }

    public function test_self_serve_index_does_not_leak_sentinel(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/api-tokens', [
            'name'      => 'Listed Token',
            'abilities' => ['messaging:sms'],
        ])->assertStatus(201);

        $rows = $this->getJson('/api/v1/auth/api-tokens')->assertStatus(200)->json('data');
        foreach ($rows as $row) {
            $this->assertNotContains(self::SENTINEL, $row['abilities']);
        }
    }

    /**
     * Pentest/regressão: a sentinela é interna e NÃO pode ser usada como ability
     * funcional. O middleware token.ability nunca a concede; e o usuário não pode
     * forjá-la para escalar privilégio (validação restringe a lista permitida).
     */
    public function test_user_cannot_inject_sentinel_via_request(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/v1/auth/api-tokens', [
            'name'      => 'Forge Attempt',
            'abilities' => ['messaging:sms', self::SENTINEL],
        ]);

        // A sentinela não está na lista de abilities permitidas → 422.
        $resp->assertStatus(422)->assertJsonValidationErrors(['abilities.1']);
    }

    public function test_store_returns_plaintext(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/v1/auth/api-tokens', [
            'name'      => 'CI Token',
            'abilities' => ['messaging:sms'],
        ]);

        $resp->assertStatus(201)
            ->assertJsonStructure(['id', 'token', 'abilities', 'expires_at']);

        $this->assertIsString($resp->json('token'));
        $this->assertNotEmpty($resp->json('token'));
        $this->assertContains('messaging:sms', $resp->json('abilities'));
    }

    public function test_index_omits_plaintext_token(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        // Create one via the API to ensure shape
        $this->postJson('/api/v1/auth/api-tokens', [
            'name'      => 'List Token',
            'abilities' => ['messaging:sms'],
        ])->assertStatus(201);

        $resp = $this->getJson('/api/v1/auth/api-tokens');
        $resp->assertStatus(200);

        $rows = $resp->json('data');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('name', $row);
            $this->assertArrayHasKey('abilities', $row);
            $this->assertArrayHasKey('expires_at', $row);
            $this->assertArrayNotHasKey('token', $row);
        }
    }

    public function test_destroy_revokes(): void
    {
        $user = $this->makeUser();

        // Create token using real Sanctum so plaintext is usable for the next request
        $created = $user->createToken('Revoke Me', ['messaging:sms']);
        $plaintext = $created->plainTextToken;
        $tokenId   = $created->accessToken->id;

        // Delete the token (acts as the user via Sanctum)
        Sanctum::actingAs($user);
        $del = $this->deleteJson("/api/v1/auth/api-tokens/{$tokenId}");
        $del->assertStatus(204);

        // Subsequent call with the now-revoked token must return 401.
        // Use a fresh request that does NOT reuse the prior Sanctum actingAs state.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $after = $this->withHeaders([
                'Authorization' => "Bearer {$plaintext}",
                'Accept'        => 'application/json',
            ])
            ->get('/api/v1/auth/me');
        $after->assertStatus(401);

        // Belt-and-suspenders: row really gone from DB.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_validates_abilities_subset(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/v1/auth/api-tokens', [
            'name'      => 'Bad Token',
            'abilities' => ['admin:*'],
        ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.0']);
    }
}
