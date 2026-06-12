<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TenantChannel;
use Illuminate\Http\Request;

class TenantChannelsController extends Controller
{
    // GET /admin/tenants/{tenantId}/channels
    public function index(int $tenantId)
    {
        $channels = TenantChannel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get();

        // Return all 4 channels with their status (default disabled if not in DB)
        $allChannels = ['sms', 'voice', 'email', 'whatsapp'];
        $result = [];
        foreach ($allChannels as $ch) {
            $existing = $channels->firstWhere('channel', $ch);
            $result[] = [
                'channel' => $ch,
                'status' => $existing?->status ?? 'disabled',
                'config' => $existing?->config ?? [],
                'updated_at' => $existing?->updated_at,
            ];
        }

        return ApiResponse::success($result);
    }

    // PUT /admin/tenants/{tenantId}/channels/{channel}
    public function update(int $tenantId, string $channel, Request $request)
    {
        if (!in_array($channel, ['sms', 'voice', 'email', 'whatsapp'])) {
            return ApiResponse::error('Canal inválido', [], 422);
        }

        $data = $request->validate([
            'status' => ['required', 'in:disabled,enabled,pending_setup'],
            'config' => ['nullable', 'array'],
        ]);

        $tc = TenantChannel::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'channel' => $channel],
            ['status' => $data['status'], 'config' => $data['config'] ?? []]
        );

        return ApiResponse::success($tc, 'Canal atualizado');
    }
}
