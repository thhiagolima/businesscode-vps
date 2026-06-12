<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;

class TenantConversationsController extends Controller
{
    /**
     * NOTE: The conversations.status DB enum is ['open', 'bot', 'human', 'closed']
     * (see migration 2026_03_22_100020_create_conversations_table.php). There is
     * no 'archived' value despite earlier plan drafts assuming it. We validate
     * exactly against the real enum so writes never produce a DB-level enum
     * violation, and so the support UI can move conversations between the four
     * legitimate states from the admin drill-down.
     */
    private const STATUS_VALUES = 'open,bot,human,closed';

    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $request->validate([
            'status' => ['nullable', 'in:' . self::STATUS_VALUES],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $q = Conversation::where('tenant_id', $tenant);
        if ($s = $request->input('status')) {
            $q->where('status', $s);
        }
        if ($search = $request->input('search')) {
            $esc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $q->where('phone', 'like', "%{$esc}%");
        }

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(20));
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $conv = Conversation::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'status'      => ['sometimes', 'in:' . self::STATUS_VALUES],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
        ]);

        $before = $conv->only(array_keys($data));
        $conv->update($data);

        AdminAuditLogger::log('conversation', 'update', $tenant, $id, [
            'before' => $before,
            'after'  => $data,
        ]);

        return ApiResponse::success($conv->fresh());
    }
}
