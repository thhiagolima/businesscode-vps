<?php

namespace App\Console\Commands\Billing;

use App\Models\Payment;
use App\Services\MercadoPagoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcilePendingMercadoPagoPayments extends Command
{
    protected $signature = 'billing:reconcile-mp-payments {--limit=100}';

    protected $description = 'Reconcile pending Mercado Pago payments without depending on frontend polling.';

    public function handle(MercadoPagoService $mercadoPago): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $payments = Payment::withoutGlobalScopes()
            ->where('status', 'pending')
            ->whereNotNull('mp_payment_id')
            ->where('created_at', '>=', now()->subDays(7))
            ->oldest()
            ->limit($limit)
            ->get();

        $changed = 0;

        foreach ($payments as $payment) {
            try {
                $before = $payment->status;
                $after = $mercadoPago->reconcilePayment($payment)->status;
                if ($before !== $after) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                Log::channel('campaign')->warning('mp.pending_reconcile_failed', [
                    'payment_id' => $payment->id,
                    'mp_payment_id' => $payment->mp_payment_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reconciled {$changed} of {$payments->count()} pending Mercado Pago payments.");

        return self::SUCCESS;
    }
}
