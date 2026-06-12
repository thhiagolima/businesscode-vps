# Prompt — Fase 5: IA

Cole esse prompt no chat do SOLO Coder após aprovação da Fase 4.

---

## Texto do Prompt

```
Leia README.md, .trae/rules.md e as skills antes de começar.
Skills obrigatórias para esta fase:
  - .trae/skills/api-response.md
  - .trae/skills/tabler-ui.md
  - .trae/skills/multitenancy.md
  - .trae/skills/use-api.md
  - .trae/skills/settings-global.md

A Fase 4 foi aprovada. Iniciando Fase 5 — IA.
Trabalhe em ordem. Ao terminar tudo, chame o reviewer antes de entregar.

---

## FASE 5 — IA

### 5.1 Migrations

Criar nesta ordem:

1. create_audio_generations_table
   - id
   - tenant_id → FK tenants, cascadeOnDelete
   - campaign_id → FK campaigns, nullOnDelete, nullable
   - voice_id (string) — voice_id da ElevenLabs (não FK — é string externa)
   - voice_name (string) — nome cached para exibição
   - script (text) — roteiro usado para gerar o áudio
   - audio_path (string nullable) — caminho no storage
   - audio_url (string nullable) — URL pública/assinada
   - duration_seconds (unsignedInt nullable)
   - characters_used (unsignedInt nullable)
   - cost_price (decimal 10,4 default 0) — custo pago à ElevenLabs
   - sale_price (decimal 10,4 default 0) — valor cobrado ao tenant
   - credits_charged (unsignedInt default 0)
   - status (enum: pending/processing/completed/failed, default: pending)
   - error_message (text nullable)
   - timestamps
   - INDEX: tenant_id, campaign_id, status

Rodar: php artisan migrate

---

### 5.2 Model AudioGeneration

fillable: tenant_id, campaign_id, voice_id, voice_name, script,
          audio_path, audio_url, duration_seconds, characters_used,
          cost_price, sale_price, credits_charged, status, error_message
casts: cost_price/sale_price → decimal, status → string
GlobalScope: tenant_id
creating: tenant_id ??= auth()->user()?->tenant_id
belongsTo: campaign, tenant

---

### 5.3 GrokService — Implementação Completa

app/Services/Ai/GrokService.php

Substituir o stub da Fase 2 pela implementação completa:

```php
class GrokService
{
    public function __construct(private SettingsService $settings) {}

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

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
        ])
        ->timeout(30)
        ->post('https://api.x.ai/v1/chat/completions', [
            'model'    => $model ?? 'grok-beta',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMsg],
            ],
            'temperature' => 0.8,
        ]);

        if (!$response->successful()) {
            Log::channel('ai')->error('Grok API error', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("Grok API error {$response->status()}");
        }

        $content = $response->json('choices.0.message.content');

        return [
            'content'       => $content,
            'tokens_input'  => $response->json('usage.prompt_tokens', 0),
            'tokens_output' => $response->json('usage.completion_tokens', 0),
            'model'         => $response->json('model', $model),
        ];
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
        app(CreditService::class)->debit(
            $tenantId,
            10,
            'ai_generation',
            $generation->id,
            "Geração IA — {$generation->service}"
        );
    }

    private function calcCostUsd(
        int    $tokensIn,
        int    $tokensOut,
        string $model
    ): float {
        // Grok-beta pricing (aproximado — atualizar conforme pricing da xAI)
        $rates = [
            'grok-beta'         => ['in' => 0.000005, 'out' => 0.000015],
            'grok-2'            => ['in' => 0.000002, 'out' => 0.000010],
            'grok-vision-beta'  => ['in' => 0.000005, 'out' => 0.000015],
        ];
        $rate = $rates[$model] ?? $rates['grok-beta'];
        return round(($tokensIn * $rate['in']) + ($tokensOut * $rate['out']), 6);
    }
}
```

---

### 5.4 ElevenLabsService — Implementação Completa

app/Services/ElevenLabs/ElevenLabsService.php

Substituir o stub da Fase 2 pela implementação completa:

```php
class ElevenLabsService
{
    public function __construct(private SettingsService $settings) {}

