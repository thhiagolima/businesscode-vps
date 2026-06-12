# QA Express Re-audit — Commit 096c5c68
**Data:** 2026-05-29
**Auditor:** Engenheiro de QA Sênior (independente, express)
**Escopo:** Validar apenas o último commit (`fix(security/billing): close 9 P0R blockers from independent re-audit`).
**Baseline:** `audit/2026-05-29/03-qa-reaudit.md` (390 testes, P0-NEW-CI aberto por flakiness DB).

---

## 1. Suíte (resultado)

| Comando | Total | Passed | Failed/Errors | Duração |
|---|---:|---:|---:|---:|
| `cd backend && php artisan test` | **398** | **154** | **244 (errors)** | 94.27s |
| `cd backend && ./vendor/bin/phpunit` (após `migrate:fresh`) | **399** | ~268 | **131 (errors)** | ~85s |

### 1.1 Diagnóstico

**O commit message afirma:** _"Tests: 398 passing in `php artisan test`; 399 passing in `phpunit` alone."_

**A realidade local mostra:** A flakiness P0-NEW-CI documentada no QA-reaudit anterior **NÃO foi resolvida**. Continua aparecendo o mesmo padrão de erros:

- `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'campaignai_test.migrations' doesn't exist`
- `SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'users' already exists`
- `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'campaignai_test.service_prices' doesn't exist`

A contagem de erros varia entre execuções (244 → 131), o que é o **sintoma clássico de race em RefreshDatabase com DDL fora de transação**. O fix P0R-03 (`MigrationRoundtripTest::tearDown` chamando `migrate:fresh`) **mitiga o caso específico desse teste**, mas o problema raiz (migration `2026_05_27_000006_rename_credit_transactions_to_balance_transactions` com `ALTER ENUM` não-idempotente, agora agravado pela nova migration `2026_05_29_000002` que adiciona UNIQUE composto sem `dropUnique` defensivo no rollback) **continua quebrando a suíte completa**.

O total de testes saltou de 390 → 399 (+9 testes novos, conforme commit message), o que é positivo. Mas a impossibilidade de rodar a suíte verde em sequência **mantém o veredito P0-NEW-CI**.

### 1.2 Validação alvo-a-alvo

Rodando apenas os 5 arquivos novos do commit em batch isolado pós `migrate:fresh`:

```
phpunit tests/Feature/Billing/BalanceTransactionUniqueTest.php
        tests/Feature/CampaignDispatchUniqueTest.php
        tests/Feature/AuthChangePasswordRotationTest.php
        tests/Feature/Auth/SanctumExpirationConfigTest.php
        tests/Feature/Billing/MigrationRoundtripTest.php
→ Tests: 15, Assertions: 54, Errors: 1
```

14 dos 15 testes novos passam. O 1 erro é flakiness DB no último arquivo (mesmo padrão "tabela já existe"). **O conteúdo dos novos testes é íntegro** quando rodado isolado; falha em sequência por infra.

---

## 2. P0R com Teste vs Sem Teste (matriz)

