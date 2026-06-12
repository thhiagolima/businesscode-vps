<?php

namespace App\Services\Ai;

use App\Exceptions\AiNotConfiguredException;
use App\Models\AiGeneration;
use App\Models\AiPrompt;
use App\Services\Billing\BillingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GrokService
{
    public function __construct(private SettingsService $settings) {}

    // ─── Teste de conexão e listagem de modelos ─────────────────────

    public function testConnection(?string $apiKey = null): array
    {
        $key = $apiKey ?: $this->settings->getGlobal('ai', 'grok_api_key');
        if (empty($key)) {
            return ['ok' => false, 'error' => 'API Key não configurada'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
            ])->timeout(10)->get('https://api.x.ai/v1/models');

            if ($response->successful()) {
                return ['ok' => true, 'models' => collect($response->json('data', []))->pluck('id')->values()->toArray()];
            }

            return ['ok' => false, 'error' => 'HTTP ' . $response->status() . ': ' . $response->body()];
        } catch (\Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function listModels(): array
    {
        $result = $this->testConnection();
        return $result['ok'] ? ($result['models'] ?? []) : [];
    }

    // ─── Geração de variações ───────────────────────────────────────

    public function generateVariations(
        string $channel,
        array  $briefing,
        int    $variations,
        int    $tenantId
    ): array {
        $prompt = $this->loadPrompt($channel);
        $userMsg = $this->composeUserMessage($prompt->user_template, [
            ...$briefing,
            'variations' => $variations,
        ]);

        $generation = $this->registerGeneration(
            $tenantId, $channel, $prompt->version,
            ['channel' => $channel, 'briefing' => $briefing, 'variations' => $variations]
        );

        try {
            $response = $this->callApi($prompt->system_prompt, $userMsg, $prompt->model);
            $parsed   = $this->parseVariations($response['content']);

            $this->completeGeneration($generation, $response, $parsed);
            $this->debitCredits($tenantId, $generation);

            return [
                'generation_id'  => $generation->generation_id,
                'prompt_version' => $prompt->version,
                'variations'     => $parsed,
                'credits_used'   => 10,
            ];
        } catch (\Throwable $e) {
            $this->failGeneration($generation, $e);
            throw $e;
        }
    }

    // ─── Análise e melhoria de mensagem ────────────────────────────

    public function analyzeContent(
        string $channel,
        string $content,
        int    $tenantId
    ): array {
        $service = "{$channel}_analysis";
        $prompt  = $this->loadPrompt($service);
        $userMsg = $this->composeUserMessage($prompt->user_template, [
            'content' => $content,
        ]);

        $generation = $this->registerGeneration(
            $tenantId, $service, $prompt->version,
            ['channel' => $channel, 'content' => $content]
        );

        try {
            $response = $this->callApi($prompt->system_prompt, $userMsg, $prompt->model);
            $parsed   = $this->parseAnalysis($response['content']);

            $this->completeGeneration($generation, $response, $parsed);
            $this->debitCredits($tenantId, $generation);

            return [
                'generation_id'    => $generation->generation_id,
                'analysis'         => $parsed['analysis'],
                'improved_versions'=> $parsed['improved_versions'],
                'credits_used'     => 10,
            ];
        } catch (\Throwable $e) {
            $this->failGeneration($generation, $e);
            throw $e;
        }
    }

    // ─── Geração de roteiro de voz ──────────────────────────────────

    public function generateVoiceScript(
        array $briefing,
        int   $tenantId
    ): array {
        $prompt  = $this->loadPrompt('voice_script');
        $userMsg = $this->composeUserMessage($prompt->user_template, $briefing);
        $generation = $this->registerGeneration(
            $tenantId, 'voice_script', $prompt->version, $briefing
        );

        try {
            $response = $this->callApi($prompt->system_prompt, $userMsg, $prompt->model);
            $parsed   = $this->parseVariations($response['content']);

            $this->completeGeneration($generation, $response, $parsed);
            $this->debitCredits($tenantId, $generation);

            return [
                'generation_id'  => $generation->generation_id,
                'variations'     => $parsed,
                'credits_used'   => 10,
            ];
        } catch (\Throwable $e) {
            $this->failGeneration($generation, $e);
            throw $e;
        }
    }

    // ─── Helpers privados ───────────────────────────────────────────

    private function loadPrompt(string $service): AiPrompt
    {
        $prompt = AiPrompt::where('service', $service)
            ->where('is_active', true)
            ->first();

        if (!$prompt) {
            throw new \RuntimeException("Prompt não encontrado para: {$service}");
        }

        return $prompt;
    }

    private function composeUserMessage(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $template = str_replace("{{{$key}}}", (string) $value, $template);
        }
        // Remove blocos condicionais não preenchidos: {{#key}}...{{/key}}
        $template = preg_replace('/\{\{#\w+\}\}.*?\{\{\/\w+\}\}/s', '', $template);
        return trim($template);
    }

    private function callApi(string $systemPrompt, string $userMsg, string $model): array
    {
        $apiKey = $this->settings->getGlobal('ai', 'grok_api_key');
        if (empty($apiKey)) {
            throw new AiNotConfiguredException('Grok API Key não configurada.');
        }

        $this->checkCircuitBreaker('grok');
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
            ])
            ->timeout(30)
            ->post('https://api.x.ai/v1/chat/completions', [
                'model'    => $model ?? 'grok-3-mini',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userMsg],
                ],
                'temperature' => 0.8,
            ]);

            if (!$response->successful()) {
                $body     = $response->body();
                $errorMsg = $response->json('error.message') ?? $body;
                Log::channel('ai')->error('Grok API error', [
                    'status' => $response->status(),
                    'error'  => $errorMsg,
                    'model'  => $model,
                ]);
                $this->recordFailure('grok');
                throw new \RuntimeException("Grok API error {$response->status()}: {$errorMsg}");
            }

            $this->recordSuccess('grok');
            $content = $response->json('choices.0.message.content');

            return [
                'content'       => $content,
                'tokens_input'  => $response->json('usage.prompt_tokens', 0),
                'tokens_output' => $response->json('usage.completion_tokens', 0),
                'model'         => $response->json('model', $model),
            ];
        } catch (\Throwable $e) {
            $this->recordFailure('grok');
            throw $e;
        }
    }

    // ─── Circuit Breaker ───────────────────────────────────────────

    private function checkCircuitBreaker(string $service): void
    {
        $key      = "circuit_breaker_{$service}";
        $failures = (int) Cache::get($key, 0);
        if ($failures >= 5) {
            $until = Cache::get("{$key}_until");
            if ($until && now()->lt($until)) {
                throw new \RuntimeException(
                    "Serviço {$service} temporariamente indisponível (circuit breaker aberto). Tente novamente em alguns minutos."
                );
            }
            Cache::forget($key);
        }
    }

    private function recordFailure(string $service): void
    {
        $key      = "circuit_breaker_{$service}";
        $failures = (int) Cache::increment($key);
        if ($failures >= 5) {
            Cache::put("{$key}_until", now()->addMinutes(2), 300);
        }
        Cache::put($key, $failures, 600);
    }

    private function recordSuccess(string $service): void
    {
        Cache::forget("circuit_breaker_{$service}");
    }

    private function parseVariations(string $raw): array
    {
        $clean = preg_replace('/```json|```/','', $raw);
        $data  = json_decode(trim($clean), true);

        if (!isset($data['variations'])) {
            throw new \RuntimeException('Resposta Grok inválida: sem variações.');
        }

        return array_map(fn($v) => [
            'id'    => (string) \Str::uuid(),
            'text'  => $v['text'],
            'chars' => mb_strlen($v['text']),
            'tone'  => $v['tone'] ?? null,
        ], $data['variations']);
    }

    private function parseAnalysis(string $raw): array
    {
        $clean = preg_replace('/```json|```/', '', $raw);
        $data  = json_decode(trim($clean), true);

        if (!isset($data['analysis'], $data['improved_versions'])) {
            throw new \RuntimeException('Resposta Grok inválida: sem análise.');
        }

        $data['improved_versions'] = array_map(fn($v) => [
            'id'     => (string) \Str::uuid(),
            'text'   => $v['text'],
            'chars'  => mb_strlen($v['text']),
            'change' => $v['change'] ?? 'Versão melhorada',
        ], $data['improved_versions']);

        return $data;
    }

    private function registerGeneration(
        int    $tenantId,
        string $service,
        string $promptVersion,
        array  $inputPayload
    ): AiGeneration {
        return AiGeneration::create([
            'tenant_id'       => $tenantId,
            'generation_id'   => (string) \Str::uuid(),
            'service'         => $service,
            'prompt_version'  => $promptVersion,
            'input_payload'   => $inputPayload,
            'status'          => 'pending',
        ]);
    }

    private function completeGeneration(
        AiGeneration $generation,
        array $response,
        mixed $output
    ): void {
        $generation->update([
            'output'         => $output,
            'tokens_input'   => $response['tokens_input'],
            'tokens_output'  => $response['tokens_output'],
            'cost_usd'       => $this->calcCostUsd(
                $response['tokens_input'],
                $response['tokens_output'],
                $response['model']
            ),
            'model'          => $response['model'],
            'status'         => 'completed',
        ]);
    }

    private function failGeneration(AiGeneration $generation, \Throwable $e): void
    {
        $generation->update(['status' => 'failed']);
        Log::channel('ai')->error('generation.failed', [
            'generation_id' => $generation->generation_id,
            'error'         => $e->getMessage(),
        ]);
    }

    private function debitCredits(int $tenantId, AiGeneration $generation): void
    {
        // AI generation cost (cents) — placeholder until ServicePrice 'ai_*' lookup is wired
        app(BillingService::class)->reserve(
            $tenantId,
            10,
            'ai_generation',
            $generation->id
        );
    }

    private function calcCostUsd(
        int    $tokensIn,
        int    $tokensOut,
        string $model
    ): float {
        // Grok-beta pricing (aproximado — atualizar conforme pricing da xAI)
        $rates = [
            'grok-3-mini'         => ['in' => 0.000005, 'out' => 0.000015],
            'grok-2'            => ['in' => 0.000002, 'out' => 0.000010],
            'grok-vision-beta'  => ['in' => 0.000005, 'out' => 0.000015],
        ];
        $rate = $rates[$model] ?? $rates['grok-3-mini'];
        return round(($tokensIn * $rate['in']) + ($tokensOut * $rate['out']), 6);
    }
}

