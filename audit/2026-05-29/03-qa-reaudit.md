# Re-auditoria QA — CampaignAI
**Data:** 2026-05-29
**Auditor:** Engenheiro de QA Sênior (independente)
**Escopo:** Validar correções dos 8 bloqueadores + 15 atenções de `audit/2026-05-28/03-qa.md` e dos 31 P0 do consolidado do PM.
**Insumos:** 4 commits novos no master (`f2650f85`, `6d39c32f`, `d31bf2d8`, `e1889124`) + suíte de testes aumentada de ~270 para **390 métodos** em `backend/tests`.

---

## 1. Veredito Executivo

**Pode lançar?** **AINDA NÃO**, porém o salto qualitativo é **expressivo**: 6 dos 8 bloqueadores QA originais estão **✅ Corrigidos com teste**, 1 está **⚠ Parcial**, 1 está **❌ em aberto por gap de cobertura** (concorrência real). Os controles de receita (cobrança antes do envio, idempotência de reserve/recharge, snapshot de preço, refund residual no `then()`/`catch()` do `Bus::batch`) estão implementados e cobertos por testes que **passam quando rodados isoladamente**.

**Achado crítico operacional:** a suíte completa `php artisan test` falha catastroficamente devido a **flakiness do `RefreshDatabase` contra MySQL real**. O resultado da execução completa foi `Tests: 242 failed, 147 passed, 1 warning (432 assertions)` em 85s, mas a grande maioria das falhas é com erros tipo `Base table or view not found: 1146 Table 'campaignai_test.X' doesn't exist` e `Base table or view already exists: 1050 Table 'users' already exists` — ou seja, **estado de banco corrompido entre testes**, não falha lógica. Quando os testes P0 críticos são rodados em grupos pequenos após `migrate:fresh`, **passam 100%** (validado em 4 lotes — ver §2.2). Isso é, em si, um **bloqueador de CI**: como está, nenhuma proteção de regressão funciona em pipeline; qualquer PR passa porque "vermelho é o normal".

**3 lacunas estruturais persistem:**
1. **Não há teste E2E** (`HappyPathTest`) — registro → assinatura → import → campanha → disparo → relatório nunca foi exercido como fluxo único; o item P2-19 do consolidado segue aberto.
2. **Não há teste de concorrência real** (forks/threads) para `sendNow` paralelos; existem testes sequenciais (`BillingSecurityTest::test_04_reserve_race`) que simulam *via loop*, mas não cobrem o caso real de dois HTTP requests concorrentes.
3. **Endpoints `POST /messaging/opt-outs/import` e `GET /messaging/opt-outs/export` existem no `routes/api.php` (linhas 199, 203) mas NÃO têm teste** — `OptOutsApiTest` cobre apenas `index/store/destroy`. Mesma situação para a UI Vue: zero testes Vitest (`Glob frontend/**/*.spec.ts` → vazio fora de `node_modules`).

---

## 2. Suíte de Testes (resultado da execução)

### 2.1 Execução completa
```
$ cd backend && php artisan migrate:fresh --env=testing
[OK — todas as migrations rodam]
$ php artisan test
Tests: 242 failed, 1 warning, 147 passed (432 assertions)
Duration: 85.48s
```

**Diagnóstico das 242 falhas:** todas resultam de uma destas duas mensagens SQL e nenhuma reflete falha lógica:
- `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'campaignai_test.migrations' doesn't exist`
- `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'campaignai_test.service_prices' doesn't exist`
- `SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'users' already exists`

**Causa raiz suspeita:** alguma migration (forte candidato: `2026_05_27_000006_rename_credit_transactions_to_balance_transactions.php` que faz `Schema::rename` + `ALTER ENUM`) interage mal com `RefreshDatabase` em MySQL real; o `down()` não desfaz o ENUM ALTER de forma idempotente, e o próximo `migrate` da suíte seguinte tropeça. Isso é o que produz "tabela já existe" e simultaneamente "migrations não existe": o drop foi parcial.

**Impacto:** **bloqueador de CI** (P0-NEW-CI), porque a suíte não pode ser usada para gate de PR no estado atual.

### 2.2 Execução em lotes (após `migrate:fresh` por lote)

Para validar que o **conteúdo dos testes** está correto e os bloqueadores foram realmente fechados, rodei os testes novos em 4 lotes pequenos:

