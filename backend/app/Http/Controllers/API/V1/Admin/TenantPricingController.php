<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTenantPricingRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use Illuminate\Support\Facades\DB;

class TenantPricingController extends Controller
{
    /**
     * List per-tenant price overrides.
     */
    public function index(Tenant $tenant)
    {
        $overrides = TenantServicePrice::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderBy('service')
            ->get(['id', 'service', 'sale_cents', 'reason', 'created_by', 'updated_at']);

        return ApiResponse::success([
            'tenant_id' => $tenant->id,
            'overrides' => $overrides,
        ]);
    }

    /**
     * Upsert a tenant override. If sale_cents is null, the override is deleted.
     */
    public function upsert(UpdateTenantPricingRequest $request, Tenant $tenant)
    {
        $data = $request->validated();
        $user = $request->user();
        $service = $data['service'];

        return DB::transaction(function () use ($tenant, $data, $user, $service, $request) {
            $existing = TenantServicePrice::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('service', $service)
                ->lockForUpdate()
                ->first();

            $before = $existing ? [
                'sale_cents' => $existing->sale_cents,
                'reason'     => $existing->reason,
            ] : null;

            $action = null;
            $after = null;
            $resourceId = $existing?->id;

            if ($data['sale_cents'] === null) {
                if ($existing) {
                    $existing->delete();
                    $action = 'tenant_pricing.deleted';
                } else {
                    // Nothing to delete; treat as no-op but still audit.
                    $action = 'tenant_pricing.noop';
                }
            } else {
                $row = TenantServicePrice::withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'service' => $service],
                    [
                        'sale_cents' => (int) $data['sale_cents'],
                        'reason'     => $data['reason'],
                        'created_by' => $user?->id,
                    ]
                );
                $resourceId = $row->id;
                $after = [
                    'sale_cents' => $row->sale_cents,
                    'reason'     => $row->reason,
                ];
                $action = $existing ? 'tenant_pricing.updated' : 'tenant_pricing.created';
            }

            AuditLog::create([
                'user_id'     => $user?->id,
                'tenant_id'   => $user?->tenant_id,
                'action'      => $action,
                'resource'    => 'tenant_service_prices',
                'resource_id' => $resourceId,
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'metadata'    => [
                    'subject_tenant_id' => $tenant->id,
                    'service'           => $service,
                    'before'            => $before,
                    'after'             => $after,
                    'reason'            => $data['reason'],
                ],
            ]);

            return ApiResponse::success([
                'tenant_id' => $tenant->id,
                'service'   => $service,
                'override'  => $after,
            ], 'Override atualizado.');
        });
    }
}
