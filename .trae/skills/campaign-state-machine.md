# Skill: Campaign State Machine

## Quando Usar
Sempre que implementar ou modificar transições de status
de campanha, validação pré-disparo ou lógica de despacho.

## Estados e Transições

```
┌─────────┐   send now   ┌────────────┐   job starts  ┌─────────┐
│  draft  │ ──────────── │ processing │ ──────────── │ running │
└─────────┘              └────────────┘               └─────────┘
     │                                                     │
     │ schedule                                   all done │ error
     ▼                                                     ▼
┌───────────┐  scheduler  ┌────────────┐          ┌───────────┐  ┌────────┐
│ scheduled │ ─────────── │ processing │          │ completed │  │ failed │
└───────────┘             └────────────┘          └───────────┘  └────────┘
                                                                      │
                                                               user resets│
                                                                      ▼
                                                                  ┌───────┐
                                                                  │ draft │
                                                                  └───────┘
```

## Transições Permitidas

```php
const ALLOWED_TRANSITIONS = [
    'draft'      => ['processing', 'scheduled'],
    'scheduled'  => ['processing', 'draft'],
    'processing' => ['running'],
    'running'    => ['completed', 'failed'],
    'failed'     => ['draft'],
    'completed'  => [],  // terminal — nenhuma transição permitida
];
```

## Implementação — CampaignStateMachine

`app/Services/CampaignStateMachine.php`:

```php
<?php

namespace App\Services;

use App\Models\Campaign;
use App\Exceptions\InvalidCampaignTransitionException;
use Illuminate\Support\Facades\Log;

class CampaignStateMachine
{
    private const ALLOWED = [
        'draft'      => ['processing', 'scheduled'],
        'scheduled'  => ['processing', 'draft'],
        'processing' => ['running'],
        'running'    => ['completed', 'failed'],
        'failed'     => ['draft'],
        'completed'  => [],
    ];

    public function transition(Campaign $campaign, string $to): void
    {
        $from = $campaign->status;

        if (!$this->canTransition($from, $to)) {
            throw new InvalidCampaignTransitionException(
                "Transição inválida: {$from} → {$to} (campanha #{$campaign->id})"
            );
        }

        $updates = ['status' => $to];

        // Timestamps por estado
        $updates += match ($to) {
            'processing' => [],
            'running'    => ['started_at'   => now()],
            'completed'  => ['completed_at' => now()],
            'scheduled'  => [],
            'draft'      => ['started_at' => null, 'completed_at' => null],
            default      => [],
        };

        $campaign->update($updates);

        Log::channel('campaign')->info("campaign.{$to}", [
            'campaign_id' => $campaign->id,
            'from'        => $from,
            'to'          => $to,
        ]);
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? []);
    }

    public function assertCanDispatch(Campaign $campaign): array
    {
        $errors = [];

        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            $errors[] = "Status '{$campaign->status}' não permite disparo.";
        }

        if (empty($campaign->name)) {
            $errors[] = 'Nome da campanha é obrigatório.';
        }

        if (empty($campaign->content) && empty($campaign->audio_url)) {
            $errors[] = 'Conteúdo da campanha é obrigatório.';
        }

        if (empty($campaign->contact_list_id)) {
            $errors[] = 'Lista de contatos é obrigatória.';
        }

        if (!in_array($campaign->type, ['sms', 'voice', 'email'])) {
            $errors[] = "Canal '{$campaign->type}' inválido.";
        }

        return $errors;
    }
}
```

## Uso no Controller

```php
public function sendNow(int $id): JsonResponse
{
    $campaign = Campaign::findOrFail($id);
    $machine  = app(CampaignStateMachine::class);

    // Validar pré-condições
    $errors = $machine->assertCanDispatch($campaign);
    if (!empty($errors)) {
        return ApiResponse::error('Campanha inválida para disparo.', $errors, 422);
    }

    // Verificar créditos
    $billing = app(BillingService::class);
    $lock    = $billing->lockCampaignIfInsufficient($campaign->id);
    if ($lock['locked'] && $lock['possible_sends'] === 0) {
        return ApiResponse::error(
            'Créditos insuficientes para disparar a campanha.',
            ['missing_credits' => $lock['missing_credits']],
            402
        );
    }

    // Transição e despacho
    $machine->transition($campaign, 'processing');
    ProcessCampaignJob::dispatch($campaign->id, $campaign->tenant_id);

    return ApiResponse::success($campaign->fresh(), 'Campanha iniciada.');
}
```

## Uso no ProcessCampaignJob

```php
public function handle(): void
{
    $campaign = Campaign::withoutGlobalScopes()
        ->where('id', $this->campaignId)
        ->where('tenant_id', $this->tenantId)
        ->firstOrFail();

    $machine = app(CampaignStateMachine::class);
    $machine->transition($campaign, 'running');

    try {
        // ... lógica de envio ...

        $campaign->update([
            'sent_count'   => $sent,
            'failed_count' => $failed,
        ]);

        $machine->transition($campaign, 'completed');

    } catch (\Throwable $e) {
        $machine->transition($campaign, 'failed');
        throw $e;
    }
}

public function failed(\Throwable $e): void
{
    Campaign::withoutGlobalScopes()
        ->where('id', $this->campaignId)
        ->update(['status' => 'failed']);

    Log::channel('campaign')->error('campaign.failed', [
        'campaign_id' => $this->campaignId,
        'error'       => $e->getMessage(),
    ]);
}
```

## Exception

`app/Exceptions/InvalidCampaignTransitionException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class InvalidCampaignTransitionException extends RuntimeException {}
```

## Helper Vue para Status (frontend)

```typescript
// composables/useCampaignStatus.ts
export const useCampaignStatus = () => {
  const transitions: Record<string, string[]> = {
    draft:      ['processing', 'scheduled'],
    scheduled:  ['processing', 'draft'],
    processing: ['running'],
    running:    ['completed', 'failed'],
    failed:     ['draft'],
    completed:  [],
  }

  const color = (status: string): string => ({
    draft:      'secondary',
    processing: 'warning',
    running:    'info',
    scheduled:  'azure',
    completed:  'success',
    failed:     'danger',
  }[status] ?? 'secondary')

  const label = (status: string): string => ({
    draft:      'Rascunho',
    processing: 'Processando',
    running:    'Enviando',
    scheduled:  'Agendado',
    completed:  'Concluído',
    failed:     'Falhou',
  }[status] ?? status)

  const canSendNow = (status: string) =>
    ['draft', 'scheduled'].includes(status)

  const canDuplicate = (status: string) =>
    ['completed', 'failed', 'draft'].includes(status)

  const canCancel = (status: string) =>
    ['scheduled'].includes(status)

  return { color, label, canSendNow, canDuplicate, canCancel }
}
```

## Nunca Fazer

```php
// ❌ Atualizar status diretamente sem usar a state machine
$campaign->update(['status' => 'completed']);
$campaign->status = 'running';
$campaign->save();

// ❌ Disparar campanha sem validar pré-condições
ProcessCampaignJob::dispatch($campaign->id);

// ❌ Transição sem registrar timestamp
Campaign::find($id)->update(['status' => 'running']);
// deve atualizar started_at também
```