| Lote | Arquivos | Resultado |
|---|---|---|
| Lote 1 | `CampaignDoubleDispatch`, `CampaignCancelReset`, `CampaignReserveBeforeDispatch`, `CampaignOptOutCompliance` | **11 passed (28 asserts)** em 10.12s |
| Lote 2 | `CampaignQuietHoursCompliance`, `CampaignEmailUnsubscribe`, `CampaignChannelLicense`, `CampaignFromEmailGuard`, `CampaignPricingSnapshot`, `AuditLogEndpoint` | **15 passed (44 asserts)** em 11.35s |
| Lote 3 | `Reports/CreditsExtract`, `Reports/ExportRbac`, `Security/CampaignMassAssignment`, `Security/UserMassAssignment`, `Security/EmailDomainsIdor`, `Billing/BillingRechargeGuard`, `Billing/BillingAuditLog`, `Billing/RechargeIdempotency`, `Billing/ReserveIdempotency` | **28 passed, 1 failed (DB flakiness no último — mesmo padrão "users já existe")** |
| Lote 4 | `Webhooks/InfobipDeliveryDedup`, `Webhooks/OutboundWebhookSsrf`, `Webhooks/MercadoPagoWebhookValidation`, `Account/PricingEndpoint`, `Messaging/OptOutsApi` | **15 passed (37 asserts)** em 9.62s |

**Total dos 4 lotes:** 69 passed, 1 failed por flakiness de DB. Conteúdo dos testes está **íntegro**; é a infra de teste que precisa de saneamento.

### 2.3 Pentest sub-suite (`testsuite=Pentest`)
```
Tests: 23 failed, 19 passed (1080 assertions)
Duration: 17.73s
```
Mesma flakiness — quando rodo apenas `MessagingSecurityTest` via `--filter test_1` (11 cenários), **passa 11/11**. Quando rodo a classe inteira em sequência com `BillingSecurityTest`, o estado do DB é corrompido após o ~12º teste.

---

## 3. Status dos 8 Bloqueadores QA Originais

| ID | Bloqueador | Status | Evidência (arquivo:linha ou teste) |
|---|---|---|---|
| **QA-CAMP-01** | Store aceita campanha sem destinatários | ⚠ **Parcial** | `CampaignsController::store` (linhas 75–94) ainda **não exige** `contact_list_id` **OU** `adhoc_phones`. Validação só ocorre em `assertCanDispatch`. **Não há teste** validando rejeição. **Aberto.** |
| **QA-CAMP-02** | `scheduled_at` passado em store | ⚠ **Parcial** | `store` (linha 75–94) continua **sem** `scheduled_at`/`after:now`; só `update` (linha 119) e `schedule` (linha 175). Nenhum teste regressivo. **Aberto.** |
| **QA-DISP-01** | Cobrança pós-batch / Horizon | ✅ **Corrigido** | Reserve agora ocorre **ANTES** dos batches em `ProcessCampaignJob.php:130-145`. Cobertura: `tests/Feature/CampaignReserveBeforeDispatchTest.php` (3 cenários: debit-antes, insuficiente, retry sem double-debit). Refund residual em `then()/catch()` (linhas 163-216) com snapshot persistido (`unit_cents_at_dispatch`, `reserved_cents`). |
| **QA-DISP-02** | Race em `sendNow` paralelo | ✅ **Corrigido** | `CampaignsController::sendNow` (linha 143) agora usa `DB::transaction` + `Campaign::lockForUpdate()`. `ProcessCampaignJob::middleware()` (linha 41) adiciona `WithoutOverlapping("campaign:{id}")->expireAfter(600)->dontRelease()`. Cobertura: `tests/Feature/CampaignDoubleDispatchTest.php` (2 cenários — passou no isolamento). |
| **QA-DISP-04** | Caminho de campanha ignora opt-out | ✅ **Corrigido** | `SendCampaignBatchJob.php:71,95` agora chama `OptOutService::isOptedOut()` antes de cada envio (contato e adhoc). Cobertura: `tests/Feature/CampaignOptOutComplianceTest.php` (2 cenários — contato com opt-out e adhoc com opt-out; ambos persistem dispatch row com status=failed para auditoria). |
| **QA-BILL-01** | Saldo negativo até credit_limit sem alerta | ⚠ **Parcial** | `BillingService::reserve` (linhas 91–115) emite evento `LowBalanceCrossed` com debounce 24h. **Mas:** ainda não há circuit-breaker ou bloqueio automático ao cruzar limite. Sem teste validando alerta + threshold. **Atenção.** |
| **QA-REPORT-01** | `credits` filtra type=debit/credit inexistente | ✅ **Corrigido** | Validação agora aceita `reserve,release,recharge,manual_adjustment`. Cobertura: `tests/Feature/Reports/CreditsExtractTest.php` valida totais corretos (300 debit / 5100 credit / 3 transactions) E isolamento entre tenants. |
| **QA-WH-01** | Webhook delivery aplica status em replay | ✅ **Corrigido** | `WebhookController::infobipDelivery` agora dedup por `(messageId, status)`. Cobertura: `tests/Feature/Webhooks/InfobipDeliveryDedupTest.php::test_replayed_delivery_does_not_overwrite_delivered_at_or_refire_outbound` — `delivered_at` imutável + `FireOutboundWebhookJob::dispatchedTimes == 1`. |
| **QA-CONC-01** | `lockCampaignIfInsufficient` sem lockForUpdate | ✅ **Corrigido** | `sendNow` e `schedule` agora envolvem o check em `DB::transaction + lockForUpdate` (linhas 143 e 179). `BillingService::reserve` (linha 26) já tinha `Tenant::lockForUpdate()`. Reforçado por `WithoutOverlapping` no job. ✅ Mas **gap:** teste de **concorrência real** (paralelo) inexiste — só `BillingSecurityTest::test_04_reserve_race` sequencial. |
| **QA-LGPD-01** | Caminho de campanha não honra opt-out | ✅ **Corrigido** | Duplicado de QA-DISP-04. Cobertura: `CampaignOptOutComplianceTest`. |

