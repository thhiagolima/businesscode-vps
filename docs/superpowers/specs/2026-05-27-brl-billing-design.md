# BRL Billing — Migração de Créditos para Reais — Design

**Data:** 2026-05-27
**Autor:** PM + Claude
**Status:** Aprovado — pronto para plan

## 1. Contexto e Objetivo

Hoje o sistema cobra por **créditos** (inteiro): `tenants.credits_balance`, `plans.credits_included`, `credits_per_sms/voice/email/ai`, `MessageDispatch.credits_unit/charged`. Cada serviço consome uma quantidade fixa de créditos (1 SMS = 1 crédito, 1 voz = 5, etc.). O usuário recarrega créditos via Mercado Pago. Modelo simples mas:
- Esconde o custo real do tenant (não vê R$, só "créditos")
- Dificulta diferenciação comercial (cliente VIP com preço melhor)
- Não permite linha de crédito / faturamento postpaid
- Margem (custo vs venda) não é visível em lugar nenhum

**Objetivo:** substituir totalmente o modelo de créditos por **R$ em centavos (integer)**, expondo cost vs sale price ao admin financeiro, permitindo override por tenant, suportando linha de crédito opcional com dunning (7d grace + retry diário + block) e cobrança mensal automática no aniversário do tenant.

## 2. Decisões Resumidas

| Item | Decisão |
|------|---------|
| Modelo | Substituição total (créditos → cents R$) |
| Estrutura preços | `cost_cents` + `sale_cents` global + override `sale_cents` por tenant |
| Conversão dados | 1:1 deliberadamente uniforme: cada crédito existente vira `BILLING_MIGRATION_PRICE_CENTS` (default 15) — tratamento "SMS-equivalente" mesmo para créditos que historicamente custariam mais (voz=5cr). Tradeoff aceito porque crédito é unidade abstrata; auditoria contábil sai pelo balance positivo equivalente. |
| Plano | `included_balance_cents` mensal; remove `overage_rate_*` |
| Cobrança | Prepaid + linha de crédito opcional (`credit_limit_cents` per tenant) |
| Inadimplência | 7d grace + retry diário + block dia 8 |
| Papéis | superadmin (existente) + novo `finance` |
| Ciclo cobrança | Aniversário do tenant (`billing_cycle_day` baseado em created_at) |
| Currency | Integer cents — zero float arithmetic |
| Snapshot pricing | `MessageDispatch` grava `cost_cents/sale_cents` do momento (preços novos NÃO afetam histórico) |
| Idempotência cobrança | UNIQUE `balance_transactions(reference_type='monthly_billing', reference_id=YYYYMM:tenant_id)` |
| Audit | Toda ação financeira em `audit_logs` + `balance_transactions` |

## 3. Arquitetura

```
                        [Admin/Finance UI Vue]
                                  │
                                  ▼  (Sanctum + role:superadmin|finance)
   ┌────────────────────────────────────────────────────────────────┐
   │ Controllers/API/V1/Admin/                                       │
   │   ServicePricingController     (CRUD service_prices global)     │
   │   TenantPricingController      (override sale_cents per tenant) │
   │   TenantCreditLineController   (set credit_limit_cents)         │
   │   BillingReportController      (margem, MRR, inadimplência)     │
   └────────────────────────────────────────────────────────────────┘
                                  │
                                  ▼
   ┌────────────────────────────────────────────────────────────────┐
   │ App\Services\Billing\BillingService  (renomeia CreditService)   │
   │  reserve(tenant, cents, ref) → respeita balance + credit_line   │
   │  confirm(tenant, cents, ref) → marca como cobrado               │
   │  release(tenant, cents, ref) → devolve no fail                  │
   │  chargeOverdueBalance(tenant) → cobra cartão se negativo        │
   │  Atômico via DB::transaction + lockForUpdate em tenants         │
   └────────────────────────────────────────────────────────────────┘
                                  │
        ┌─────────────────────────┼─────────────────────────┐
        ▼                         ▼                         ▼
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────────────┐
│ PricingService   │  │ MessagingService │  │ MonthlyBillingJob        │
│  priceFor(tenant,│  │ (Phase 2 msging) │  │  - dispara no aniversário│
│  service)        │  │ chama Billing-   │  │  - chargeOverdueBalance  │
│  → {cost, sale}  │  │ Service no lugar │  │    se balance < 0        │
│  3 níveis:       │  │ de CreditService │  │  - 7d grace + retry      │
│  override>plano  │  │                  │  │    diário                │
│  >global         │  │                  │  │  - bloqueia se 7d sem ok │
└──────────────────┘  └──────────────────┘  └──────────────────────────┘
                                                       │
                                                       ▼
                                          ┌──────────────────────────┐
                                          │ MercadoPagoService       │
                                          │ chargeStoredCard(tenant, │
                                          │   amount_cents, ref)     │
                                          └──────────────────────────┘
                                                       │
                                                       ▼
                          Webhooks MP → BillingService.applyChargeResult()
```

