<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class TenantReportsController extends Controller
{
    public function index(int $tenant)
    {
        Tenant::findOrFail($tenant);
        $since = now()->subDays(30);

        // NB: `whatsapp` is intentionally listed even though the dispatch enum
        // currently exposes only sms/voice/email — it returns zeros and keeps
        // the response shape stable for the admin UI once the column is
        // extended (channel-add migration is planned but not in this PR).
        $byChannel = ['sms', 'whatsapp', 'email', 'voice'];
        $by = collect($byChannel)->map(function ($ch) use ($tenant, $since) {
            $base = MessageDispatch::where('tenant_id', $tenant)
                ->where('channel', $ch)
                ->where('created_at', '>=', $since);
            return [
                'channel'   => $ch,
                'sent'      => (clone $base)->count(),
                'delivered' => (clone $base)->where('status', 'delivered')->count(),
                'failed'    => (clone $base)->where('status', 'failed')->count(),
            ];
        })->all();

        $daily = MessageDispatch::where('tenant_id', $tenant)
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as sent'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'sent' => (int) $r->sent])
            ->all();

        $top = Campaign::where('tenant_id', $tenant)
            ->where('created_at', '>=', $since)
            ->orderByDesc('sent_count')
            ->limit(5)
            ->get(['id', 'name', 'sent_count', 'failed_count'])
            ->map(function ($c) {
                $total = max(1, (int) $c->sent_count);
                $ok    = max(0, $total - (int) $c->failed_count);
                return [
                    'id'             => $c->id,
                    'name'           => $c->name,
                    'sent'           => (int) $c->sent_count,
                    'delivered_rate' => round($ok / $total, 4),
                ];
            })->all();

        return ApiResponse::success([
            'by_channel_30d'    => $by,
            'daily_sent_30d'    => $daily,
            'top_campaigns_30d' => $top,
        ]);
    }
}