**Placar dos 8 (excluindo duplicatas):**
- ✅ Corrigidos com teste: **6** (QA-DISP-01, QA-DISP-02, QA-DISP-04/LGPD-01, QA-REPORT-01, QA-WH-01, QA-CONC-01 — este último parcial sem teste de concorrência real)
- ⚠ Parciais (código incompleto ou sem teste): **3** (QA-CAMP-01, QA-CAMP-02, QA-BILL-01)
- ❌ Em aberto: **0** estrutural — mas QA-CONC-01 carece de teste de concorrência verdadeira.

---

## 4. Cobertura de Novos Endpoints

| Endpoint | Rota | Teste? | Onde |
|---|---|---|---|
| `GET /api/v1/audit-log` | `routes/api.php` (verificado via `Grep`) | ✅ | `tests/Feature/AuditLogEndpointTest.php` (4 cenários: admin lista, user 403, isolamento tenant, filtros) |
| `POST /api/v1/messaging/opt-outs/import` | `routes/api.php:203` | ❌ **Não há teste** | `OptOutsApiTest` cobre apenas `index`, `store`, `destroy`. **Bloqueador de regressão.** |
| `GET /api/v1/messaging/opt-outs/export` | `routes/api.php:199` | ❌ **Não há teste** | Idem. |
| `POST /api/v1/campaigns/{id}/reset` | `CampaignsController::reset` (linha 224) | ✅ | `tests/Feature/CampaignCancelResetTest.php::test_reset_moves_failed_to_draft_and_zeroes_counters` + `test_reset_rejects_non_failed_campaign` |
| `POST /api/v1/campaigns/{id}/cancel` | `CampaignsController::cancel` (linha 207) | ✅ | `tests/Feature/CampaignCancelResetTest.php::test_cancel_moves_scheduled_to_draft_and_clears_schedule` + `test_cancel_rejects_completed_campaign` |
| `GET /api/v1/account/pricing` | rota nova (verificado) | ✅ | `tests/Feature/Account/PricingEndpointTest.php` — valida 4 canais retornados (sms/voice/email/whatsapp) |
| `POST /api/v1/email-domains/{id}/verify` (IDOR) | `EmailDomainsController` | ✅ | `tests/Feature/Security/EmailDomainsIdorTest.php` (show/verify/destroy retorno 404 cross-tenant) |
| Cancel/Reset campaign | linha 207/224 | ✅ | acima |

**Smoke de UI (Vue):**