### 3.1 Componentes novos

- `App\Services\Billing\BillingService` — substitui `CreditService` (mesma assinatura, unidade cents)
- `App\Services\Billing\PricingService` — substitui `App\Services\Messaging\PricingService`, expandido para todos os 5 serviços (sms, voice, email, ai_generation, audio_tts)
- `App\Jobs\DispatchMonthlyBillingJob` — orquestrador diário (cron)
- `App\Jobs\MonthlyBillingJob` — execução por tenant
- `App\Jobs\DispatchOverdueRetryJob` — orquestrador diário do dunning
- `App\Jobs\OverdueRetryJob` — retenta cobrança no grace period
- `App\Models\ServicePrice` — `(service, cost_cents, sale_cents)` global, 1 linha por serviço
- `App\Models\TenantServicePrice` — override `(tenant_id, service, sale_cents)`
- `App\Notifications\*` — 8 templates: monthly-success, overdue-warning-day1/retry/final, tenant-suspended, card-expired, card-missing, credit-limit-warning
- 4 controllers em `Http/Controllers/API/V1/Admin/`
- Middleware `EnsureRole` (aceita string ou array)
- 5 FormRequests + audit em todas as ações

### 3.2 Componentes alterados

- `App\Models\Tenant`:
  - DROP `credits_balance`
  - ADD `balance_cents BIGINT` (pode ser NEGATIVO até -credit_limit_cents)
  - ADD `credit_limit_cents BIGINT` (>= 0; 0 = prepaid puro)
  - ADD `billing_status ENUM('active','grace','suspended','blocked')`
  - ADD `billing_cycle_day TINYINT 1-28`
  - ADD `last_billing_at TIMESTAMP NULL`
  - ADD `overdue_since TIMESTAMP NULL`
  - ADD `overdue_attempts TINYINT default 0`
  - ADD `mp_customer_id`, `mp_default_card_id` (preparação)

- `App\Models\Plan`:
  - DROP `credits_included`, `overage_rate_sms/voice/email/ai`, `credits_per_sms/voice/email_override`
  - ADD `included_balance_cents BIGINT default 0`
  - ADD `sale_cents_overrides JSON NULL` (e.g. `{"sms": 12, "voice": 70}`). Validado em runtime: chaves devem ser do enum de serviços; valores int 0..10000000. Tenant-level override (`tenant_service_prices`) tem precedência sobre plan-level.

- `App\Models\MessageDispatch`:
  - DROP `credits_unit`, `credits_charged`
  - ADD `cost_cents INT default 0` (snapshot)
  - ADD `sale_cents INT default 0` (snapshot)
  - ADD `charged_cents INT default 0` (0 se liberado)

