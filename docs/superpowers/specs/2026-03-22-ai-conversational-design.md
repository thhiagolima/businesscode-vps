# IA Conversacional — Sub-projeto 3

**Date:** 2026-03-22
**Scope:** IA respondendo leads via WhatsApp, persona configurável com aprovação, modo autônomo vs sugestão
**Stack:** Laravel 12 (backend), Vue 3 + Tabler UI (frontend)
**Depends on:** Sub-projeto 1 (WhatsApp Foundation), Sub-projeto 2 (Inbox)

---

## Contexto

O Sub-projeto 2 entregou o Inbox completo com placeholder de sugestão IA. O `ProcessInboundMessageJob` é um stub que apenas loga. Este sub-projeto implementa a lógica real: a IA (Grok) responde leads com base em persona configurada pelo tenant e aprovada pelo superadmin.

### Decisões de design

| Questão | Decisão |
|---------|---------|
| Contexto para IA | Últimas 10 mensagens da conversa |
| Persona | Formulário estruturado (não texto livre) |
| Aprovação | Obrigatória pelo superadmin antes de ativar |
| Modo | Configurável: autônomo ou sugestão (default: sugestão) |
| Créditos | 2 créditos fixos por resposta IA |

---

## 1. ChatAiService

**File:** `backend/app/Services/Ai/ChatAiService.php`

Service dedicado para conversas (separado do GrokService que é para geração de conteúdo de campanhas).

### Responsabilidades

- Montar system prompt com a persona aprovada do tenant
- Formatar histórico de mensagens como array de messages Grok (role: user/assistant)
- Chamar Grok API
- Retornar resposta em texto
- Debitar 2 créditos

### Método principal

```php
public function generateReply(Conversation $conversation, int $tenantId): array
// Retorna: ['ok' => bool, 'reply' => string|null, 'error' => string|null, 'credits_used' => int]
```

### Fluxo interno

1. Carregar `AiPersona` aprovada do tenant. Se não existe → retorna `['ok' => false, 'error' => 'Persona não configurada ou não aprovada']`
2. Carregar últimas 10 mensagens da conversa (`ConversationMessage::where('conversation_id', ...)->orderByDesc('created_at')->limit(10)->get()->reverse()`)
3. Montar system prompt:

```
Você é {nome_bot}, assistente virtual da empresa {empresa}.

Tom: {tom}
Produtos/Serviços: {produtos_servicos}
Horário de funcionamento: {horario_funcionamento}

Regras de negócio:
{regras_negocio}

Instruções especiais:
{instrucoes_especiais}

IMPORTANTE: Responda de forma concisa (máximo 500 caracteres). Não invente informações que não estão nas regras acima. Se não souber responder, diga que vai encaminhar para um atendente humano.
```

4. Formatar mensagens como:
```php
$messages = [
    ['role' => 'system', 'content' => $systemPrompt],
    // Para cada mensagem do histórico:
    ['role' => $msg->direction === 'inbound' ? 'user' : 'assistant', 'content' => $msg->content],
    // ...
]
```

5. Chamar Grok API:
```php
Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
    ->post('https://api.x.ai/v1/chat/completions', [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 300,
    ]);
```

6. Extrair resposta: `$response->json('choices.0.message.content')`
7. Debitar 2 créditos via `CreditService::debit()` (superadmin é isento)
8. Logar no canal `ai`: generation info, tokens usados

### Configuração

- API key: `$settings->getGlobal('ai', 'grok_api_key')` (mesmo do GrokService)
- Model: `$settings->getGlobal('ai', 'grok_model', 'grok-beta')`
- Créditos: `$settings->getGlobal('billing', 'credits_per_ai_chat', '2')`

---

## 2. Model AiPersona

**File:** `backend/app/Models/AiPersona.php`
**Com** AppliesTenantScope.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `tenant_id` | bigint FK | |
| `bot_name` | string | Nome do bot (ex: "Ana") |
| `tone` | enum `formal,casual,friendly` | Tom da comunicação |
| `company_name` | string | Nome da empresa |
| `products_services` | text | Descrição de produtos/serviços |
| `business_rules` | text | Regras de negócio |
| `special_instructions` | text nullable | Instruções adicionais |
| `working_hours` | string nullable | Horário de funcionamento |
| `status` | enum `draft,pending_approval,approved,rejected` | |
| `rejection_reason` | text nullable | Motivo da rejeição (preenchido pelo superadmin) |
| `approved_by` | bigint FK nullable | user_id do superadmin que aprovou |
| `approved_at` | datetime nullable | |
| `created_at/updated_at` | timestamps | |

