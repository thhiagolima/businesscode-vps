<?php

namespace App\Jobs;

use App\Models\FunnelExecution;
use App\Models\FunnelNode;
use App\Services\Funnel\FunnelEngineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FunnelConditionCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $executionId) {}

    public function handle(FunnelEngineService $engine): void
    {
        $execution = FunnelExecution::withoutGlobalScopes()->find($this->executionId);
        if (! $execution || ! in_array($execution->status, ['running', 'waiting'])) return;

        $currentNode = FunnelNode::where('funnel_id', $execution->funnel_id)
            ->where('node_id', $execution->current_node_id)
            ->first();

        if (! $currentNode || $currentNode->type !== 'condition') return;

        $execution->update(['status' => 'running']);
        $engine->advance($execution);
        Log::channel('whatsapp')->debug('funnel.condition_rechecked', ['execution' => $this->executionId]);
    }
}