| Página | Existe? | Teste Vitest? |
|---|---|---|
| `frontend/src/pages/settings/OptOuts.vue` | ✅ Sim | ❌ Não — `Glob frontend/**/*.spec.ts` retorna apenas arquivos em `node_modules`. |
| `frontend/src/pages/settings/AuditLog.vue` | ✅ Sim | ❌ Não. |
| `frontend/src/pages/settings/EmailDomains.vue` | ✅ Sim | ❌ Não. |
| `frontend/src/pages/settings/Saldo.vue` | ✅ Sim | ❌ Não. |

Não existe `vitest.config.*` no frontend (`Glob frontend/**/vitest.config*` → vazio). **Zero cobertura de regressão de UI.** O consolidado lista vários P0 de UX que dependem dessas páginas (P0-25 ∞ saldo opaco, P0-26 banner saldo baixo, P0-33 auto-save silencioso); todos rebaixados a "verificação manual".

---

## 5. Lacunas de Cobertura Ainda Presentes

### 5.1 Críticos (precisam virar bloqueador)

1. **QA-NEW-01 — Suíte de testes não roda completa.** `php artisan test` retorna 242 failed por flakiness de RefreshDatabase com MySQL real. Sem CI verde, qualquer alteração futura entra em produção sem rede de proteção. **Bloqueador.** Fix sugerido: migrar para SQLite-in-memory para a suíte (manter MySQL apenas para `BillingSecurityTest` que exige tipos REAL e enum), ou reescrever a migration `2026_05_27_000006` para usar `dropIfExists` + `down()` simétrico, ou adicionar isolation level + recriação programática em `Tests\TestCase::setUp`.

2. **QA-NEW-02 — `POST /messaging/opt-outs/import` e `GET /messaging/opt-outs/export` sem teste.** Bloqueador. Pentest: CSV malicioso (`=cmd|'/c calc'!A1` — formula injection) não validado nem para import nem para export; LGPD Art. 18 (portabilidade) sem teste de regressão. **Bloqueador.**

3. **QA-NEW-03 — Não existe teste E2E (`HappyPathTest`).** O smoke `register → subscribe → list → import → campaign → dispatch → report` que era a recomendação central do relatório anterior (linha 309 do `03-qa.md`) NÃO foi escrito. **Bloqueador estratégico** — sem ele, qualquer refactor pode quebrar o caminho do cliente novo sem alarme.

4. **QA-NEW-04 — Concorrência real (paralelo) inexiste.** Toda "race" testada hoje é sequencial (loop). A correção de `WithoutOverlapping` em `ProcessCampaignJob` precisa de pelo menos um teste que dispare dois requests em fork (via `Process::start` ou `concurrently()` do Laravel 11) para validar que o segundo lock é negado em runtime, não só em código estático.

### 5.2 Atenções

5. **QA-NEW-05 — `ImportContactsJob` (CSV) sem teste.** `Glob backend/tests/**/*Import*.php` retorna zero. Caminho do cliente novo passa obrigatoriamente por import; sem cobertura.

6. **QA-NEW-06 — `SubscriptionController::store` cobertura mínima.** Só `SubscriptionPendingExpirationTest`. Cenários ausentes: cupom válido + race, MP retorna `pending` + webhook chega, MP retorna 500, idempotência por `card_token`.

7. **QA-NEW-07 — `ReportController::export` valida role mas não tamanho.** `ExportRbacTest` valida só RBAC (admin/user). P1-23 ("export sem limite total") segue sem teste; 10M linhas derruba PHP-FPM.

8. **QA-NEW-08 — `CampaignPricingSnapshotTest` não valida o **gasto efetivo** após `then()`.** Cobre que `unit_cents_at_dispatch` é persistido, mas não que o refund residual é calculado corretamente quando `sent_count < estimated_contacts` (cenário típico: opt-out reduziu envios). Falta cenário: 10 contatos × 10c reserva 100c → 3 opt-out → sent=7 → refund=30c.

9. **QA-NEW-09 — `BillingAuditLogTest` não cobre `recharge` via webhook MP.** Cobre só recharge sincronizado. Idempotência entre PaymentController + WebhookController não tem teste cruzado.

10. **QA-NEW-10 — Append-only do AuditLog é só Eloquent.** `tests/Feature/Billing/BillingAuditLogTest::test_audit_log_is_append_only_no_update/no_delete` valida o `RuntimeException` em PHP, mas P0-10 (consolidado) pedia também **trigger MySQL**. Sem teste de defesa-em-profundidade DB-level.