- `App\Models\CreditTransaction` → renomeia `BalanceTransaction`:
  - RENAME tabela `credit_transactions` → `balance_transactions`
  - DROP `amount INT`
  - ADD `amount_cents BIGINT`
  - DROP `balance_after`
  - ADD `balance_after_cents BIGINT`
  - Atualiza ENUM `type`: + `'recharge'`, `'monthly_charge'`, `'manual_adjustment'`

- `App\Services\Messaging\MessagingService` — usa `BillingService` em vez de `CreditService`
- `App\Jobs\SendMessageJob` — mesma troca; release usa `BillingService`
- `users.role` ENUM: + `'finance'`

### 3.3 Componentes reusados sem mudança externa

- `MercadoPagoService` — adiciona `chargeStoredCard()` method (resto intacto)
- `MessageDispatch` lookup pelo webhook — só nome dos campos mudou
- `Plan::priceFor(cycle)` — preço da assinatura, separado do saldo incluído

## 4. Schema (Migrations)

### 4.1 `2026_05_27_000001_create_service_prices_table`
```
service_prices
├─ id              BIGINT PK
├─ service         VARCHAR(50) UNIQUE
├─ cost_cents      INT
├─ sale_cents      INT
├─ updated_by      BIGINT FK → users
├─ created_at, updated_at
```
Seed: sms(8,15), voice(40,80), email(2,5), ai_generation(10,25), audio_tts(30,60).

### 4.2 `2026_05_27_000002_create_tenant_service_prices_table`
```
tenant_service_prices
├─ id              BIGINT PK
├─ tenant_id       BIGINT FK → tenants
├─ service         VARCHAR(50)
├─ sale_cents      INT
├─ reason          VARCHAR(200) NULL
├─ created_by      BIGINT FK → users
├─ created_at, updated_at

UNIQUE (tenant_id, service)
INDEX  (tenant_id)
```

### 4.3 `2026_05_27_000003_alter_tenants_for_brl_billing`
Drop `credits_balance`. Add 6+2 colunas billing+MP. Migration data-aware popula `balance_cents = credits_balance * 15` (configurável via env `BILLING_MIGRATION_PRICE_CENTS`) e `billing_cycle_day = LEAST(DAY(created_at), 28)`. `down()` simétrico.

### 4.4 `2026_05_27_000004_alter_plans_for_brl_billing`
Drop 8 colunas legacy, add `included_balance_cents` + `sale_cents_overrides`. Migration data-aware: `included_balance_cents = credits_included * 15`.

### 4.5 `2026_05_27_000005_alter_message_dispatches_for_brl`
Drop `credits_*`, add `cost_cents/sale_cents/charged_cents`. Migration data-aware: `cost_cents = credits_unit * 8`, `sale_cents = credits_unit * 15`, `charged_cents = credits_charged * 15`.

### 4.6 `2026_05_27_000006_rename_credit_transactions`
RENAME table. Drop `amount`/`balance_after` (INT), add `amount_cents`/`balance_after_cents` (BIGINT). Estende ENUM type. Migration data-aware: multiplica todos os valores por 15. Cria VIEW `credit_transactions` apontando para `balance_transactions` durante 30 dias (deprecation period).

### 4.7 `2026_05_27_000007_add_finance_role`
`ALTER users.role` ENUM adiciona `'finance'`.

## 5. Fluxo

### 5.1 Envio (prepaid + linha de crédito)

