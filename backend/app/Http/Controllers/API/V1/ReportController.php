<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\BalanceTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use Concerns\ChecksTenant;
    /**
     * Relatório geral de campanhas (paginado).
     */
    public function campaigns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from'   => ['nullable','date'],
            'to'     => ['nullable','date'],
            'type'   => ['nullable','in:sms,voice,email,whatsapp'],
            'status' => ['nullable','string'],
            'search' => ['nullable','string'],
            'page'   => ['nullable','integer'],
        ]);

        $q = Campaign::query()->with('contactList');

        if (!empty($validated['from'])) {
            $q->where('created_at', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $q->where('created_at', '<=', $validated['to']);
        }
        if (!empty($validated['type'])) {
            $q->where('type', $validated['type']);
        }
        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }
        if (!empty($validated['search'])) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $q->where('name', 'like', '%'.$search.'%');
        }

        $q->orderByDesc('completed_at');

        $paginator = $q->paginate(20, [
            'id','name','type','status','contact_list_id',
            'sent_count','failed_count','estimated_contacts','started_at','completed_at',
        ]);

        $items = collect($paginator->items())->map(function (Campaign $c) {
            $totalEstimated = max(1, (int)$c->estimated_contacts);
            $deliveryRate = $c->sent_count > 0 && $totalEstimated > 0
                ? round(($c->sent_count / $totalEstimated) * 100, 1)
                : 0.0;
            $duration = ($c->started_at && $c->completed_at)
                ? $c->started_at->diffInMinutes($c->completed_at)
                : null;
            return [
                'id'                  => $c->id,
                'name'                => $c->name,
                'type'                => $c->type,
                'status'              => $c->status,
                'contact_list'        => $c->contactList?->name,
                'sent_count'          => (int)$c->sent_count,
                'failed_count'        => (int)$c->failed_count,
                'estimated_contacts'  => (int)$c->estimated_contacts,
                'delivery_rate'       => $deliveryRate,
                'started_at'          => $c->started_at,
                'completed_at'        => $c->completed_at,
                'duration_minutes'    => $duration,
            ];
        });

        return ApiResponse::success($items, 'OK', [
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ]
        ]);
    }

    /**
     * Relatório detalhado de uma campanha.
     */
    public function campaign(int $id): JsonResponse
    {
        $campaign = Campaign::with('contactList')->findOrFail($id);
        $this->ensureTenantOwns($campaign);

        $total      = CampaignDispatch::where('campaign_id', $campaign->id)->count();
        $sent       = CampaignDispatch::where('campaign_id', $campaign->id)->whereIn('status', ['sent','delivered','read'])->count();
        $delivered  = CampaignDispatch::where('campaign_id', $campaign->id)->whereIn('status', ['delivered','read'])->count();
        $failed     = CampaignDispatch::where('campaign_id', $campaign->id)->where('status', 'failed')->count();
        $read       = CampaignDispatch::where('campaign_id', $campaign->id)->where('status', 'read')->count();
        $pending    = CampaignDispatch::where('campaign_id', $campaign->id)->where('status', 'pending')->count();

        $deliveryRate = $total > 0 ? round(($delivered / $total) * 100, 1) : 0.0;
        $readRate     = $sent > 0 ? round(($read / $sent) * 100, 1) : 0.0;

        // timeline: agrupar por hora do sent_at (últimas 24h da campanha)
        $timelineRaw = CampaignDispatch::selectRaw("DATE_FORMAT(sent_at, '%Y-%m-%d %H:00') as hour,
                SUM(CASE WHEN status IN ('sent','delivered','read') THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('sent_at')
            ->groupBy('hour')
            ->orderBy('hour')
            ->limit(24)
            ->get();

        $timeline = $timelineRaw->map(function ($t) {
            return [
                'hour'      => $t->hour,
                'sent'      => (int)$t->sent,
                'delivered' => (int)$t->delivered,
                'failed'    => (int)$t->failed,
            ];
        });

        // errors: agrupar error_message e contar
        $errors = CampaignDispatch::selectRaw('error_message, COUNT(*) as count')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('error_message')
            ->groupBy('error_message')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($e) => ['message' => $e->error_message, 'count' => (int)$e->count]);

        // amostra de dispatches (primeiros 10)
        $dispatchesSample = CampaignDispatch::where('campaign_id', $campaign->id)
            ->orderBy('id')
            ->limit(10)
            ->get(['id','contact_id','phone','status','sent_at','delivered_at','error_message']);

        return ApiResponse::success([
            'campaign' => $campaign,
            'stats' => [
                'total'         => (int)$total,
                'sent'          => (int)$sent,
                'delivered'     => (int)$delivered,
                'failed'        => (int)$failed,
                'read'          => (int)$read,
                'pending'       => (int)$pending,
                'delivery_rate' => $deliveryRate,
                'read_rate'     => $readRate,
            ],
            'timeline'          => $timeline,
            'errors'            => $errors,
            'dispatches_sample' => $dispatchesSample,
        ]);
    }

    /**
     * Exporta CSV dos dispatches de uma campanha.
     */
    public function export(int $id): StreamedResponse
    {
        // CSV contains PII (phone, email) of every recipient. Restrict to admin/superadmin
        // (LGPD: dado pessoal — só perfis administrativos podem exportar em lote).
        $user = auth()->user();
        if (! $user || ! in_array($user->role, ['admin', 'superadmin'], true)) {
            abort(403, 'Apenas administradores podem exportar relatórios.');
        }
        $campaign = Campaign::findOrFail($id);
        $this->ensureTenantOwns($campaign);

        return response()->stream(function () use ($campaign) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 para Excel brasileiro
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'Nome', 'Telefone', 'Email', 'Status',
                'ID Externo', 'Enviado em', 'Entregue em', 'Erro'
            ], ';');

            CampaignDispatch::where('campaign_id', $campaign->id)
                ->with('contact:id,name,email')
                ->orderBy('id')
                ->chunk(500, function ($dispatches) use ($handle) {
                    foreach ($dispatches as $d) {
                        fputcsv($handle, [
                            $d->contact?->name     ?? '',
                            $d->phone,
                            $d->contact?->email    ?? '',
                            $d->status,
                            $d->external_message_id ?? '',
                            $d->sent_at?->format('d/m/Y H:i') ?? '',
                            $d->delivered_at?->format('d/m/Y H:i') ?? '',
                            $d->error_message ?? '',
                        ], ';');
                    }
                });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"campanha-{$id}.csv\"",
            'Cache-Control'       => 'no-cache',
        ]);
    }

    /**
     * Extrato de créditos (paginado).
     */
    public function credits(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable','date'],
            'to'   => ['nullable','date'],
            // Accept both legacy aliases ('debit'/'credit') and real enum values written by BillingService.
            'type' => ['nullable','in:debit,credit,reserve,release,recharge,manual_adjustment,monthly_charge'],
            'page' => ['nullable','integer'],
        ]);

        $q = BalanceTransaction::query();

        if (!empty($validated['from'])) {
            $q->where('created_at', '>=', $validated['from']);
        }
        if (!empty($validated['to'])) {
            $q->where('created_at', '<=', $validated['to']);
        }
        if (!empty($validated['type'])) {
            $q->where('type', $validated['type']);
        }

        $q->orderByDesc('created_at');

        $paginator = $q->paginate(30, [
            'id','type','amount_cents','balance_after_cents','reference_type','description','created_at'
        ]);

        $items = collect($paginator->items())->map(function (BalanceTransaction $t) {
            return [
                'id'                  => $t->id,
                'type'                => $t->type,
                'amount_cents'        => (int)$t->amount_cents,
                'balance_after_cents' => (int)$t->balance_after_cents,
                'reference_type'      => $t->reference_type,
                'description'         => $t->description,
                'created_at'          => $t->created_at,
            ];
        });

        // Totalizadores (últimos 30 dias) — agrega por sinal de amount_cents,
        // pois o enum real do BillingService é reserve/release/recharge/manual_adjustment
        // (cada um podendo ter sinal +/-), não os legados debit/credit.
        $now    = now();
        $last30 = $now->copy()->subDays(30);
        $tenantId = auth()->user()->tenant_id;
        $totalDebited = -(int) BalanceTransaction::where('tenant_id', $tenantId)
            ->where('created_at', '>=', $last30)
            ->where('amount_cents', '<', 0)
            ->sum('amount_cents');
        $totalCredited = (int) BalanceTransaction::where('tenant_id', $tenantId)
            ->where('created_at', '>=', $last30)
            ->where('amount_cents', '>', 0)
            ->sum('amount_cents');
        $currentBalance = auth()->user()?->tenant?->balance_cents ?? 0;
        $transactionsCount = BalanceTransaction::where('tenant_id', $tenantId)->count();

        return ApiResponse::success($items, 'OK', [
            'summary' => [
                'total_debited_30d'  => (int)$totalDebited,
                'total_credited_30d' => (int)$totalCredited,
                'current_balance'    => (int)$currentBalance,
                'transactions_count' => (int)$transactionsCount,
            ],
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ]
        ]);
    }
}
