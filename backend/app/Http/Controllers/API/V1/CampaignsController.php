<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\ProcessCampaignJob;
use App\Models\AuditLog;
use App\Models\Campaign;
use App\Services\CampaignStateMachine;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignsController extends Controller
{
    use Concerns\ChecksTenant;
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type'   => ['nullable', 'in:sms,voice,email,whatsapp'],
            'status' => ['nullable', 'in:draft,scheduled,processing,running,completed,failed'],
        ]);

        $q = Campaign::query()->with('contactList:id,name,contact_count');

        if (!empty($validated['search'])) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $q->where('name', 'like', "%{$search}%");
        }
        if (!empty($validated['type'])) {
            $q->where('type', $validated['type']);
        }
        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        $paginator = $q->orderByDesc('id')->paginate(20);
        return ApiResponse::paginated($paginator, 'OK');
    }

    public function show(int $id)
    {
        $campaign = Campaign::with('contactList')->findOrFail($id);
        $this->ensureTenantOwns($campaign);
        return ApiResponse::success($campaign);
    }

    public function dispatches(int $id, \Illuminate\Http\Request $request)
    {
        $campaign = Campaign::findOrFail($id);
        $this->ensureTenantOwns($campaign);

        $q = \App\Models\CampaignDispatch::where('campaign_id', $campaign->id)
            ->with('contact:id,name,email');

        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        $paginator = $q->orderBy('id')->paginate(50);
        return ApiResponse::paginated($paginator);
    }

    public function store(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;
        $ruleExists = \Illuminate\Validation\Rule::exists('contact_lists','id')->where('tenant_id', $tenantId);
        $licensedChannel = function ($attribute, $value, $fail) use ($tenantId) {
            if (! \App\Models\TenantChannel::isAvailable((int) $tenantId, (string) $value)) {
                $fail("O canal '{$value}' não está habilitado para a sua conta. Habilite em Configurações > Canais.");
            }
        };
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'type'            => ['required', 'in:sms,voice,email,whatsapp', $licensedChannel],
            'content'         => ['nullable', 'string', 'max:10000'],
            'subject'         => ['nullable', 'string', 'max:255'],
            'audio_url'       => ['nullable', 'url', 'regex:/^https?:\/\//'],
            'contact_list_id' => ['nullable', 'integer', $ruleExists],
            'settings'        => ['nullable', 'array'],
            'settings.adhoc_phones'   => ['nullable', 'array', 'max:10000'],
            'settings.adhoc_phones.*' => ['string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'settings.template_name'  => ['nullable', 'string', 'max:255'],
        ]);
        $data['status'] = 'draft';
        // Remove client-sent estimated_contacts — calculate server-side only
        unset($data['estimated_contacts']);
        if (!empty($data['settings']['adhoc_phones'])) {
            $data['estimated_contacts'] = count($data['settings']['adhoc_phones']);
        }
        $campaign = Campaign::create($data);
        return ApiResponse::success($campaign, 'Campanha criada', [], 201);
    }

    public function update(Request $request, int $id)
    {
        $campaign = Campaign::findOrFail($id);
        $this->ensureTenantOwns($campaign);
        $tenantId = auth()->user()->tenant_id;
        $ruleExists = \Illuminate\Validation\Rule::exists('contact_lists','id')->where('tenant_id', $tenantId);
        $licensedChannel = function ($attribute, $value, $fail) use ($tenantId) {
            if (! \App\Models\TenantChannel::isAvailable((int) $tenantId, (string) $value)) {
                $fail("O canal '{$value}' não está habilitado para a sua conta. Habilite em Configurações > Canais.");
            }
        };
        $data = $request->validate([
            'name'            => ['sometimes', 'string', 'max:255'],
            'type'            => ['sometimes', 'in:sms,voice,email,whatsapp', $licensedChannel],
            'content'         => ['nullable', 'string', 'max:10000'],
            'subject'         => ['nullable', 'string', 'max:255'],
            'audio_url'       => ['nullable', 'url', 'regex:/^https?:\/\//'],
            'contact_list_id' => ['nullable', 'integer', $ruleExists],
            'settings'        => ['nullable', 'array'],
            'settings.adhoc_phones'   => ['nullable', 'array', 'max:10000'],
            'settings.adhoc_phones.*' => ['string', 'max:20', 'regex:/^\+[1-9]\d{6,14}$/'],
            'settings.template_name'  => ['nullable', 'string', 'max:255'],
            'scheduled_at'    => ['nullable', 'date', 'after:now'],
        ]);
        // Remove client-sent estimated_contacts — calculate server-side only
        unset($data['estimated_contacts']);
        if (!empty($data['settings']['adhoc_phones'])) {
            $data['estimated_contacts'] = count($data['settings']['adhoc_phones']);
        }
        $campaign->update($data);
        return ApiResponse::success($campaign, 'Campanha atualizada');
    }

    public function destroy(int $id)
    {
        $campaign = Campaign::findOrFail($id);
        $this->ensureTenantOwns($campaign);
        $campaign->delete();
        AuditLog::record('campaign.deleted', 'Campaign', $id);
        return ApiResponse::success([], 'Campanha removida');
    }

    public function sendNow(int $id, CampaignStateMachine $machine, BillingService $billing)
    {
        // Pessimistic lock on the campaign row prevents concurrent sendNow calls from passing
        // the state-machine check and dispatching the job twice for the same campaign.
        $result = DB::transaction(function () use ($id, $machine, $billing) {
            $campaign = Campaign::lockForUpdate()->findOrFail($id);
            $this->ensureTenantOwns($campaign);
            $errors = $machine->assertCanDispatch($campaign);
            if (!empty($errors)) {
                return ['response' => ApiResponse::error('Campanha inválida para disparo.', $errors, 422)];
            }
            if (auth()->user()->role !== 'superadmin') {
                $lock = $billing->lockCampaignIfInsufficient($campaign);
                if ($lock['locked'] && $lock['possible_sends'] === 0) {
                    return ['response' => ApiResponse::error('Saldo insuficiente para disparar a campanha.', [
                        'missing_cents' => $lock['missing_cents'],
                        'available'     => $lock['available'],
                        'required'      => $lock['required'],
                    ], 402)];
                }
            }
            $machine->transition($campaign, 'processing');
            return ['campaign' => $campaign];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }
        $campaign = $result['campaign'];
        ProcessCampaignJob::dispatch($campaign->id, $campaign->tenant_id);
        AuditLog::record('campaign.send_now', 'Campaign', $campaign->id);
        return ApiResponse::success($campaign->fresh(), 'Disparo iniciado');
    }

    public function schedule(int $id, Request $request, CampaignStateMachine $machine, BillingService $billing)
    {
        $when = $request->validate([
            'scheduled_at' => ['required','date','after:now'],
        ])['scheduled_at'];

        $result = DB::transaction(function () use ($id, $when, $machine, $billing) {
            $campaign = Campaign::lockForUpdate()->findOrFail($id);
            $this->ensureTenantOwns($campaign);
            $errors = $machine->assertCanDispatch($campaign);
            if (!empty($errors)) {
                return ['response' => ApiResponse::error('Campanha inválida para agendamento.', $errors, 422)];
            }
            if (auth()->user()->role !== 'superadmin') {
                $lock = $billing->lockCampaignIfInsufficient($campaign);
                if ($lock['locked'] && $lock['possible_sends'] === 0) {
                    return ['response' => ApiResponse::error('Saldo insuficiente para agendar a campanha.', [
                        'missing_cents' => $lock['missing_cents'],
                        'available'     => $lock['available'],
                        'required'      => $lock['required'],
                    ], 402)];
                }
            }
            $campaign->update(['scheduled_at' => $when]);
            $machine->transition($campaign, 'scheduled');
            return ['campaign' => $campaign];
        });

        if (isset($result['response'])) {
            return $result['response'];
        }
        AuditLog::record('campaign.scheduled', 'Campaign', $result['campaign']->id, [
            'scheduled_at' => $when,
        ]);
        return ApiResponse::success($result['campaign']->fresh(), 'Agendado');
    }

    public function cancel(int $id, CampaignStateMachine $machine)
    {
        $campaign = Campaign::findOrFail($id);
        $this->ensureTenantOwns($campaign);
        if (!in_array($campaign->status, ['scheduled', 'processing'])) {
            return ApiResponse::error('Somente campanhas agendadas ou em processamento podem ser canceladas', [], 422);
        }
        $machine->transition($campaign, 'draft');
        $campaign->update(['scheduled_at' => null]);
        AuditLog::record('campaign.cancel', 'Campaign', $campaign->id);
        return ApiResponse::success($campaign->fresh(), 'Agendamento cancelado');
    }

    /**
     * Reset uma campanha em 'failed' de volta para 'draft' para que o operador
     * possa corrigir e reenviar. State machine já permite failed → draft.
     */
    public function reset(int $id, CampaignStateMachine $machine)
    {
        $campaign = Campaign::findOrFail($id);
        $this->ensureTenantOwns($campaign);
        if ($campaign->status !== 'failed') {
            return ApiResponse::error('Apenas campanhas com falha podem ser redefinidas.', [], 422);
        }
        $machine->transition($campaign, 'draft');
        // sent_count/failed_count não são fillable (são internos do worker);
        // usamos forceFill para zerar no reset.
        $campaign->forceFill(['scheduled_at' => null, 'sent_count' => 0, 'failed_count' => 0])->save();
        AuditLog::record('campaign.reset', 'Campaign', $campaign->id);
        return ApiResponse::success($campaign->fresh(), 'Campanha redefinida para rascunho');
    }

    /**
     * Lista sessões de geração IA da campanha.
     */
    public function aiSessions(int $id)
    {
        $campaign = Campaign::findOrFail($id);
        $this->ensureTenantOwns($campaign);
        $sessions = \App\Models\AiGenerationSession::where('campaign_id', $campaign->id)
            ->orderByDesc('created_at')
            ->get();
        return ApiResponse::success($sessions);
    }
}