```
POST /v1/messaging/sms
  → SendSmsRequest
  → MessagingService::dispatch:
    1. PhoneNormalizer::e164(to)
    2. IdempotencyService::lookup → return hit
    3. OptOutService::isOptedOut → 422 RECIPIENT_OPTED_OUT
    4. QuietHoursService::isQuietHour → 422 QUIET_HOURS ou defer
    5. PricingService::priceFor(tenant, 'sms')
       → ordem: TenantServicePrice → Plan.sale_cents_overrides[service] → ServicePrice
       → retorna {cost_cents, sale_cents}
    6. DB::transaction:
       a. Cria MessageDispatch(status='queued', cost_cents, sale_cents, charged_cents=0)
       b. BillingService::reserve(tenant, sale_cents, 'message_dispatch', dispatch.id)
          → lockForUpdate em tenants
          → ok se balance_cents - sale_cents >= -credit_limit_cents
          → balance_cents -= sale_cents
          → balance_transaction(type='reserve', amount=-sale_cents)
          → false → InsufficientFundsException (422 ou 402)
       c. SendMessageJob::dispatch
       d. 202 {dispatch_id, sale_cents_reserved, _links}
```

### 5.2 Linha de crédito

```
credit_limit_cents = 0 (default) → prepaid puro; saldo não pode ficar negativo
credit_limit_cents = 50000        → balance pode ir até -50000 (R$ -500)

Caso: saldo R$ 10, limit R$ 500
  envia até esgotar saldo, depois usa linha de crédito
  R$ -500,00 atingido → 402 CREDIT_LIMIT_REACHED
```

### 5.3 SendMessageJob

```
1. lockForUpdate dispatch → status='sending'
2. InfobipService::sendSms (ou TransactionalMailer para email transacional)
3. Sucesso:
     dispatch.update(status='sent', external_message_id, charged_cents=sale_cents, sent_at)
     balance_transaction(type='confirm', amount=0)  // já decrementado no reserve
4. Falha definitiva (4xx):
     BillingService::release(tenant, sale_cents, 'message_dispatch', dispatch.id)
       balance_cents += sale_cents
       balance_transaction(type='release', amount=+sale_cents)
     dispatch.update(status='failed', charged_cents=0)
5. Transitória (5xx, timeout):
     re-throw TransientProviderException → queue retenta
     ao final dos retries: release como em 4
```

### 5.4 Cobrança mensal — aniversário

```
Cron daily 03:00 SP:
  DispatchMonthlyBillingJob
    SELECT tenants WHERE billing_cycle_day = DAY(today)
      AND (last_billing_at IS NULL OR last_billing_at < today - 25 days)
    foreach: MonthlyBillingJob::dispatch(tenant.id)

MonthlyBillingJob (per tenant):
  DB::transaction lockForUpdate(tenant):
    // 1) Adiciona saldo do plano
    if (tenant.plan.included_balance_cents > 0):
      tenant.balance_cents += plan.included_balance_cents
      balance_transactions(type='credit', amount=+included, reason='monthly_plan_credit')

    // 2) Se saldo ainda negativo → cobra cartão
    if (tenant.balance_cents < 0):
      debt = abs(tenant.balance_cents)
      result = MP::chargeStoredCard(tenant, debt, 'monthly:tenant.id:YYYYMM')
      if result.ok:
        tenant.balance_cents += debt
        balance_transactions(type='monthly_charge', amount=+debt)
        tenant.billing_status = 'active'; overdue_* = null
      else:
        tenant.billing_status = 'grace'
        tenant.overdue_since = now()
        tenant.overdue_attempts = 1
        notify OverdueWarning(day=1)

    tenant.last_billing_at = now()
```

### 5.5 Dunning (grace → block)

```
Cron daily 04:00 SP:
  DispatchOverdueRetryJob
    SELECT tenants WHERE billing_status IN ('grace','suspended')
    foreach: OverdueRetryJob::dispatch(tenant.id)

OverdueRetryJob:
  days_overdue = diff(now, overdue_since)
  if (days_overdue <= 7):
    chargeStoredCard(abs(balance_cents))
    if ok: status='active'; clear overdue_*
    else: overdue_attempts++; notify OverdueWarning(day=N)
  elif (days_overdue == 8):
    status='blocked'
    notify TenantSuspended
  // dívida permanece como balance_cents negativo (contas a receber manual)
```

### 5.6 Estados e respostas API

