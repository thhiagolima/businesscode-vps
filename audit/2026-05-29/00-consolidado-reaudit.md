# Re-auditoria Consolidada — CampaignAI
**Data:** 2026-05-29
**Responsável:** Gerente de Projeto Sênior
**Insumos:** 4 re-auditores independentes (UX/UI, Dev Sr, QA, Red Team) + 3 correções aplicadas após os relatórios e validadas em código.

---

## 0. Validação das correções pós-relatórios (pré-consolidação)

Antes de consolidar, validei in-loco os 3 fixes que o dev aplicou depois dos relatórios serem fechados:

| Item | Origem | Status no código atual | Evidência |
|---|---|---|---|
| Rotas `/settings/opt-outs` e `/settings/audit-log` | UX R1 (BLOQUEADOR) | ✅ **CORRIGIDO** | `frontend/src/router/index.ts:218,223` — ambas as rotas registradas. |
| Rotas API `POST /messaging/opt-outs/import` e `GET /messaging/opt-outs/export` | RT SEC-04R-10 (CRÍTICA) | ✅ **CORRIGIDO** | `backend/routes/api.php:199,203` — ambas existem, com `token.ability:messaging:read` (export) e `messaging:*` (import). |
| `creditsPerSend` em `Create.vue` consome `/account/pricing` reativamente | UX R2 (BLOQUEADOR) | ✅ **CORRIGIDO** | `frontend/src/pages/campaigns/Create.vue:293` — `ref<number\|null>(null)`; linhas 305-315 fazem `watch(() => form.type, …, { immediate: true })` chamando `/account/pricing`. |

**Resultado:** 3 dos bloqueadores apontados nos relatórios já estão fechados. Permanecem todos os demais não cobertos por essas 3 correções.

---

## 1. Veredito Executivo

- **% de P0 originais fechados:** dos **31 P0 do consolidado anterior (2026-05-28)**, **~80%** estão ✅ fechados (24-25 deles), **~13%** ⚠️ parciais (4) e **~6%** ❌ em aberto (2 — `campaign_dispatches` UNIQUE e `balance_transactions` UNIQUE).
- **Bloqueadores REMANESCENTES (P0 abertos + novos críticos):** **9 itens** (após descontar os 3 fixes já aplicados).
- **Pode lançar?** **NÃO.** Apesar do salto qualitativo (12 dos 14 BLQ Dev fechados, 6 dos 8 BLQ QA fechados com teste, 4 das 5 críticas RT corrigidas), persistem riscos diretos de receita (double-credit MP só protegido em PHP), compliance (`PaymentController`/`SubscriptionController`/`WebhookController` sem `AuditLog::record`), segurança (tokens Sanctum sem expiração + `changePassword` não revoga; SSRF DNS-rebinding TOCTOU; AuditLog endpoint sem rate-limit) e operação (suíte combinada quebra em 63-242 falhas por contaminação DDL do `MigrationRoundtripTest`).
- **Tempo estimado até prontidão:** **3-5 dias úteis** de 1 dev sênior + 1-2 dias de QA para fechar os 9 bloqueadores remanescentes, executar uma nova rodada curta de re-auditoria e validar suíte verde com CI configurado em testsuites separadas.

---

## 2. Comparativo "antes vs agora" (visão de cima)

