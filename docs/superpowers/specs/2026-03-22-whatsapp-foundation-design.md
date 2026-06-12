# WhatsApp Foundation — Sub-projeto 1

**Date:** 2026-03-22
**Scope:** Meta Cloud API integration, template campaigns, webhook de recebimento, models de conversa
**Stack:** Laravel 12 (backend), Vue 3 + Tabler UI (frontend)
**Prerequisite for:** Sub-projeto 2 (Inbox), Sub-projeto 3 (IA Conversacional), Sub-projeto 4 (Funil Visual)

---

## Contexto

O CampaignAI é um SaaS multi-tenant de campanhas (SMS, Voz, Email) via Infobip. Este sub-projeto adiciona o canal **WhatsApp** conectando diretamente à Meta Cloud API (sem intermediários). Cada tenant traz sua própria WABA (WhatsApp Business Account).

### Decisões de design

| Questão | Decisão |
|---------|---------|
| Provedor | Meta Cloud API direta (não Infobip) |
| Credenciais | Por tenant (settings table) |
| Chat model | Híbrido: bot responde + operador assume (handoff) |
| IA | Configurável: modo autônomo ou sugestão (sub-projeto 3) |
| Funil | Editor visual drag-and-drop (sub-projeto 4) |

---

## 1. WhatsAppService

**File:** `backend/app/Services/WhatsApp/WhatsAppService.php`

Segue o padrão do `InfobipService`. Recebe `SettingsService`, carrega credenciais por tenant.

### Credenciais por tenant (tabela `settings`, group `whatsapp`)

| Key | Tipo | Descrição |
|-----|------|-----------|
| `phone_number_id` | string | ID do número no Meta |
| `waba_id` | string | WhatsApp Business Account ID |
| `access_token` | encrypted | Token permanente do System User |
| `verify_token` | string | Token para validar webhook da Meta |
| `app_secret` | encrypted | Para validar assinatura X-Hub-Signature-256 |

### Métodos

```php
class WhatsAppService
{
    public function __construct(private SettingsService $settings) {}

    // Valida token fazendo GET /v20.0/{phone_number_id}
    public function testConnection(int $tenantId): array
    // ['ok' => bool, 'phone_display' => string|null, 'error' => string|null]

    // POST /v20.0/{phone_number_id}/messages — tipo template
    public function sendTemplate(
        string $to,
        string $templateName,
        string $languageCode,
        array $components,
        int $tenantId
    ): array
    // ['ok' => bool, 'message_id' => string|null, 'error' => string|null]

    // POST /v20.0/{phone_number_id}/messages — tipo text (janela 24h)
    public function sendText(string $to, string $text, int $tenantId): array

    // POST /v20.0/{phone_number_id}/messages — tipo image/video/document/audio
    public function sendMedia(string $to, string $mediaType, string $url, ?string $caption, int $tenantId): array

    // GET /v20.0/{waba_id}/message_templates?status=APPROVED
    public function getTemplates(int $tenantId): array

    // Cliente HTTP interno
    private function makeClient(int $tenantId): PendingRequest
}
```

**Base URL:** `https://graph.facebook.com`
**API Version:** `v20.0`
**Auth header:** `Authorization: Bearer {access_token}`

**Retorno padronizado** (mesmo contrato do InfobipService):
```php
['ok' => bool, 'message_id' => string|null, 'error' => string|null]
```

**Logging:** Canal `whatsapp` (diário, 14 dias retenção — adicionar em `config/logging.php`).

---

## 2. Models

### 2.1 WhatsAppPhoneNumber

**File:** `backend/app/Models/WhatsAppPhoneNumber.php`
**Sem** AppliesTenantScope (lookup global no webhook).

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `phone_number_id` | string unique | ID do Meta (lookup no webhook) |
| `tenant_id` | bigint FK | |
| `phone_display` | string | Número formatado para exibição |
| `created_at/updated_at` | timestamps | |

Preenchido quando o tenant configura WhatsApp (testConnection retorna o display number).

### 2.2 Conversation

**File:** `backend/app/Models/Conversation.php`
**Com** AppliesTenantScope.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `tenant_id` | bigint FK | |
| `contact_id` | bigint FK nullable | Referência ao contato (se existir) |
| `phone` | string | wa_id do contato (E.164) |
| `channel` | string default `whatsapp` | Preparado para futuro |
| `status` | enum `open,bot,human,closed` | Quem controla a conversa |
| `assigned_to` | bigint FK nullable | user_id do operador |
| `last_message_at` | datetime | Para ordenar inbox |
| `unread_count` | int default 0 | Mensagens não lidas pelo operador |
| `metadata` | JSON nullable | Tags, notas, dados do funil |
| `created_at/updated_at` | timestamps | |

