# AI Conversational Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement AI-powered chat replies via Grok with configurable persona (approved by superadmin) and two modes: autonomous (bot replies directly) and suggestion (operator confirms).

**Architecture:** New `ChatAiService` handles Grok API calls for conversations. `AiPersona` model stores structured persona per tenant with approval workflow. `ProcessInboundMessageJob` is upgraded from stub to real logic that routes through ChatAiService. Frontend gets chatbot settings page, admin approval page, and activates the AI suggestion placeholder in the Inbox.

**Tech Stack:** Laravel 12 (backend), Vue 3 + Tabler UI (frontend), Grok API (xAI)

**Spec:** `docs/superpowers/specs/2026-03-22-ai-conversational-design.md`

---

## File Structure

### Backend — Create
| File | Responsibility |
|------|---------------|
| `backend/database/migrations/2026_03_22_200000_create_ai_personas_table.php` | Persona table |
| `backend/app/Models/AiPersona.php` | Model with tenant scope |
| `backend/app/Services/Ai/ChatAiService.php` | Grok conversational AI |
| `backend/app/Http/Controllers/API/V1/ChatbotController.php` | Tenant persona + settings |
| `backend/app/Http/Controllers/API/V1/Admin/AiPersonaAdminController.php` | Superadmin approval |

### Backend — Modify
| File | Change |
|------|--------|
| `backend/app/Jobs/ProcessInboundMessageJob.php` | Replace stub with real logic |
| `backend/routes/api.php:95-106,107-126` | Add chatbot + admin persona routes |
| `backend/database/seeders/GlobalSettingsSeeder.php:32` | Add credits_per_ai_chat |

### Frontend — Create
| File | Responsibility |
|------|---------------|
| `frontend/src/pages/chatbot/Settings.vue` | Tenant persona config + mode toggle |
| `frontend/src/pages/admin/AiPersonas.vue` | Superadmin approval page |

### Frontend — Modify
| File | Change |
|------|--------|
| `frontend/src/components/conversations/ConversationChat.vue:52-57` | Activate AI suggestion |
| `frontend/src/components/layout/AppSidebar.vue:44-46` | Add Chatbot nav item |
| `frontend/src/router/index.ts` | Add chatbot + admin persona routes |

---

## Task 1: Migration + Model — AiPersona

**Files:**
- Create: `backend/database/migrations/2026_03_22_200000_create_ai_personas_table.php`
- Create: `backend/app/Models/AiPersona.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_personas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('bot_name', 50);
            $table->enum('tone', ['formal', 'casual', 'friendly'])->default('friendly');
            $table->string('company_name', 100);
            $table->text('products_services');
            $table->text('business_rules');
            $table->text('special_instructions')->nullable();
            $table->string('working_hours', 200)->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id');
            $table->index('status');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_personas');
    }
};
```

- [ ] **Step 2: Create AiPersona model**

```php
<?php

namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;

class AiPersona extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'bot_name', 'tone', 'company_name',
        'products_services', 'business_rules', 'special_instructions',
        'working_hours', 'status', 'rejection_reason',
        'approved_by', 'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
```

- [ ] **Step 3: Run migration**

Run: `cd backend && php artisan migrate`
Expected: 1 migration executed

- [ ] **Step 4: Verify syntax**

Run: `php -l app/Models/AiPersona.php`

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_03_22_200000_create_ai_personas_table.php backend/app/Models/AiPersona.php
git commit -m "feat: add AiPersona migration and model"
```

---

## Task 2: ChatAiService

**Files:**
- Create: `backend/app/Services/Ai/ChatAiService.php`
- Modify: `backend/database/seeders/GlobalSettingsSeeder.php:32`

- [ ] **Step 1: Add credits_per_ai_chat to seeder**

In `GlobalSettingsSeeder.php`, add after line 32 (after `credits_per_whatsapp`):

```php
            ['billing',     'credits_per_ai_chat',      '2',                       'string'],
```

- [ ] **Step 2: Create ChatAiService**

```php
<?php

namespace App\Services\Ai;

