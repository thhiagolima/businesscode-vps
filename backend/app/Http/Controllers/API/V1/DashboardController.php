<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BalanceTransaction;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Contact;
use App\Models\Funnel;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Estatísticas gerais do dashboard (últimos 30 dias).
     */
    public function stats(): JsonResponse
    {
        $user     = auth()->user();
        $tenantId = $user->tenant_id;

        $stats = Cache::remember("dashboard_stats_{$tenantId}", 300, function () use ($user) {
            $now    = now();
            $last30 = $now->copy()->subDays(30);

            // KPIs principais
            $totalCampaigns  = Campaign::count();
            $activeCampaigns = Campaign::whereIn('status', ['running', 'processing'])->count();

            // Contatos: total e válidos (qualquer status diferente de 'invalid').
            $contactsTotal   = Contact::count();
            $contactsValid   = Contact::where('status', '!=', 'invalid')->count();
            $contactsActive  = Contact::where('status', 'active')->count();

            // Novos contatos no mês corrente (calendário, não rolling 30d).
            $startOfMonth   = $now->copy()->startOfMonth();
            $newContactsMtd = Contact::where('created_at', '>=', $startOfMonth)->count();

            // Funis automatizados em execução agora.
            $activeAutomations = Funnel::where('status', 'active')->count();

            $dispatches30 = CampaignDispatch::where('created_at', '>=', $last30);
            $sent30       = (clone $dispatches30)->whereIn('status', ['sent','delivered','read'])->count();
            $delivered30  = (clone $dispatches30)->whereIn('status', ['delivered','read'])->count();
            $failed30     = (clone $dispatches30)->where('status', 'failed')->count();
            $total30      = (clone $dispatches30)->count();

            $deliveryRate = $total30 > 0
                ? round(($delivered30 / $total30) * 100, 1)
                : 0;

            // Saldo em centavos (superadmin mostra ∞, tenant mostra saldo real)
            $balanceCents = $user->role === 'superadmin'
                ? -1  // frontend interpreta -1 como ilimitado
                : ($user->tenant?->balance_cents ?? 0);

            // Envios por dia (últimos 14 dias)
            $dailySent = CampaignDispatch::selectRaw('DATE(created_at) as date, COUNT(*) as total')
                ->where('created_at', '>=', $now->copy()->subDays(14))
                ->whereIn('status', ['sent','delivered','read'])
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->keyBy('date');

            // Preencher dias sem envio com zero
            $days = [];
            for ($i = 13; $i >= 0; $i--) {
                $date = $now->copy()->subDays($i)->format('Y-m-d');
                $days[] = [
                    'date'  => $date,
                    'label' => $now->copy()->subDays($i)->format('d/M'),
                    'total' => $dailySent->get($date)?->total ?? 0,
                ];
            }

            // Distribuição por canal (últimos 30 dias)
            $byChannel = Campaign::where('created_at', '>=', $last30)
                ->selectRaw('type, COUNT(*) as total')
                ->groupBy('type')
                ->pluck('total', 'type');

            // Últimas 5 campanhas
            $recentCampaigns = Campaign::with('contactList')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(['id','name','type','status','sent_count','failed_count','estimated_contacts','created_at']);

            // Últimas 5 transações de crédito
            $recentTransactions = BalanceTransaction::orderByDesc('created_at')
                ->limit(5)
                ->get(['id','type','amount_cents','balance_after_cents','description','created_at']);

            return [
                'kpis' => [
                    'total_campaigns'         => $totalCampaigns,
                    'active_campaigns'        => $activeCampaigns,
                    'sent_30d'                => $sent30,
                    'delivered_30d'           => $delivered30,
                    'failed_30d'              => $failed30,
                    'delivery_rate_30d'       => $deliveryRate,
                    'balance_cents'           => $balanceCents,
                    // Métricas da página Contatos (substituem placeholders frontend).
                    'contacts_total'          => $contactsTotal,
                    'contacts_valid'          => $contactsValid,
                    'contacts_active'         => $contactsActive,
                    'new_contacts_mtd'        => $newContactsMtd,
                    'active_automations'      => $activeAutomations,
                ],
                'warnings' => array_filter([
                    // Saldo baixo: < R$ 10,00 (1000 cents)
                    $balanceCents !== -1 && $balanceCents < 1000 ? 'Saldo baixo. Recarregue para continuar enviando.' : null,
                ]),
                'daily_sent'          => $days,
                'by_channel'          => $byChannel,
                'recent_campaigns'    => $recentCampaigns,
                'recent_transactions' => $recentTransactions,
            ];
        });

        return ApiResponse::success($stats);
    }
}

