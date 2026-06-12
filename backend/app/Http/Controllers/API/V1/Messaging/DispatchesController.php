<?php

namespace App\Http\Controllers\API\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageDispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DispatchesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MessageDispatch::class);

        $user = $request->user();
        $q = MessageDispatch::query();

        if (($user->role ?? null) !== 'superadmin') {
            $q->where('tenant_id', $user->tenant_id);
        }

        if ($channel = $request->query('channel')) {
            $q->where('channel', $channel);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        $items = $q->orderByDesc('id')->limit(100)->get();
        return response()->json(['data' => $items]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $isSuperadmin = ($user->role ?? null) === 'superadmin';

        // Scope query by tenant FIRST so cross-tenant lookups return 404 (not 403),
        // preventing existence enumeration of dispatches in other tenants.
        $query = MessageDispatch::withoutGlobalScopes();
        if (! $isSuperadmin) {
            $query->where('tenant_id', $user->tenant_id);
        }
        $dispatch = $query->findOrFail($id);

        // Policy still runs for defense-in-depth (in case the where filter is bypassed).
        Gate::authorize('view', $dispatch);

        if ($user->id !== $dispatch->user_id && ! $isSuperadmin) {
            $dispatch->content = mb_substr((string) $dispatch->content, 0, 20) . '…';
        }

        return response()->json(['data' => $dispatch]);
    }
}