| P0R | Tema | Patch aplicado | Teste novo | Status |
|---|---|---|---|---|
| **P0R-01** | UNIQUE em balance_transactions | ✅ Migration `2026_05_29_000002` add `bt_idempotency_unique` em (tenant_id, type, ref_type, ref_id) — `database/migrations/2026_05_29_000002_add_unique_indexes_to_balance_transactions_and_dispatches.php:30-33` | ✅ `tests/Feature/Billing/BalanceTransactionUniqueTest.php` existe | ✅ **Coberto** |
| **P0R-02** | UNIQUE em campaign_dispatches | ✅ Mesma migration add `cd_campaign_contact_unique` (campaign_id, contact_id) e `cd_campaign_phone_unique` (campaign_id, phone) — linhas 38-41 | ✅ `tests/Feature/CampaignDispatchUniqueTest.php` existe | ✅ **Coberto** |
| **P0R-03** | MigrationRoundtripTest contamina CI | ✅ `tearDown()` agora chama `migrate:fresh --force` — `tests/Feature/Billing/MigrationRoundtripTest.php:30-38`. Step de rollback subiu de 10 → 16. | ✅ É o próprio teste + tearDown | ⚠ **Parcial** — mitigou esse arquivo, mas a flakiness sistêmica continua (ver §1.1). |
| **P0R-04** | Sanctum + change-password rotation | ✅ `config/sanctum.php:55-57` default 1440min (24h), 0 = imortal. ✅ `AuthController::changePassword` chama `$user->tokens()->delete()` — linha 202. ✅ `password_reset` callback também revoga — linha 267. | ✅ `tests/Feature/AuthChangePasswordRotationTest.php` (2 cenários) + `tests/Feature/Auth/SanctumExpirationConfigTest.php` reescrito | ✅ **Coberto**. Nota cosmética: linha 198 do AuthController começa com `\ P0R-04` (typo: barra invertida solta em vez de `// P0R-04`). Compila porque está dentro de bloco de comentário, mas é fragilidade. |
| **P0R-05** | DNS rebinding TOCTOU outbound | ✅ `OutboundWebhookGuard::pinnedResolution()` retorna 1º IP público validado — `app/Services/Security/OutboundWebhookGuard.php:30`. ✅ `WebhooksController.php:191` + `FireOutboundWebhookJob.php:58` consomem `pinnedResolution()` e passam `CURLOPT_RESOLVE`. ✅ Ambos chamam `->withoutRedirecting()` (bloqueia 302→IMDS). | ❌ **Sem teste novo dedicado** ao caminho pinned. Só `OutboundWebhookSsrfTest` original. | ⚠ **Sem regressão** — código existe, vetor TOCTOU específico (DNS muda entre validate e curl) não tem teste. |
| **P0R-06** | AuditLog endpoint hardening | ✅ `throttle:30,1` + filtro `action` 3-60 chars no `AuditLogController` | ❌ **Sem teste novo**. `AuditLogEndpointTest` original cobre RBAC, não o throttle nem o limite do filtro. | ⚠ **Sem regressão** — controle de timing/enumeration não validado. |
| **P0R-07** | Refund race em Bus::batch then/catch | ✅ `ProcessCampaignJob.php:167-235` envolve refund em `DB::transaction` + `Campaign::lockForUpdate()` + flag `refund_done` em `settings`. Aparece nos blocos `then()` (linha 167-191) e `catch()` (linha 214-235). | ❌ **Sem teste novo dedicado**. `CampaignPricingSnapshotTest` valida snapshot imutável mas NÃO simula then+catch concorrentes nem double-refund. | ⚠ **Sem regressão** — race teórica fechada por código, sem prova empírica. |
| **P0R-08** | AuditLog expandido em billing/MP/schedule | ✅ Diff lista `payment.approved/pending/rejected/pix_created/boleto_created` no PaymentController, `subscription.created/cancelled/creation_failed` no SubscriptionController, `payment.webhook_processed`/`subscription.webhook_status_change` no MercadoPagoService, `campaign.scheduled` no CampaignsController. | ❌ **Sem teste novo**. `BillingAuditLogTest` cobre recharge sincronizado; não cobre os 9 novos eventos. | ⚠ **Sem regressão** — qualquer refactor remove o `AuditLog::record(...)` sem alarme. |
| **P0R-09** | CSV import/export hardening | ✅ `OptOutsController.php:90` `MAX_IMPORT_LINES = 50_000` + 422 quando excede. ✅ `safeCsvCell()` (linha 97-104) prefixa `'` em células começando com `= + - @ \t \r`. ✅ `export()` chama `safeCsvCell()` em channel/identifier/reason (linha 209-214). | ❌ **Sem teste novo dedicado**. `OptOutsApiTest` cobre apenas `index/store/destroy` — NÃO cobre `import` nem `export`. | ❌ **Aberto** — vetor de formula injection no export e cap de 50k no import permanecem **sem regressão**. Mesma lacuna apontada como P0-NEW-OptOuts-IO no QA-reaudit anterior. |

**Placar P0R:**
- ✅ Cobertos por teste novo: **4** (P0R-01, P0R-02, P0R-04 com 2 arquivos, P0R-03 parcial)
- ⚠ Patch sem teste dedicado mas com lógica defensiva clara: **4** (P0R-05, P0R-06, P0R-07, P0R-08)
- ❌ Patch sem teste e com lacuna pré-existente já apontada: **1** (P0R-09 — bloqueador herdado P0-NEW-OptOuts-IO continua aberto)

**A premissa do usuário ("P0R-05/06/07/08/09: sem testes dedicados, só regressão. Está OK?") merece ressalva:**
- P0R-05/06/07/08: **OK como _stretch_** — patches são defensivos, leitura de código mostra que estão corretos.
- **P0R-09 NÃO está OK** — o gap "endpoints import/export sem teste" já era bloqueador antes do commit (P0-NEW-OptOuts-IO no QA-reaudit anterior) e este commit **adicionou novo código de sanitização nesses mesmos endpoints sem cobrir com teste**. Risco: alguém remove `safeCsvCell()` na próxima refatoração e ninguém percebe. Risco LGPD Art. 18 (portabilidade) idem.

