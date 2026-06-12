# Messaging APIs (SMS / Voz / Email) — Design

**Data:** 2026-05-26
**Autor:** PM + Claude
**Status:** Aprovado — pronto para plan

## 1. Contexto e Objetivo

Hoje o projeto envia SMS, voz e email **apenas via campanhas** (pipeline `Campaign` → `ProcessCampaignJob` → `SendCampaignBatchJob` → `InfobipService`). Não existem endpoints autenticados para envio direto (1‑off, transacional ou integração server‑to‑server). O `CreditService` já cobra crédito por campanha, mas o fluxo nunca foi auditado de ponta a ponta para garantir billing 100% correto sob concorrência, retry, opt‑out e janela horária.

**Objetivo:** entregar APIs autenticadas de envio direto (SMS, voz, email) com cálculo de cobrança auditável, opt‑out bloqueante, rate limit por tenant, idempotência e janela horária — sem regredir o fluxo de campanhas existente. Validação por Dev Senior, QA e Red Team, orquestrados por PM (gate em cada fase).

## 2. Decisões Resumidas

| Item | Decisão |
|------|---------|
| Escopo | Auditar campanhas + adicionar APIs diretas |
| Modelo de envio | Assíncrono (queue) com resposta `202 Accepted` |
| Provider SMS | Infobip (único) |
| Provider Voz | Infobip TTS nativo |
| Provider Email | Infobip (bulk/marketing) + Laravel Mail (transacional) |
| Tabela | Nova `message_dispatches` (separada de `campaign_dispatches`) |
| Opt‑out | Bloqueante, lista por tenant + canal |
| Rate limit | Por tenant + canal, configurável por plano |
| Janela horária | 22h–8h timezone destinatário, configurável por plano |
| Autenticação | Sanctum SPA token + Personal Access Token com abilities |
| Idempotência | Header `Idempotency-Key` obrigatório, TTL 24h |
| Time | PM + Dev Senior + QA + Red Team + Code Reviewer (subagents) |

## 3. Arquitetura

```
[Frontend / Integração externa]
        │
        ▼  (Sanctum SPA token OU Personal Access Token com abilities)
┌──────────────────────────────────────────┐
│ Controllers/API/V1/Messaging/             │
│   SmsController, VoiceController,         │
│   EmailController, DispatchesController   │
└──────────────────────────────────────────┘
        │
        ▼
┌──────────────────────────────────────────┐
│ MessagingService (fachada)                │
│  1) Idempotency key lookup                │
│  2) OptOutService.isOptedOut              │
│  3) QuietHoursService.isQuietHour         │
│  4) CreditService.reserve (atômico)       │
│  5) Cria MessageDispatch (status=queued)  │
│  6) Dispatch SendMessageJob               │
└──────────────────────────────────────────┘
        │
        ▼ (queue:messaging)
┌──────────────────────────────────────────┐
│ SendMessageJob                            │
│  - Resolve canal                          │
│  - InfobipService OU LaravelMail          │
│  - Sucesso: confirma débito               │
│  - Falha definitiva: libera crédito       │
│  - Retry com backoff em falha transitória │
└──────────────────────────────────────────┘
        │
        ▼
[Infobip API / SMTP]
        │
        ▼ (callback assíncrono)
┌──────────────────────────────────────────┐
│ /v1/webhooks/infobip/delivery (existente) │
│  - Lookup em message_dispatches PRIMEIRO  │
│  - Fallback em campaign_dispatches        │
└──────────────────────────────────────────┘

NOVO: /v1/webhooks/infobip/inbound       (opt-out SMS "SAIR")
NOVO: /v1/messaging/unsubscribe/{token}  (opt-out email link)
```

### 3.1 Componentes Novos