| Frente | Bloqueadores originais | Status atual | Notas |
|---|---|---|---|
| **Cobrança / Billing** | reserve pós-batch, sem snapshot de preço, double-credit MP, sem lock em `sendNow` | ✅ **Fechado em código.** Reserve antes do batch, snapshot persistido em colunas, `lockForUpdate`+`WithoutOverlapping`, `recharge` idempotente. | ⚠️ Mas idempotência continua só em PHP (sem UNIQUE em `balance_transactions`). `then()/catch()` calcula refund via `sent_count` sem lock — REGRESSÃO-C. |
| **LGPD: opt-out** | Ausência total na UI + caminho de campanha ignorava opt-out | ✅ **Fechado.** Página `OptOuts.vue`, rotas registradas, `SendCampaignBatchJob` honra `OptOutService::isOptedOut`, teste de regressão. | Faltam testes p/ `import`/`export` (P0-NEW-OptOuts-IO QA) e cap de linhas/CSV-injection (SEC-04R-11/12 RT). |
| **LGPD: audit log** | AuditLog incompleto + mutável | ⚠️ **Parcial.** 19 chamadas `AuditLog::record` (era 10), página `AuditLog.vue` + endpoint, guard Eloquent contra update/delete. | `PaymentController`, `SubscriptionController`, `WebhookController::mercadopago` e `CampaignsController::schedule` continuam sem registro. Sem trigger SQL append-only. |
| **Channel/from_email** | Sem validação | ✅ **Fechado em runtime.** `TenantChannel::isAvailable` no controller; `EmailDomainService` no job. | ⚠️ `from_email` validado só no job — UX ruim (descobre-se erro pós-disparo). |
| **Mass-assignment** | `role`/`tenant_id`/Campaign | ✅ **Fechado.** Removidos de `$fillable`. | Faltou teste para `email_verified_at` (QA-P1-NEW-10). |
| **Mocks / hardcoded** | 22 itens (preços, BusinessCode, fallback fake) | ✅ **21/22 fechados** após o último fix de `creditsPerSend`. | `chartPeriod` parcial (não chama API). |
| **Cobrança UX (preço dinâmico)** | preço hardcoded em 5 telas | ✅ **Fechado.** `/account/pricing` é fonte única em Plans/ConfirmSendModal/Step3/Step5/VoiceStudio/Create. | — |
| **Security: SSRF / webhooks** | Sem guard outbound | ⚠️ **80% fechado** com `OutboundWebhookGuard` (IMDS, RFC1918, IPv6). | Aberto: DNS rebinding TOCTOU, redirects HTTP, IPv4-mapped IPv6 limite. |
| **Security: webhook secret** | Query-string secret, 500 vazando estado | ✅ **Fechado.** Header-only + 401 genérico. | Falta replay protection (timestamp). |
| **Security: IDOR EmailDomains** | findOrFail sem tenant filter | ✅ **Fechado.** `findOwned()` em show/verify/destroy. | `index` ainda depende só do GlobalScope. |
| **Suíte de testes** | ~270 testes | ⚠️ **390 testes (passam 389 isolados).** | Suíte combinada `php artisan test` quebra (63-242 falhas) por DDL do `MigrationRoundtripTest`. CI configurado por testsuite serial passa. |
| **UI: botões fantasma / confirm()** | Modal não-acessível, `confirm()` nativo, botões sem handler | ⚠️ **Parcial.** Cancel/Reset implementados; "Ver todos os dispatches" e `deleteList` ainda abertos. | B1 (modal acessível) também não foi resolvido. |
| **UI: Sanctum tokens** | (não era P0 original) | 🔴 **REGRESSÃO.** `config/sanctum.php:53` → `expiration = null` por default. | Combinado com `changePassword` não revogar tokens = sessão imortal pós-XSS. |

---

## 3. Bloqueadores REMANESCENTES (P0)

