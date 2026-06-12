<?php

namespace Tests\Feature\Billing;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'balance_cents' => 100000, 'credit_limit_cents' => 0,
        ]);
    }

    public function test_recharge_writes_billing_recharged_audit_row(): void
    {
        $tenant = $this->makeTenant();
        app(BillingService::class)->recharge($tenant->id, 5000, 'credit_purchase', 1, 'test');

        $row = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'billing.recharge')
            ->first();
        $this->assertNotNull($row, 'Recharge must write a billing.recharge audit row (LGPD art. 37)');
        $this->assertSame('Tenant', $row->resource);
        $this->assertSame($tenant->id, (int) $row->resource_id);
        $this->assertSame(5000, (int) ($row->metadata['amount_cents'] ?? 0));
    }

    public function test_reserve_writes_billing_reserved_audit_row(): void
    {
        $tenant = $this->makeTenant();
        app(BillingService::class)->reserve($tenant->id, 1000, 'campaign_dispatch', 7);

        $row = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'billing.reserve')
            ->first();
        $this->assertNotNull($row, 'Reserve must write a billing.reserve audit row');
        $this->assertSame('campaign_dispatch', (string) ($row->metadata['reference_type'] ?? ''));
    }

    public function test_release_writes_billing_released_audit_row(): void
    {
        $tenant = $this->makeTenant();
        app(BillingService::class)->reserve($tenant->id, 1000, 'campaign_dispatch', 9);
        app(BillingService::class)->release($tenant->id, 500, 'campaign_dispatch_refund', 9);

        $row = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'billing.release')
            ->first();
        $this->assertNotNull($row, 'Release must write a billing.release audit row');
    }

    public function test_manual_adjustment_writes_billing_manual_audit_row(): void
    {
        $tenant = $this->makeTenant();
        $admin = (new User())->forceFill([
            'name' => 'Admin', 'email' => 'a@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'superadmin',
        ]);
        $admin->save();

        app(BillingService::class)->manualAdjustment($tenant->id, -200, 'support credit-back', $admin->id);

        $row = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'billing.manual_adjustment')
            ->first();
        $this->assertNotNull($row, 'Manual adjustment must write an audit row');
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertSame(-200, (int) ($row->metadata['amount_cents'] ?? 0));
    }

    public function test_audit_log_is_append_only_no_update(): void
    {
        $tenant = $this->makeTenant();
        app(BillingService::class)->recharge($tenant->id, 5000, 'credit_purchase', 1);

        $row = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'billing.recharge')
            ->first();
        $this->assertNotNull($row);

        // Attempting to update must throw (append-only enforced at Eloquent layer).
        $this->expectException(\RuntimeException::class);
        $row->action = 'billing.tampered';
        $row->save();
    }

    public function test_audit_log_is_append_only_no_delete(): void
    {
        $tenant = $this->makeTenant();
        app(BillingService::class)->recharge($tenant->id, 5000, 'credit_purchase', 1);
        $row = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'billing.recharge')
            ->first();

        $this->expectException(\RuntimeException::class);
        $row->delete();
    }
}
