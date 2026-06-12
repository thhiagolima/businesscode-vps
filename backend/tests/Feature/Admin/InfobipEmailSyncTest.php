<?php

namespace Tests\Feature\Admin;

use App\Models\EmailSenderDomain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InfobipEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
    }

    private function asSuperadmin(): User
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($user, ['*']);
        return $user;
    }

    private function asTenantUser(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($user, ['*']);
        return $user;
    }

    private function infobipListPayload(): array
    {
        return [
            'results' => [
                [
                    'domainId'   => 42,
                    'domainName' => 'pixreals.com',
                    'active'     => true,
                    'dnsRecords' => [
                        ['type' => 'TXT', 'name' => 'sel1._domainkey.pixreals.com', 'expectedValue' => 'v=DKIM1;...', 'verified' => true],
                        ['type' => 'TXT', 'name' => 'pixreals.com', 'expectedValue' => 'v=spf1 include:spf.infobip.com ~all', 'verified' => true],
                        ['type' => 'CNAME', 'name' => 'bounces.pixreals.com', 'expectedValue' => 'bounces.infobip.com', 'verified' => true],
                    ],
                ],
            ],
            'paging' => ['page' => 0, 'size' => 1, 'totalPages' => 1, 'totalResults' => 1],
        ];
    }

    public function test_non_superadmin_cannot_list_domains(): void
    {
        $this->asTenantUser();
        $this->getJson('/api/v1/admin/infobip-email/domains')->assertStatus(403);
    }

    public function test_non_superadmin_cannot_sync(): void
    {
        $this->asTenantUser();
        $this->postJson('/api/v1/admin/infobip-email/sync')->assertStatus(403);
    }

    public function test_non_superadmin_cannot_assign(): void
    {
        $this->asTenantUser();
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);
        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => null])
            ->assertStatus(403);
    }

    public function test_superadmin_lists_pool_and_assigned_domains(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'name' => 'Acme']);

        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'pool.com', 'status' => 'pending',
        ]);
        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'domain' => 'acme.com', 'status' => 'active',
        ]);

        $resp = $this->getJson('/api/v1/admin/infobip-email/domains')->assertStatus(200);
        $domains = collect($resp->json('data'));
        $this->assertCount(2, $domains);
        $this->assertContains('pool.com', $domains->pluck('domain')->all());
        $this->assertContains('acme.com', $domains->pluck('domain')->all());

        $acme = $domains->firstWhere('domain', 'acme.com');
        $this->assertSame('Acme', $acme['tenant']['name'] ?? null);
    }

    public function test_superadmin_syncs_domains(): void
    {
        $this->asSuperadmin();
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response($this->infobipListPayload(), 200),
        ]);

        $resp = $this->postJson('/api/v1/admin/infobip-email/sync')->assertStatus(200);
        $this->assertSame(1, $resp->json('data.synced'));
        $this->assertDatabaseHas('email_sender_domains', ['domain' => 'pixreals.com', 'tenant_id' => null]);
    }

    public function test_assign_to_tenant_sets_provider_setting(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'pixreals.com', 'status' => 'active',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => $tenant->id])
            ->assertStatus(200)
            ->assertJsonPath('data.tenant_id', $tenant->id);

        $this->assertSame('infobip', app(SettingsService::class)->get($tenant->id, 'email', 'provider'));
    }

    public function test_assign_with_null_tenant_unassigns(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'domain' => 'pixreals.com', 'status' => 'active',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.tenant_id', null);
    }

    public function test_assign_does_not_unassign_other_domains_of_same_tenant(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        $a = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'domain' => 'one.com', 'status' => 'active',
        ]);
        $b = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'two.com', 'status' => 'active',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$b->id}/assign", ['tenant_id' => $tenant->id])
            ->assertStatus(200);

        $this->assertSame($tenant->id, $a->fresh()->tenant_id, 'pre-existing assignment must persist (N domains per tenant allowed)');
        $this->assertSame($tenant->id, $b->fresh()->tenant_id);
    }

    public function test_tenant_user_does_not_see_pool_domains_via_tenant_endpoint(): void
    {
        // Ensure unassigned pool entries are NOT leaked through the tenant-facing /email-domains index.
        $this->asTenantUser();
        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'pool.com', 'status' => 'pending',
        ]);

        $resp = $this->getJson('/api/v1/email-domains')->assertStatus(200);
        $domains = collect($resp->json('data'));
        $this->assertNotContains('pool.com', $domains->pluck('domain')->all());
    }
}
