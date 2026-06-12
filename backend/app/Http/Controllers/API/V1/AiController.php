<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiGeneration;
use App\Models\AiGenerationSession;
use App\Models\AiPrompt;
use App\Services\Ai\GrokService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiController extends Controller
{
    public function __construct(private GrokService $grok, private SettingsService $settings) {}

    /**
     * Gera variações de conteúdo com IA, registrando ai_generations e sessão.
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'campaign_id'        => ['nullable', 'integer', 'exists:campaigns,id'],
            'channel'            => ['required', 'in:sms,voice,email'],
            'briefing'           => ['required', 'array'],
            'briefing.product'   => ['required', 'string'],
            'briefing.audience'  => ['required', 'string'],
            'briefing.benefit'   => ['required', 'string'],
            'briefing.cta'       => ['required', 'string'],
            'briefing.tone'      => ['nullable', 'string'],
            'briefing.link'      => ['nullable', 'string'],
            'briefing.avoid'     => ['nullable', 'array'],
            'briefing.max_chars' => ['nullable', 'integer'],
            'variations'         => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $service = $validated['channel'];
        $variationsCount = (int)($validated['variations'] ?? 3);

        $prompt = AiPrompt::activeFor($service);
        if (!$prompt) {
            return ApiResponse::error('Prompt não configurado para o serviço', [], 422);
        }

        $generationId = (string) Str::uuid();
        Log::channel('ai')->info('ai.generate.start', ['generation_id' => $generationId, 'service' => $service]);

        $gen = AiGeneration::create([
            'generation_id' => $generationId,
            'service'       => $service,
            'prompt_version'=> $prompt->version,
            'input_payload' => $validated,
            'model'         => $prompt->model,
            'status'        => 'pending',
        ]);

        $session = AiGenerationSession::create([
            'campaign_id' => $validated['campaign_id'] ?? null,
            'channel'     => $service,
            'status'      => 'pending',
            'briefing'    => $validated['briefing'],
            'variations'  => [],
            'expires_at'  => now()->addHours(6),
        ]);

        try {
            $result = $this->grok->generateVariations(
                model: $prompt->model,
                systemPrompt: $prompt->system_prompt,
                userTemplate: $prompt->user_template,
                briefing: $validated['briefing'],
                count: $variationsCount
            );

            $variations = $result['variations'] ?? [];
            $tokensIn   = (int)($result['tokens_input'] ?? 0);
            $tokensOut  = (int)($result['tokens_output'] ?? 0);
            $costUsd    = (float)($result['cost_usd'] ?? 0);

            $gen->update([
                'output'        => $result,
                'tokens_input'  => $tokensIn,
                'tokens_output' => $tokensOut,
                'cost_usd'      => $costUsd,
                'status'        => 'completed',
            ]);

            $session->update([
                'status'     => 'completed',
                'variations' => $variations,
            ]);

            $creditsUsed = (int) $this->settings->getGlobal('billing', 'credits_per_ai_generation', 10);

            Log::channel('ai')->info('ai.generate.ok', ['generation_id' => $generationId, 'count' => count($variations)]);

            return ApiResponse::success([
                'session_id'   => $session->id,
                'generation_id'=> $generationId,
                'credits_used' => $creditsUsed,
                'variations'   => $variations,
            ], 'Geração concluída');
        } catch (\Throwable $e) {
            Log::channel('ai')->error('ai.generate.fail', ['generation_id' => $generationId, 'error' => $e->getMessage()]);
            $gen->update([
                'status' => 'failed',
                'output' => ['error' => $e->getMessage()],
            ]);
            $session->update([
                'status' => 'failed',
            ]);
            return ApiResponse::error('Falha na geração com IA', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Analisa um conteúdo com IA e registra ai_generations.
     */
    public function analyze(Request $request)
    {
        $validated = $request->validate([
            'campaign_id' => ['nullable', 'integer', 'exists:campaigns,id'],
            'channel'     => ['required', 'in:sms,voice,email'],
            'content'     => ['required', 'string'],
        ]);

        $service = $validated['channel'] . '_analysis';

        $prompt = AiPrompt::activeFor($service);
        if (!$prompt) {
            return ApiResponse::error('Prompt de análise não configurado', [], 422);
        }

        $generationId = (string) Str::uuid();
        Log::channel('ai')->info('ai.analyze.start', ['generation_id' => $generationId, 'service' => $service]);

        $gen = AiGeneration::create([
            'generation_id' => $generationId,
            'service'       => $service,
            'prompt_version'=> $prompt->version,
            'input_payload' => $validated,
            'model'         => $prompt->model,
            'status'        => 'pending',
        ]);

        try {
            $result = $this->grok->analyzeContent(
                model: $prompt->model,
                systemPrompt: $prompt->system_prompt,
                userTemplate: $prompt->user_template,
                content: $validated['content'],
            );

            $tokensIn   = (int)($result['tokens_input'] ?? 0);
            $tokensOut  = (int)($result['tokens_output'] ?? 0);
            $costUsd    = (float)($result['cost_usd'] ?? 0);

            $gen->update([
                'output'        => $result,
                'tokens_input'  => $tokensIn,
                'tokens_output' => $tokensOut,
                'cost_usd'      => $costUsd,
                'status'        => 'completed',
            ]);

            Log::channel('ai')->info('ai.analyze.ok', ['generation_id' => $generationId]);

            // Retorna sugestões (se houver); frontend hoje apenas exibe toast
            return ApiResponse::success([
                'generation_id' => $generationId,
                'suggestions'   => $result['suggestions'] ?? [],
            ], 'Análise concluída');
        } catch (\Throwable $e) {
            Log::channel('ai')->error('ai.analyze.fail', ['generation_id' => $generationId, 'error' => $e->getMessage()]);
            $gen->update([
                'status' => 'failed',
                'output' => ['error' => $e->getMessage()],
            ]);
            return ApiResponse::error('Falha na análise com IA', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lista sessões recentes de geração IA do tenant.
     */
    public function sessions(Request $request)
    {
        $items = AiGenerationSession::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (AiGenerationSession $s) {
                $text = null;
                $vars = $s->variations ?? [];
                if (is_array($vars) && count($vars) > 0) {
                    $selected = null;
                    if ($s->selected_variation_id) {
                        $selected = collect($vars)->firstWhere('id', $s->selected_variation_id);
                    }
                    $text = ($selected['text'] ?? null) ?: ($vars[0]['text'] ?? null);
                }
                return [
                    'id'         => $s->id,
                    'channel'    => $s->channel,
                    'status'     => $s->status,
                    'text'       => $text,
                    'created_at' => $s->created_at,
                ];
            });

        return ApiResponse::success($items);
    }
}

