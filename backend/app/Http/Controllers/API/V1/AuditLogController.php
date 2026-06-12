<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only endpoint para o tenant consultar o próprio histórico de auditoria
 * (P0-11). Apenas admin/superadmin podem listar (mesma política do export CSV
 * de campanhas — auditoria contém PII/IPs).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'superadmin'], true)) {
            abort(403, 'Apenas administradores podem consultar o histórico de auditoria.');
        }

        $validated = $request->validate([
            // 'action' aceita prefixo livre, mas exige >= 3 chars para reduzir enumeração
            // por tempo de resposta. Máximo 60 chars para impedir LIKE com payload longo.
            'action'   => ['nullable', 'string', 'min:3', 'max:60'],
            'resource' => ['nullable', 'string', 'max:60'],
            'user_id'  => ['nullable', 'integer'],
            'from'     => ['nullable', 'date'],
            'to'       => ['nullable', 'date'],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        // GlobalScope AppliesTenantScope já filtra por tenant_id; superadmin vê todos.
        $q = AuditLog::query();

        if (!empty($validated['action'])) {
            $q->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $validated['action']) . '%');
        }
        if (!empty($validated['resource'])) {
            $q->where('resource', $validated['resource']);
        }
        if (!empty($validated['user_id'])) {
            $q->where('user_id', (int) $validated['user_id']);
        }
        if (!empty($validated['from'])) {
            $q->where('created_at', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $q->where('created_at', '<=', $validated['to']);
        }

        $perPage = (int) ($validated['per_page'] ?? 30);
        $paginator = $q->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }
}