use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Billing\CreditService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatAiService
{
    public function __construct(
        private SettingsService $settings,
        private CreditService $credits
    ) {}

    /**
     * Gera resposta da IA para uma conversa.
     *
     * @return array{ok: bool, reply: string|null, error: string|null, credits_used: int}
     */
    public function generateReply(Conversation $conversation, int $tenantId): array
    {
        try {
            // 1. Carregar persona aprovada
            $persona = AiPersona::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', 'approved')
                ->first();

            if (! $persona) {
                return ['ok' => false, 'reply' => null, 'error' => 'Persona não configurada ou não aprovada', 'credits_used' => 0];
            }

            // 2. Carregar últimas 10 mensagens
            $recentMessages = ConversationMessage::withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->reverse()
                ->values();

            if ($recentMessages->isEmpty()) {
                return ['ok' => false, 'reply' => null, 'error' => 'Nenhuma mensagem na conversa', 'credits_used' => 0];
            }

            // 3. Montar system prompt
            $systemPrompt = $this->buildSystemPrompt($persona);

            // 4. Formatar mensagens como chat history
            $messages = [['role' => 'system', 'content' => $systemPrompt]];

            foreach ($recentMessages as $msg) {
                $role = $msg->direction === 'inbound' ? 'user' : 'assistant';
                if ($msg->content) {
                    $messages[] = ['role' => $role, 'content' => $msg->content];
                }
            }

            // 5. Chamar Grok API
            $apiKey = $this->settings->getGlobal('ai', 'grok_api_key', '');
            $model  = $this->settings->getGlobal('ai', 'grok_model', 'grok-beta');

            if (empty($apiKey)) {
                return ['ok' => false, 'reply' => null, 'error' => 'API key de IA não configurada', 'credits_used' => 0];
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type'  => 'application/json',
            ])
            ->timeout(30)
            ->post('https://api.x.ai/v1/chat/completions', [
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => 0.7,
                'max_tokens'  => 300,
            ]);

            if (! $response->successful()) {
                $error = $response->json('error.message', $response->body());
                Log::channel('ai')->warning('chat.api_failed', ['tenant' => $tenantId, 'error' => $error]);
                return ['ok' => false, 'reply' => null, 'error' => $error, 'credits_used' => 0];
            }

            $reply = $response->json('choices.0.message.content', '');
            $tokensIn  = $response->json('usage.prompt_tokens', 0);
            $tokensOut = $response->json('usage.completion_tokens', 0);

            // 6. Debitar créditos
            $creditsAmount = (int) $this->settings->getGlobal('billing', 'credits_per_ai_chat', '2');

            $this->credits->debit(
                $tenantId,
                $creditsAmount,
                'ai_chat',
                $conversation->id,
                "Chat IA — conversa #{$conversation->id}"
            );

            Log::channel('ai')->info('chat.reply_generated', [
                'tenant'       => $tenantId,
                'conversation' => $conversation->id,
                'tokens_in'    => $tokensIn,
                'tokens_out'   => $tokensOut,
                'credits'      => $creditsAmount,
                'reply_length' => strlen($reply),
            ]);

            return ['ok' => true, 'reply' => $reply, 'error' => null, 'credits_used' => $creditsAmount];

        } catch (\Throwable $e) {
            Log::channel('ai')->error('chat.exception', [
                'tenant'       => $tenantId,
                'conversation' => $conversation->id,
                'error'        => $e->getMessage(),
            ]);
            return ['ok' => false, 'reply' => null, 'error' => $e->getMessage(), 'credits_used' => 0];
        }
    }

    private function buildSystemPrompt(AiPersona $persona): string
    {
        $toneMap = ['formal' => 'Formal e profissional', 'casual' => 'Casual e descontraído', 'friendly' => 'Amigável e acolhedor'];
        $tone = $toneMap[$persona->tone] ?? $persona->tone;

        $prompt = "Você é {$persona->bot_name}, assistente virtual da empresa {$persona->company_name}.\n\n";
        $prompt .= "Tom: {$tone}\n";
        $prompt .= "Produtos/Serviços: {$persona->products_services}\n";

        if ($persona->working_hours) {
            $prompt .= "Horário de funcionamento: {$persona->working_hours}\n";
        }

        $prompt .= "\nRegras de negócio:\n{$persona->business_rules}\n";

        if ($persona->special_instructions) {
            $prompt .= "\nInstruções especiais:\n{$persona->special_instructions}\n";
        }

        $prompt .= "\nIMPORTANTE: Responda de forma concisa (máximo 500 caracteres). Não invente informações que não estão nas regras acima. Se não souber responder, diga que vai encaminhar para um atendente humano.";

        return $prompt;
    }
}
```

- [ ] **Step 3: Verify syntax + run seeder**

Run: `php -l app/Services/Ai/ChatAiService.php && php artisan db:seed --class=GlobalSettingsSeeder`

- [ ] **Step 4: Commit**

```bash
git add backend/app/Services/Ai/ChatAiService.php backend/database/seeders/GlobalSettingsSeeder.php
git commit -m "feat: add ChatAiService for conversational AI via Grok"
```

---

## Task 3: ProcessInboundMessageJob — replace stub

**Files:**
- Modify: `backend/app/Jobs/ProcessInboundMessageJob.php`

- [ ] **Step 1: Replace entire file**

```php
<?php