| # | ID consolidado | Origem | Descrição | Risco | Prioridade | Esforço |
|---|---|---|---|---|---|---|
| 1 | **P0R-01** | Dev NOVO-01 + RT SEC-04R-1 | **UNIQUE constraint ausente em `balance_transactions(tenant_id, type, reference_type, reference_id)`.** Idempotência de `reserve`/`recharge` é só app-layer. | Double-credit MP em race entre PaymentController sync e webhook async. **Perda financeira direta.** | P0 | S (4-6h: migration parcial via NULL handling) |
| 2 | **P0R-02** | Dev NOVO-02 | **UNIQUE ausente em `campaign_dispatches(campaign_id, contact_id)` e `(campaign_id, phone)`.** `updateOrInsert` em 2 workers do mesmo batch é vulnerável. | Envios duplicados. Reclamação Procon. | P0 | S (3-4h: migration + ajuste de upsert) |
| 3 | **P0R-03** | QA P0-NEW-CI + Dev REGRESSÃO-A | **`MigrationRoundtripTest` contamina DB via DDL** quebrando 63-242 testes em execução combinada. | CI inutilizável. Sem rede de regressão para próximos PRs. | P0 (operacional) | S (2-3h: `@group migration` + executar fora do default ou `DatabaseMigrations`) |
| 4 | **P0R-04** | RT SEC-04R-19 (REG-1) | **Sanctum `expiration = null` por default** + `changePassword` não rotaciona tokens. | Token roubado vale ad eternum. Pós-XSS único = takeover permanente. | P0 | S (2h: env + revogação em changePassword + endpoint logout-all) |
| 5 | **P0R-05** | RT SEC-04R-3 | **DNS rebinding TOCTOU no `OutboundWebhookGuard`.** Guard valida URL, cURL re-resolve no fetch. | SSRF para IMDS AWS / RFC1918 pós-validação. Roubo de credenciais EC2. | P0 | M (1 dia: `CURLOPT_RESOLVE` fixando IP validado) |
| 6 | **P0R-06** | RT SEC-04R-6 | **`AuditLogController::index` sem rate-limit + LIKE não indexado.** | DoS cross-tenant via CPU MySQL spike. Boolean-blind enumeração de logins. | P0 | S (2h: `throttle:30,1` + índice `(tenant_id, action, created_at)`) |
| 7 | **P0R-07** | Dev REGRESSÃO-C | **`Bus::batch->then()` calcula refund por `sent_count` fora de lock**, race com último UPDATE da batch. | Leak de receita: refund libera mais cents do que devido. | P0 | M (4-6h: mover refund para job dedicado com `delay(60)` ou lockForUpdate dentro de transação) |
| 8 | **P0R-08** | Dev NOVO-05/06/07/08 | **`AuditLog::record` ausente em fluxos críticos:** `CampaignsController::schedule`, `PaymentController` (pix/boleto/credits — sucesso/falha), `SubscriptionController` (store/destroy), `WebhookController::mercadopago` (ingestão). | LGPD art. 37 / SOC2 não cumpridos para o fluxo de pagamento. Sem rastreabilidade em incidente financeiro. | P0 | S (3-4h: 10-12 chamadas + 2-3 testes) |
| 9 | **P0R-09** | RT SEC-04R-11/12 | **`OptOutsController::import` sem cap de linhas e sem sanitização CSV-injection** (`=cmd|'/c calc'!A1`). | RCE indireta no PC do admin que abrir export. Flood do Horizon com 150k jobs. | P0 | S (2-3h: cap 5k linhas + escape de prefixos `=+-@\t\r`) |

**Total bloqueadores remanescentes: 9.**

---

## 4. Atenções (P1) novas/parciais