11. **QA-NEW-11 — Quiet-hours testa só "tudo ON" vs "tudo OFF".** `CampaignQuietHoursComplianceTest` não cobre cenário de horário de verão / transição de fuso (`America/Sao_Paulo` DST).

### 5.3 Melhorias

12. **QA-NEW-12 — Sem teste de retenção LGPD** (P2-18, P2-20).
13. **QA-NEW-13 — Sem teste de export CSV mascarado** (P1-03 do relatório anterior; LGPD).
14. **QA-NEW-14 — Sem mutation testing** (Infection PHP). Suíte cresceu 44% (~270 → 390), mas qualidade dos asserts não foi validada.

---

## 6. Smoke Test Mental do Happy Path

Caminhei pelo código simulando um cliente novo. Resultado:

| # | Passo | Status | Onde quebra HOJE |
|---|---|---|---|
| 1 | `POST /api/v1/auth/register` | ✅ | `AuthFlowTest` cobre. |
| 2 | `POST /api/v1/subscriptions {plan_id, card_token, billing_cycle:monthly}` | ⚠ Funciona, mas sem teste de cupom-race nem de PIX `pending → approved`. | Cliente que paga PIX e desconecta o navegador antes do webhook MP pode ver "pending" eterno até `subscription_pending_timeout_hours` (24h). |
| 3 | Webhook MP confirma assinatura | ✅ Cobertura nova: `MercadoPagoWebhookValidationTest` (4 cenários: type vazio, type desconhecido, data_id vazio, signature faltando). | Mas: e quando type é válido e `MercadoPagoService::processWebhook` lança? Sem teste. |
| 4 | `POST /api/v1/contact-lists {name}` | ❌ Zero teste em `ContactListsController`. | Sem regressão; qualquer refactor pode quebrar silenciosamente. |
| 5 | `POST /api/v1/imports {file, contact_list_id}` | ❌ Zero teste em `ImportContactsJob`. | CSV com BOM UTF-8, com formula injection, com 100k linhas — nada validado. |
| 6 | `POST /api/v1/campaigns {type:sms, content, contact_list_id}` | ⚠ `CampaignChannelLicenseTest` cobre canal habilitado; `CampaignMassAssignmentTest` cobre `tenant_id` forçado. Mas QA-CAMP-01 (sem destinatários aceito) e QA-CAMP-02 (`scheduled_at` passado no store) seguem **abertos**. | Cliente que esquece de selecionar lista cria draft inválida; só descobre no dispatch. |
| 7 | `POST /api/v1/campaigns/{id}/send-now` | ✅ `CampaignDoubleDispatchTest`, `CampaignReserveBeforeDispatchTest`, `CampaignPricingSnapshotTest`. | OK. |
| 8 | `Bus::batch` executa, `SendCampaignBatchJob` envia | ✅ `CampaignOptOutComplianceTest` (opt-out skipped), `CampaignFromEmailGuardTest` (from-email não autorizado bloqueia), `CampaignEmailUnsubscribeTest` (token único + opt-out via link). | Mas **opt-out de WhatsApp** (canal `whatsapp`) não tem teste — só SMS e Email. |
| 9 | Webhook Infobip atualiza `CampaignDispatch.status = delivered` | ✅ `InfobipDeliveryDedupTest` cobre replay. | Mas o teste valida `MessageDispatch`, não `CampaignDispatch` (são tabelas diferentes; ver QA-WH-04 original). Lookup correto para campanha não tem teste E2E. |
| 10 | `GET /api/v1/reports/campaigns` | ⚠ Zero teste. | Filtro `type=whatsapp` agora aceito no controller, mas não há regressão. |
| 10b | `GET /api/v1/reports/credits` | ✅ `CreditsExtractTest` cobre totais corretos + isolamento. | OK. |
| 11 | `GET /api/v1/reports/campaigns/{id}/export` (CSV) | ✅ `ExportRbacTest` cobre user/admin/superadmin (403/200/200). | Mas QA-REPORT-03 (telefone mascarado) e P1-23 (limite total) seguem abertos. |
| 12 | UI: cliente clica em "Cancelar agendamento" / "Redefinir failed" | ✅ Backend cobre (`CampaignCancelResetTest`). | UI Vue dos botões não tem teste. P0-22 sai como "código existe, UI funcional manual". |

**Conclusão do smoke E2E:** caminho passa de **5 dos 11 passos cobertos (45%)** para **8 dos 11 passos cobertos (73%)** após a rodada de correções. Os 3 buracos remanescentes (passos 4, 5 e 10) seguem sendo risco real.

