<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchOverdueRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Tenant::query()
            ->whereIn('billing_status', ['grace', 'suspended'])
            ->chunkById(50, function ($tenants) {
                foreach ($tenants as $tenant) {
                    OverdueRetryJob::dispatch($tenant->id);
                }
            });
    }
}