**Índices:** `(tenant_id, phone)` unique, `(tenant_id, status)`, `(tenant_id, last_message_at desc)`

**Relationships:**
- `belongsTo(Contact)`
- `belongsTo(User, 'assigned_to')`
- `hasMany(ConversationMessage)`

### 2.3 ConversationMessage

**File:** `backend/app/Models/ConversationMessage.php`
**Com** AppliesTenantScope.

| Campo | Tipo | Descrição |
|-------|------|-----------|
| `id` | bigint PK | |
| `conversation_id` | bigint FK | |
| `tenant_id` | bigint FK | |
| `direction` | enum `inbound,outbound` | Recebida ou enviada |
| `sender_type` | enum `contact,bot,human,campaign,ai` | Quem enviou |
| `sender_id` | bigint nullable | user_id se humano |
| `type` | string | `text,template,image,video,document,audio,location,reaction` |
| `content` | text nullable | Corpo da mensagem |
| `media_url` | string nullable | URL da mídia |
| `template_name` | string nullable | Nome do template (se tipo=template) |
| `external_message_id` | string nullable index | wamid do Meta |
| `status` | enum `pending,sent,delivered,read,failed` | |
| `error_message` | text nullable | |
| `metadata` | JSON nullable | Botões clicados, template components, etc. |
| `created_at` | timestamp | |
| `sent_at` | datetime nullable | |
| `delivered_at` | datetime nullable | |
| `read_at` | datetime nullable | |

**Índices:** `(conversation_id, created_at)`, `(external_message_id)` unique

**Relationships:**
- `belongsTo(Conversation)`
- `belongsTo(User, 'sender_id')`

### Por que separar de CampaignDispatch?

`CampaignDispatch` é one-shot (campanha → contato). `ConversationMessage` é bidirecional com threading. Quando uma campanha WhatsApp dispara:
- Cria `CampaignDispatch` (relatórios unificados)
- Cria `ConversationMessage` com `sender_type=campaign` (histórico da conversa)
- Cria/reabre `Conversation` em status `bot`

---

## 3. Webhook de Recebimento

### Resolução de tenant no webhook

A Meta envia o `phone_number_id` em `entry[].changes[].value.metadata.phone_number_id`. O controller faz lookup na tabela `whatsapp_phone_numbers` para resolver o `tenant_id`.

### Rotas públicas (sem auth Sanctum)

```
GET  /api/v1/webhooks/whatsapp  → WhatsAppWebhookController@verify
POST /api/v1/webhooks/whatsapp  → WhatsAppWebhookController@handle
```

### WhatsAppWebhookController

**File:** `backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php`

#### Verificação (GET)

```php
public function verify(Request $request): Response
{
    $mode      = $request->query('hub_mode');
    $token     = $request->query('hub_verify_token');
    $challenge = $request->query('hub_challenge');

    // Buscar verify_token em qualquer tenant (webhook é único por app)
    $phoneNumber = WhatsAppPhoneNumber::first(); // simplificação
    $expectedToken = $this->settings->get($phoneNumber->tenant_id, 'whatsapp', 'verify_token');

    if ($mode === 'subscribe' && hash_equals($expectedToken, $token)) {
        return response($challenge, 200);
    }
    return response('Forbidden', 403);
}
```

**Nota:** A Meta usa um único webhook URL por App. Para multi-tenant, o `phone_number_id` no payload identifica qual tenant. O `verify_token` pode ser global (configurado no App da Meta) ou por tenant.

#### Mensagens recebidas (POST)

```php
public function handle(Request $request): JsonResponse
{
    // 1. Validar assinatura X-Hub-Signature-256
    if (! $this->validateSignature($request)) {
        return response()->json(['error' => 'Invalid signature'], 401);
    }

    $payload = $request->all();

    foreach ($payload['entry'] ?? [] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];

            // Resolver tenant pelo phone_number_id
            $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
            $phoneNumber = WhatsAppPhoneNumber::where('phone_number_id', $phoneNumberId)->first();
            if (! $phoneNumber) continue;

            $tenantId = $phoneNumber->tenant_id;

            // Processar mensagens recebidas
            foreach ($value['messages'] ?? [] as $message) {
                $this->processInboundMessage($message, $tenantId, $value['contacts'] ?? []);
            }

            // Processar status updates (sent → delivered → read)
            foreach ($value['statuses'] ?? [] as $status) {
                $this->processStatusUpdate($status, $tenantId);
            }
        }
    }

    return response()->json(['ok' => true]);
}
```

#### processInboundMessage