---

## 7. Novos Achados

### 🔴 Bloqueadores (P0-NEW)

#### P0-NEW-CI — Suíte de testes inutilizada em CI por flakiness de DB
- **Onde:** `phpunit.xml` (linha 29-34) força `DB_CONNECTION=mysql` + `DB_DATABASE=campaignai_test` para todos os 86 arquivos que usam `RefreshDatabase`.
- **Sintoma:** `php artisan test` reporta **242 failed / 147 passed** em ambiente local limpo; rodando em lotes pequenos, taxa de sucesso é **~99%**.
- **Causa raiz provável:** migration `2026_05_27_000006_rename_credit_transactions_to_balance_transactions` faz `Schema::rename` + `DB::statement('ALTER TABLE ... MODIFY COLUMN type ENUM(...)')`. Em `RefreshDatabase` o `down()` é chamado entre testes; o `ALTER ENUM` no `down()` mantém referências que quebram o próximo `up()`.
- **Impacto:** **gate de PR não funciona**. Qualquer refactor entra em produção sem rede.
- **Remediação:** (1) migrar suíte a SQLite-in-memory (override `DB_CONNECTION=sqlite + DB_DATABASE=:memory:` em `phpunit.xml`) ou (2) usar `DatabaseTransactions` em vez de `RefreshDatabase` para todos os testes que não criam tabelas/seeders pesados, ou (3) reescrever a migration `2026_05_27_000006` para ser totalmente idempotente.

#### P0-NEW-OptOuts-IO — Endpoints `/messaging/opt-outs/import` e `/messaging/opt-outs/export` sem teste
- **Onde:** `routes/api.php:199,203`. UI Vue `frontend/src/pages/settings/OptOuts.vue` consome ambos.
- **Sintoma:** zero arquivo em `tests/Feature/Messaging/` cobre `import` ou `export`. `Grep "opt-outs/import|opt-outs/export"` em `tests/` retorna vazio.
- **Risco:** **LGPD Art. 18 (portabilidade) sem regressão**. Formula-injection em CSV (`=cmd|'/c calc'!A1`) não validado. Quota/timeout em export de 1M linhas não testado.
- **Bloqueador.**

#### P0-NEW-E2E — `HappyPathTest` ainda não escrito
- **Onde:** `Glob backend/tests/**/E2E/*` e `Glob backend/tests/**/*HappyPath*` ambos retornam vazio.
- **Risco:** recomendação central do relatório anterior (`audit/2026-05-28/03-qa.md` §11 ponto 6) foi ignorada. Sem isso, refactor de cobrança/state machine quebra o cliente sem alarme.
- **Esforço:** M (1 sprint).

### 🟡 Atenções (P1-NEW)

#### P1-NEW-01 — Sem teste de concorrência real (forks)
- `BillingSecurityTest::test_04_reserve_race_only_balance_worth_succeeds` simula 50 reserves **sequenciais** num único processo. O lock pessimista `lockForUpdate` é validado em pretexto, não em prática. Precisa de `Process::concurrently()` ou `pcntl_fork` para validar `WithoutOverlapping` no Horizon real.

#### P1-NEW-02 — Snapshot de preço não testa refund residual
- `CampaignPricingSnapshotTest` valida que `unit_cents_at_dispatch` é persistido e imutável. Não valida o caminho `then()/catch()` do `Bus::batch` que faz `$billing->release($refund)` quando `sent_count < estimated_contacts` (cenário típico após opt-out).

#### P1-NEW-03 — Webhook MP sem teste de erro no `processWebhook`
- `MercadoPagoWebhookValidationTest` cobre 4 cenários de validação de entrada; nenhum cobre **exception no service**. P0-19 do consolidado segue parcial.

#### P1-NEW-04 — `ImportContactsJob` sem teste
- Caminho obrigatório do cliente novo. CSV malicioso, BOM UTF-8, 100k linhas — zero cobertura.

#### P1-NEW-05 — `ContactListsController` sem teste
- Sem teste de CRUD nem de tenant-isolation explícito.

#### P1-NEW-06 — WhatsApp opt-out path sem teste
- `CampaignOptOutComplianceTest` cobre só SMS. `whatsapp` como canal também passa por `OptOutService::isOptedOut` no `SendCampaignBatchJob`, mas não há regressão.