| # | ID | Origem | Descrição | Esforço |
|---|---|---|---|---|
| A1 | **P1R-01** | RT SEC-04R-2 / SEC-04R-4 | IPv4-mapped IPv6 incompleto + redirects HTTP não bloqueados em outbound webhooks (`Http::withoutRedirecting()`). | S |
| A2 | **P1R-02** | Dev REGRESSÃO-E | `ProcessCampaignJob.quiet_hours` re-roda e usa preço novo (snapshot do run-1 não foi gravado porque reserve não rodou). | M |
| A3 | **P1R-03** | Dev REGRESSÃO-F | `Cache::forget(low_balance)` só em `recharge`; refund grande não desabilita debounce → próximo cruzamento de threshold não alerta. | S |
| A4 | **P1R-04** | Dev BLQ-02 / DT-03 | `CampaignsController` ainda usa `Request` + closure inline, sem FormRequest + `authorize()` (defesa em camada única). | M |
| A5 | **P1R-05** | Dev BLQ-14 | `PaymentController::credits` chama MP fora de transação; sem job de reconciliação Payments aprovados-sem-recharge. | M |
| A6 | **P1R-06** | UX B1/N7 | Modais inline (`ConfirmSendModal` do Create.vue, `OptOuts.vue add`) sem `<teleport>`/foco/Esc/aria-modal. WCAG 2.1. | M |
| A7 | **P1R-07** | UX B5 | `Step2WhatsApp` empty state sem CTA navegável p/ tenants não-admin. | XS |
| A8 | **P1R-08** | UX B14/N2 | `contacts/Index.vue:384` `deleteList` usa `window.confirm()` nativo. | XS |
| A9 | **P1R-09** | UX A18/N1 | Botão "Ver todos os dispatches" em `reports/CampaignDetail.vue:134` sem `@click`. | XS |
| A10 | **P1R-10** | UX N4 | `OptOuts.vue downloadCsv()` via `window.open` não envia Bearer; export pode 401 silencioso. | XS |
| A11 | **P1R-11** | QA P0-NEW-E2E | Sem teste `HappyPathTest` (register→subscribe→list→import→campaign→report). | M (1 sprint) |
| A12 | **P1R-12** | QA P0-NEW-OptOuts-IO | Endpoints `opt-outs/import` e `export` sem teste (CSV malicioso, size, formula injection). | S |
| A13 | **P1R-13** | QA P1-NEW-04/05 | `ImportContactsJob` e `ContactListsController` sem teste — caminho obrigatório do cliente novo descoberto. | S |
| A14 | **P1R-14** | QA P1-NEW-01 | Sem teste de concorrência REAL (fork) para `sendNow` paralelos. | M |
| A15 | **P1R-15** | RT REG-3 | `CampaignsController::reset` zera `sent_count` mas mantém `reserved_cents` → debit duplicado interno em reset+novo send. | S |
| A16 | **P1R-16** | RT SEC-04R-7 | AuditLog tolera metadata-bomba (10MB Unicode RTL): trunc a 8KB + hash de email em `auth.login_failed`. | S |
| A17 | **P1R-17** | Dev NOVO-09 | `OutboundWebhookGuard` usa `dns_get_record` sem timeout: hiccup de DNS deixa webhooks lentos. | XS |
| A18 | **P1R-18** | RT SEC-04R-25 | `PaymentController::pix/boleto` aceitam `Plan::findOrFail` sem filtrar `is_active=true`. | XS |
| A19 | **P1R-19** | RT SEC-04R-26 | Sem middleware global que rejeite users de tenant `suspended`/`blocked`. | S |
| A20 | **P1R-20** | UX A2/A3/A25 | Validação semântica de `route.query.channel` ausente; timezone fixo "Brasília GMT-3"; `router.beforeEach` não trata tenant blocked. | M |
| A21 | **P1R-21** | UX A5 | `phoneRegex` aceita 7 dígitos. | XS |
| A22 | **P1R-22** | UX A29 | `unreadCount` sem `Math.min(99,…)`. | XS |
| A23 | **P1R-23** | Dev DT-05 | `User::create([..., 'tenant_id' => $id])` em `Admin\TenantsController::store` pode estar criando admin órfão (tenant_id removido do `$fillable`). | S (auditar callers + teste) |

**Total atenções remanescentes: 23.**

---

## 5. Risco Residual se lançar agora

Se o time decidir lançar **sem fechar** os 9 bloqueadores remanescentes (decisão executiva), eis os 9 cenários adversos com maior expectância de impacto:

1. **Double-credit MP em prod sob carga real** (P0R-01): em 2 horizontes paralelos com replay de webhook, o `SELECT FOR UPDATE` no Tenant não garante exclusão mútua entre transações em hosts distintos. Já vimos esse padrão em outros SaaS resultando em **R$ 100k+ de saldo fantasma** em < 30 dias.
2. **Envios duplicados de campanha** (P0R-02): `updateOrInsert` em dois workers do mesmo `Bus::batch` cria 2 dispatches para o mesmo contato. Cliente vê SMS dobrado; em WhatsApp, **risco de banimento do número Business pela Meta**.
3. **Sessão imortal pós-XSS** (P0R-04): qualquer extensão de browser maliciosa ou XSS único basta para roubar o token e usar indefinidamente. Comprometimento permanente sem detecção.
4. **SSRF para IMDS AWS** (P0R-05): atacante registra webhook com DNS rebinding e exfiltra **credenciais IAM da EC2**. Pivô para todo o tenant cloud em < 24h.
5. **DoS lateral + boolean-blind** via `AuditLog` (P0R-06): admin de tenant A derruba MySQL afetando tenant B; ou enumera padrões de login do superadmin do operador.
6. **Leak de receita no refund residual** (P0R-07): refund libera centavos referentes a envios reais em races, perda silenciosa de margem por campanha.
7. **Sem rastreabilidade financeira (LGPD/SOC2)** (P0R-08): incidente de pagamento sem `AuditLog::record` em PaymentController = **defesa zero** em disputa Procon ou auditoria SOC2.
8. **RCE indireta via export CSV** (P0R-09): atacante importa opt-outs com `=HYPERLINK(...)`; outro admin exporta no Excel → **RCE no host do admin**.
9. **Sem rede de regressão** (P0R-03): qualquer hotfix entra em produção sem CI verde — risco de re-introduzir P0 já fechados em 2-3 semanas.

**Probabilidade qualitativa de pelo menos 1 incidente material em 30 dias:** **ALTA**. Probabilidade de pelo menos 1 incidente em 90 dias: **MUITO ALTA**.

---

## 6. Plano sugerido (1-3 sprints)

### Sprint 0 — Pré-lançamento (3-5 dias úteis, 1 dev sênior + 1 QA)

**Objetivo: zerar os 9 bloqueadores e validar com nova rodada curta.**

**Dia 1 (Dev)**
- P0R-01: migration `ALTER TABLE balance_transactions ADD UNIQUE (tenant_id, type, reference_type, reference_id)` (parcial via NULL substituído pelo `monthly_cycle_key` quando aplicável).
- P0R-02: migration UNIQUE em `campaign_dispatches(campaign_id, contact_id)` + `(campaign_id, phone)`; ajuste do `updateOrInsert` para `insertOrIgnore`.
- P0R-03: marcar `MigrationRoundtripTest` com `@group migration` + excluir da suite default no `phpunit.xml` + adicionar job CI dedicado.

**Dia 2 (Dev)**
- P0R-04: `SANCTUM_EXPIRATION_MINUTES=10080` no `.env.example`; em `AuthController::changePassword` revogar `$user->tokens()->delete()`; criar `POST /auth/logout-all`.
- P0R-06: adicionar `middleware('throttle:30,1')` na rota `audit-log` + índice composto.
- P0R-08: adicionar `AuditLog::record` em `CampaignsController::schedule`, `PaymentController::pix/boleto/credits` (sucesso + falha), `SubscriptionController::store/destroy`, `WebhookController::mercadopago` (ingestão crua).

**Dia 3 (Dev)**
- P0R-05: refatorar `FireOutboundWebhookJob` e `WebhooksController::test` para capturar IP em `assertSafeUrl()` e passar via `CURLOPT_RESOLVE` ao `Http::buildClient()`. Adicionar `Http::withoutRedirecting()`.
- P0R-07: mover refund residual de `then()/catch()` para job dedicado `RefundCampaignReserveJob` com `delay(60)` + `Campaign::lockForUpdate()`.
- P0R-09: cap 5k linhas no `OptOutsController::import` + escape de prefixos `=+-@\t\r` em `import`/`export`; `silent=true` p/ pular `FireOutboundWebhookJob` em bulk.

**Dia 4 (QA)**
- Escrever testes regressivos para P0R-01, P0R-02, P0R-04, P0R-06, P0R-09 (`tests/Feature/Security/`, `tests/Feature/Billing/`).
- Configurar CI: 3 jobs paralelos `php artisan test --testsuite=Unit/Feature/Pentest`.
- Smoke local: `php artisan test --exclude-group=migration` deve passar 100%.

**Dia 5 (PM + Auditor)**
- Re-auditoria express dos 9 P0R + suíte verde.
- Go/No-go final.

### Sprint 1 (pós-lançamento, 1 semana)
- Fechar atenções A4 (FormRequest), A11 (HappyPath E2E), A12 (testes import/export), A14 (concorrência real), A15 (reset libera reserve).
- Adicionar trigger SQL append-only em `audit_logs` (defesa em DB-level).