- `App\Services\Messaging\MessagingService` — orquestrador
- `App\Services\Messaging\OptOutService` — gerencia opt‑outs por tenant + canal
- `App\Services\Messaging\QuietHoursService` — valida janela horária por timezone
- `App\Jobs\SendMessageJob` — processa dispatch transacional
- `App\Models\MessageDispatch`
- `App\Models\MessageOptOut`
- `App\Http\Controllers\API\V1\Messaging\{Sms,Voice,Email,Dispatches,OptOuts}Controller`
- `App\Http\Controllers\API\V1\ApiTokensController`
- `App\Http\Middleware\EnsureTokenAbility`
- `App\Mail\TransactionalMailer` — wrapper que registra dispatch + chama `Mail::send`

### 3.2 Componentes Existentes (sem alteração de assinatura)

- `InfobipService::sendSms / sendEmail / sendVoice` (testado)
- `CreditService::reserve / release / debit` (atômico)
- `WebhookController::infobipDelivery` (lookup estendido)
- `SettingsService`
- `App\Services\TenantContext`

## 4. Modelo de Dados

### 4.1 Migration `create_message_dispatches_table`

```
message_dispatches
├─ id                     BIGINT PK
├─ tenant_id              BIGINT FK → tenants (indexed)
├─ user_id                BIGINT FK → users (nullable; null = sistema)
├─ channel                ENUM('sms','voice','email')
├─ source                 ENUM('api','transactional','internal') default 'api'
├─ to                     VARCHAR(255)        // E.164 ou email
├─ from                   VARCHAR(255) NULL
├─ subject                VARCHAR(255) NULL   // email
├─ content                TEXT
├─ audio_url              VARCHAR(500) NULL
├─ provider               VARCHAR(50)         // 'infobip' | 'laravel_mail'
├─ external_message_id    VARCHAR(120) NULL   (indexed)
├─ status                 ENUM(
│                            'queued','sending','sent','delivered','failed',
│                            'rejected_opt_out','rejected_quiet_hours'
│                          )
├─ credits_unit           INT
├─ credits_charged        INT                 // 0 se liberado
├─ idempotency_key        VARCHAR(64) NULL
├─ idempotency_payload_hash CHAR(64) NULL    // sha256 do payload para detectar replay com payload diferente
├─ unsubscribe_token      VARCHAR(64) NULL    // único para emails
├─ unsubscribe_consumed_at TIMESTAMP NULL    // single-use marker
├─ error_code             VARCHAR(64) NULL
├─ error_message          VARCHAR(500) NULL
├─ scheduled_for          TIMESTAMP NULL
├─ sent_at                TIMESTAMP NULL
├─ delivered_at           TIMESTAMP NULL
├─ failed_at              TIMESTAMP NULL
├─ meta                   JSON NULL
├─ created_at, updated_at

INDEXES:
  UNIQUE (tenant_id, idempotency_key)
  UNIQUE (unsubscribe_token)
  INDEX  (tenant_id, channel, created_at)
  INDEX  (external_message_id)
  INDEX  (status, scheduled_for)
```

### 4.2 Migration `create_message_opt_outs_table`

```
message_opt_outs
├─ id                 BIGINT PK
├─ tenant_id          BIGINT FK → tenants (indexed)
├─ channel            ENUM('sms','voice','email','all')
├─ identifier         VARCHAR(255)         // E.164 normalizado / email lowercased
├─ identifier_hash    CHAR(64)             // sha256 para lookup + LGPD
├─ reason             VARCHAR(50)          // 'user_request','sms_stop','email_link','bounce','complaint'
├─ source_dispatch_id BIGINT NULL          FK → message_dispatches
├─ created_at, updated_at

INDEXES:
  UNIQUE (tenant_id, channel, identifier_hash)
  INDEX  (tenant_id, identifier_hash)
```

### 4.3 Migration `add_messaging_settings_to_plans`

