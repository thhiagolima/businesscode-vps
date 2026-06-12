<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use App\Services\CampaignStateMachine;
use Illuminate\Http\Request;

class TenantCampaignsController extends Controller
{
    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type'   => ['nullable', 'in:sms,voice,email,whatsapp'],
            'status' => ['nullable', 'in:draft,scheduled,processing,running,completed,failed,paused'],
        ]);

        $q = Campaign::where('tenant_id', $tenant)->with('contactList:id,name,contact_count');

        if (!empty($validated['search'])) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $q->where('name', 'like', "%{$s}%");
        }
        if (!empty($validated['type']))   $q->where('type',   $validated['type']);
        if (!empty($validated['status'])) $q->where('status', $validated['status']);

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(20));
    }

    public function show(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $c = Campaign::where('tenant_id', $tenant)->with('contactList')->findOrFail($id);
        return ApiResponse::success($c);
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $c = Campaign::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'action' => ['required', 'in:pause,resume,cancel'],
            'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:500'],
        ]);

        $before = $c->status;

        $sm = app(CampaignStateMachine::class);
        match ($data['action']) {
            'pause'  => $sm->transition($c, 'paused'),
            'resume' => $sm->transition($c, 'processing'),
            'cancel' => $sm->transition($c, 'failed'),
        };

        AdminAuditLogger::log('campaign', $data['action'], $tenant, $c->id, [
            'before' => $before,
            'after'  => $c->fresh()->status,
            'reason' => $data['reason'] ?? null,
        ]);

        return ApiResponse::success($c->fresh(), "Campanha {$data['action']} ok");
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $c = Campaign::where('tenant_id', $tenant)->findOrFail($id);

        if (in_array($c->status, ['processing', 'running'])) {
            return ApiResponse::error(
                'Não é possível excluir campanha em execução. Cancele antes.',
                ['status' => $c->status],
                422,
            );
        }

        $name = $c->name;
        $c->delete();

        AdminAuditLogger::log('campaign', 'delete', $tenant, $id, ['name' => $name]);

        return ApiResponse::success([], 'Campanha removida');
    }
}