1. Busca ou cria `Contact` pelo `wa_id` (campo `phone`)
2. Busca ou cria `Conversation` (`tenant_id` + `phone`)
   - Se nova: status = `bot`, `last_message_at = now()`
   - Se existente: atualiza `last_message_at`, incrementa `unread_count`
3. Cria `ConversationMessage`:
   - `direction=inbound`, `sender_type=contact`
   - `type` = text/image/video/etc (baseado no payload)
   - `content` = text body ou caption
   - `media_url` = extraída se mídia
   - `external_message_id` = wamid
4. Se conversa em modo `bot` → despacha `ProcessInboundMessageJob` (stub neste sub-projeto, implementado no sub-projeto 3)
5. Se conversa em modo `human` → apenas incrementa `unread_count`

#### processStatusUpdate

1. Busca `ConversationMessage` por `external_message_id`
2. Atualiza `status` (sent → delivered → read) e timestamps correspondentes
3. Se a mensagem veio de campanha (verificar `sender_type=campaign`), atualiza `CampaignDispatch` correspondente também

#### Validação de assinatura

```php
private function validateSignature(Request $request): bool
{
    $signature = $request->header('X-Hub-Signature-256', '');
    $expectedSignature = 'sha256=' . hash_hmac('sha256', $request->getContent(), $this->getAppSecret());
    return hash_equals($expectedSignature, $signature);
}
```

**Nota sobre app_secret:** Como o webhook é por App (não por tenant), o `app_secret` é o mesmo para todos os tenants. Pode ser armazenado como setting global ou como env var `WHATSAPP_APP_SECRET`.

---

## 4. Campanhas WhatsApp

### Migração — adicionar `whatsapp` ao enum

```php
// alter_campaigns_add_whatsapp_type
DB::statement("ALTER TABLE campaigns MODIFY COLUMN type ENUM('sms','voice','email','whatsapp') NOT NULL");
```

**Atualizar validação em:**
- `CampaignsController::store()` — `'in:sms,voice,email,whatsapp'`
- `CampaignsController::update()` — idem
- `CampaignStateMachine::assertCanDispatch()` — aceitar whatsapp

### Campo `settings` da campanha WhatsApp

```json
{
  "template_name": "promo_verao",
  "template_language": "pt_BR",
  "components": [
    {
      "type": "body",
      "parameters": [
        {"type": "text", "text": "{nome}"},
        {"type": "text", "text": "50%"}
      ]
    }
  ]
}
```

Variáveis dinâmicas (`{nome}`, `{telefone}`, `{email}`) são substituídas no `ProcessCampaignJob` pelos dados reais do `Contact`.

### ProcessCampaignJob — novo case

```php
'whatsapp' => $this->sendWhatsAppTemplate($contact, $campaign, $settings)
```

Método `sendWhatsAppTemplate`:
1. Carrega template settings do `$campaign->settings`
2. Substitui variáveis dinâmicas: `{nome}` → `$contact->name`, `{telefone}` → `$contact->phone`, `{email}` → `$contact->email`
3. Chama `$whatsapp->sendTemplate(to, templateName, language, components, tenantId)`
4. Cria `CampaignDispatch` (relatórios)
5. Cria/reabre `Conversation` com status `bot`
6. Cria `ConversationMessage` com `sender_type=campaign`, `type=template`

**Créditos:** `billing.credits_per_whatsapp` = 3 (default, configurável)

### Cache de templates

Templates buscados via `WhatsAppService::getTemplates()` e cacheados 5 minutos (`Cache::remember`). O cache é invalidado manualmente via botão "Sincronizar" no admin.

---

## 5. Rotas e Controllers

### WhatsAppController (autenticado)

**File:** `backend/app/Http/Controllers/API/V1/WhatsAppController.php`

```
GET  /v1/whatsapp/templates              → templates()     # Lista templates aprovados (cache 5min)
GET  /v1/whatsapp/templates/{name}       → templatePreview()  # Estrutura do template para preview
POST /v1/whatsapp/send-text              → sendText()      # Envia texto em conversa aberta
```

### WhatsAppWebhookController (público)

```
GET  /v1/webhooks/whatsapp               → verify()
POST /v1/webhooks/whatsapp               → handle()
```

### Admin\WhatsAppSettingsController (superadmin)

**File:** `backend/app/Http/Controllers/API/V1/Admin/WhatsAppSettingsController.php`

Mesma pattern do `InfobipSettingsController`:
```
GET  /v1/admin/settings/whatsapp         → index()
PUT  /v1/admin/settings/whatsapp         → update()
POST /v1/admin/settings/whatsapp/test    → test()
POST /v1/admin/settings/whatsapp/sync-templates → syncTemplates()
```