```
plans
+ rate_limit_sms_per_min        INT NULL   // override global
+ rate_limit_voice_per_min      INT NULL
+ rate_limit_email_per_min      INT NULL
+ credits_per_sms_override      INT NULL
+ credits_per_voice_override    INT NULL
+ credits_per_email_override    INT NULL
+ quiet_hours_enabled           BOOL default true
+ quiet_hours_start             TIME default '22:00:00'
+ quiet_hours_end               TIME default '08:00:00'
+ quiet_hours_timezone          VARCHAR(50) default 'America/Sao_Paulo'
```

### 4.4 Compatibilidade

- `campaign_dispatches` permanece intacto — campanhas continuam funcionando.
- `credit_transactions` ganha tipo `reference_type='message_dispatch'`.
- `tenants.credits_balance` é a única fonte da verdade.

## 5. Fluxo de Envio

### 5.1 Request síncrona (controller → service)

```
1. Middleware: auth:sanctum                       → 401 se inválido
2. Middleware: token-ability:messaging:<channel>  → 403 se token sem scope
3. Middleware: throttle:messaging-<channel>       → 429 com X-RateLimit-*
4. FormRequest valida payload                     → 422
5. MessagingService::dispatch():
   a) Se idempotency_key existe e dispatch <24h:
        - payload_hash bate → return dispatch existente (200, sem cobrar)
        - payload_hash diverge → 409 {error: "IDEMPOTENCY_KEY_REUSE"}
   b) Normaliza destinatário (E.164 / lowercased email)
   c) OptOutService::isOptedOut?
        → grava dispatch status='rejected_opt_out', credits=0
        → 422 {error: "RECIPIENT_OPTED_OUT"}
   d) QuietHoursService::isQuietHour?
        → header X-Quiet-Hours-Strategy:
            'reject'  → 422 {error: "QUIET_HOURS"}
            'defer'   → scheduled_for = próximo horário válido
   e) $unit = plan.credits_per_<channel>_override ?? global default
   f) DB::transaction:
        - Cria MessageDispatch (status='queued', credits_charged=0,
          credits_unit=$unit, idempotency_payload_hash=sha256(payload))
        - CreditService::reserve(tenant, $unit, 'message_dispatch', $dispatch->id)
            → false: rollback, lança InsufficientCreditsException
              → 402 {error: "INSUFFICIENT_CREDITS", required, balance}
   g) Dispatch SendMessageJob::dispatch($dispatch->id)->onQueue('messaging')
        → 202 {dispatch_id, status, credits_reserved, _links: {status: ...}}
```

### 5.2 Job (worker assíncrono)

```
$dispatch = MessageDispatch::lockForUpdate()->find($id);
if ($dispatch->status !== 'queued') return;
$dispatch->update(['status' => 'sending']);

try {
  $result = match($dispatch->channel) {
    'sms'   => $infobip->sendSms(...),
    'voice' => $infobip->sendVoice(...),
    'email' => $dispatch->source === 'transactional'
                 ? $mailer->send($dispatch)
                 : $infobip->sendEmail(...)
  };
} catch (RetryableException $e) {
  $this->release(30 * $this->attempts());
  throw $e;
}

if ($result['ok']) {
  $dispatch->update([
    'status' => 'sent',
    'external_message_id' => $result['message_id'],
    'credits_charged' => $dispatch->credits_unit,
    'sent_at' => now(),
  ]);
} else {
  CreditService::release($dispatch->tenant_id, $dispatch->credits_unit, 'message_dispatch', $dispatch->id);
  $dispatch->update([
    'status' => 'failed',
    'error_code' => $result['error_code'] ?? 'PROVIDER_ERROR',
    'error_message' => $result['error'],
    'failed_at' => now(),
    'credits_charged' => 0,
  ]);
}
```

### 5.3 Retry

- `tries=3`, `backoff=[30, 120, 600]` segundos.
- HTTP 5xx, timeout, conexão → retryable.
- HTTP 4xx (payload inválido, número bloqueado pelo provider) → não‑retryable, libera crédito imediato.
- Após 3 tentativas falhando → libera crédito + status `failed`.

