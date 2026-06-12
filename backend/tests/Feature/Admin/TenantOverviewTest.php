<?php

namespace Tests\Feature\Admin;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_sees_kpis_of_any_tenant(): void
    {
        $target = Tenant::factory()->create();
        Campaign::factory()->count(3)->create(['tenant_id' => $target->id]);
        Contact::factory()->count(5)->create(['tenant_id' => $target->id]);

        $admin = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->getJson("/api/v1/admin/tenants/{$target->id}/overview");

        $resp->assertStatus(200)
            ->assertJsonPath('data.tenant.id', $target->id)
            ->assertJsonPath('data.kpis.campaigns_total', 3)
            ->assertJsonPath('data.kpis.contacts_total', 5);
    }

    public function test_regular_user_gets_403(): void
    {
        $target = Tenant::factory()->create();
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/v1/admin/tenants/{$target->id}/overview")
            ->assertStatus(403);
    }

    public function test_unknown_tenant_404(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/v1/admin/tenants/9999/overview")
            ->assertStatus(404);
    }
}