### ConversationController (autenticado)

**File:** `backend/app/Http/Controllers/API/V1/ConversationController.php`

Base para o sub-projeto 2 (inbox):
```
GET  /v1/conversations                   → index()    # Lista conversas (paginado, filtro status/search)
GET  /v1/conversations/{id}              → show()     # Conversa + mensagens paginadas
POST /v1/conversations/{id}/messages     → sendMessage()  # Envia texto/media
```

---

## 6. Frontend — Mínimo para Sub-projeto 1

### Admin Settings WhatsApp

**File:** `frontend/src/pages/admin/SettingsWhatsApp.vue`

Mesma pattern de `SettingsInfobip.vue`:
- Formulário com campos: Phone Number ID, WABA ID, Access Token, Verify Token, App Secret
- Botão "Testar conexão"
- Botão "Sincronizar templates"
- Lista de templates sincronizados (nome, status, idioma, categoria)

### Wizard Step 2 — Modo WhatsApp

Quando canal = `whatsapp` no wizard de campanha:
- Substitui editor de texto por **seletor de template**
- Select com templates aprovados (buscados via `GET /v1/whatsapp/templates`)
- Ao selecionar: preview do template com placeholders editáveis
- Campos para mapear variáveis: dropdown com opções `{nome}`, `{telefone}`, `{email}`, texto fixo

### Sidebar — WhatsApp na navegação

Adicionar item "WhatsApp" com sub-itens no `AppSidebar.vue`:
- Conversas (`/conversations`) — preparado, página placeholder até sub-projeto 2

---

## 7. Arquivos do Sub-projeto 1

### Backend — Criar
| Arquivo | Responsabilidade |
|---------|-----------------|
| `app/Services/WhatsApp/WhatsAppService.php` | Meta Cloud API client |
| `app/Http/Controllers/API/V1/WhatsAppController.php` | Templates, envio texto |
| `app/Http/Controllers/API/V1/WhatsAppWebhookController.php` | Webhook recebimento |
| `app/Http/Controllers/API/V1/Admin/WhatsAppSettingsController.php` | Config por tenant |
| `app/Http/Controllers/API/V1/ConversationController.php` | CRUD conversas |
| `app/Models/WhatsAppPhoneNumber.php` | Lookup tenant no webhook |
| `app/Models/Conversation.php` | Conversa bidirecional |
| `app/Models/ConversationMessage.php` | Mensagem individual |
| `app/Jobs/ProcessInboundMessageJob.php` | Stub (sub-projeto 3 implementa) |
| `database/migrations/xxx_alter_campaigns_add_whatsapp_type.php` | Enum type |
| `database/migrations/xxx_create_whatsapp_phone_numbers_table.php` | Lookup table |
| `database/migrations/xxx_create_conversations_table.php` | Conversas |
| `database/migrations/xxx_create_conversation_messages_table.php` | Mensagens |

### Backend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `routes/api.php` | Adicionar rotas WhatsApp, webhook, conversations |
| `config/logging.php` | Adicionar canal `whatsapp` |
| `app/Jobs/ProcessCampaignJob.php` | Adicionar case `whatsapp` no dispatch |
| `app/Services/CampaignStateMachine.php` | Aceitar `whatsapp` em assertCanDispatch |
| `database/seeders/GlobalSettingsSeeder.php` | Adicionar `credits_per_whatsapp` |

### Frontend — Criar
| Arquivo | Responsabilidade |
|---------|-----------------|
| `src/pages/admin/SettingsWhatsApp.vue` | Página settings WhatsApp |
| `src/components/campaigns/steps/Step2WhatsApp.vue` | Seletor de template + preview |

### Frontend — Modificar
| Arquivo | Alteração |
|---------|-----------|
| `src/pages/campaigns/Create.vue` | Condicionar Step 2 por canal (whatsapp → Step2WhatsApp) |
| `src/components/layout/AppSidebar.vue` | Adicionar "WhatsApp" + "Conversas" na nav |
| `src/router/index.ts` | Adicionar rotas admin/settings/whatsapp e /conversations |

---

## 8. Out of Scope (Sub-projetos futuros)

- **Sub-projeto 2:** Inbox UI, lista de conversas, tela de chat, handoff bot↔humano
- **Sub-projeto 3:** IA conversacional, persona/instruções, modo autônomo vs sugestão
- **Sub-projeto 4:** Editor visual de funil drag-and-drop, engine de execução, triggers

O `ProcessInboundMessageJob` é um **stub** neste sub-projeto (apenas loga a mensagem recebida). A lógica de resposta automática será implementada no sub-projeto 3.