| Cenário | Status | API resposta |
|---------|--------|--------------|
| Saldo + limite OK | active | 202 |
| Estoura limite | active | 402 INSUFFICIENT_FUNDS |
| Falha definitiva job | active | (não-API; release) |
| Tenant em grace, envia | grace | 202 (até o limite) |
| Tenant suspended | suspended | 402 BILLING_SUSPENDED |
| Tenant blocked | blocked | 402 BILLING_BLOCKED |

## 6. Cron + Comandos + Mercado Pago

### 6.1 Schedule

```php
$schedule->job(new DispatchMonthlyBillingJob)
    ->dailyAt('03:00')->timezone('America/Sao_Paulo')
    ->name('billing.monthly-dispatch')
    ->withoutOverlapping(60)->onOneServer();

$schedule->job(new DispatchOverdueRetryJob)
    ->dailyAt('04:00')->timezone('America/Sao_Paulo')
    ->name('billing.overdue-retry')
    ->withoutOverlapping(60)->onOneServer();
```

### 6.2 Comandos Artisan

```
php artisan billing:status                          # snapshot global
php artisan billing:status {tenantId}               # detalhe tenant
php artisan billing:bill {tenantId}                 # força MonthlyBillingJob
php artisan billing:retry {tenantId}                # força OverdueRetryJob
php artisan billing:unlock {tenantId} --reason="..."     # libera manual
php artisan billing:adjust {tenantId} {cents} --reason="..."  # ajuste manual
php artisan billing:recheck-status                  # varre + corrige status
php artisan billing:report {--month=YYYY-MM} --csv   # exporta receita/margem
php artisan billing:pre-migration-check             # pré-flight
```

Todos exigem confirmação para ações destrutivas; todos gravam em `audit_logs`.

### 6.3 `MercadoPagoService::chargeStoredCard`

```php
public function chargeStoredCard(Tenant $tenant, int $amountCents, string $description): array
{
    if (!$tenant->mp_customer_id || !$tenant->mp_default_card_id) {
        return ['ok' => false, 'error' => 'NO_STORED_CARD', 'mp_payment_id' => null];
    }
    $response = $this->client->post('/v1/payments', [
        'transaction_amount' => $amountCents / 100,
        'description'        => $description,
        'payment_method_id'  => 'credit_card',
        'payer'              => ['type' => 'customer', 'id' => $tenant->mp_customer_id],
        // MP exige gerar um card-token a partir do card_id salvo (POST /v1/card_tokens)
        // antes de criar payment; método helper a implementar dentro do MercadoPagoService
        'token'              => $this->createTokenFromStoredCard($tenant->mp_customer_id, $tenant->mp_default_card_id),
        'installments'       => 1,
        'external_reference' => 'monthly:' . $tenant->id . ':' . now()->format('Ym'),
        'notification_url'   => url('/api/v1/webhooks/mercadopago'),
        'metadata'           => ['tenant_id' => $tenant->id, 'billing_kind' => $description],
    ]);
    if (!$response->successful()) {
        return ['ok' => false, 'error' => $response->json('message') ?? 'MP_API_ERROR'];
    }
    $body = $response->json();
    return [
        'ok'             => $body['status'] === 'approved',
        'mp_payment_id'  => $body['id'],
        'mp_status'      => $body['status'],
        'error'          => $body['status'] === 'approved' ? null : ($body['status_detail'] ?? 'rejected'),
    ];
}
```

Edge cases: `pending`/`in_process` → tratar como falha + retry; 5xx → re-throw `TransientProviderException`; `cc_rejected_expired` → email específico; `cc_rejected_fraud` → email genérico.

### 6.4 Notificações

8 templates em `resources/views/emails/billing/`:
monthly-success, overdue-warning-day1, overdue-warning-retry, overdue-final, tenant-suspended, card-expired, card-missing, credit-limit-warning (opcional fase 2).

### 6.5 Webhook MP — handler

