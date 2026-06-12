<?php

namespace App\Console\Commands;

use App\Models\FunnelExecution;
use App\Services\Funnel\FunnelEngineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FunnelTickCommand extends Command
{
    protected $signature = 'funnel:tick';
    protected $description = 'Process waiting funnel executions whose wait_until has passed';

    public function handle(FunnelEngineService $engine): int
    {
        $executions = FunnelExecution::withoutGlobalScopes()
            ->where('status', 'waiting')
            ->where('wait_until', '<=', now())
            ->limit(100)
            ->get();

        if ($executions->isEmpty()) return 0;

        $processed = 0;
        foreach ($executions as $execution) {
            try {
                $execution->update(['status' => 'running']);
                $engine->advance($execution);
                $processed++;
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->error('funnel.tick_error', [
                    'execution' => $execution->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        if ($processed > 0) {
            Log::channel('whatsapp')->info('funnel.tick', ['processed' => $processed]);
        }

        return 0;
    }
}
