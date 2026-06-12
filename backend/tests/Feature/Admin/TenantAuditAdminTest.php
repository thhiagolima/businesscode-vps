<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantAuditAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_audit_filtered_by_tenant_with_filters(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        AuditLog::record('campaign.created', 'Campaign', 1, [], null, $a->id);
        AuditLog::record('contact.created',  'Contact',  2, [], null, $a->id);
        AuditLog::record('campaign.created', 'Campaign', 3, [], null, $b->id);

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);

        $resp = $this->getJson("/api/v1/admin/tenants/{$a->id}/audit");
        $resp->assertStatus(200)->assertJsonCount(2, 'data');

        $respFiltered = $this->getJson("/api/v1/admin/tenants/{$a->id}/audit?action=campaign.created");
        $respFiltered->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_403_for_non_superadmin(): void
    {
        $t = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
        $this->getJson("/api/v1/admin/tenants/{$t->id}/audit")->assertStatus(403);
    }
}
