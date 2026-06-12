<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\BalanceTransaction;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantCreditLineApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdminAndSubject(string $role = 'superadmin'): array
    {
        $plan = Plan::factory()->create();
        $adminTenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $admin = User::factory()->create(['tenant_id' => $adminTenant->id, 'role' => $role]);
        $subject = Tenant::factory()->create([
            'plan_id'           => $plan->id,
            'balance_cents'     => 500,
            'credit_limit_cents' => 0,
            'billing_status'    => 'active',
        ]);
        return [$admin, $subject];
    }

    public function test_patch_credit_limit_updates_and_audits(): void
    {
        [$admin, $subject] = $this->makeAdminAndSubject();
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->patchJson("/api/v1/admin/billing/tenants/{$subject->id}/credit-limit", [
            'credit_limit_cents' => 10000,
            'reason'             => 'Cliente premium com fluxo previsível',
        ]);

        $resp->assertStatus(200)->assertJsonPath('data.credit_limit_cents', 10000);
        $this->assertSame(10000, (int) $subject->fresh()->credit_limit_cents);

        $log = AuditLog::where('action', 'tenant.credit_limit.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(0, (int) $log->metadata['before']['credit_limit_cents']);
        $this->assertSame(10000, (int) $log->metadata['after']['credit_limit_cents']);
    }

    public function test_adjust_creates_balance_transaction_and_changes_balance(): void
    {
        [$admin, $subject] = $this->makeAdminAndSubject();
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/adjust", [
            'amount_cents' => 2500,
            'reason'       => 'Cortesia: ressarcimento por incidente XYZ',
        ]);

        $resp->assertStatus(200)->assertJsonPath('data.balance_cents', 3000);

        $tx = BalanceTransaction::latest('id')->first();
        $this->assertNotNull($tx);
        $this->assertSame('manual_adjustment', $tx->type);
        $this->assertSame(2500, (int) $tx->amount_cents);
        $this->assertSame(3000, (int) $tx->balance_after_cents);
    }

    public function test_adjust_negative_decrements_balance(): void
    {
        [$admin, $subject] = $this->makeAdminAndSubject();
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/adjust", [
            'amount_cents' => -200,
            'reason'       => 'Estorno de crédito atribuído por engano',
        ]);

        $resp->assertStatus(200)->assertJsonPath('data.balance_cents', 300);
    }

    public function test_unlock_is_superadmin_only(): void
    {
        // Finance cannot unlock
        [$financeAdmin, $subject] = $this->makeAdminAndSubject('finance');
        $subject->update(['billing_status' => 'blocked', 'overdue_attempts' => 3]);
        Sanctum::actingAs($financeAdmin, ['*']);

        $resp = $this->postJson("/api/v1/admin/billing/tenants/{$subject->id}/unlock", [
            'reason' => 'Cliente quitou via PIX manual',
        ]);
        $resp->assertStatus(403);

        // Superadmin can unlock
        [$super, $subject2] = $this->makeAdminAndSubject('superadmin');
        $subject2->update(['billing_status' => 'blocked', 'overdue_attempts' => 3]);
        Sanctum::actingAs($super, ['*']);

        $resp = $this->postJson("/api/v1/admin/billing/tenants/{$subject2->id}/unlock", [
            'reason' => 'Cliente quitou via PIX manual',
        ]);
        $resp->assertStatus(200)->assertJsonPath('data.billing_status', 'active');
        $this->assertSame('active', $subject2->fresh()->billing_status);
        $this->assertSame(0, (int) $subject2->fresh()->overdue_attempts);
    }

    public function test_regular_user_cannot_modify_credit_limit(): void
    {
        [$admin, $subject] = $this->makeAdminAndSubject('user');
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->patchJson("/api/v1/admin/billing/tenants/{$subject->id}/credit-limit", [
            'credit_limit_cents' => 99999,
            'reason'             => 'tentativa não autorizada',
        ]);
        $resp->assertStatus(403);
    }
}
