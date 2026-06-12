<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Funnel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantFunnelsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_list_toggle_delete(): void
    {
        $t = Tenant::factory()->create();
        // The funnels table uses a status enum (draft/active/paused) — there's
        // no is_active boolean column on this codebase. We pause the funnel as
        // the moral equivalent of "deactivate" from the admin drill-down.
        $f = Funnel::factory()->create(['tenant_id' => $t->id, 'status' => 'active']);
        $this->admin();

        $this->getJson("/api/v1/admin/tenants/{$t->id}/funnels")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/admin/tenants/{$t->id}/funnels/{$f->id}", [
            'status' => 'paused',
        ])->assertStatus(200);
        $this->assertSame('paused', $f->fresh()->status);

        $this->deleteJson("/api/v1/admin/tenants/{$t->id}/funnels/{$f->id}")
            ->assertStatus(200);

        $this->assertTrue(AuditLog::where('action', 'admin.funnel.update')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.funnel.delete')->exists());
    }

    public function test_only_lists_target_tenant_funnels(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        Funnel::factory()->count(3)->create(['tenant_id' => $a->id]);
        Funnel::factory()->count(2)->create(['tenant_id' => $b->id]);
        $this->admin();

        $this->getJson("/api/v1/admin/tenants/{$a->id}/funnels")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_cross_tenant_update_returns_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $f = Funnel::factory()->create(['tenant_id' => $b->id]);
        $this->admin();

        $this->patchJson("/api/v1/admin/tenants/{$a->id}/funnels/{$f->id}", [
            'status' => 'paused',
        ])->assertStatus(404);
    }

    public function test_403_for_non_superadmin(): void
    {
        $t = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
        $this->getJson("/api/v1/admin/tenants/{$t->id}/funnels")->assertStatus(403);
    }
}