### 5.4 Billing (matriz à prova de Red Team)

| Cenário | Crédito |
|---------|---------|
| Request inválida (422) | Não reserva |
| Saldo insuficiente (402) | Não reserva |
| Opt‑out / quiet hours | Não reserva; dispatch gravado para auditoria |
| Idempotency replay (24h) | Não reserva, retorna dispatch existente |
| Reservado, falha provider definitiva | Libera 100% |
| Reservado, sent, delivery=failed via webhook | Mantém débito (provider cobrou). Configurável `MESSAGING_REFUND_ON_UNDELIVERABLE` (default false) |
| Job estourou retries | Libera 100% |
| Sucesso → sent → delivered | Débito mantido |

### 5.5 Email transacional

`TransactionalMailer::send($to, Mailable $mailable, Tenant $tenant)`:
1. Cria `MessageDispatch` `source=transactional`, `credits_unit=MESSAGING_TRANSACTIONAL_EMAIL_CREDITS` (default 0).
2. Envia via `Mail::to($to)->send($mailable)`.
3. Atualiza dispatch para `sent` (Laravel Mail é síncrono por default; bounces via SMTP webhook depois).

## 6. Webhooks e Opt‑outs

### 6.1 `/v1/webhooks/infobip/delivery` (existente, lookup estendido)

```php
$dispatch = MessageDispatch::where('external_message_id', $msgId)->first()
         ?? CampaignDispatch::where('external_message_id', $msgId)->first();
```

### 6.2 `/v1/webhooks/infobip/inbound` (novo)

- Sem auth Sanctum; validação por secret `INFOBIP_INBOUND_WEBHOOK_SECRET` via header `Authorization: Bearer ...` com `hash_equals`.
- Payload Infobip: array `results[]` com `from`, `to`, `text`.
- Match conteúdo normalizado (uppercase, trim) contra `['SAIR','STOP','CANCELAR','PARAR','REMOVER']` → `OptOutService::add(tenant_id, 'sms', $from, 'sms_stop')` + resposta automática "Você foi removido. Para reativar envie ENTRAR."
- `['ENTRAR','START']` → remove opt‑out.
- Tenant resolvido via número de destino (`to`): lookup em `tenant_channels.identifier` onde `channel='sms'`. Se não encontrado, log warning + drop silencioso (não revela existência de tenant).

### 6.3 `/v1/messaging/unsubscribe/{token}` (público)

- Token = `HMAC_SHA256(dispatch.id || tenant.id, APP_KEY)`, 64 chars, gravado em `message_dispatches.unsubscribe_token`.
- Validação por `hash_equals`.
- Single‑use: marca `unsubscribe_consumed_at` (já no schema 4.1); replay retorna idempotente.
- Adiciona opt‑out `channel='email'`, redireciona para landing pública.

## 7. Endpoints (Resumo)

```
# Envio
POST   /v1/messaging/sms                 auth:sanctum + token-ability:messaging:sms     + throttle:messaging-sms
POST   /v1/messaging/voice               auth:sanctum + token-ability:messaging:voice   + throttle:messaging-voice
POST   /v1/messaging/email               auth:sanctum + token-ability:messaging:email   + throttle:messaging-email

# Consulta
GET    /v1/messaging/dispatches          auth:sanctum + token-ability:messaging:read
GET    /v1/messaging/dispatches/{id}     auth:sanctum + token-ability:messaging:read

# Opt-outs
GET    /v1/messaging/opt-outs            auth:sanctum + token-ability:messaging:read
POST   /v1/messaging/opt-outs            auth:sanctum + token-ability:messaging:*
DELETE /v1/messaging/opt-outs/{id}       auth:sanctum + token-ability:messaging:*

# API Tokens (server-to-server)
GET    /v1/auth/api-tokens               auth:sanctum
POST   /v1/auth/api-tokens               auth:sanctum
DELETE /v1/auth/api-tokens/{id}          auth:sanctum

# Webhooks (sem auth Sanctum; validação por secret)
POST   /v1/webhooks/infobip/inbound      throttle:webhook
POST   /v1/webhooks/infobip/delivery     throttle:webhook   (existente)

# Unsubscribe (público, token HMAC)
GET    /v1/messaging/unsubscribe/{token} throttle:60,1

# Admin (superadmin)
GET    /v1/admin/messaging/pricing       superadmin
PUT    /v1/admin/messaging/pricing       superadmin
GET    /v1/admin/messaging/stats         superadmin
```

