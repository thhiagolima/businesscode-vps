<?php

namespace Tests\Feature\Security;

use App\Models\EmailSenderDomain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailDomainsAdminIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
    }

    private function asSuperadmin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_assign_rejects_nonexistent_tenant_id(): void
    {
        $this->asSuperadmin();
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => 999999])
            ->assertStatus(422);
    }

    public function test_assign_rejects_string_tenant_id_injection(): void
    {
        $this->asSuperadmin();
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => "1' OR '1'='1"])
            ->assertStatus(422);
    }

    public function test_assign_to_nonexistent_domain_returns_404(): void
    {
        $this->asSuperadmin();
        $this->putJson('/api/v1/admin/infobip-email/domains/9999/assign', ['tenant_id' => null])
            ->assertStatus(404);
    }

    public function test_assign_is_idempotent_no_duplicate_settings(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row    = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => $tenant->id])->assertStatus(200);
        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => $tenant->id])->assertStatus(200);

        $settingsRows = \DB::table('settings')
            ->where('tenant_id', $tenant->id)
            ->where('group', 'email')
            ->where('key', 'provider')
            ->count();

        $this->assertSame(1, $settingsRows, 'repeated assign must not duplicate settings rows');
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/infobip-email/domains')->assertStatus(401);
    }

    public function test_sync_requires_auth(): void
    {
        $this->postJson('/api/v1/admin/infobip-email/sync')->assertStatus(401);
    }

    public function test_assign_requires_auth(): void
    {
        $this->putJson('/api/v1/admin/infobip-email/domains/1/assign', ['tenant_id' => null])->assertStatus(401);
    }

    public function test_assign_strips_unexpected_fields_and_only_updates_tenant_id(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row    = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", [
            'tenant_id' => $tenant->id,
            'domain'    => 'hijacked.com',
            'status'    => 'active',
        ])->assertStatus(200);

        $fresh = $row->fresh();
        $this->assertSame('x.com', $fresh->domain, 'domain must not be mass-assigned via assign endpoint');
        $this->assertSame('pending', $fresh->status, 'status must not be mass-assigned via assign endpoint');
        $this->assertSame($tenant->id, $fresh->tenant_id);
    }
}