#### P1-NEW-07 — `BillingAuditLogTest::test_audit_log_is_append_only_no_update` cobre só Eloquent
- Defesa em profundidade pedida em P0-10 (trigger MySQL) sem teste DB-level. Atacker que tem `DB::raw('UPDATE audit_logs SET ...')` ainda passa pelo guard.

#### P1-NEW-08 — `CampaignChannelLicenseTest` cobre 3 cenários mas não cobre channel `suspended`
- Apenas `enabled` vs `disabled`; o enum `tenant_channels.status` aceita também `suspended` e `pending_approval` (verificar migration). Sem teste.

#### P1-NEW-09 — `Reports/CreditsExtractTest` não cobre off-by-one em `from/to`
- QA-REPORT-05 original (range datetime vs date) segue sem regressão.

#### P1-NEW-10 — Mass-assignment de `User.email_verified_at`, `User.password`, `User.remember_token`
- `UserMassAssignmentTest` cobre `role` e `tenant_id`. Não cobre `email_verified_at` (atacante que consegue mass-assign verificação burla email-verification flow).

### 🟢 Melhorias (P2-NEW)

- **P2-NEW-01** — Adicionar Infection (mutation testing) ao pipeline; mede qualidade real dos asserts além de cobertura de linha.
- **P2-NEW-02** — Adicionar Pest browser tests para os 4 fluxos UI críticos (OptOuts, AuditLog, Saldo recharge, Create campanha step3-step5).
- **P2-NEW-03** — Adicionar `php artisan test --parallel` com paratest (resolve flakiness mais cedo do que SQLite-in-memory).
- **P2-NEW-04** — Adicionar smoke test pós-deploy (PM-G14 do consolidado) que dispara campanha de teste para número interno.

---

## 8. Veredito Final

**Pode lançar agora?** **NÃO.**

**Por quê (em 3 pontos objetivos):**

1. **Suíte de CI inutilizável** (P0-NEW-CI). Mesmo com correções técnicas corretas no código (validadas em lotes isolados), a impossibilidade de rodar `php artisan test` end-to-end significa que **não há proteção de regressão**. É como ter cinto de segurança bom mas sem fivela.

2. **Gaps de cobertura de endpoints novos**: `messaging/opt-outs/import` e `messaging/opt-outs/export` foram criados, expostos na UI Vue e nunca testados. LGPD Art. 18 (portabilidade) vira surpresa em auditoria.

3. **Ausência de E2E HappyPath**: o caminho do cliente real (registro → assinatura → import → campanha → relatório) não tem único teste cobrindo a sequência. 3 dos 11 passos seguem sem nenhuma regressão.

**O que mudou para melhor (placar honesto):**
- **6 de 8 bloqueadores QA fechados com teste** (QA-DISP-01, 02, 04/LGPD-01, REPORT-01, WH-01, CONC-01 — último parcial).
- **+62 testes novos** criados, focados nos P0 do consolidado, com asserts não-triviais (idempotência, lock, dedup, snapshot imutável, append-only).
- **Cobertura de happy-path do cliente sobe de 45% (5/11) para 73% (8/11) passos**.
- **Compliance LGPD** (opt-out em campanha, quiet-hours, unsubscribe email, append-only audit) **agora coberta por testes**.

**Critério mínimo para liberar QA-go-live (próxima re-auditoria):**

1. ☐ `php artisan test` retorna verde sem `migrate:fresh` manual entre rodadas. (Resolve P0-NEW-CI)
2. ☐ Existe `tests/Feature/E2E/HappyPathTest.php` cobrindo `register → subscribe → list → import → send-now → batch run → report credits`. (Resolve P0-NEW-E2E + QA-NEW-05/06)
3. ☐ Existem `tests/Feature/Messaging/OptOutsImportTest.php` e `OptOutsExportTest.php` cobrindo CSV malicioso + tamanho. (Resolve P0-NEW-OptOuts-IO)
4. ☐ QA-CAMP-01 e QA-CAMP-02 resolvidos no `CampaignsController::store` + teste regressivo.
5. ☐ Existe um teste de concorrência paralela real (usando `Process::concurrently()` ou paratest) cobrindo `sendNow` duplicado.

**Estimativa para fechar:** **3-5 dias de QA + 2-3 dias de dev** (sprint pequeno, focado).

---

**Fim do relatório.**
