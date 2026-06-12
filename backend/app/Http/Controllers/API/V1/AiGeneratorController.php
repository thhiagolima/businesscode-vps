<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiGeneration;
use App\Models\AiGenerationSession;
use App\Models\AiPrompt;
use App\Models\Tenant;
use App\Services\Ai\GrokService;
use App\Services\Billing\BillingService;
use Illuminate\Http\Request;

class AiGeneratorController extends Controller
{
    public function __construct(private GrokService $grok, private BillingService $billing) {}

    public function generate(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id;

        $data = $request->validate([
            'campaign_id'        => ['required', 'integer', 'exists:campaigns,id'],
            'channel'            => ['required', 'in:sms,voice,email,whatsapp'],
            'briefing'           => ['required', 'array'],
            'briefing.product'   => ['required', 'string', 'max:500'],
            'briefing.audience'  => ['required', 'string', 'max:500'],
            'briefing.benefit'   => ['required', 'string', 'max:500'],
            'briefing.cta'       => ['required', 'string', 'max:500'],
            'briefing.tone'      => ['required', 'in:professional,casual,urgent,inspiring,fun,direct'],
            'briefing.link'      => ['nullable', 'url', 'regex:/^https?:\/\//', 'max:2048'],
            'briefing.avoid'     => ['nullable', 'array', 'max:20'],
            'briefing.avoid.*'   => ['string', 'max:50'],
            'briefing.max_chars' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'variations'         => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        // Campaign pertence ao tenant (GlobalScope em Campaign garante)
        $campaignId = (int) $data['campaign_id'];
        \App\Models\Campaign::findOrFail($campaignId);

        // Superadmin não tem cobrança
        if ($user->role !== 'superadmin') {
            $required = 10; // cents
            $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
            $available = $tenant ? $tenant->availableBalanceCents() : 0;
            if ($available < $required) {
                return ApiResponse::error('Saldo insuficiente.', [
                    'missing_cents' => max(0, $required - $available),
                    'available'     => $available,
                ], 402);
            }
        }

        // Criar sessão
        $session = AiGenerationSession::create([
            'tenant_id'   => $tenantId,
            'campaign_id' => $campaignId,
            'channel'     => $data['channel'],
            'briefing'    => $data['briefing'],
            'status'      => 'pending',
        ]);

        // Chamar serviço Grok
        $result = $this->grok->generateVariations(
            $data['channel'],
            $data['briefing'],
            (int) $data['variations'],
            $tenantId
        );

        // Atualizar sessão
        $session->update([
            'status'     => 'completed',
            'variations' => $result['variations'] ?? [],
        ]);

        return ApiResponse::success([
            'session_id'    => $session->id,
            'generation_id' => $result['generation_id'],
            'variations'    => $result['variations'] ?? [],
            'credits_used'  => $result['credits_used'] ?? 10,
        ]);
    }

    public function analyze(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id;

        $data = $request->validate([
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
            'channel'     => ['required', 'in:sms,voice,email,whatsapp'],
            'content'     => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        // Campaign pertence ao tenant
        \App\Models\Campaign::findOrFail((int)$data['campaign_id']);

        // Superadmin não tem cobrança
        if ($user->role !== 'superadmin') {
            $required = 10; // cents
            $tenant = Tenant::withoutGlobalScopes()->find($tenantId);
            $available = $tenant ? $tenant->availableBalanceCents() : 0;
            if ($available < $required) {
                return ApiResponse::error('Saldo insuficiente.', [
                    'missing_cents' => max(0, $required - $available),
                    'available'     => $available,
                ], 402);
            }
        }

        $result = $this->grok->analyzeContent(
            $data['channel'],
            $data['content'],
            $tenantId
        );

        return ApiResponse::success([
            'generation_id'     => $result['generation_id'],
            'analysis'          => $result['analysis'],
            'improved_versions' => $result['improved_versions'],
            'credits_used'      => $result['credits_used'] ?? 10,
        ]);
    }

    // Superadmin: listar prompts
    public function prompts(Request $request)
    {
        if (!$request->user() || $request->user()->role !== 'superadmin') {
            return ApiResponse::error('Acesso negado', [], 403);
        }
        $items = AiPrompt::orderBy('service')->orderByDesc('is_active')->get();
        return ApiResponse::success($items);
    }

    // Histórico de gerações do tenant
    public function history(Request $request)
    {
        $query = AiGeneration::query()->orderByDesc('created_at');
        if ($service = $request->query('service')) {
            $query->where('service', $service);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $items = $query->paginate(20)->through(function (AiGeneration $g) {
            return [
                'id'             => $g->id,
                'generation_id'  => $g->generation_id,
                'service'        => $g->service,
                'prompt_version' => $g->prompt_version,
                'tokens_input'   => $g->tokens_input,
                'tokens_output'  => $g->tokens_output,
                'cost_usd'       => $g->cost_usd,
                'credits_used'   => 10,
                'status'         => $g->status,
                'created_at'     => $g->created_at,
            ];
        });

        return ApiResponse::paginated($items);
    }
}