`WebhookController::mercadopago` ganha branch para `external_reference` `monthly:*`:
- `approved` → confirma + balance updated (idempotente via UNIQUE em `balance_transactions(monthly_billing, YYYYMM:tenant_id)`)
- `rejected` → status='grace', overdue_attempts++
- `refunded` → reverso

### 6.6 `.env`

```
BILLING_OVERDUE_GRACE_DAYS=7
BILLING_OVERDUE_RETRY_HOUR=04
BILLING_MONTHLY_DISPATCH_HOUR=03
BILLING_CREDIT_LIMIT_WARNING_PCT=80
BILLING_DEFAULT_INCLUDED_BALANCE_CENTS=0
BILLING_MIGRATION_PRICE_CENTS=15
```

### 6.7 Dashboard endpoint

`GET /v1/admin/billing/stats` retorna agregados (total positivo, total devido, MRR 30d, margem 30d, taxa recuperação, tenants por status).

## 7. UI

### 7.1 Admin/Finance (Vue + Tabler)

- **`/admin/billing/pricing`** — tabela 5 serviços inline-editable; modal com razão obrigatória; audit. Aviso: alterações só afetam novos envios.
- **`/admin/billing/tenants`** — listagem paginada; filtros (status, busca, saldo, uso); destaque visual grace/suspended; ações por linha.
- **`/admin/billing/tenants/{id}`** — drill-down 3 abas: Saldo&Limite, Preços Customizados, Histórico Financeiro. Ações: aumentar/diminuir limite, ajuste manual, forçar cobrança, desbloquear.
- **`/admin/billing/reports`** — dashboard cards + gráficos Chart.js (receita 90d, margem por serviço 30d, status pie, top 10). Exportar CSV.

### 7.2 User-facing

- **`/settings/saldo`** (renomeia `/settings/credits`) — cards saldo/limite/próx cobrança, recarregar via MP, histórico recente, banner se grace/suspended.
- **Checkout** — checkbox "Salvar cartão para débito mensal" (marcado default); salva `mp_customer_id` + `mp_default_card_id`.
- **Componentes substituídos** — `OrderSummary, CheckoutForm, ConfirmSendModal, Step5Review, VoiceStudio, AppSidebar, dashboard/Index` — todos "créditos" → "R$".
- **Banner global de uso** — visível quando `balance_cents < 0`: azul 50%, laranja 80%, vermelho 100%.

### 7.3 Roteamento + sidebar

```ts
{ path: '/admin/billing/pricing',     meta: { role: 'finance', title: 'Preços de Serviços' } },
{ path: '/admin/billing/tenants',     meta: { role: 'finance' } },
{ path: '/admin/billing/tenants/:id', meta: { role: 'finance' } },
{ path: '/admin/billing/reports',     meta: { role: 'finance' } },
{ path: '/settings/saldo',            meta: { title: 'Meu Saldo' } },
```

Router guard: `meta.role` aceita string ou array; libera se `auth.user.role === meta.role || auth.user.role === 'superadmin'`.

Sidebar ganha seção "Financeiro" (visível para superadmin|finance) com 3 itens. User comum vê "Meu Saldo".

## 8. Migração de Dados + Rollback

### 8.1 Sequência (janela 15-30min)

```
Pré-deploy (sem downtime):
  1. Deploy código com flag BILLING_BRL_ENABLED=false; checkout salva mp_customer_id

Janela:
  2. php artisan down --secret=... --refresh=30
  3. php artisan billing:pre-migration-check (aborta se falhar)
  4. php artisan migrate --force (executa 7 migrations data-aware)
  5. php artisan db:seed --class=ServicePricesSeeder
  6. BILLING_BRL_ENABLED=true && php artisan config:clear
  7. Smoke: php artisan billing:status + 1 SMS API real
  8. php artisan up
```

### 8.2 Pré-flight check