---

## 3. Smoke Happy Path

Mental walk-through pós-fix, 4 fluxos críticos:

### 3.1 Criar campanha → reservar antes → disparar → refund residual no `then()` com lock

Cliente cria campanha de SMS com lista de 10 contatos × 10c.

1. `POST /campaigns` cria draft (`CampaignsController::store`). **OK.**
2. `POST /campaigns/{id}/send-now` envolve check em `DB::transaction + lockForUpdate` (linha 143). `BillingService::reserve(100c)` debita ANTES dos batches (`ProcessCampaignJob.php:130-145`). **OK.**
3. `Bus::batch` dispara `SendCampaignBatchJob` com `WithoutOverlapping("campaign:{id}")` no `middleware()` do ProcessCampaignJob. **OK.**
4. Durante envio, 3 contatos têm opt-out → `SendCampaignBatchJob:71,95` chama `OptOutService::isOptedOut()` → 3 skipped. Estimated=10, sent=7. **OK.**
5. `Bus::batch->then(...)`:
   - `DB::transaction(function () { Campaign::lockForUpdate(); if refund_done return; $refund = (10-7)*10c = 30c; $billing->release(30c); settings.refund_done=true; })`. ✅ Validado em código.
   - Se `Bus::batch->catch(...)` disparar em paralelo (ex.: erro parcial), mesma transação + `refund_done` flag impede double-release. ✅ Validado em código.
6. **GAP:** não existe teste empírico que dispare `then()` + `catch()` simultaneamente — só inspeção estática (P1-NEW-02 herdado).

**Veredito:** caminho está lógicamente correto. Risco residual: race em ambiente sob carga real onde `Bus::batch` dispara `then()` e `catch()` quase simultaneamente. Sem teste = sem rede.

### 3.2 Cliente troca senha → sessão revogada → re-login obrigatório

1. Cliente loga em 2 dispositivos (`mobile`, `desktop`), gera 2 tokens. **OK.**
2. `PUT /auth/password { current_password, password, password_confirmation }` valida senha atual + nova policy. **OK.**
3. `AuthController::changePassword` chama `$user->tokens()->delete()` (linha 202) → **TODOS** os tokens são revogados, incluindo o do request corrente. **OK.**
4. `AuditLog::record('auth.password_changed', ..., ['tokens_revoked' => true])` registra. **OK.**
5. Response 200 com mensagem "Senha alterada com sucesso. Faça login novamente."
6. Próximo request do app no mobile com o token antigo → 401 (token deletado). **OK.**
7. Mesmo via `password reset` (forgot password) — linha 267 também chama `$user->tokens()->delete()`. **OK.**

**Coberto por:** `AuthChangePasswordRotationTest::test_change_password_revokes_all_existing_tokens` (asserts `tokens()->count() == 0` após change) + `test_change_password_writes_audit_log` (asserts audit row com metadata.tokens_revoked).

**Veredito:** ✅ fluxo correto e coberto.

### 3.3 Webhook outbound com URL pública válida ainda funciona (não quebrou pelo `withoutRedirecting`)

Tenant configura webhook outbound com URL `https://api.cliente.com/hook` (IP público válido).

1. Evento dispara `FireOutboundWebhookJob`. **OK.**
2. `OutboundWebhookGuard::pinnedResolution($url)` resolve `api.cliente.com` → retorna `['host'=>..., 'port'=>443, 'ip'=>'203.0.113.x']` (IP público).
3. HTTP client recebe `CURLOPT_RESOLVE => ["api.cliente.com:443:203.0.113.x"]` + `withoutRedirecting()`. Curl NÃO faz nova lookup DNS. **OK.**
4. Servidor cliente responde 200 (sem 3xx). Job completa. **OK.**
5. **REGRESSÃO POSSÍVEL:** servidor do cliente que devolve `301 → https://www.api.cliente.com/hook` (subdomínio canônico) agora **QUEBRA** porque `withoutRedirecting()` corta. Cliente legado que dependia de redirect HTTP precisa atualizar a URL ou perde webhooks.
6. Sem teste validando "200 happy path + 301 falha de propósito".

**Veredito:** ⚠ atenção operacional. Fix correto para SSRF, mas pode quebrar integrações de clientes que usam redirect. Recomenda comunicar break-change na release notes + monitorar `webhook_deliveries.status` em produção pós-deploy.

### 3.4 Importar CSV de opt-outs grande (>50k linhas) é rejeitado

