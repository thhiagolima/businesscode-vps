<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BalanceTransaction;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingReportController extends Controller
{
    /**
     * GET /admin/billing/stats
     * Aggregated, cross-tenant snapshot for finance dashboards.
     */
    public function stats(Request $request)
    {
        $since = now()->subDays(30);

        // Tenant balance aggregates — cross-tenant by design (route is role:finance|superadmin).
        $balanceAggregate = Tenant::query()
            ->selectRaw('SUM(CASE WHEN balance_cents > 0 THEN balance_cents ELSE 0 END) AS positive_sum')
            ->selectRaw('SUM(CASE WHEN balance_cents < 0 THEN -balance_cents ELSE 0 END) AS negative_sum')
            ->first();

        $totalBalanceBrl = (float) (($balanceAggregate->positive_sum ?? 0) / 100);
        $totalOwedBrl    = (float) (($balanceAggregate->negative_sum ?? 0) / 100);

        // Tenants grouped by status (active|suspended|trial — `status` column, not billing_status).
        $tenantsByStatus = Tenant::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        // Tenants grouped by billing_status (active|grace|suspended|blocked).
        $tenantsByBillingStatus = Tenant::query()
            ->select('billing_status', DB::raw('COUNT(*) as total'))
            ->groupBy('billing_status')
            ->pluck('total', 'billing_status')
            ->toArray();

        // MRR (last 30d) — sum of recharge + monthly_charge.
        $mrrCents = (int) BalanceTransaction::withoutGlobalScopes()
            ->whereIn('type', ['recharge', 'monthly_charge'])
            ->where('created_at', '>=', $since)
            ->sum('amount_cents');

        // Margin (last 30d) — sum(charged - cost) on sent dispatches.
        $marginCents = (int) MessageDispatch::withoutGlobalScopes()
            ->where('status', 'sent')
            ->where('created_at', '>=', $since)
            ->sum(DB::raw('COALESCE(charged_cents,0) - COALESCE(cost_cents,0)'));

        // Overdue recovery rate (best-effort, last 30d):
        // - Denominator: tenants that entered grace at least once (proxy: count of
        //   `monthly_billing` transactions that produced overdue debt). Approximated
        //   by counting tenants whose overdue_since is in the last 30 days OR was
        //   cleared in the last 30 days. We use audit_logs as canonical signal.
        // - Numerator: count of `tenant.billing.unlocked` actions in same window
        //   plus auto-recoveries (rows in balance_transactions of type monthly_charge
        //   with description "Retentativa de cobrança em atraso").
        $autoRecoveries = (int) BalanceTransaction::withoutGlobalScopes()
            ->where('type', 'monthly_charge')
            ->where('description', 'Retentativa de cobrança em atraso')
            ->where('created_at', '>=', $since)
            ->count();

        $manualUnlocks = (int) DB::table('audit_logs')
            ->where('action', 'tenant.billing.unlocked')
            ->where('created_at', '>=', $since)
            ->count();

        // Best-effort denominator: tenants currently in grace + recovered tenants.
        $currentlyInGrace = (int) Tenant::query()->where('billing_status', 'grace')->count();
        $denominator      = $currentlyInGrace + $autoRecoveries + $manualUnlocks;

        $overdueRecoveryRate = $denominator > 0
            ? round((($autoRecoveries + $manualUnlocks) / $denominator) * 100, 2)
            : 0.0;

        return ApiResponse::success([
            'total_balance_brl'         => round($totalBalanceBrl, 2),
            'total_owed_brl'            => round($totalOwedBrl, 2),
            'tenants_by_status'         => $tenantsByStatus,
            'tenants_by_billing_status' => $tenantsByBillingStatus,
            'mrr_last_30d'              => round($mrrCents / 100, 2),
            'margin_last_30d'           => round($marginCents / 100, 2),
            'overdue_recovery_rate_30d' => $overdueRecoveryRate,
            'overdue_recovery_meta'     => [
                'auto_recoveries' => $autoRecoveries,
                'manual_unlocks'  => $manualUnlocks,
                'in_grace_now'    => $currentlyInGrace,
                'denominator'     => $denominator,
            ],
            'window_days' => 30,
        ]);
    }
}
