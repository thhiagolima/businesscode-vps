<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantCampaignsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperadmin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_lists_campaigns_of_target_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $other  = Tenant::factory()->create();
        Campaign::factory()->count(2)->create(['tenant_id' => $tenant->id]);
        Campaign::factory()->count(5)->create(['tenant_id' => $other->id]);

        $this->asSuperadmin();

        $resp = $this->getJson("/api/v1/admin/tenants/{$tenant->id}/campaigns");
        $resp->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_cancel_campaign_audits_admin_action(): void
    {
        $tenant = Tenant::factory()->create();
        $c = Campaign::factory()->create(['tenant_id' => $tenant->id, 'status' => 'scheduled']);
        $admin = $this->asSuperadmin();

        $resp = $this->patchJson("/api/v1/admin/tenants/{$tenant->id}/campaigns/{$c->id}", [
            'action' => 'cancel',
            'reason' => 'cliente solicitou via suporte',
        ]);

        $resp->assertStatus(200);
        $this->assertSame('failed', $c->fresh()->status);

        $log = AuditLog::where('action', 'admin.campaign.cancel')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($tenant->id, $log->metadata['target_tenant_id']);
        $this->assertSame('cliente solicitou via suporte', $log->metadata['reason']);
    }

    public function test_body_tenant_id_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $c = Campaign::factory()->create(['tenant_id' => $tenant->id, 'status' => 'scheduled']);
        $this->asSuperadmin();

        $resp = $this->patchJson("/api/v1/admin/tenants/{$tenant->id}/campaigns/{$c->id}", [
            'action'    => 'cancel',
            'reason'    => 'teste',
            'tenant_id' => $other->id,
        ]);

        $resp->assertStatus(200);
        $this->assertSame($tenant->id, $c->fresh()->tenant_id);
    }

    public function test_delete_running_campaign_returns_422(): void
    {
        $tenant = Tenant::factory()->create();
        $c = Campaign::factory()->create(['tenant_id' => $tenant->id, 'status' => 'running']);
        $this->asSuperadmin();

        $this->deleteJson("/api/v1/admin/tenants/{$tenant->id}/campaigns/{$c->id}")
            ->assertStatus(422);
    }

    public function test_non_superadmin_403(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($u, ['*']);

        $this->getJson("/api/v1/admin/tenants/{$tenant->id}/campaigns")
            ->assertStatus(403);
    }
}