Total: **15 rotas novas**. Todas as 12 não‑públicas autenticadas.

## 8. Segurança (Red Team Checklist)

| # | Vetor | Mitigação |
|---|-------|-----------|
| 1 | Authn bypass | Todas rotas testadas sem token / token expirado / token revogado |
| 2 | Authz bypass / IDOR | Policy `dispatch.tenant_id === auth().tenant_id`; superadmin auditado |
| 3 | Token scope bypass | `EnsureTokenAbility` valida exato |
| 4 | Idempotency abuse | UNIQUE `(tenant_id, idempotency_key)`; payload hash registrado para detectar replay com payload diferente |
| 5 | Race no crédito | `lockForUpdate` em `tenants` no `reserve` (já implementado) |
| 6 | Opt‑out bypass por normalização | E.164 + lowercase email + trim antes de hash; `identifier_hash` único |
| 7 | SSRF audio_url | Allowlist de host via `MESSAGING_AUDIO_URL_ALLOWLIST`; rejeita IPs privados/redirects |
| 8 | XSS email | HTMLPurifier no body antes de enviar |
| 9 | Header/SMTP injection | Strip `\r\n` em subject e from |
| 10 | Webhook spoofing | `hash_equals` com secret; secret separado por endpoint |
| 11 | Unsubscribe brute | Token HMAC 64 chars; rate limit 60/min na rota pública |
| 12 | Rate limit bypass | Bucket por `tenant_id` (não por IP / user / token) |
| 13 | Quiet hours bypass | Server‑side com timezone do destinatário; `scheduled_for` validado futuro |
| 14 | Credit drain via opt‑out probing | Checagem NÃO debita; resposta uniforme 422 sem revelar existência (timing constante) |
| 15 | Logs leak | Tokens hasheados; headers Authorization redacted; content truncado em logs |
| 16 | LGPD content access | Usuário comum vê `content` mascarado de dispatches de outros usuários do mesmo tenant; superadmin loggado |
| 17 | CSRF nas rotas UI (api-tokens) | Sanctum SPA mode protege; verificar |
| 18 | Validação E.164 | Regex `/^\+?[1-9]\d{6,14}$/`; rejeita números premium, null byte |
| 19 | Mass‑assignment | `$fillable` explícito; nunca `Request::all()` direto |
| 20 | Time‑based attacks | `hash_equals` em todos secret/token comparisons |

## 9. Estratégia de Testes

### 9.1 Unit

- `MessagingServiceTest` — idempotency, opt‑out, quiet hours, insufficient credits, normalização, plan override
- `OptOutServiceTest` — add/remove, hash determinístico, lookup por canal e `all`, idempotente
- `QuietHoursServiceTest` — timezone map, fronteira, `scheduled_for` pula validação
- `SendMessageJobTest` — success, retry em 5xx, release em 4xx final, `lockForUpdate` previne dupla execução
- `MessageDispatchPolicyTest` — cross‑tenant isolation
- `EnsureTokenAbilityMiddlewareTest` — `tokenCan` true/false, sem token, ability `*`
- `RateLimiterMessagingTest` — 60ª passa, 61ª 429 com `Retry-After`

### 9.2 Feature