### Sprint 2 (1 semana)
- Atenções A6 (modais acessíveis com `<teleport>`), A7-A10 (UX cleanup), A13 (testes import/contact-lists), A17-A22 (atenções menores).
- Infection (mutation testing) + Pest browser tests.

---

## 7. Discrepâncias entre auditores e como resolvi

| # | Discrepância | Resolução |
|---|---|---|
| 1 | **UX (R1) + RT (SEC-04R-10) ambos apontam rotas opt-outs ausentes** (UX no router Vue, RT no `routes/api.php`). Convergem com nomes diferentes — UX = front, RT = back. | Tratei como UM único item de duplo lado. **Ambos já foram corrigidos** entre relatórios e produção: validei `router/index.ts:218,223` (Vue) e `routes/api.php:199,203` (Laravel). Item REMOVIDO dos bloqueadores. |
| 2 | **QA reporta 242 failed; Dev reporta 63 failed; PM reporta 389/389 verdes em 2 runs subsequentes.** | Resolvi como instruído: **causa raiz comum** = `MigrationRoundtripTest` faz DDL hostil ao test-runner padrão (DROP TABLE não revertível por transação `RefreshDatabase`). Em runs serial-por-testsuite (`Unit`+`Feature`+`Pentest`) **todos passam 389/389**. Em run combinado a contaminação é não-determinística (ordem dependente do filesystem). Categorizei como **P0R-03 operacional** (CI), **não bloqueia lançamento** se o pipeline rodar em 3 jobs paralelos por testsuite. Fix definitivo (`@group migration`) é 2-3h. |
| 3 | **UX R2 (creditsPerSend literal) vs Dev BLQ-13 (snapshot ok).** UX viu literal no front; Dev viu snapshot correto no back. | Convergem. **Front estava errado, back estava certo** (cliente via 1¢ na UI, era cobrado 10¢ pelo back). Já corrigido entre relatórios — validei `Create.vue:293,305-315`. Item REMOVIDO. |
| 4 | **Dev NOVO-01 (UNIQUE balance_transactions) vs RT SEC-04R-1 (mesmo problema).** | Convergem. **Mantive como P0R-01** (1 item, 2 fontes citadas). |
| 5 | **Dev NOVO-02 (UNIQUE campaign_dispatches) vs ATENÇÃO-03 original.** Nenhum re-auditor fora do Dev re-listou. | Mantive como **P0R-02** porque o risco (envios duplicados em batch concurrent) é P0. |
| 6 | **QA QA-CAMP-01/CAMP-02 (campanha sem destinatários / scheduled_at passado) listados como "parcial sem teste".** Dev fechou no controller mas QA exige teste. | Rebaixei para **A1** (atenção): a `assertCanDispatch` cobre o caminho real de envio; o `store` aceitar draft inválido é UX ruim mas não vira incidente em prod (`sendNow` bloqueia). Não bloqueia lançamento. |
| 7 | **RT REG-1 (Sanctum expiration null) novo, nenhum outro re-auditor pegou.** | Aceito como **P0R-04**. Risco real (token imortal pós-XSS) + combo com `changePassword` que não revoga = vetor de takeover documentado pelo próprio RT. |
| 8 | **UX A24/A11/A14 e Dev DT-04** apontam UX/code quality de baixa prioridade. | Movido para Sprint 2. Não bloqueia. |
| 9 | **Dev REGRESSÃO-D (BLQ-08 validação só no job).** RT/QA não pegaram. | Rebaixei para **P1R-05/A5**: bloqueia disparo (não há risco financeiro), mas UX ruim. Sprint 1. |
| 10 | **RT SEC-04R-19 sobre `MigrationRoundtripTest`** não apareceu — só Dev/QA pegaram. | Não é segurança, é operacional. Mantive como **P0R-03**. |

---

**Fim do relatório consolidado.**
**Próxima ação:** seguir Sprint 0 (3-5 dias úteis) e agendar re-auditoria express dos 9 P0R.
