<?php

namespace App\Services;

use App\Models\Campaign;
use App\Exceptions\InvalidCampaignTransitionException;
use Illuminate\Support\Facades\Log;

class CampaignStateMachine
{
    private const ALLOWED = [
        'draft'      => ['processing', 'scheduled'],
        // 'failed' allowed from 'scheduled' so superadmin can cancel scheduled campaigns
        // from the admin drill-down UI without first moving them to processing.
        'scheduled'  => ['processing', 'draft', 'failed'],
        // 'failed' allowed from 'processing' so insufficient-funds pre-debit check
        // can mark the campaign as failed without ever dispatching any send.
        'processing' => ['running', 'draft', 'failed'],
        'running'    => ['completed', 'failed'],
        'failed'     => ['draft'],
        'completed'  => [],
    ];

    public function transition(Campaign $campaign, string $to): void
    {
        $from = $campaign->status;
        if (!$this->canTransition($from, $to)) {
            throw new InvalidCampaignTransitionException("Transição inválida: {$from} → {$to}");
        }

        $updates = ['status' => $to] + match ($to) {
            'running'    => ['started_at' => now()],
            'completed'  => ['completed_at' => now()],
            'draft'      => ['started_at' => null, 'completed_at' => null],
            default      => [],
        };

        $campaign->forceFill($updates)->save();

        Log::channel('campaign')->info("campaign.{$to}", [
            'campaign_id' => $campaign->id,
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    public function assertCanDispatch(Campaign $campaign): array
    {
        $errors = [];
        if (!in_array($campaign->status, ['draft', 'scheduled'], true)) {
            $errors[] = "Status '{$campaign->status}' não permite disparo.";
        }
        if (empty($campaign->name)) $errors[] = 'Nome da campanha é obrigatório.';
        if ($campaign->type === 'whatsapp') {
            $settings = $campaign->settings ?? [];
            if (empty($settings['template_name'])) $errors[] = 'Template WhatsApp é obrigatório.';
        } elseif ($campaign->type === 'voice') {
            if (empty($campaign->audio_url)) {
                $errors[] = 'Áudio é obrigatório para campanhas de voz. Gere o áudio antes de enviar.';
            }
        } elseif (empty($campaign->content)) {
            $errors[] = 'Conteúdo da campanha é obrigatório.';
        }
        $hasAdhocPhones = !empty($campaign->settings['adhoc_phones'] ?? []);
        if (empty($campaign->contact_list_id) && !$hasAdhocPhones) {
            $errors[] = 'Selecione uma lista de contatos ou informe números para envio.';
        }
        if (!in_array($campaign->type, ['sms', 'voice', 'email', 'whatsapp'], true)) $errors[] = "Canal '{$campaign->type}' inválido.";

        // Verificar se canal está habilitado para o tenant
        $tenantId = $campaign->tenant_id;
        if ($tenantId && !\App\Models\TenantChannel::isAvailable($tenantId, $campaign->type)) {
            $errors[] = "Canal '{$campaign->type}' não está habilitado para este tenant.";
        }

        return $errors;
    }
}

