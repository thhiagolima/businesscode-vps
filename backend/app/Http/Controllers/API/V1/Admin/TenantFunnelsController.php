<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Funnel;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;

class TenantFunnelsController extends Controller
{
    /**
     * NOTE: Funnels do NOT have an `is_active` boolean column on this codebase.
     * Activation is modelled via the `status` enum ('draft','active','paused')
     * — see migration 2026_03_22_300000_create_funnels_table.php. We expose
     * `status` directly on PATCH so the admin UI can pause/resume a tenant's
     * funnel without rewriting the underlying model.
     */
    private const STATUS_VALUES = 'draft,active,paused';

    public function index(int $tenant)
    {
        Tenant::findOrFail($tenant);

        return ApiResponse::paginated(
            Funnel::where('tenant_id', $tenant)->orderByDesc('id')->paginate(20)
        );
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $f = Funnel::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'status' => ['sometimes', 'in:' . self::STATUS_VALUES],
            'name'   => ['sometimes', 'string', 'max:255'],
        ]);

        $before = $f->only(array_keys($data));
        $f->update($data);

        AdminAuditLogger::log('funnel', 'update', $tenant, $id, [
            'before' => $before,
            'after'  => $data,
        ]);

        return ApiResponse::success($f->fresh());
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $f = Funnel::where('tenant_id', $tenant)->findOrFail($id);
        $name = $f->name;
        $f->delete();

        AdminAuditLogger::log('funnel', 'delete', $tenant, $id, ['name' => $name]);

        return ApiResponse::success([], 'Funil removido');
    }
}
