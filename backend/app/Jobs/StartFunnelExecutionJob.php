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

class StartFunnelExecutionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $funnelId,
        public int $contactId,
        public int $conversationId,
        public int $tenantId
    ) {}

    public function handle(FunnelEngineService $engine): void
    {
        $startNode = FunnelNode::where('funnel_id', $this->funnelId)->where('type', 'start')->first();
        if (! $startNode) {
            Log::channel('whatsapp')->warning('funnel.no_start_node', ['funnel' => $this->funnelId]);
            return;
        }

        $execution = FunnelExecution::create([
            'tenant_id'       => $this->tenantId,
            'funnel_id'       => $this->funnelId,
            'contact_id'      => $this->contactId,
            'conversation_id' => $this->conversationId,
            'current_node_id' => $startNode->node_id,
            'status'          => 'running',
            'started_at'      => now(),
        ]);

        $engine->advance($execution);
        Log::channel('whatsapp')->info('funnel.started', ['funnel' => $this->funnelId, 'contact' => $this->contactId, 'execution' => $execution->id]);
    }
}