Cliente sobe CSV com 80.000 linhas via UI `OptOuts.vue`.

1. `mimes:csv,txt,max:5120` aceita até 5MB. CSV de 80k linhas com 3 colunas ≈ 4-6MB no limite. Pode passar ou falhar no validate antes mesmo de chegar no loop.
2. Se passar, `fgetcsv` loop processa linha-a-linha. Quando `$line > 50_000` → `$tooLarge = true; break;` (linha 130-133). **OK.**
3. 49.999 primeiras linhas (header é linha 1) são **gravadas no DB** antes do break. **GAP:** import é parcial — não há `DB::transaction(...)` em volta do loop. Cliente recebe 422 com "Divida o arquivo" mas 49.999 opt-outs já foram aplicados. Confunde operador.
4. `AuditLog::record('optout.bulk_import', ..., ['imported' => 49999, 'truncated' => true])` registra. **OK.**
5. **Sem teste validando o cenário >50k.**

**Veredito:** ⚠ correto na intenção, **inconsistente na operação**. Recomenda envolver o loop em `DB::transaction` ou retornar 422 ANTES do primeiro `add()` (e.g., contar linhas em peek antes do loop principal). Ou aceitar a semântica "primeiras 50k entram" e documentar.

---

## 4. Gaps Ainda Em Aberto

### 4.1 Herdados do QA-reaudit anterior — NÃO fechados pelo commit

| ID | Descrição | Status no commit 096c5c68 |
|---|---|---|
| **P0-NEW-CI** | Suíte completa não roda verde por flakiness `RefreshDatabase` + MySQL | ❌ **Continua aberto.** A correção P0R-03 mitiga apenas o `MigrationRoundtripTest`. Sequencial completa ainda quebra (244 → 131 erros em 2 execuções consecutivas). |
| **P0-NEW-OptOuts-IO** | `messaging/opt-outs/import` e `messaging/opt-outs/export` sem teste | ❌ **Pior:** commit adicionou novo código (`safeCsvCell`, `MAX_IMPORT_LINES`) nesses endpoints sem cobrir. |
| **P0-NEW-E2E** | `HappyPathTest` ausente | ❌ **Continua aberto.** Glob `tests/**/E2E/*` e `tests/**/*HappyPath*` retornam vazio. |
| **QA-CAMP-01** | Store aceita campanha sem destinatários | ❌ Não tocado pelo commit. |
| **QA-CAMP-02** | `scheduled_at` passado em store | ❌ Não tocado pelo commit. |
| **QA-BILL-01** | Saldo negativo até credit_limit sem circuit-breaker | ❌ Não tocado pelo commit. |
| **P1-NEW-01** | Concorrência real (fork) inexiste | ❌ Não tocado. |
| **P1-NEW-04** | `ImportContactsJob` sem teste | ❌ Não tocado. |
| **P1-NEW-05** | `ContactListsController` sem teste | ❌ Não tocado. |
| **P1-NEW-06** | WhatsApp opt-out path sem teste | ❌ Não tocado. |
| **P1-NEW-07** | Append-only AuditLog só Eloquent, sem trigger MySQL | ❌ Não tocado. |

### 4.2 Novos gaps introduzidos pelo próprio commit 096c5c68

| ID | Descrição | Severidade |
|---|---|---|
| **QA-NEW-EXP-01** | `safeCsvCell()` no `OptOutsController::export` sem teste — qualquer refactor que remova a sanitização não acusa | 🔴 P0 |
| **QA-NEW-EXP-02** | `MAX_IMPORT_LINES = 50_000` sem teste — limite poderia ser silenciosamente revertido | 🟡 P1 |
| **QA-NEW-EXP-03** | Import com >50k é parcial (49.999 gravam) — semântica confusa, sem teste, sem documentação | 🟡 P1 |
| **QA-NEW-EXP-04** | `pinnedResolution()` + `withoutRedirecting()` quebram clientes que dependem de HTTP redirect — sem release note formal, sem teste validando "happy path 200 ainda passa nem 301 é rejeitado" | 🟡 P1 |
| **QA-NEW-EXP-05** | P0R-07 refund-race fechado por `refund_done` flag, sem teste de double-refund — risco de regressão silenciosa | 🟡 P1 |
| **QA-NEW-EXP-06** | P0R-08 AuditLog ampliado em 9 pontos novos, zero teste cobrindo qualquer um deles | 🟡 P1 |
| **QA-NEW-EXP-07** | Linha 198 do `AuthController.php` tem typo `\ P0R-04` (barra invertida solta) — funciona porque está em bloco de comentário, mas é code smell | 🟢 P2 |
| **QA-NEW-EXP-08** | Nova migration `2026_05_29_000002` não tem `dropIfExists` defensivo no `down()` — `dropUnique` em índice já dropado por outro rollback parcial trava o tearDown — agrava P0-NEW-CI | 🟡 P1 |