    // ─── Geração de áudio TTS ───────────────────────────────────────

    public function generateAudio(
        string $script,
        string $voiceId,
        int    $campaignId,
        int    $tenantId
    ): AudioGeneration {
        $apiKey  = $this->resolveApiKey();
        $modelId = $this->settings->getGlobal('elevenlabs', 'model_id', 'eleven_multilingual_v2');

        $voice = ElevenLabsVoice::where('voice_id', $voiceId)->firstOrFail();
        $chars = mb_strlen($script);

        // Calcular custo e preço antes de gerar
        $costPerChar  = (float) $this->settings->getGlobal('elevenlabs', 'cost_per_char', '0.0003');
        $salePerChar  = (float) $this->settings->getGlobal('elevenlabs', 'sale_per_char', '0.001');
        $credPerChar  = (int)   $this->settings->getGlobal('elevenlabs', 'credits_per_char', '1');

        $costPrice    = round($chars * $costPerChar, 4);
        $salePrice    = round($chars * $salePerChar, 4);
        $credits      = $chars * $credPerChar;

        // Verificar saldo antes de gerar
        $balance = app(CreditService::class)->checkBalance($tenantId, $credits);
        if (!$balance['allowed']) {
            throw new \RuntimeException(
                "Saldo insuficiente para gerar áudio. Necessário: {$credits} créditos."
            );
        }

        // Criar registro pending
        $generation = AudioGeneration::create([
            'tenant_id'        => $tenantId,
            'campaign_id'      => $campaignId,
            'voice_id'         => $voiceId,
            'voice_name'       => $voice->name,
            'script'           => $script,
            'characters_used'  => $chars,
            'cost_price'       => $costPrice,
            'sale_price'       => $salePrice,
            'credits_charged'  => $credits,
            'status'           => 'processing',
        ]);

        try {
            // Chamar API ElevenLabs
            $response = Http::withHeaders([
                'xi-api-key'   => $apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'audio/mpeg',
            ])
            ->timeout(60)
            ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}", [
                'text'     => $script,
                'model_id' => $modelId,
                'voice_settings' => [
                    'stability'        => 0.5,
                    'similarity_boost' => 0.75,
                ],
            ]);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    "ElevenLabs error {$response->status()}: " . $response->body()
                );
            }

            // Salvar áudio no storage
            $filename = "audio/{$tenantId}/{$generation->id}.mp3";
            Storage::put($filename, $response->body());

            // Gerar URL pública temporária (24h)
            $audioUrl = Storage::temporaryUrl($filename, now()->addHours(24));

            // Estimar duração (aprox: 150 palavras/min)
            $wordCount = str_word_count($script);
            $duration  = (int) ceil(($wordCount / 150) * 60);

            $generation->update([
                'audio_path'       => $filename,
                'audio_url'        => $audioUrl,
                'duration_seconds' => $duration,
                'status'           => 'completed',
            ]);

            // Debitar créditos
            app(CreditService::class)->debit(
                $tenantId,
                $credits,
                'audio_generation',
                $generation->id,
                "Geração de áudio — {$chars} caracteres"
            );

            Log::channel('elevenlabs')->info('audio.generated', [
                'generation_id' => $generation->id,
                'chars'         => $chars,
                'duration'      => $duration,
                'cost_usd'      => $costPrice,
            ]);

            return $generation;

        } catch (\Throwable $e) {
            $generation->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('elevenlabs')->error('audio.failed', [
                'generation_id' => $generation->id,
                'error'         => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ─── Listar vozes disponíveis ───────────────────────────────────

    public function getAvailableVoices(?string $language = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ElevenLabsVoice::where('is_active', true)
            ->orderBy('language')
            ->orderBy('name');

        if ($language) {
            $query->where('language', $language);
        }

        return $query->get();
    }

    // ─── Renovar URL do áudio (expira em 24h) ───────────────────────

    public function refreshAudioUrl(AudioGeneration $generation): string
    {
        if (!$generation->audio_path) {
            throw new \RuntimeException('Arquivo de áudio não encontrado.');
        }

        $url = Storage::temporaryUrl(
            $generation->audio_path,
            now()->addHours(24)
        );

        $generation->update(['audio_url' => $url]);
        return $url;
    }

    // ─── Helpers ────────────────────────────────────────────────────

    private function resolveApiKey(): string
    {
        $key = $this->settings->getGlobal('elevenlabs', 'api_key');
        if (empty($key)) {
            throw new ElevenLabsNotConfiguredException('ElevenLabs API Key não configurada.');
        }
        return $key;
    }

    // fetchVoices e syncVoices já implementados na Fase 2
}
```

---

### 5.5 Controllers

#### AiGeneratorController
Rotas: /api/v1/ai — auth:sanctum

POST /ai/generate   → generate()
POST /ai/analyze    → analyze()
GET  /ai/prompts    → prompts() — apenas superadmin

generate():
  Validar:
    campaign_id (required, exists:campaigns,id)
    channel (required, in:sms,voice,email)
    briefing (required, array)
    briefing.product (required string)
    briefing.audience (required string)
    briefing.benefit (required string)
    briefing.cta (required string)
    briefing.tone (required, in:professional,casual,urgent,inspiring,fun,direct)
    briefing.link (nullable url)
    briefing.avoid (nullable array)
    briefing.max_chars (nullable integer min:1)
    variations (required, integer, min:1, max:5)

  Verificar campaign pertence ao tenant:
    Campaign::findOrFail($campaignId) — GlobalScope cuida

  Verificar saldo mínimo:
    CreditService::checkBalance(tenantId, 10)
    Se !allowed: ApiResponse::error('Créditos insuficientes.', [], 402)

  Criar AiGenerationSession:
    tenant_id, campaign_id, channel, briefing (json), status=pending

  Chamar GrokService::generateVariations()

  Atualizar session: status=completed, variations (json)

  Retornar:
    session_id, generation_id, variations[], credits_used

analyze():
  Validar:
    campaign_id (required, exists)
    channel (required, in:sms,voice,email)
    content (required, string, min:10, max:5000)

  Verificar saldo (10 créditos)
  Chamar GrokService::analyzeContent()
  Retornar: generation_id, analysis{}, improved_versions[], credits_used

#### AudioGenerationController
Rotas: /api/v1/ — auth:sanctum

POST /campaigns/{campaignId}/audio     → generate()
GET  /campaigns/{campaignId}/audio     → index()
GET  /audio/{id}                       → show()
GET  /audio/{id}/refresh-url           → refreshUrl()

generate():
  Validar:
    script (required, string, min:10, max:2000)
    voice_id (required, string, exists:elevenlabs_voices,voice_id)

  Verificar campaign pertence ao tenant (GlobalScope)
  Verificar campaign.type = 'voice':
    Se não: ApiResponse::error('Apenas campanhas de voz podem gerar áudio.', [], 422)
  Verificar campaign.status = 'draft':
    Se não: ApiResponse::error('Apenas rascunhos podem gerar áudio.', [], 422)

  Chamar ElevenLabsService::generateAudio()

  Após geração, atualizar campanha:
    campaign->update(['audio_url' => $generation->audio_url])

  Retornar: AudioGeneration completo

index():
  Retorna all AudioGenerations da campanha (ordenado por created_at DESC)

refreshUrl():
  Chama ElevenLabsService::refreshAudioUrl()
  Retorna nova URL

#### AiContentModelController
Rotas: /api/v1/ai/models — auth:sanctum

GET    /ai/models         → index()
POST   /ai/models         → store()
GET    /ai/models/{id}    → show()
DELETE /ai/models/{id}    → destroy()

index():
  Retorna modelos do tenant
  Filtro: ?channel=sms
  Ordenado por created_at DESC

store():
  Validar: name (required), channel (required), content (required), briefing (nullable)
  Criar AiContentModel

destroy():
  Verificar pertence ao tenant (GlobalScope)
  Deletar

---

### 5.6 Rotas API

Em routes/api.php, dentro de auth:sanctum:

Route::post('ai/generate',  [AiGeneratorController::class, 'generate']);
Route::post('ai/analyze',   [AiGeneratorController::class, 'analyze']);

Route::get('ai/models',         [AiContentModelController::class, 'index']);
Route::post('ai/models',        [AiContentModelController::class, 'store']);
Route::get('ai/models/{id}',    [AiContentModelController::class, 'show']);
Route::delete('ai/models/{id}', [AiContentModelController::class, 'destroy']);

Route::post('campaigns/{id}/audio',         [AudioGenerationController::class, 'generate']);
Route::get('campaigns/{id}/audio',          [AudioGenerationController::class, 'index']);
Route::get('audio/{id}',                    [AudioGenerationController::class, 'show']);
Route::get('audio/{id}/refresh-url',        [AudioGenerationController::class, 'refreshUrl']);

Route::get('campaigns/{id}/ai-sessions',    [CampaignController::class, 'aiSessions']);

---

### 5.7 Frontend — Stores

stores/ai.ts:
  state: sessions, models, isGenerating, isAnalyzing, isGeneratingAudio
  actions:
    generate(payload)            → POST /ai/generate
    analyze(payload)             → POST /ai/analyze
    fetchSessions(campaignId)    → GET /campaigns/{id}/ai-sessions
    fetchModels(channel?)        → GET /ai/models
    saveModel(data)              → POST /ai/models
    deleteModel(id)              → DELETE /ai/models/{id}
    generateAudio(campaignId, data) → POST /campaigns/{id}/audio
    fetchAudio(campaignId)       → GET /campaigns/{id}/audio
    refreshAudioUrl(id)          → GET /audio/{id}/refresh-url

---

### 5.8 Frontend — Completar Step 2 do Wizard (canal Voz)

Substituir o placeholder de voz no Step2Content.vue
pela implementação completa de VoiceStudio.vue:

#### components/campaigns/steps/VoiceStudio.vue

Layout em 2 colunas: col-7 (esquerda: roteiro) + col-5 (direita: voz + preview)

Coluna esquerda — "Roteiro":

  Toggle igual ao Step2Content:
    ● Gerar roteiro com IA
    ● Escrever manualmente

  Modo IA (roteiro):
    Mesmo formulário de briefing do AiMode.vue
    Ao gerar: chama /ai/generate com channel='voice'
    Exibe variações de ROTEIRO (não de mensagem SMS)
    Hint abaixo do form:
      "O roteiro será convertido em áudio. Ideal: 30-60 segundos (75-150 palavras)."
    Contador de palavras: "X palavras ≈ Y segundos"

  Modo manual (roteiro):
    Textarea com contador de palavras
    Estimativa de duração abaixo: "≈ X segundos"
    Botão "Analisar com IA" (mesmo AnalysisModal)

Coluna direita — "Voz e Geração":

  Card "Escolher voz":
    Select de vozes (ElevenLabsVoice):
      Carrega GET /admin/settings/elevenlabs/voices
      Agrupado por idioma: 🇧🇷 Português | 🇺🇸 Inglês | 🇪🇸 Espanhol
      Cada option: nome + gênero badge
    Botão preview: ▶ toca preview_url via <audio>
    Preview player compacto sob o select

  Estimativa de custo (atualiza em tempo real ao digitar roteiro):
  ```html
  <div class="card bg-light border-0 mt-3">
    <div class="card-body py-2">
      <div class="d-flex justify-content-between small">
        <span class="text-muted">Caracteres</span>
        <span>{{ charCount }}</span>
      </div>
      <div class="d-flex justify-content-between small">
        <span class="text-muted">Custo estimado</span>
        <span>{{ creditsEstimate }} créditos</span>
      </div>
      <div class="d-flex justify-content-between small">
        <span class="text-muted">Duração estimada</span>
        <span>≈ {{ durationEstimate }}s</span>
      </div>
    </div>
  </div>
  ```

  Botão gerar áudio:
  ```html
  <button class="btn btn-primary w-100 mt-3"
    :disabled="!script || !selectedVoice || isGeneratingAudio"
    @click="generateAudio">
    <span v-if="isGeneratingAudio"
      class="spinner-border spinner-border-sm me-2"></span>
    <i v-else class="ti ti-microphone me-2"></i>
    {{ isGeneratingAudio ? 'Gerando áudio...' : 'Gerar áudio' }}
  </button>
  ```

  Card "Áudio gerado" (após geração bem-sucedida):
  ```html
  <div class="card border-success mt-3" v-if="latestAudio">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="badge bg-success">
          <i class="ti ti-check me-1"></i>Áudio gerado
        </span>
        <span class="text-muted small">
          {{ latestAudio.duration_seconds }}s ·
          {{ latestAudio.characters_used }} chars ·
          {{ latestAudio.credits_charged }} créditos
        </span>
      </div>
      <audio controls :src="latestAudio.audio_url" class="w-100"></audio>
      <div class="d-flex gap-2 mt-2">
        <button class="btn btn-sm btn-ghost-secondary"
          @click="refreshUrl">
          <i class="ti ti-refresh me-1"></i>Renovar URL
        </button>
        <button class="btn btn-sm btn-ghost-secondary"
          @click="saveAsModel({ text: script })">
          <i class="ti ti-bookmark me-1"></i>Salvar roteiro
        </button>
      </div>
    </div>
  </div>
  ```

  Histórico de áudios gerados (acordeão compacto):
  ```html
  <div class="mt-3" v-if="audioHistory.length > 1">
    <div class="text-muted small mb-2">Gerações anteriores</div>
    <div v-for="audio in audioHistory.slice(1)" :key="audio.id"
      class="d-flex align-items-center gap-2 mb-2 p-2 rounded border">
      <audio :src="audio.audio_url" style="height:28px; flex:1"></audio>
      <span class="text-muted small">{{ audio.duration_seconds }}s</span>
      <button class="btn btn-ghost-secondary btn-sm btn-icon"
        @click="useAudio(audio)">
        <i class="ti ti-check"></i>
      </button>
    </div>
  </div>
  ```

useAudio(audio):
  wizard.saveField('audio_url', audio.audio_url)
  toast.success('Áudio selecionado para a campanha.')

---

### 5.9 Frontend — Página de Modelos Salvos

#### pages/ai/Index.vue

Page header: "Gerador de Conteúdo"

Tabs Tabler:
  [Modelos salvos] [Histórico de gerações]

Tab "Modelos Salvos":

  Filtro por canal (segmented control): Todos | SMS | Voz | Email

  Grid de cards (row-cards):
  ```html
  <div class="col-sm-6 col-lg-4"
    v-for="model in filteredModels" :key="model.id">
    <div class="card h-100">
      <div class="card-header">
        <div class="d-flex align-items-center gap-2 w-100">
          <span class="badge"
            :class="channelBadge(model.channel)">
            {{ channelLabel(model.channel) }}
          </span>
          <span class="fw-medium text-truncate">{{ model.name }}</span>
          <div class="ms-auto dropdown">
            <button class="btn btn-ghost-secondary btn-sm btn-icon"
              data-bs-toggle="dropdown">
              <i class="ti ti-dots-vertical"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end">
              <a class="dropdown-item" @click="copyContent(model)">
                <i class="ti ti-copy me-2"></i>Copiar conteúdo
              </a>
              <a class="dropdown-item" @click="useInNewCampaign(model)">
                <i class="ti ti-speakerphone me-2"></i>Usar em nova campanha
              </a>
              <div class="dropdown-divider"></div>
              <a class="dropdown-item text-danger" @click="deleteModel(model.id)">
                <i class="ti ti-trash me-2"></i>Excluir
              </a>
            </div>
          </div>
        </div>
      </div>
      <div class="card-body">
        <p class="text-muted small mb-0"
          style="white-space: pre-wrap; display: -webkit-box;
                 -webkit-line-clamp: 4; -webkit-box-orient: vertical;
                 overflow: hidden">
          {{ model.content }}
        </p>
      </div>
      <div class="card-footer text-muted small">
        <i class="ti ti-calendar me-1"></i>
        {{ formatDate(model.created_at) }}
      </div>
    </div>
  </div>
  ```

  Empty state: ti-bookmark, "Nenhum modelo salvo ainda"
  Hint: "Salve variações geradas pela IA para reutilizar em futuras campanhas."

  useInNewCampaign(model):
    Abre ChannelPickerModal já com o canal pre-selecionado (read-only)
    Ao criar campanha, redireciona para wizard e preenche o conteúdo com model.content

Tab "Histórico de Gerações":
  Tabela: Data | Canal | Tipo | Status | Tokens | Custo USD | Créditos usados
  Carrega de GET /ai/generations (novo endpoint — listar ai_generations do tenant)
  Badge de status: completed=success, failed=danger, pending=warning
  Paginado (20/página)

---

### 5.10 Endpoint adicional

GET /api/v1/ai/generations
Responsável: AiGeneratorController@history

Retorna: ai_generations do tenant, paginado (20/página), ordenado created_at DESC
Filtros: ?service=sms&status=completed
Campos: id, generation_id, service, prompt_version, tokens_input,
        tokens_output, cost_usd, credits_used (sempre 10), status, created_at

Adicionar rota:
  Route::get('ai/generations', [AiGeneratorController::class, 'history']);

---

### 5.11 Seeder — Prompts de Voz e Email

Adicionar ao AiPromptsSeeder os prompts que faltam:

Voice Script (service='voice_script', version='v1', is_active=true):
  system_prompt: "Você é um especialista em marketing de voz e locuções
    comerciais. Crie roteiros naturais, adequados para síntese de voz,
    com frases curtas e pausas naturais. Evite pontuação excessiva.
    Ideal: 75-150 palavras (30-60 segundos)."
  user_template: igual ao SMS mas sem limite de chars,
    com instrução: "Gere roteiros adequados para torpedo de voz."

Voice Analysis (service='voice_analysis', version='v1', is_active=true):
  system_prompt: "Você analisa roteiros de torpedo de voz."
  user_template: igual ao sms_analysis mas adaptado para voz.

Email Generation (service='email', version='v1', is_active=true):
  system_prompt: "Você é especialista em email marketing. Crie
    emails com assunto atraente e corpo persuasivo em HTML simples.
    Evite spam triggers. Use CTAs claros."
  user_template: igual ao SMS com campos adicionais:
    {{subject_hint}} para sugestão de assunto.
    Retornar JSON: { "variations": [{"subject":"...","text":"..."}] }

Email Analysis (service='email_analysis', version='v1', is_active=true):
  Adaptar análise para email (subject + body).

Rodar: php artisan db:seed --class=AiPromptsSeeder

---

### 5.12 Revisão

Chamar agent `reviewer` com escopo da Fase 5:

GrokService:
  - registerGeneration() chamado ANTES da API (status=pending)
  - completeGeneration() atualiza tokens + cost após resposta
  - failGeneration() em todo catch
  - parseVariations() e parseAnalysis() tratam JSON inválido
  - calcCostUsd() usa taxas por modelo

ElevenLabsService:
  - Saldo verificado ANTES de gerar (não depois)
  - Áudio salvo em storage (não em public/)
  - URL temporária (24h) gerada após salvar
  - Créditos debitados APÓS geração bem-sucedida
  - generation.status=failed em todo catch

Frontend:
  - isGeneratingAudio bloqueia botão (sem duplo clique)
  - refreshUrl() chamado quando audio_url expirar (404 no player)
  - Estimativa de custo atualiza em tempo real (computed)
  - useInNewCampaign() pré-preenche conteúdo no wizard

Multitenancy:
  - AudioGeneration tem GlobalScope + creating hook
  - AiContentModel tem GlobalScope + creating hook
  - history() endpoint filtra por tenant_id (GlobalScope)

Apresentar checklist preenchido antes de entregar.

---

## Entrega da Fase 5

Liste todos os arquivos criados/modificados.
Informe comandos a rodar (migrate, seed).
Aguarde aprovação para iniciar a Fase 6.
```