<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class DispatchMonthlyBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $today = Carbon::now('America/Sao_Paulo')->day;

        Tenant::query()
            ->where('billing_cycle_day', $today)
            ->where(function ($q) {
                $q->whereNull('last_billing_at')
                  ->orWhere('last_billing_at', '<', now()->subDays(25));
            })
            ->whereNotIn('billing_status', ['blocked'])
            ->chunkById(50, function ($tenants) {
                foreach ($tenants as $tenant) {
                    MonthlyBillingJob::dispatch($tenant->id);
                }
            });
    }
}