**Relationships:**
- `belongsTo(Tenant)`
- `belongsTo(User, 'approved_by')`

**Regra:** Cada tenant tem no máximo 1 persona ativa (approved). Se editar, status volta para `pending_approval`.

---

## 3. ProcessInboundMessageJob (Implementação Real)

**File:** `backend/app/Jobs/ProcessInboundMessageJob.php` — substituir stub

### Fluxo

```
Mensagem chega → Webhook cria ConversationMessage → Despacha Job
                                                         │
                                                         ▼
                                              Conversa em modo 'bot'?
                                              ├─ Não → return (humano controla)
                                              │
                                              ▼
                                        Persona aprovada?
                                        ├─ Não → return (sem IA)
                                        │
                                        ▼
                                   Modo do tenant?
                                   ├─ 'autonomous' → gera resposta → envia via WhatsApp
                                   │                                  → cria ConversationMessage(sender_type=ai)
                                   │
                                   └─ 'suggestion' → gera resposta → salva em conversation.metadata.ai_suggestion
                                                                     → NÃO envia (operador decide)
```

### Código principal

```php
public function handle(
    ChatAiService $ai,
    WhatsAppService $whatsapp,
    SettingsService $settings
): void {
    $conversation = Conversation::withoutGlobalScopes()->findOrFail($this->conversationId);

    // Só processa se conversa está em modo bot
    if ($conversation->status !== 'bot') {
        Log::channel('whatsapp')->debug('inbound.skip_not_bot', [...]);
        return;
    }

    // Verificar se tem persona aprovada
    $persona = AiPersona::withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)
        ->where('status', 'approved')
        ->first();

    if (! $persona) {
        Log::channel('whatsapp')->debug('inbound.skip_no_persona', [...]);
        return;
    }

    // Gerar resposta da IA
    $result = $ai->generateReply($conversation, $this->tenantId);

    if (! $result['ok']) {
        Log::channel('ai')->warning('chat.reply_failed', [...]);
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
        }
    } else {
        // Modo sugestão — salvar na metadata da conversa
        $metadata = $conversation->metadata ?? [];
        $metadata['ai_suggestion'] = $result['reply'];
        $conversation->update(['metadata' => $metadata]);
    }
}
```

---

## 4. Settings do Chatbot por Tenant

### Armazenamento (tabela `settings`)

| Group | Key | Tipo | Default | Descrição |
|-------|-----|------|---------|-----------|
| `chatbot` | `mode` | string | `suggestion` | `autonomous` ou `suggestion` |
| `chatbot` | `enabled` | boolean | `false` | Liga/desliga o chatbot |
| `billing` | `credits_per_ai_chat` | string | `2` | Créditos por resposta IA (global) |

### Endpoints tenant

```
GET  /v1/chatbot/persona    → ChatbotController@persona      # Persona do tenant
PUT  /v1/chatbot/persona    → ChatbotController@updatePersona # Criar/editar (→ pending_approval)
GET  /v1/chatbot/settings   → ChatbotController@settings      # Modo atual + enabled
PUT  /v1/chatbot/settings   → ChatbotController@updateSettings # Mudar modo
```

### ChatbotController

**File:** `backend/app/Http/Controllers/API/V1/ChatbotController.php`

**persona():** Retorna a persona do tenant ou null.

**updatePersona():** Valida campos estruturados, salva/atualiza. Se já tinha persona approved, status volta para `pending_approval`:

```php
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
```

**settings():** Retorna `{ mode, enabled, has_approved_persona }`.

**updateSettings():** Valida e salva modo. Só permite ativar se persona está approved:

```php
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
```

---

## 5. Admin — Aprovação de Personas

### Endpoints superadmin

```
GET   /v1/admin/ai-personas              → AiPersonaAdminController@index    # Lista todas (filtro status)
GET   /v1/admin/ai-personas/{id}         → AiPersonaAdminController@show     # Detalhe
PATCH /v1/admin/ai-personas/{id}/approve → AiPersonaAdminController@approve  # Aprovar
PATCH /v1/admin/ai-personas/{id}/reject  → AiPersonaAdminController@reject   # Rejeitar
```

### AiPersonaAdminController