- `SendSmsApiTest`, `SendVoiceApiTest`, `SendEmailApiTest` — happy path, 401, 403, 422 (várias razões), 402, 429, idempotency replay
- `GetDispatchStatusTest` — transições + IDOR retorna 404
- `WebhookDeliveryRoutingTest` — lookup dual table funciona
- `InboundWebhookSmsOptOutTest` — SAIR adiciona + resposta auto; ENTRAR remove
- `EmailUnsubscribeTest` — token válido, alterado, replay idempotente
- `ApiTokenCrudTest` — create / list (sem plaintext) / revoke
- `TransactionalEmailRegistersDispatchTest` — welcome email cria dispatch source=transactional, credits=0
- `BillingFlowTest` — reserve no controller, confirm no job sucesso, release no job falha, sem double‑charge em retry

### 9.3 Pentest (`tests/Pentest/MessagingSecurityTest.php`)

20 cenários da seção 8 cobertos por testes automatizados. Manuais via Burp/curl: web request smuggling, prototype pollution em meta JSON, race em idempotency entre N workers.

### 9.4 DoD (Definition of Done)

1. `php artisan test` 100% green (existentes + novos)
2. Coverage ≥80% nos arquivos novos
3. Zero achado crítico/alto do Red Team
4. Toda rota nova requer auth (exceto whitelist documentada)
5. Lint/format passa
6. Spec + plan commitados em `docs/superpowers/`
7. Smoke E2E real: 1 SMS + 1 voice + 1 email com Infobip de produção, log gravado

## 10. Estrutura do Time e Roadmap

### 10.1 Time

| Papel | Responsabilidade | Agent |
|-------|------------------|-------|
| PM | Aprovação, sequenciamento, gate por fase | conversa principal |
| Dev Senior | Implementação | `general-purpose` em worktree isolado |
| QA | Testes Unit + Feature | `general-purpose` |
| Red Team | Pentest + relatório | `superpowers:code-reviewer` + `general-purpose` |
| Code Reviewer | Review final | `superpowers:code-reviewer` |

### 10.2 Fases (gate PM em cada)

```
Fase 0  Setup: spec + plan + worktree                    [conversa]
Fase 1  Auditoria rotas existentes + webhook lookup       [Dev]    ~1h
Fase 2  Implementação (migrations, services, jobs, ctrl)  [Dev]    ~6-8h
Fase 3  QA: testes Unit + Feature, coverage ≥80%          [QA]     ~4h
Fase 4  Red Team: pentest 20 cenários + fixes             [Red]    ~3h
Fase 5  Code Review: contra spec + plan                   [Rev]    ~1h
Fase 6  Merge + smoke E2E real                            [conversa]
```

## 11. Configurações `.env`

```
MESSAGING_QUIET_HOURS_DEFAULT_TZ=America/Sao_Paulo
MESSAGING_IDEMPOTENCY_TTL_HOURS=24
MESSAGING_AUDIO_URL_ALLOWLIST=s3.amazonaws.com,storage.googleapis.com
MESSAGING_TRANSACTIONAL_EMAIL_CREDITS=0
MESSAGING_REFUND_ON_UNDELIVERABLE=false
INFOBIP_INBOUND_WEBHOOK_SECRET=...
```

## 12. Riscos e Mitigações

| Risco | Probabilidade | Impacto | Mitigação |
|-------|---------------|---------|-----------|
| Quebra do fluxo de campanhas existente | Baixa | Alto | Tabela separada; testes de regressão `CampaignStateMachineTest` mantidos; smoke test campanha na fase 6 |
| Race condition no débito com alto volume | Média | Alto | `lockForUpdate` + teste de carga simulado no Red Team (item 5) |
| Webhook inbound recebe mensagem comum (não opt‑out) e responde por engano | Média | Médio | Match estrito contra lista fechada; resposta auto APENAS se match exato |
| Infobip muda formato de delivery report | Baixa | Médio | Mantém parsing tolerante (já implementado); log de payloads novos |
| LGPD: vazamento de número/email em logs ou GET | Média | Alto | Mascaramento server‑side; auditoria de acesso a `content` |
