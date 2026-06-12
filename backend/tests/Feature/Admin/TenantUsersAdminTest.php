<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantUsersAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_list_create_update_delete(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user']);
        $this->admin();

        $this->getJson("/api/v1/admin/tenants/{$t->id}/users")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/admin/tenants/{$t->id}/users", [
            'name'     => 'Novo',
            'email'    => 'novo@test.com',
            'password' => 'Secret123A',
            'role'     => 'admin',
        ])->assertStatus(201);

        $this->putJson("/api/v1/admin/tenants/{$t->id}/users/{$u->id}", [
            'status' => 'suspended',
        ])->assertStatus(200);

        $this->deleteJson("/api/v1/admin/tenants/{$t->id}/users/{$u->id}")
            ->assertStatus(200);

        $this->assertTrue(AuditLog::where('action', 'admin.user.create')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.user.update')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.user.delete')->exists());
    }

    public function test_suspending_user_revokes_tokens(): void
    {
        $t = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user']);
        $target->createToken('x');
        $this->admin();

        $this->putJson("/api/v1/admin/tenants/{$t->id}/users/{$target->id}", [
            'status' => 'suspended',
        ])->assertStatus(200);

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_reset_password_returns_temp_password_and_revokes_tokens(): void
    {
        $t = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user']);
        $target->createToken('x');
        $this->admin();

        $resp = $this->postJson("/api/v1/admin/tenants/{$t->id}/users/{$target->id}/reset-password");
        $resp->assertStatus(200)
             ->assertJsonStructure(['data' => ['temp_password']]);

        $temp = $resp->json('data.temp_password');
        $this->assertNotEmpty($temp);
        $this->assertGreaterThanOrEqual(16, strlen($temp));
        $this->assertTrue(Hash::check($temp, $target->fresh()->password));
        $this->assertTrue((bool) $target->fresh()->force_password_reset);
        $this->assertSame(0, $target->tokens()->count());

        $log = AuditLog::where('action', 'admin.user.password_reset')->latest('id')->first();
        $this->assertNotNull($log);
        // The temp password must never be persisted in audit metadata.
        $this->assertArrayNotHasKey('temp_password', $log->metadata ?? []);
    }

    public function test_cannot_suspend_superadmin(): void
    {
        $t = Tenant::factory()->create();
        $sa = User::factory()->create(['tenant_id' => $t->id, 'role' => 'superadmin']);
        $this->admin();

        $this->putJson("/api/v1/admin/tenants/{$t->id}/users/{$sa->id}", [
            'status' => 'suspended',
        ])->assertStatus(422);
    }

    public function test_cannot_delete_superadmin(): void
    {
        $t = Tenant::factory()->create();
        $sa = User::factory()->create(['tenant_id' => $t->id, 'role' => 'superadmin']);
        $this->admin();

        $this->deleteJson("/api/v1/admin/tenants/{$t->id}/users/{$sa->id}")
            ->assertStatus(422);
    }

    public function test_403_for_non_superadmin(): void
    {
        $t = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
        $this->getJson("/api/v1/admin/tenants/{$t->id}/users")->assertStatus(403);
    }
}
