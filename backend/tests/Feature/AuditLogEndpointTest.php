<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function setupUser(string $role): array
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => $role,
        ]);
        $user->save();
        return [$tenant, $user];
    }

    public function test_admin_can_list_own_tenant_audit_log(): void
    {
        [$tenant, $user] = $this->setupUser('admin');
        AuditLog::record('test.event', 'X', 1, ['k' => 'v'], $user->id, $tenant->id);

        Sanctum::actingAs($user);
        $resp = $this->getJson('/api/v1/audit-log');
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(1, count($resp->json('data')));
    }

    public function test_user_role_cannot_list_audit_log(): void
    {
        [, $user] = $this->setupUser('user');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/audit-log')->assertStatus(403);
    }

    public function test_admin_cannot_see_other_tenant_logs(): void
    {
        [$tenantA, $userA] = $this->setupUser('admin');
        [$tenantB, $userB] = $this->setupUser('admin');

        AuditLog::record('a.event', 'X', 1, null, $userA->id, $tenantA->id);
        AuditLog::record('b.event', 'X', 2, null, $userB->id, $tenantB->id);

        Sanctum::actingAs($userA);
        $resp = $this->getJson('/api/v1/audit-log');
        $resp->assertOk();
        $actions = collect($resp->json('data'))->pluck('action')->all();
        $this->assertContains('a.event', $actions);
        $this->assertNotContains('b.event', $actions);
    }

    public function test_filters_action_and_resource(): void
    {
        [$tenant, $user] = $this->setupUser('admin');
        AuditLog::record('campaign.send_now', 'Campaign', 1, null, $user->id, $tenant->id);
        AuditLog::record('campaign.cancel',   'Campaign', 1, null, $user->id, $tenant->id);
        AuditLog::record('auth.login',        'User',     $user->id, null, $user->id, $tenant->id);

        Sanctum::actingAs($user);
        $resp = $this->getJson('/api/v1/audit-log?action=campaign.');
        $actions = collect($resp->json('data'))->pluck('action')->all();
        $this->assertContains('campaign.send_now', $actions);
        $this->assertContains('campaign.cancel', $actions);
        $this->assertNotContains('auth.login', $actions);
    }
}
