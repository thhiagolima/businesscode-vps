<?php

namespace App\Console\Commands;

use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Services\Billing\BillingService;
use App\Services\CampaignStateMachine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DispatchScheduledCampaigns extends Command
{
    protected $signature   = 'campaigns:dispatch-scheduled';
    protected $description = 'Processa campanhas agendadas cujo horário de disparo já chegou.';

    public function handle(CampaignStateMachine $machine, BillingService $billing): int
    {
        $due = Campaign::withoutGlobalScopes()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $this->info("Encontradas {$due->count()} campanha(s) para disparar.");

        foreach ($due as $campaign) {
            // Lock atômico por campanha: evita que duas instâncias do worker processem a mesma
            $lockKey = "campaign_dispatch_lock:{$campaign->id}";
            $lock    = Cache::lock($lockKey, 60);

            if (! $lock->get()) {
                $this->line("  Campanha #{$campaign->id} já está sendo processada (lock ativo). Pulando.");
                continue;
            }

            try {
                // Re-verifica o status dentro do lock (proteção contra race condition)
                $campaign->refresh();
                if ($campaign->status !== 'scheduled') {
                    $this->line("  Campanha #{$campaign->id} não está mais como 'scheduled'. Pulando.");
                    continue;
                }

                // Valida saldo antes de disparar
                $check = $billing->lockCampaignIfInsufficient($campaign);
                if ($check['locked'] && $check['possible_sends'] === 0) {
                    $campaign->update([
                        'status'          => 'failed',
                        'warning_message' => "Saldo insuficiente no momento do disparo. Faltaram R$ " . number_format($check['missing_cents'] / 100, 2, ',', '.'),
                    ]);
                    Log::channel('campaign')->warning('campaign.scheduled.no_funds', [
                        'campaign_id'   => $campaign->id,
                        'missing_cents' => $check['missing_cents'],
                    ]);
                    $this->warn("  Campanha #{$campaign->id} falhou: saldo insuficiente.");
                    continue;
                }

                // Transiciona para processing e enfileira o job real
                $machine->transition($campaign, 'processing');
                ProcessCampaignJob::dispatch($campaign->id, $campaign->tenant_id);

                $this->info("  Campanha #{$campaign->id} '{$campaign->name}' enfileirada.");
                Log::channel('campaign')->info('campaign.scheduled.dispatched', [
                    'campaign_id' => $campaign->id,
                    'tenant_id'   => $campaign->tenant_id,
                    'scheduled_at'=> $campaign->scheduled_at,
                ]);
            } catch (\Throwable $e) {
                Log::channel('campaign')->error('campaign.scheduled.error', [
                    'campaign_id' => $campaign->id,
                    'error'       => $e->getMessage(),
                ]);
                $this->error("  Erro na campanha #{$campaign->id}: {$e->getMessage()}");
            } finally {
                Cache::lock($lockKey)->forceRelease();
            }
        }

        return self::SUCCESS;
    }
}
