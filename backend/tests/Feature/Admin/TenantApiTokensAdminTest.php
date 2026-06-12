<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantApiTokensAdminTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperadmin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_lists_tokens_of_all_users_of_target_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $userA = User::factory()->create(['tenant_id' => $a->id]);
        $userB = User::factory()->create(['tenant_id' => $b->id]);
        $userA->createToken('one', ['*']);
        $userA->createToken('two', ['campaigns.send']);
        $userB->createToken('three', ['*']);

        $this->asSuperadmin();
        $resp = $this->getJson("/api/v1/admin/tenants/{$a->id}/api-tokens");

        $resp->assertStatus(200)->assertJsonCount(2, 'data');
        $resp->assertJsonPath('data.0.user.id', $userA->id);
    }

    public function test_creates_token_for_user_of_tenant(): void
    {
        $t = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $t->id]);
        $this->asSuperadmin();

        $resp = $this->postJson("/api/v1/admin/tenants/{$t->id}/api-tokens", [
            'user_id'   => $user->id,
            'name'      => 'Integração ERP',
            'abilities' => ['campaigns.send', 'reports.read'],
        ]);

        $resp->assertStatus(201)
             ->assertJsonStructure(['data' => ['id', 'token', 'abilities']]);

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $log = AuditLog::where('action', 'admin.api_token.create')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($t->id, $log->metadata['target_tenant_id']);
        $this->assertArrayNotHasKey('token', $log->metadata ?? []);
    }

    public function test_cannot_create_token_for_user_of_other_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $userB = User::factory()->create(['tenant_id' => $b->id]);
        $this->asSuperadmin();

        $resp = $this->postJson("/api/v1/admin/tenants/{$a->id}/api-tokens", [
            'user_id'   => $userB->id,
            'name'      => 'X',
            'abilities' => ['*'],
        ]);

        $resp->assertStatus(422);
    }

    public function test_revokes_token_and_audits(): void
    {
        $t = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $t->id]);
        $token = $user->createToken('one');
        $tokenId = $token->accessToken->id;
        $this->asSuperadmin();

        $this->deleteJson("/api/v1/admin/tenants/{$t->id}/api-tokens/{$tokenId}")
            ->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'admin.api_token.revoke')->exists());
    }

    public function test_cannot_revoke_token_of_other_tenant_user(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $userB = User::factory()->create(['tenant_id' => $b->id]);
        $tokenB = $userB->createToken('x')->accessToken;
        $this->asSuperadmin();

        $this->deleteJson("/api/v1/admin/tenants/{$a->id}/api-tokens/{$tokenB->id}")
            ->assertStatus(404);
    }

    public function test_non_superadmin_403(): void
    {
        $t = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);

        $this->getJson("/api/v1/admin/tenants/{$t->id}/api-tokens")->assertStatus(403);
    }
}
