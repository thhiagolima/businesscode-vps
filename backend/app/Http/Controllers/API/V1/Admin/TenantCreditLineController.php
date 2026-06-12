<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManualBalanceAdjustmentRequest;
use App\Http\Requests\Admin\UpdateCreditLimitRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantCreditLineController extends Controller
{
    /**
     * PATCH /admin/billing/tenants/{tenant}/credit-limit
     * Update the tenant credit_limit_cents (overdraft ceiling).
     */
    public function setLimit(UpdateCreditLimitRequest $request, Tenant $tenant)
    {
        $data = $request->validated();
        $user = $request->user();

        return DB::transaction(function () use ($tenant, $data, $user, $request) {
            $tenant = Tenant::lockForUpdate()->findOrFail($tenant->id);

            $before = ['credit_limit_cents' => $tenant->credit_limit_cents];
            $tenant->update(['credit_limit_cents' => (int) $data['credit_limit_cents']]);
            $after = ['credit_limit_cents' => $tenant->credit_limit_cents];

            AuditLog::create([
                'user_id'     => $user?->id,
                'tenant_id'   => $user?->tenant_id,
                'action'      => 'tenant.credit_limit.updated',
                'resource'    => 'tenants',
                'resource_id' => $tenant->id,
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'metadata'    => [
                    'subject_tenant_id' => $tenant->id,
                    'before'            => $before,
                    'after'             => $after,
                    'reason'            => $data['reason'],
                ],
            ]);

            return ApiResponse::success([
                'tenant_id'          => $tenant->id,
                'credit_limit_cents' => $tenant->credit_limit_cents,
            ], 'Limite de crédito atualizado.');
        });
    }

    /**
     * POST /admin/billing/tenants/{tenant}/adjust
     * Manual balance adjustment (positive or negative). Delegates to BillingService.
     */
    public function adjust(ManualBalanceAdjustmentRequest $request, Tenant $tenant, BillingService $billing)
    {
        $data = $request->validated();
        $user = $request->user();

        $tx = $billing->manualAdjustment(
            tenantId: $tenant->id,
            amountCents: (int) $data['amount_cents'],
            reason: $data['reason'],
            adminUserId: (int) ($user?->id ?? 0),
        );

        $tenant->refresh();

        AuditLog::create([
            'user_id'     => $user?->id,
            'tenant_id'   => $user?->tenant_id,
            'action'      => 'tenant.balance.manual_adjustment',
            'resource'    => 'tenants',
            'resource_id' => $tenant->id,
            'ip_address'  => $request->ip(),
            'user_agent'  => substr((string) $request->userAgent(), 0, 500),
            'metadata'    => [
                'subject_tenant_id'      => $tenant->id,
                'amount_cents'           => (int) $data['amount_cents'],
                'balance_after_cents'    => $tenant->balance_cents,
                'balance_transaction_id' => $tx->id,
                'reason'                 => $data['reason'],
            ],
        ]);

        return ApiResponse::success([
            'tenant_id'              => $tenant->id,
            'balance_cents'          => $tenant->balance_cents,
            'balance_transaction_id' => $tx->id,
        ], 'Saldo ajustado.');
    }

    /**
     * POST /admin/billing/tenants/{tenant}/unlock
     * Manually clear a suspended/blocked billing_status. Superadmin-only.
     */
    public function unlock(Request $request, Tenant $tenant)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'superadmin') {
            return ApiResponse::error('Apenas superadmin pode desbloquear manualmente.', [], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:200'],
        ]);

        return DB::transaction(function () use ($tenant, $user, $validated, $request) {
            $tenant = Tenant::lockForUpdate()->findOrFail($tenant->id);

            $before = [
                'billing_status'   => $tenant->billing_status,
                'overdue_since'    => $tenant->overdue_since,
                'overdue_attempts' => $tenant->overdue_attempts,
            ];

            $tenant->update([
                'billing_status'   => 'active',
                'overdue_since'    => null,
                'overdue_attempts' => 0,
            ]);

            $after = [
                'billing_status'   => $tenant->billing_status,
                'overdue_since'    => $tenant->overdue_since,
                'overdue_attempts' => $tenant->overdue_attempts,
            ];

            AuditLog::create([
                'user_id'     => $user->id,
                'tenant_id'   => $user->tenant_id,
                'action'      => 'tenant.billing.unlocked',
                'resource'    => 'tenants',
                'resource_id' => $tenant->id,
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'metadata'    => [
                    'subject_tenant_id' => $tenant->id,
                    'before'            => $before,
                    'after'             => $after,
                    'reason'            => $validated['reason'],
                ],
            ]);

            return ApiResponse::success([
                'tenant_id'      => $tenant->id,
                'billing_status' => $tenant->billing_status,
            ], 'Tenant desbloqueado.');
        });
    }
}
