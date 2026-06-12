<?php

namespace Tests\Feature\Billing;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B11 — Regressão: ajustes manuais sequenciais do mesmo admin para o mesmo
 * tenant não podem colidir com bt_idempotency_unique.
 *
 * Antes do fix, BillingService::manualAdjustment usava reference_id = admin
 * user id, então 2 ajustes do mesmo admin geravam Integrity violation 1062.
 *
 * Agora cada manual_adjustment recebe reference_id único (microtime + jitter)
 * e o admin executor fica em executed_by_user_id.
 */
class ManualAdjustmentRepeatableTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_admin_can_make_multiple_adjustments_for_same_tenant(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 100]);
        $admin  = User::factory()->create(['role' => 'finance']);
        $svc    = app(BillingService::class);

        $svc->manualAdjustment($tenant->id, 5000, 'primeiro ajuste', $admin->id);
        $svc->manualAdjustment($tenant->id, 3000, 'segundo ajuste', $admin->id);
        $svc->manualAdjustment($tenant->id, -2000, 'estorno parcial', $admin->id);

        $this->assertSame(100 + 5000 + 3000 - 2000, (int) $tenant->fresh()->balance_cents);

        $rows = BalanceTransaction::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'manual_adjustment')
            ->get();

        $this->assertCount(3, $rows);
        // Admin executor preservado em coluna dedicada
        $rows->each(fn ($r) => $this->assertSame($admin->id, (int) $r->executed_by_user_id));
        // reference_ids devem ser todos distintos para satisfazer bt_idempotency_unique
        $this->assertCount(3, $rows->pluck('reference_id')->unique());
    }

    public function test_different_admins_can_adjust_same_tenant(): void
    {
        $tenant  = Tenant::factory()->create(['balance_cents' => 0]);
        $admin1  = User::factory()->create(['role' => 'finance']);
        $admin2  = User::factory()->create(['role' => 'superadmin']);
        $svc     = app(BillingService::class);

        $svc->manualAdjustment($tenant->id, 1000, 'ajuste do admin1', $admin1->id);
        $svc->manualAdjustment($tenant->id, 2000, 'ajuste do admin2', $admin2->id);

        $rows = BalanceTransaction::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->get();

        $this->assertSame($admin1->id, (int) $rows[0]->executed_by_user_id);
        $this->assertSame($admin2->id, (int) $rows[1]->executed_by_user_id);
    }
}
