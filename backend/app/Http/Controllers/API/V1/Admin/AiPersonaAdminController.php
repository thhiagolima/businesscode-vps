<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiPersona;
use Illuminate\Http\Request;

class AiPersonaAdminController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,pending_approval,approved,rejected'],
        ]);

        $q = AiPersona::withoutGlobalScopes()->with('tenant:id,name');

        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        $items = $q->orderByRaw("FIELD(status, 'pending_approval', 'draft', 'approved', 'rejected')")
            ->orderByDesc('updated_at')
            ->paginate(20);

        return ApiResponse::paginated($items, 'OK');
    }

    public function show(int $id)
    {
        $persona = AiPersona::withoutGlobalScopes()->with('tenant:id,name')->findOrFail($id);
        return ApiResponse::success($persona);
    }

    public function approve(int $id)
    {
        $persona = AiPersona::withoutGlobalScopes()->findOrFail($id);

        $persona->update([
            'status'           => 'approved',
            'approved_by'      => auth()->id(),
            'approved_at'      => now(),
            'rejection_reason' => null,
        ]);

        try {
            $tenantAdmin = \App\Models\User::where('tenant_id', $persona->tenant_id)->first();
            if ($tenantAdmin) {
                $tenantAdmin->notify(new \App\Notifications\PersonaApprovedNotification('approved'));
            }
        } catch (\Throwable $e) {
            // silent
        }

        return ApiResponse::success($persona->fresh(), 'Persona aprovada');
    }

    public function reject(int $id, Request $request)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $persona = AiPersona::withoutGlobalScopes()->findOrFail($id);

        $persona->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['reason'],
            'approved_by'      => null,
            'approved_at'      => null,
        ]);

        try {
            $tenantAdmin = \App\Models\User::where('tenant_id', $persona->tenant_id)->first();
            if ($tenantAdmin) {
                $tenantAdmin->notify(new \App\Notifications\PersonaApprovedNotification('rejected', $data['reason']));
            }
        } catch (\Throwable $e) {
            // silent
        }

        return ApiResponse::success($persona->fresh(), 'Persona rejeitada');
    }
}
