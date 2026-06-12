<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantChannel;
use Illuminate\Http\JsonResponse;

class ChannelsController extends Controller
{
    public function myChannels(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $channels = TenantChannel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('channel');

        $plan = auth()->user()->tenant?->plan;
        $planChannels = $plan ? (json_decode($plan->features, true)['channels'] ?? []) : [];

        $result = [];
        foreach (['sms', 'voice', 'email', 'whatsapp'] as $ch) {
            $existing = $channels->get($ch);
            if ($existing) {
                $result[$ch] = [
                    'status' => $existing->status,
                    'config' => $existing->config ?? [],
                ];
            } else {
                $result[$ch] = [
                    'status' => in_array($ch, $planChannels) ? 'enabled' : 'disabled',
                    'config' => [],
                ];
            }
        }
        return response()->json(['data' => $result]);
    }
}