`php artisan billing:pre-migration-check`:
- Backup DB confirmado
- Zero jobs pendentes em queues messaging/billing/campaigns
- Zero campanhas 'running'
- MP credentials válidos
- APP_ENV ∈ {staging, production}
- BILLING_MIGRATION_PRICE_CENTS definido
- Summary: "X tenants serão convertidos, total Y créditos → Z cents. Continuar? [y/N]"

### 8.3 Rollback

**Cenário A — migration falha (raro):** rollback automático Laravel; corrigir + re-rodar.

**Cenário B — smoke falha:** `php artisan migrate:rollback --step=7` + reativa flag false + `up`. `down()` migrations simétricas restauram credits.

**Cenário C — problema horas depois:** runbook separado em `docs/runbooks/billing-rollback.md` com:
- mysqldump pré-rollback (preserva audit)
- aplica down migrations
- reconcilia balance_transactions
- documentação para suporte

### 8.4 Comunicação (produção)

- **T-7 dias:** email "modernizaremos cobrança em R$, mesmo poder de compra, R$ 0,15/SMS"
- **T-1 dia:** reminder + link staging preview + FAQ + WhatsApp suporte
- **Dia D:** "migração concluída, seu saldo é R$ X" + CTA cartão recorrente
- **T+30 dias:** NPS de 1 pergunta

### 8.5 Riscos

| Risco | Prob | Impact | Mitigação |
|-------|------|--------|-----------|
| Migration lenta com milhões de rows | Baixa | Médio | Batch via job se crescer; hoje ~10k dispatches |
| Tenant reclama do valor convertido | Média | Baixo | Comunicação T-7d + conversão 1:1 |
| MP rejeita sem cartão salvo | Alta inicial | Médio | UI checkout pede cartão; banner pede cadastro; credit_limit=0 default impede débito |
| Rename quebra integrações externas | Baixa | Alto | VIEW `credit_transactions` apontando pra nova tabela 30 dias |
| Cron mensal não roda | Baixa | Alto | Healthcheck endpoint + alerta auto |
| Job em loop | Média | Alto | tries=3, backoff [60,600,3600], failed_jobs alert |
| Race 2 cobranças | Baixa | Alto | UNIQUE + lockForUpdate |
| Override retroativo | Média | Médio | sale_cents é snapshot; UI alerta |

## 9. Testes

### 9.1 Unit (~10 arquivos)

- `PricingServiceTest` — 3 níveis de resolução; margins
- `BillingServiceTest` — reserve/confirm/release; lockForUpdate; race
- `MonthlyBillingJobTest` — plan credit; charge negative; idempotência
- `OverdueRetryJobTest` — sucesso grace; falha 7d block; sem cartão
- `ServicePricePolicyTest` — só superadmin|finance
- `TenantServicePriceTest` — per-tenant isolation
- `EnsureRoleMiddlewareTest` — string vs array; superadmin wildcard

### 9.2 Feature (~15 arquivos)

- `AdminPricingApiTest`, `TenantPricingApiTest`, `TenantCreditLineApiTest` — CRUD por role
- `BalanceFlowTest` — happy/release/credit line
- `BlockedTenantCannotSendTest` — 3 canais bloqueados
- `MonthlyBillingDispatchTest` — seleciona corretos; last_billing_at
- `OverdueDunningTest` — flow grace→active e grace→block
- `MercadoPagoChargeStoredCardTest` — fake + sucesso/falha
- `MpWebhookMonthlyChargeIdempotentTest` — UNIQUE previne duplicate
- `BillingStatsReportTest` — agregados
- `MessageDispatchSnapshotsPriceTest` — preço antigo preservado
- `MigrationRoundtripTest` — migrate/rollback/migrate consistente

### 9.3 Pentest (`tests/Pentest/BillingSecurityTest.php`)

20 cenários:

| # | Vetor |
|---|-------|
| 1 | Negative balance overflow (1 cent além do limite) |
| 2 | Cross-tenant credit_limit grant |
| 3 | Cross-tenant override visibility |
| 4 | Race no reserve (50 paralelos, saldo para 10) |
| 5 | Race na cobrança mensal (2 jobs simultâneos) |
| 6 | Webhook MP forge |
| 7 | Audit log bypass (PUT sem razão) |
| 8 | Role escalation (user → admin/billing) |
| 9 | Finance role escalation (finance → DELETE tenant) |
| 10 | Stored card replay (webhook duplicado) |
| 11 | Negative sale_cents override |
| 12 | Excessive sale_cents override (>R$ 100k/msg) |
| 13 | Manual adjustment sem razão |
| 14 | Suspended tenant bypass via token cached |
| 15 | Concurrent recharge + send |
| 16 | Delete tenant com saldo negativo |
| 17 | Pricing snapshot integrity |
| 18 | Billing job sem cartão MP |
| 19 | Float rounding (1000x R$ 0,01) |
| 20 | LGPD: histórico de tenant deletado |

### 9.4 DoD

1. `php artisan test` 100% green (175 + ~50 = ~225)
2. Coverage ≥85% billing files
3. Zero crítico/alto Red Team
4. Migrations limpas em DB seed-populated + dump real staging
5. Rollback testado em staging (apply → rollback → re-apply)
6. Smoke E2E real: recarga MP em staging + envio + cobrança forçada
7. Documentação `docs/runbooks/billing-rollback.md`
8. Email templates renderizados corretamente em Litmus

## 10. Time e Roadmap

### 10.1 Estrutura do time

| Papel | Responsabilidade | Agent |
|-------|------------------|-------|
| PM | aprovação, gates, decisões de produto | conversa |
| Dev Senior | migrations, models, services, jobs, controllers, frontend | `general-purpose` em branch isolado |
| QA | unit + feature tests; coverage ≥85% | `general-purpose` |
| Red Team | pentest 20 cenários + relatório | `general-purpose` |
| Code Reviewer | review final | `superpowers:code-reviewer` |
| SRE/Ops (você) | backup, janela, comunicação, observação 48h | manual |

### 10.2 Roadmap em 10 fases (gate PM em cada)

| Fase | Owner | Output | ~Esforço |
|------|-------|--------|----------|
| 0 | PM | Spec + plan + branch | conversa |
| 1 | Dev | Configs + 7 migrations + models | 3-4h |
| 2 | Dev | BillingService + PricingService + exceptions | 4h |
| 3 | Dev | MonthlyBillingJob + OverdueRetryJob + cron + MP::chargeStoredCard + emails | 3-4h |
| 4 | Dev | Admin controllers + routes + EnsureRole + audit | 3h |
| 5 | Dev | Frontend admin/finance (4 páginas + sidebar) | 4-5h |
| 6 | Dev | Frontend user-facing renames + banner | 2-3h |
| 7 | QA | ~50 unit + feature tests; coverage ≥85% | 4-5h |
| 8 | Red Team | Pentest 20 cenários + fixes | 3-4h |
| 9 | Reviewer | Review final | 1-2h |
| 10 | PM | Pré-flight + janela + smoke + obs 48h | 2h+ |

## 11. Configurações `.env`

```
APP_TIMEZONE=America/Sao_Paulo                    # já existe
DB_TIMEZONE=-03:00                                 # já existe
BILLING_OVERDUE_GRACE_DAYS=7
BILLING_OVERDUE_RETRY_HOUR=04
BILLING_MONTHLY_DISPATCH_HOUR=03
BILLING_CREDIT_LIMIT_WARNING_PCT=80
BILLING_DEFAULT_INCLUDED_BALANCE_CENTS=0
BILLING_MIGRATION_PRICE_CENTS=15
BILLING_BRL_ENABLED=false                          # feature flag pré-deploy; true após migrate
```