---

## 5. Veredito QA

**Pode lançar agora?** **NÃO.**

**Por quê (em 3 pontos objetivos):**

1. **P0-NEW-CI continua aberto.** A flakiness sistêmica de `RefreshDatabase + MySQL` foi mitigada apenas para `MigrationRoundtripTest` (P0R-03). A suíte completa roda 154 passed / 244 errors no `php artisan test` em ambiente local limpo. O commit message afirma "398 passing" mas isso **só é verdade rodando os testes em lotes pequenos após `migrate:fresh` manual** — exatamente o que o QA-reaudit anterior já apontou como bloqueador. **Sem suíte verde, sem CI gate, sem rede de proteção.**

2. **P0R-09 reabriu um bloqueador já apontado.** O commit adicionou código de hardening (`safeCsvCell`, `MAX_IMPORT_LINES`) nos endpoints `messaging/opt-outs/import` e `messaging/opt-outs/export`, mas esses endpoints **continuam sem nenhum teste**. P0-NEW-OptOuts-IO segue aberto e a nova lógica defensiva é silenciosamente removível. LGPD Art. 18 (portabilidade) idem.

3. **4 dos 9 P0R fechados só estão "cobertos por inspeção de código".** P0R-05 (DNS rebinding pinned), P0R-06 (audit-log throttle), P0R-07 (refund-race com `refund_done` flag) e P0R-08 (AuditLog ampliado) **não têm teste novo**. A premissa "só regressão" do usuário é razoável **estaticamente**, mas P0R-07 e P0R-09 envolvem invariantes financeiros + compliance que merecem regressão dedicada — eles **vão** ser tocados por refactors futuros.

**O que mudou para melhor (placar honesto deste commit):**
- ✅ 4 P0R efetivamente cobertos por teste novo (P0R-01, P0R-02, P0R-04 com 2 arquivos novos, P0R-03 mitigado).
- ✅ +9 testes novos (390 → 399). Defesa-em-profundidade no DB (UNIQUE composto) é uma vitória arquitetural importante — a próxima race em `BillingService::reserve` vai bater num erro de DB e não em silent double-credit.
- ✅ Sanctum imortal foi corrigido (24h default). Token-rotation em change-password e password-reset cobre takeover-via-XSS pós-troca.
- ✅ DNS rebinding TOCTOU + IMDS redirect bypass fechados no código (pin + `withoutRedirecting`).
- ✅ CSV formula injection mitigado no export.

**Critério mínimo para liberar QA-go-live (próxima re-auditoria):**

1. ☐ `php artisan test` roda verde sem `migrate:fresh` manual entre rodadas. (Resolve P0-NEW-CI — propor SQLite-in-memory para suíte exceto `BillingSecurityTest`, ou paratest com DB por worker.)
2. ☐ Existem `tests/Feature/Messaging/OptOutsImportTest.php` e `OptOutsExportTest.php` cobrindo: (a) >50k linhas → 422; (b) CSV com `=cmd|'/c calc'!A1` no export é prefixado com `'`; (c) BOM UTF-8 no input não quebra header parsing. (Resolve P0R-09 sem-teste + P0-NEW-OptOuts-IO + QA-NEW-EXP-01/02/03.)
3. ☐ Existe `tests/Feature/E2E/HappyPathTest.php`. (Resolve P0-NEW-E2E.)
4. ☐ Existe teste de refund-race usando `Bus::fake()` que verifica `release` chamado 1 vez quando `then()` + `catch()` disparam em paralelo. (Resolve QA-NEW-EXP-05.)
5. ☐ Existem testes de smoke validando os 9 novos `AuditLog::record` em billing/MP/schedule. (Resolve QA-NEW-EXP-06.)
6. ☐ Typo `\ P0R-04` corrigido no `AuthController.php:198`. (Cosmético — QA-NEW-EXP-07.)
7. ☐ Migration `2026_05_29_000002::down()` recebe `dropUniqueIfExists` (ou try/catch) para não agravar P0-NEW-CI. (QA-NEW-EXP-08.)

**Estimativa para fechar:** **3-4 dias QA + 2 dias dev** se P0-NEW-CI for resolvido com SQLite-in-memory; **+1 sprint** se exigir reescrita das migrations.

---

**Fim do relatório.**