**File:** `backend/app/Http/Controllers/API/V1/Admin/AiPersonaAdminController.php`

**index():** Lista todas as personas com filtro por status. Inclui `tenant.name` via eager loading.

**approve():**
```php
$persona = AiPersona::withoutGlobalScopes()->findOrFail($id);
$persona->update([
    'status'      => 'approved',
    'approved_by' => auth()->id(),
    'approved_at' => now(),
    'rejection_reason' => null,
]);
```

**reject():**
```php
$data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
$persona->update([
    'status'           => 'rejected',
    'rejection_reason' => $data['reason'],
    'approved_by'      => null,
    'approved_at'      => null,
]);
```

---

## 6. Frontend — Ativar Sugestão IA no Inbox

### ConversationChat.vue — ativar o placeholder

Substituir o placeholder estático por conteúdo dinâmico:

**Quando `conversation.metadata.ai_suggestion` existe:**
```html
<div style="padding:6px 12px;background:#f0fdf4;border-top:1px solid #bbf7d0;...">
  <span>💡</span>
  <span style="flex:1">{{ conversation.metadata.ai_suggestion }}</span>
  <button class="btn btn-sm btn-success" @click="useSuggestion">Usar</button>
  <button class="btn btn-sm btn-ghost-secondary" @click="ignoreSuggestion">Ignorar</button>
</div>
```

**"Usar":** Chama `sendMessage(conversation.metadata.ai_suggestion)` e limpa a sugestão.

**"Ignorar":** Limpa `conversation.metadata.ai_suggestion` via `PATCH /conversations/{id}/status` ou endpoint dedicado.

**Quando não tem sugestão** (ou modo autônomo ativo): mostra o placeholder cinza original.

### Nova página: Chatbot Settings (tenant)

**File:** `frontend/src/pages/chatbot/Settings.vue`

Duas seções:

**Seção 1 — Persona:**
- Formulário estruturado (nome bot, tom, empresa, produtos, regras, instruções, horário)
- Badge de status (draft/pendente/aprovada/rejeitada)
- Se rejeitada: mostra motivo da rejeição
- Botão "Salvar e enviar para aprovação"

**Seção 2 — Configurações:**
- Toggle enabled (on/off) — disabled se persona não aprovada
- Select modo: Autônomo / Sugestão
- Texto explicativo de cada modo

### Nova página: Admin Personas (superadmin)

**File:** `frontend/src/pages/admin/AiPersonas.vue`

- Tabela com: Tenant, Nome do bot, Status, Data de criação
- Filtro por status (pendentes primeiro)
- Click → modal com detalhes completos da persona
- Botões "Aprovar" / "Rejeitar" (com campo de motivo)

---

## 7. Arquivos

### Backend — Criar
| Arquivo | Responsabilidade |
|---------|-----------------|
| `database/migrations/xxx_create_ai_personas_table.php` | Tabela de personas |
| `app/Models/AiPersona.php` | Model com tenant scope |
| `app/Services/Ai/ChatAiService.php` | IA conversacional via Grok |
| `app/Http/Controllers/API/V1/ChatbotController.php` | Persona + settings do tenant |
| `app/Http/Controllers/API/V1/Admin/AiPersonaAdminController.php` | Aprovação de personas |

### Backend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `app/Jobs/ProcessInboundMessageJob.php` | Implementar lógica real (substituir stub) |
| `routes/api.php` | Adicionar rotas chatbot + admin personas |
| `database/seeders/GlobalSettingsSeeder.php` | Adicionar `credits_per_ai_chat` |

### Frontend — Criar
| Arquivo | Responsabilidade |
|---------|-----------------|
| `src/pages/chatbot/Settings.vue` | Configuração de persona + modo |
| `src/pages/admin/AiPersonas.vue` | Aprovação de personas |

### Frontend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `src/components/conversations/ConversationChat.vue` | Ativar sugestão IA dinâmica |
| `src/components/layout/AppSidebar.vue` | Adicionar "Chatbot" na nav |
| `src/router/index.ts` | Adicionar rotas /chatbot/settings e /admin/ai-personas |

---

## 8. Out of Scope

- Múltiplas personas por tenant (apenas 1 ativa)
- Histórico de aprovações/rejeições
- Treino customizado da IA com dados do tenant
- Respostas com mídia (IA responde apenas texto)
- Rate limiting por conversa (anti-loop)
- Fallback quando Grok API está fora do ar
- Métricas de qualidade das respostas IA