namespace App\Jobs;

use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Ai\ChatAiService;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        public int $tenantId
    ) {}

    public function handle(
        ChatAiService $ai,
        WhatsAppService $whatsapp,
        SettingsService $settings
    ): void {
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($this->conversationId);

        // Só processa se conversa está em modo bot
        if ($conversation->status !== 'bot') {
            Log::channel('whatsapp')->debug('inbound.skip_not_bot', [
                'conversation' => $this->conversationId,
                'status'       => $conversation->status,
            ]);
            return;
        }

        // Verificar se chatbot está habilitado
        $enabled = $settings->get($this->tenantId, 'chatbot', 'enabled', false);
        if (! $enabled) {
            Log::channel('whatsapp')->debug('inbound.skip_disabled', ['tenant' => $this->tenantId]);
            return;
        }

        // Verificar se tem persona aprovada
        $persona = AiPersona::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'approved')
            ->first();

        if (! $persona) {
            Log::channel('whatsapp')->debug('inbound.skip_no_persona', ['tenant' => $this->tenantId]);
            return;
        }

        // Gerar resposta da IA
        $result = $ai->generateReply($conversation, $this->tenantId);

        if (! $result['ok']) {
            Log::channel('ai')->warning('chat.reply_failed', [
                'conversation' => $this->conversationId,
                'error'        => $result['error'],
            ]);
            return;
        }

        $mode = $settings->get($this->tenantId, 'chatbot', 'mode', 'suggestion');

        if ($mode === 'autonomous') {
            // Enviar resposta diretamente via WhatsApp
            $sendResult = $whatsapp->sendText($conversation->phone, $result['reply'], $this->tenantId);

            if ($sendResult['ok']) {
                ConversationMessage::forceCreate([
                    'conversation_id'     => $conversation->id,
                    'tenant_id'           => $this->tenantId,
                    'direction'           => 'outbound',
                    'sender_type'         => 'ai',
                    'type'                => 'text',
                    'content'             => $result['reply'],
                    'external_message_id' => $sendResult['message_id'],
                    'status'              => 'sent',
                    'created_at'          => now(),
                    'sent_at'             => now(),
                ]);

                $conversation->update(['last_message_at' => now()]);

                Log::channel('whatsapp')->info('inbound.ai_replied', [
                    'conversation' => $this->conversationId,
                    'mode'         => 'autonomous',
                ]);
            } else {
                Log::channel('whatsapp')->warning('inbound.ai_send_failed', [
                    'conversation' => $this->conversationId,
                    'error'        => $sendResult['error'],
                ]);
            }
        } else {
            // Modo sugestão — salvar na metadata da conversa
            $metadata = $conversation->metadata ?? [];
            $metadata['ai_suggestion'] = $result['reply'];
            $conversation->update(['metadata' => $metadata]);

            Log::channel('whatsapp')->info('inbound.ai_suggestion_saved', [
                'conversation' => $this->conversationId,
                'mode'         => 'suggestion',
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('whatsapp')->error('inbound.job_failed', [
            'conversation' => $this->conversationId,
            'error'        => $e->getMessage(),
        ]);
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l app/Jobs/ProcessInboundMessageJob.php`

- [ ] **Step 3: Commit**

```bash
git add backend/app/Jobs/ProcessInboundMessageJob.php
git commit -m "feat: implement ProcessInboundMessageJob with AI autonomous/suggestion modes"
```

---

## Task 4: ChatbotController + AiPersonaAdminController + Routes

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/ChatbotController.php`
- Create: `backend/app/Http/Controllers/API/V1/Admin/AiPersonaAdminController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Create ChatbotController**

```php
<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiPersona;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class ChatbotController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function persona()
    {
        $persona = AiPersona::first();
        return ApiResponse::success($persona);
    }

    public function updatePersona(Request $request)
    {
        $data = $request->validate([
            'bot_name'             => ['required', 'string', 'max:50'],
            'tone'                 => ['required', 'in:formal,casual,friendly'],
            'company_name'         => ['required', 'string', 'max:100'],
            'products_services'    => ['required', 'string', 'max:2000'],
            'business_rules'       => ['required', 'string', 'max:2000'],
            'special_instructions' => ['nullable', 'string', 'max:1000'],
            'working_hours'        => ['nullable', 'string', 'max:200'],
        ]);

        $data['status'] = 'pending_approval';
        $data['rejection_reason'] = null;
        $data['approved_by'] = null;
        $data['approved_at'] = null;

        $persona = AiPersona::updateOrCreate(
            ['tenant_id' => auth()->user()->tenant_id],
            $data
        );

        return ApiResponse::success($persona, 'Persona salva e enviada para aprovação');
    }

    public function settings()
    {
        $tenantId = auth()->user()->tenant_id;
        $hasApproved = AiPersona::where('status', 'approved')->exists();

        return ApiResponse::success([
            'mode'                 => $this->settings->get($tenantId, 'chatbot', 'mode', 'suggestion'),
            'enabled'              => (bool) $this->settings->get($tenantId, 'chatbot', 'enabled', false),
            'has_approved_persona' => $hasApproved,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $request->validate([
            'mode'    => ['nullable', 'in:autonomous,suggestion'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        if ($data['enabled'] ?? false) {
            $hasApproved = AiPersona::where('status', 'approved')->exists();
            if (! $hasApproved) {
                return ApiResponse::error('Persona precisa ser aprovada antes de ativar o chatbot.', [], 422);
            }
        }

        if (array_key_exists('mode', $data) && $data['mode'] !== null) {
            $this->settings->upsert($tenantId, 'chatbot', 'mode', $data['mode'], 'string');
        }
        if (array_key_exists('enabled', $data)) {
            $this->settings->upsert($tenantId, 'chatbot', 'enabled', $data['enabled'] ? '1' : '0', 'boolean');
        }

        return ApiResponse::success([], 'Configurações atualizadas');
    }
}
```

- [ ] **Step 2: Create AiPersonaAdminController**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiPersona;
use Illuminate\Http\Request;

class AiPersonaAdminController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,pending_approval,approved,rejected'],
        ]);

        $q = AiPersona::withoutGlobalScopes()->with('tenant:id,name');

        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }

        $items = $q->orderByRaw("FIELD(status, 'pending_approval', 'draft', 'approved', 'rejected')")
            ->orderByDesc('updated_at')
            ->paginate(20);

        return ApiResponse::paginated($items, 'OK');
    }

    public function show(int $id)
    {
        $persona = AiPersona::withoutGlobalScopes()->with('tenant:id,name')->findOrFail($id);
        return ApiResponse::success($persona);
    }

    public function approve(int $id)
    {
        $persona = AiPersona::withoutGlobalScopes()->findOrFail($id);

        $persona->update([
            'status'           => 'approved',
            'approved_by'      => auth()->id(),
            'approved_at'      => now(),
            'rejection_reason' => null,
        ]);

        return ApiResponse::success($persona->fresh(), 'Persona aprovada');
    }

    public function reject(int $id, Request $request)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $persona = AiPersona::withoutGlobalScopes()->findOrFail($id);

        $persona->update([
            'status'           => 'rejected',
            'rejection_reason' => $data['reason'],
            'approved_by'      => null,
            'approved_at'      => null,
        ]);

        return ApiResponse::success($persona->fresh(), 'Persona rejeitada');
    }
}
```

- [ ] **Step 3: Register routes in api.php**

Read `routes/api.php` first. Add import at top:

```php
use App\Http\Controllers\API\V1\ChatbotController;
use App\Http\Controllers\API\V1\Admin\AiPersonaAdminController;
```

Add chatbot routes inside `auth:sanctum` group (after conversations block, around line 105):

```php
        // Chatbot
        Route::get('chatbot/persona',     [ChatbotController::class, 'persona']);
        Route::put('chatbot/persona',     [ChatbotController::class, 'updatePersona']);
        Route::get('chatbot/settings',    [ChatbotController::class, 'settings']);
        Route::put('chatbot/settings',    [ChatbotController::class, 'updateSettings']);
```

Add admin routes inside `superadmin` group (after WhatsApp settings, around line 125):

```php
            // AI Personas
            Route::get('ai-personas',              [AiPersonaAdminController::class, 'index']);
            Route::get('ai-personas/{id}',         [AiPersonaAdminController::class, 'show']);
            Route::patch('ai-personas/{id}/approve',[AiPersonaAdminController::class, 'approve']);
            Route::patch('ai-personas/{id}/reject', [AiPersonaAdminController::class, 'reject']);
```

- [ ] **Step 4: Verify**

Run: `php -l app/Http/Controllers/API/V1/ChatbotController.php && php -l app/Http/Controllers/API/V1/Admin/AiPersonaAdminController.php && php artisan route:list --path=v1/chatbot 2>&1 && php artisan route:list --path=v1/admin/ai-personas 2>&1`

- [ ] **Step 5: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/ChatbotController.php backend/app/Http/Controllers/API/V1/Admin/AiPersonaAdminController.php backend/routes/api.php
git commit -m "feat: add ChatbotController and AiPersonaAdminController with routes"
```

---

## Task 5: Frontend — Chatbot Settings page

**Files:**
- Create: `frontend/src/pages/chatbot/Settings.vue`
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/components/layout/AppSidebar.vue`

- [ ] **Step 1: Create Settings.vue**

```vue
<template>
  <div class="page-header d-print-none">
    <div class="container-xl"><h2 class="page-title">Configuração do Chatbot</h2></div>
  </div>

  <!-- Persona -->
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="card-title">Persona do Bot</div>
      <span v-if="persona" :class="['badge', statusBadge.cls]">{{ statusBadge.label }}</span>
    </div>
    <div class="card-body">
      <div v-if="persona?.status === 'rejected'" class="alert alert-danger mb-3">
        <strong>Rejeitada:</strong> {{ persona.rejection_reason }}
      </div>
      <div class="row">
        <div class="col-md-6 mb-3">
          <label class="form-label required">Nome do bot</label>
          <input class="form-control" v-model="form.bot_name" placeholder="Ex: Ana" maxlength="50">
        </div>
        <div class="col-md-6 mb-3">
          <label class="form-label required">Tom</label>
          <select class="form-select" v-model="form.tone">
            <option value="formal">Formal e profissional</option>
            <option value="casual">Casual e descontraído</option>
            <option value="friendly">Amigável e acolhedor</option>
          </select>
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label required">Nome da empresa</label>
        <input class="form-control" v-model="form.company_name" maxlength="100">
      </div>
      <div class="mb-3">
        <label class="form-label required">Produtos / Serviços</label>
        <textarea class="form-control" v-model="form.products_services" rows="3" maxlength="2000" placeholder="Descreva os produtos e serviços que o bot deve conhecer"></textarea>
        <span class="form-hint">{{ (form.products_services?.length ?? 0) }}/2000</span>
      </div>
      <div class="mb-3">
        <label class="form-label required">Regras de negócio</label>
        <textarea class="form-control" v-model="form.business_rules" rows="3" maxlength="2000" placeholder="Ex: Não dar descontos sem aprovação, encaminhar reclamações para gerência"></textarea>
        <span class="form-hint">{{ (form.business_rules?.length ?? 0) }}/2000</span>
      </div>
      <div class="mb-3">
        <label class="form-label">Instruções especiais</label>
        <textarea class="form-control" v-model="form.special_instructions" rows="2" maxlength="1000"></textarea>
      </div>
      <div class="mb-3">
        <label class="form-label">Horário de funcionamento</label>
        <input class="form-control" v-model="form.working_hours" placeholder="Ex: Seg-Sex 9h-18h, Sáb 9h-13h" maxlength="200">
      </div>
    </div>
    <div class="card-footer text-end">
      <button class="btn btn-primary" @click="savePersona" :disabled="saving">
        <span v-if="saving" class="spinner-border spinner-border-sm me-2"></span>
        Salvar e enviar para aprovação
      </button>
    </div>
  </div>

  <!-- Configurações -->
  <div class="card">
    <div class="card-header"><div class="card-title">Configurações</div></div>
    <div class="card-body">
      <div class="mb-3">
        <label class="form-check form-switch">
          <input class="form-check-input" type="checkbox" v-model="chatSettings.enabled" @change="saveSettings" :disabled="!chatSettings.has_approved_persona">
          <span class="form-check-label">Chatbot ativado</span>
        </label>
        <div v-if="!chatSettings.has_approved_persona" class="form-hint text-warning">
          A persona precisa ser aprovada pelo administrador antes de ativar o chatbot.
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label">Modo de operação</label>
        <select class="form-select" v-model="chatSettings.mode" @change="saveSettings" :disabled="!chatSettings.enabled">
          <option value="suggestion">Sugestão — IA sugere, operador confirma</option>
          <option value="autonomous">Autônomo — IA responde automaticamente</option>
        </select>
        <div class="form-hint mt-1">
          <span v-if="chatSettings.mode === 'suggestion'">A IA gera sugestões de resposta que aparecem no inbox para o operador aprovar.</span>
          <span v-else>A IA responde automaticamente aos leads sem intervenção humana.</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, put } = useApi()
const toast = useToast()
const saving = ref(false)
const persona = ref<any>(null)

const form = ref({
  bot_name: '', tone: 'friendly', company_name: '',
  products_services: '', business_rules: '',
  special_instructions: '', working_hours: '',
})

const chatSettings = ref({ mode: 'suggestion', enabled: false, has_approved_persona: false })

const statusBadge = computed(() => {
  const map: Record<string, { label: string; cls: string }> = {
    draft: { label: 'Rascunho', cls: 'bg-secondary' },
    pending_approval: { label: 'Aguardando aprovação', cls: 'bg-warning text-dark' },
    approved: { label: 'Aprovada', cls: 'bg-success' },
    rejected: { label: 'Rejeitada', cls: 'bg-danger' },
  }
  return map[persona.value?.status] ?? { label: '—', cls: 'bg-secondary' }
})

async function loadPersona() {
  try {
    const data = await get<any>('/chatbot/persona')
    persona.value = data
    if (data) {
      form.value = {
        bot_name: data.bot_name ?? '',
        tone: data.tone ?? 'friendly',
        company_name: data.company_name ?? '',
        products_services: data.products_services ?? '',
        business_rules: data.business_rules ?? '',
        special_instructions: data.special_instructions ?? '',
        working_hours: data.working_hours ?? '',
      }
    }
  } catch { /* silent */ }
}

async function loadSettings() {
  try {
    const data = await get<any>('/chatbot/settings')
    chatSettings.value = { ...chatSettings.value, ...data }
  } catch { /* silent */ }
}

async function savePersona() {
  saving.value = true
  try {
    const res = await put<any>('/chatbot/persona', form.value)
    persona.value = res
    toast.success('Persona salva e enviada para aprovação')
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro ao salvar') }
  finally { saving.value = false }
}

async function saveSettings() {
  try {
    await put('/chatbot/settings', { mode: chatSettings.value.mode, enabled: chatSettings.value.enabled })
    toast.success('Configurações atualizadas')
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao salvar')
    await loadSettings()
  }
}

onMounted(() => { loadPersona(); loadSettings() })
</script>
```

- [ ] **Step 2: Add route in router/index.ts**

Read the file. Add before the catch-all route:

```ts
  {
    path: '/chatbot/settings',
    component: () => import('@/pages/chatbot/Settings.vue'),
    meta: { title: 'Chatbot' },
  },
```

- [ ] **Step 3: Add Chatbot nav item in AppSidebar.vue**

Read the file. After the Conversas nav item (around line 44), add:

```html
        <li class="nav-item">
          <a class="nav-link" :class="{ active: isActive('/chatbot/settings') }" @click.prevent="go('/chatbot/settings')">
            <span class="nav-link-icon"><i class="ti ti-robot"></i></span>
            <span class="nav-link-title">Chatbot</span>
          </a>
        </li>
```

- [ ] **Step 4: Build**

Run: `cd frontend && npm run build 2>&1 | tail -5`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/chatbot/Settings.vue frontend/src/router/index.ts frontend/src/components/layout/AppSidebar.vue
git commit -m "feat: add Chatbot settings page with persona form and mode toggle"
```

---

## Task 6: Frontend — Admin AiPersonas approval page

**Files:**
- Create: `frontend/src/pages/admin/AiPersonas.vue`
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/components/layout/AppSidebar.vue`

- [ ] **Step 1: Create AiPersonas.vue**

```vue
<template>
  <div class="page-header d-print-none">
    <div class="container-xl"><h2 class="page-title">Aprovação de Personas IA</h2></div>
  </div>
  <div class="card">
    <div class="card-header">
      <div class="card-title">Personas</div>
      <div class="card-options">
        <select class="form-select form-select-sm" v-model="filterStatus" @change="load" style="width:180px">
          <option value="">Todos os status</option>
          <option value="pending_approval">Pendentes</option>
          <option value="approved">Aprovadas</option>
          <option value="rejected">Rejeitadas</option>
        </select>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table card-table table-vcenter">
        <thead><tr>
          <th>Tenant</th><th>Nome do bot</th><th>Tom</th><th>Status</th><th>Atualizada</th><th></th>
        </tr></thead>
        <tbody>
          <tr v-if="!items.length"><td colspan="6" class="text-center text-muted py-4">Nenhuma persona encontrada</td></tr>
          <tr v-for="p in items" :key="p.id">
            <td class="fw-bold">{{ p.tenant?.name ?? `#${p.tenant_id}` }}</td>
            <td>{{ p.bot_name }}</td>
            <td>{{ toneLabel(p.tone) }}</td>
            <td><span :class="['badge', statusCls(p.status)]">{{ statusLabel(p.status) }}</span></td>
            <td class="text-muted">{{ formatDate(p.updated_at) }}</td>
            <td>
              <button class="btn btn-sm btn-ghost-primary" @click="openDetail(p)">Revisar</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Detail Modal -->
  <div v-if="selected" class="modal modal-blur fade show" style="display:block" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Persona: {{ selected.bot_name }} — {{ selected.tenant?.name }}</h5>
          <button type="button" class="btn-close" @click="selected = null"></button>
        </div>
        <div class="modal-body">
          <table class="table table-borderless">
            <tr><td class="text-muted" style="width:150px">Nome do bot</td><td class="fw-bold">{{ selected.bot_name }}</td></tr>
            <tr><td class="text-muted">Tom</td><td>{{ toneLabel(selected.tone) }}</td></tr>
            <tr><td class="text-muted">Empresa</td><td>{{ selected.company_name }}</td></tr>
            <tr><td class="text-muted">Produtos/Serviços</td><td style="white-space:pre-wrap">{{ selected.products_services }}</td></tr>
            <tr><td class="text-muted">Regras de negócio</td><td style="white-space:pre-wrap">{{ selected.business_rules }}</td></tr>
            <tr v-if="selected.special_instructions"><td class="text-muted">Instruções especiais</td><td style="white-space:pre-wrap">{{ selected.special_instructions }}</td></tr>
            <tr v-if="selected.working_hours"><td class="text-muted">Horário</td><td>{{ selected.working_hours }}</td></tr>
          </table>
          <div v-if="selected.status === 'rejected'" class="alert alert-danger">
            <strong>Motivo da rejeição:</strong> {{ selected.rejection_reason }}
          </div>
          <div v-if="showRejectForm" class="mt-3">
            <label class="form-label">Motivo da rejeição *</label>
            <textarea class="form-control" v-model="rejectReason" rows="2" maxlength="500" placeholder="Explique o motivo da rejeição"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-link link-secondary" @click="selected = null">Fechar</button>
          <template v-if="selected.status === 'pending_approval'">
            <button v-if="!showRejectForm" class="btn btn-outline-danger" @click="showRejectForm = true" :disabled="acting">Rejeitar</button>
            <button v-if="showRejectForm" class="btn btn-danger" @click="reject" :disabled="acting || !rejectReason.trim()">
              <span v-if="acting" class="spinner-border spinner-border-sm me-1"></span>Confirmar rejeição
            </button>
            <button class="btn btn-success" @click="approve" :disabled="acting">
              <span v-if="acting" class="spinner-border spinner-border-sm me-1"></span>Aprovar
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
  <div v-if="selected" class="modal-backdrop fade show"></div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const { get, patch } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const filterStatus = ref('pending_approval')
const selected = ref<any>(null)
const showRejectForm = ref(false)
const rejectReason = ref('')
const acting = ref(false)

async function load() {
  try {
    const params: Record<string, any> = {}
    if (filterStatus.value) params.status = filterStatus.value
    const res = await get<any>('/admin/ai-personas', params)
    items.value = res?.data ?? res ?? []
  } catch { /* silent */ }
}

function openDetail(p: any) {
  selected.value = p
  showRejectForm.value = false
  rejectReason.value = ''
}

async function approve() {
  acting.value = true
  try {
    await patch(`/admin/ai-personas/${selected.value.id}/approve`, {})
    toast.success('Persona aprovada')
    selected.value = null
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
  finally { acting.value = false }
}

async function reject() {
  acting.value = true
  try {
    await patch(`/admin/ai-personas/${selected.value.id}/reject`, { reason: rejectReason.value })
    toast.success('Persona rejeitada')
    selected.value = null
    await load()
  } catch (e: any) { toast.error(e?.response?.data?.message ?? 'Erro') }
  finally { acting.value = false }
}

function toneLabel(t: string) { return { formal: 'Formal', casual: 'Casual', friendly: 'Amigável' }[t] ?? t }
function statusLabel(s: string) { return { draft: 'Rascunho', pending_approval: 'Pendente', approved: 'Aprovada', rejected: 'Rejeitada' }[s] ?? s }
function statusCls(s: string) { return { draft: 'bg-secondary', pending_approval: 'bg-warning text-dark', approved: 'bg-success', rejected: 'bg-danger' }[s] ?? 'bg-secondary' }
function formatDate(d: string) { return d ? new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—' }

onMounted(load)
</script>
```

- [ ] **Step 2: Add route**

In `router/index.ts`, add before catch-all:

```ts
  {
    path: '/admin/ai-personas',
    component: () => import('@/pages/admin/AiPersonas.vue'),
    meta: { superadmin: true, title: 'Admin • Personas IA' },
  },
```

- [ ] **Step 3: Add admin nav item**

In AppSidebar.vue, inside the admin dropdown (after WhatsApp item), add:

```html
            <a class="dropdown-item" :class="{ active: isActive('/admin/ai-personas') }" @click.prevent="go('/admin/ai-personas')">
              <i class="ti ti-robot me-2"></i> Personas IA
            </a>
```

- [ ] **Step 4: Build**

Run: `cd frontend && npm run build 2>&1 | tail -5`

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/admin/AiPersonas.vue frontend/src/router/index.ts frontend/src/components/layout/AppSidebar.vue
git commit -m "feat: add admin AI Personas approval page"
```

---

## Task 7: Frontend — Activate AI suggestion in ConversationChat

**Files:**
- Modify: `frontend/src/components/conversations/ConversationChat.vue:52-57`

- [ ] **Step 1: Replace static placeholder with dynamic suggestion**

Read `ConversationChat.vue`. Find the AI suggestion placeholder block (lines 52-57):

```html
    <!-- AI suggestion placeholder -->
    <div style="padding:6px 12px;background:#f8f9fb;border-top:1px solid #e0e5ec;font-size:12px;color:#999;display:flex;align-items:center;gap:8px">
      <span>💡</span>
      <span style="flex:1;font-style:italic">Sugestão IA indisponível neste plano</span>
      <button class="btn btn-sm btn-ghost-secondary" disabled style="font-size:11px">Usar</button>
    </div>
```

Replace with:

```html
    <!-- AI suggestion -->
    <div v-if="aiSuggestion" style="padding:8px 12px;background:#f0fdf4;border-top:1px solid #bbf7d0;font-size:13px;color:#166534;display:flex;align-items:center;gap:8px">
      <span>💡</span>
      <span style="flex:1">{{ aiSuggestion }}</span>
      <button class="btn btn-sm btn-success" @click="useSuggestion" :disabled="sending" style="font-size:11px">Usar</button>
      <button class="btn btn-sm btn-ghost-secondary" @click="ignoreSuggestion" style="font-size:11px">Ignorar</button>
    </div>
    <div v-else style="padding:6px 12px;background:#f8f9fb;border-top:1px solid #e0e5ec;font-size:12px;color:#999;display:flex;align-items:center;gap:8px">
      <span>💡</span>
      <span style="flex:1;font-style:italic">Sugestão IA indisponível</span>
    </div>
```

In the `<script setup>`, add:

```ts
const aiSuggestion = computed(() => props.conversation?.metadata?.ai_suggestion ?? null)

function useSuggestion() {
  if (aiSuggestion.value) {
    emit('send', aiSuggestion.value)
    // Suggestion will be cleared when conversation refreshes after send
  }
}

function ignoreSuggestion() {
  emit('clearSuggestion')
}
```

Add `clearSuggestion` to the `defineEmits`:

```ts
const emit = defineEmits<{
  send: [text: string]
  updateStatus: [status: string]
  toggleInfo: []
  loadMore: []
  clearSuggestion: []
}>()
```

Also add `import { computed }` if not already present.

- [ ] **Step 2: Handle clearSuggestion in Index.vue**

Read `src/pages/conversations/Index.vue`. Add the `@clear-suggestion` handler on the `<ConversationChat>` component:

```html
@clear-suggestion="clearSuggestion"
```

Add the function:

```ts
async function clearSuggestion() {
  if (!activeConversation.value) return
  try {
    const metadata = { ...(activeConversation.value.metadata ?? {}), ai_suggestion: null }
    await patch(`/conversations/${activeConversation.value.id}/status`, { status: activeConversation.value.status })
    activeConversation.value = { ...activeConversation.value, metadata }
  } catch { /* silent */ }
}
```

- [ ] **Step 3: Build**

Run: `cd frontend && npm run build 2>&1 | tail -5`

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/conversations/ConversationChat.vue frontend/src/pages/conversations/Index.vue
git commit -m "feat: activate AI suggestion in conversation chat"
```

---

## Task 8: Final verification

- [ ] **Step 1: Backend syntax check**

Run: `cd backend && php -l app/Models/AiPersona.php && php -l app/Services/Ai/ChatAiService.php && php -l app/Jobs/ProcessInboundMessageJob.php && php -l app/Http/Controllers/API/V1/ChatbotController.php && php -l app/Http/Controllers/API/V1/Admin/AiPersonaAdminController.php`

- [ ] **Step 2: Route check**

Run: `php artisan route:list --path=v1 2>&1 | grep -E "(chatbot|ai-persona)" | wc -l`
Expected: 8 routes (4 chatbot + 4 admin personas)

- [ ] **Step 3: Migration check**

Run: `php artisan migrate:status 2>&1 | grep ai_persona`
Expected: Shows as `Ran`

- [ ] **Step 4: Frontend build**

Run: `cd frontend && npm run build 2>&1 | tail -5`
Expected: `✓ built in Xs`

- [ ] **Step 5: Manual smoke test**

1. `/chatbot/settings` — persona form renders, can fill and save
2. Persona shows status "Aguardando aprovação" after save
3. `/admin/ai-personas` — superadmin sees pending persona, can approve/reject
4. After approval: tenant can enable chatbot toggle
5. Mode selector works (autonomous/suggestion)
6. Sidebar shows "Chatbot" nav item
7. Admin dropdown shows "Personas IA" item
8. In inbox: when conversation has `metadata.ai_suggestion`, green bar shows with "Usar"/"Ignorar"
