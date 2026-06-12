<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantPricingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
    }

    private function makeTenantAndAdmin(string $role = 'superadmin'): array
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        return [$tenant, $admin];
    }

    public function test_create_tenant_override_succeeds_and_returns_value(): void
    {
        [$adminTenant, $admin] = $this->makeTenantAndAdmin();
        $subject = Tenant::factory()->create(['plan_id' => $adminTenant->plan_id]);

        Sanctum::actingAs($admin, ['*']);

        $resp = $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/pricing", [
            'service'    => 'sms',
            'sale_cents' => 9,
            'reason'     => 'Cliente Enterprise com volume alto',
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.override.sale_cents', 9);

        $row = TenantServicePrice::withoutGlobalScopes()
            ->where('tenant_id', $subject->id)
            ->where('service', 'sms')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(9, (int) $row->sale_cents);

        $log = AuditLog::where('action', 'tenant_pricing.created')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($subject->id, $log->metadata['subject_tenant_id']);
    }

    public function test_update_existing_override_audits_before_after(): void
    {
        [$adminTenant, $admin] = $this->makeTenantAndAdmin();
        $subject = Tenant::factory()->create(['plan_id' => $adminTenant->plan_id]);
        Sanctum::actingAs($admin, ['*']);

        // Create
        $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/pricing", [
            'service' => 'sms', 'sale_cents' => 10, 'reason' => 'Inicial',
        ])->assertStatus(200);

        // Update
        $resp = $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/pricing", [
            'service' => 'sms', 'sale_cents' => 12, 'reason' => 'Aumento',
        ]);
        $resp->assertStatus(200)->assertJsonPath('data.override.sale_cents', 12);

        $log = AuditLog::where('action', 'tenant_pricing.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(10, (int) $log->metadata['before']['sale_cents']);
        $this->assertSame(12, (int) $log->metadata['after']['sale_cents']);
    }

    public function test_delete_override_when_sale_cents_null(): void
    {
        [$adminTenant, $admin] = $this->makeTenantAndAdmin();
        $subject = Tenant::factory()->create(['plan_id' => $adminTenant->plan_id]);
        Sanctum::actingAs($admin, ['*']);

        // Create
        $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/pricing", [
            'service' => 'sms', 'sale_cents' => 10, 'reason' => 'Inicial',
        ])->assertStatus(200);

        // Delete via null sale_cents
        $resp = $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/pricing", [
            'service' => 'sms', 'sale_cents' => null, 'reason' => 'Remover override',
        ]);
        $resp->assertStatus(200);

        $row = TenantServicePrice::withoutGlobalScopes()
            ->where('tenant_id', $subject->id)
            ->where('service', 'sms')
            ->first();
        $this->assertNull($row);

        $log = AuditLog::where('action', 'tenant_pricing.deleted')->latest('id')->first();
        $this->assertNotNull($log);
    }

    public function test_regular_user_cannot_access(): void
    {
        [$adminTenant, $admin] = $this->makeTenantAndAdmin('user');
        $subject = Tenant::factory()->create(['plan_id' => $adminTenant->plan_id]);
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->getJson("/api/v1/admin/billing/tenants/{$subject->id}/pricing");
        $resp->assertStatus(403);
    }
}
