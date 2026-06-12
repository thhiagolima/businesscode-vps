<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessagingAdminController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function getPricing(): JsonResponse
    {
        return response()->json([
            'credits_per_sms'   => (int) $this->settings->getGlobal('billing', 'credits_per_sms', '1'),
            'credits_per_voice' => (int) $this->settings->getGlobal('billing', 'credits_per_voice', '5'),
            'credits_per_email' => (int) $this->settings->getGlobal('billing', 'credits_per_email', '2'),
        ]);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credits_per_sms'   => ['nullable', 'integer', 'min:0', 'max:1000'],
            'credits_per_voice' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'credits_per_email' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        foreach ($data as $k => $v) {
            if ($v !== null) {
                $this->settings->upsertGlobal('billing', $k, (string) $v, 'string');
            }
        }

        return response()->json(['ok' => true]);
    }

    public function stats(): JsonResponse
    {
        $byChannelStatus = DB::table('message_dispatches')
            ->select(
                'tenant_id',
                'channel',
                'status',
                DB::raw('COUNT(*) as n'),
                DB::raw('SUM(charged_cents) as charged_cents')
            )
            ->groupBy('tenant_id', 'channel', 'status')
            ->orderBy('tenant_id')->orderBy('channel')->orderBy('status')
            ->limit(500)
            ->get();

        return response()->json(['data' => $byChannelStatus]);
    }
}
