# Re-auditoria Dev Sênior — CampaignAI
**Data:** 2026-05-29
**Auditor:** Dev Sr Full-Stack
**Comparação:** estado após 4 commits de correção (`f2650f85` `6d39c32f` `d31bf2d8` `e1889124`) vs `audit/2026-05-28/02-dev-senior.md`.

---

## 1. Veredito Executivo

- A rodada de correções **avançou substancialmente** nos itens financeiros e de segurança: 12 dos 14 bloqueadores originais do relatório anterior estão **✅ Corrigidos**, 1 está **⚠️ Parcial** e 1 permanece **❌ Em aberto** (sem UNIQUE constraint em `campaign_dispatches`).
- O fluxo de cobrança foi **reescrito corretamente**: `BillingService::reserve` agora é idempotente, `ProcessCampaignJob` faz reserve PRÉ-batch + release no `then/catch`, há `WithoutOverlapping` e há snapshot de preço persistido em colunas físicas (`unit_cents_at_dispatch`, `reserved_cents`). É o avanço mais importante.
- **PORÉM, 3 regressões novas e críticas foram introduzidas pelas correções**:
  1. 🔴 Suite de testes está QUEBRADA em execução conjunta: `MigrationRoundtripTest::test_rollback_then_migrate_leaves_consistent_state` derruba `migrations` table via DDL e contamina TODOS os testes subsequentes (191-249 falhas dependendo da ordem); o instrumento de validação contínua se tornou inútil.
  2. 🔴 Idempotência de `BillingService::recharge` está **apenas no PHP** — sem UNIQUE constraint no DB (`balance_transactions(reference_type, reference_id, type)`) o P0-05 (double-credit MP) volta sob race condition entre webhook MP e `PaymentController::credits`.
  3. 🔴 `Bus::batch->then()` recalcula `$consumed = $unitCents * $sent_count` para devolver refund, mas se uma das **batches falhar parcialmente** e o `catch()` rodar, o `release` baseado em `sent_count` atual **pode liberar saldo de mensagens que ainda estão na fila e ainda serão enviadas** (race entre `sent_count` final e refund), gerando perda de receita.
- A suíte rodando **isoladamente por testsuite** passa: Unit (85), Feature (262), Pentest (42) = 389 testes. Mas `php artisan test` na raiz falha por contaminação.
- Veredito: **NÃO PODE LANÇAR** ainda. Maior parte do trabalho foi feita, mas dois pontos de receita continuam expostos e a regressão da suíte impede que se valide qualquer próxima entrega.

---

## 2. Status dos 14 Bloqueadores Dev Originais

| # | Bloqueador Original | Status | Evidência atual |
|---|---|---|---|
| BLQ-01 | `CampaignsController::store` aceita canal não-licenciado | ✅ Corrigido | `CampaignsController.php:70-77` — closure `$licensedChannel` chama `TenantChannel::isAvailable()` no `store` e no `update`. |
| BLQ-02 | Falta de FormRequest + `authorize()` | ⚠️ Parcial | Continua usando `$request->validate()` inline em `CampaignsController.php:75-86`. Não há `CreateCampaignRequest/UpdateCampaignRequest`. Mass-assignment foi mitigado via `Campaign.php:20-24` removendo `tenant_id` do `$fillable` (defesa única, sem `unset()` no controller). |
| BLQ-05 | Duplo disparo sem lock | ✅ Corrigido | `CampaignsController.php:143-162` envolve `sendNow` em `DB::transaction` com `Campaign::lockForUpdate()`; `ProcessCampaignJob.php:35-43` adiciona `new WithoutOverlapping("campaign:{$id}")->expireAfter(600)->dontRelease()`. Defesa em 3 camadas. |
| BLQ-06 | Cobrança DEPOIS do batch | ✅ Corrigido | `ProcessCampaignJob.php:120-145` — `reserve` é chamado ANTES de `Bus::batch(...)->dispatch()`. Em insuficiência, transiciona para `failed` e retorna sem enfileirar nenhuma `SendCampaignBatchJob`. |
| BLQ-07 | Saldo negativo permitido sem limite | ✅ Corrigido pela mesma mudança | `BillingService.php:46-51` mantém checagem `$available < $amountCents` mas agora é pré-envio (BLQ-06). Mensagens não são enviadas se saldo insuficiente. |
| BLQ-08 | `settings.from_email` sem validação | ✅ Corrigido | `SendCampaignBatchJob.php:62-67` chama `EmailDomainService::findActiveForEmail` e linha 159-169 bloqueia o envio e grava dispatch `error: unauthorized_from_email`. **NOTA:** validação é runtime no job, não no controller — usuário só descobre erro pós-disparo. Idealmente também validar no `CampaignsController::store`. |
| BLQ-12 | `reserve` sem release automático | ✅ Corrigido | Fluxo agora é: `reserve` antes do batch, `release` no `.then()` (refund da diferença `reserved - consumed`) e no `.catch()`. `BillingService.php:114-147`. |
| BLQ-13 | `PricingService::priceFor` sem snapshot | ✅ Corrigido | Migration `2026_05_29_000001_add_pricing_snapshot_to_campaigns.php` adiciona colunas `unit_cents_at_dispatch` e `reserved_cents`; `ProcessCampaignJob.php:150-157` persiste o snapshot via `forceFill` no momento do reserve. |
| BLQ-14 | `PaymentController::credits` cobra MP fora de transação | ⚠️ Parcial | `PaymentController.php:236-315` reestruturou o fluxo: cria Payment como pending → tenta charge MP → reconciliação `update + recharge` em transação única. Porém **a chamada à MP (linha 249) continua fora de transação** (correto — MP é externa) **e não há rollback do Payment se a transação de reconciliação morrer entre linha 268 e 282** (worker crash). Sem job de reconciliação de Payments aprovados-sem-recharge. |
| BLQ-15 | MP `status: 'authorized'` literal | ✅ Corrigido | `MercadoPagoService.php:55` foi removido. Comentário explica que status real do MP é usado. |
| BLQ-18 | AuditLog crítico ausente | ✅ Corrigido (parcial) | `grep AuditLog::record` agora retorna **19 chamadas** em 5 arquivos (era 10/3). Cobertos: `billing.reserve`, `billing.release`, `billing.recharge`, `billing.manual_adjustment`, `campaign.cancel`, `campaign.reset`, `optout.created/removed/bulk_import`, `auth.email_verified`. **AINDA AUSENTES:** `campaign.schedule` (`CampaignsController.php:204` — return sem `AuditLog::record`), `payment.pix/boleto/credits` (`PaymentController.php` inteiro — `grep AuditLog` no arquivo = 0 matches), `subscription.created/cancelled` (`SubscriptionController.php` — 0 matches), `webhook.mercadopago` (`WebhookController.php` — 0 matches). |
| BLQ-19 | AuditLog mutável | ✅ Corrigido (parcial) | `AuditLog.php:23-31` adiciona hooks `updating` e `deleting` que lançam `RuntimeException`. **Falta trigger SQL** — comentário da própria classe admite que é "defense-in-depth" e que trigger MySQL ainda é necessário. Superadmin com acesso direto ao DB ainda pode adulterar. |
| BLQ-25 | Webhook MP processa sem validar `type`/`request_id` | ✅ Corrigido | `WebhookController.php:32-37` rejeita 400 quando `$dataId === ''` ou `$type === ''`, e exige `type ∈ {payment, subscription, preapproval}`. |
| BLQ-26 | InfobipWhatsApp retorna 500 sem secret | ✅ Corrigido | `InfobipWhatsAppWebhookController.php:35-38` agora retorna `401 Unauthorized` com mensagem genérica (sem vazar config interna). |

**Resumo dos 14 BLOQUEADORES originais:** ✅ 12 corrigidos · ⚠️ 2 parciais (BLQ-02 e BLQ-14) · ❌ 0 abertos.

---

## 3. Suíte de Testes (executei `php artisan test`?)

Sim. Resultados:

- **Por testsuite (isolado), TUDO PASSA:**
  - `php artisan test --testsuite=Unit` → **85 passed**, 1 warning (DNS).
  - `php artisan test --testsuite=Feature` → **262 passed**.
  - `php artisan test --testsuite=Pentest` → **42 passed** (1.272 asserções).
  - **Total isolado:** 389 testes passando, conforme prometido na instrução.
- **Combinado (`php artisan test`), QUEBRA:**
  - Última execução: **63 failed · 326 passed · 938 assertions · 52,19s**.
  - Execuções sequenciais oscilam entre **63 e 249 failures** dependendo da ordem.
  - **Causa raiz identificada:** `tests/Feature/Billing/MigrationRoundtripTest.php:41` executa `Artisan::call('migrate:rollback', ['--step' => 15])` que faz DDL real (`DROP TABLE`). `RefreshDatabase` usa transações para reverter inserts, mas **DDL não é transacional em MySQL** — após esse teste rodar, todas as tabelas resetadas pelo rollback continuam rolando para o próximo teste, que então quebra com `Base table or view not found: 1146 Table 'campaignai_test.migrations' doesn't exist`.
  - O `--step => 15` foi atualizado em commits recentes para incluir as novas migrations, mas a estratégia continua hostil ao test-runner padrão. Solução: usar `DatabaseMigrations` (não `RefreshDatabase`) só nesse teste, ou marcá-lo `@group migration` e excluir da suite default.

🔴 **REGRESSÃO-A (BLOQUEADOR NOVO):** Suite combinada quebrada inviabiliza CI/CD. Sem `php artisan test` verde, qualquer próxima entrega entra cega.

---

## 4. Regressões Detectadas

### 🔴 REGRESSÃO-B — `MigrationRoundtripTest` contamina DB de outros testes
- **Arquivo:** `backend/tests/Feature/Billing/MigrationRoundtripTest.php:41`.
- **Detalhe:** `migrate:rollback --step=15` faz `DROP TABLE` real; o test runner não consegue isolar via transação. Já documentado acima como REGRESSÃO-A (mesmo bug).
- **Impacto:** Bloqueia CI; força engenheiro a rodar `migrate:fresh` antes de cada `php artisan test`.

### 🔴 REGRESSÃO-C — `Bus::batch->then()` calcula refund por `sent_count` sem aguardar finalização atomica
- **Arquivo:** `backend/app/Jobs/ProcessCampaignJob.php:163-178`.
- **Detalhe:** O callback `then()` é executado quando o batch reporta "completed". Refund = `reserved - (unit * sent_count)`. Porém `SendCampaignBatchJob::handle` (`SendCampaignBatchJob.php:197-200`) faz `update sent_count = DB::raw('sent_count + N')` ao final de cada batch. Se o `then()` rodar antes do último `UPDATE` ser visível em transação isolation REPEATABLE READ (default MySQL), o refund libera centavos referentes a mensagens já enviadas. Saldo do tenant fica **maior** do que deveria. Não é cobrança em duplicata, mas é **leak de receita**.
- **Mitigação parcial existente:** `$campaign->refresh()` antes de calcular consumo (linha 166). Em REPEATABLE READ, snapshot é da entrada da transação implícita do worker — `refresh()` pode reler valor antigo. Fix recomendado: ler `sent_count` via `Campaign::lockForUpdate()` dentro de transação no callback, ou disparar refund em job separado com `delay(60)`.

### 🟡 REGRESSÃO-D — `BLQ-08` validação só no job, não no controller
- **Arquivo:** `backend/app/Jobs/SendCampaignBatchJob.php:62-67` (validação correta), `backend/app/Http/Controllers/API/V1/CampaignsController.php:75-86` (não valida `settings.from_email`).
- **Detalhe:** Tenant consegue salvar campanha com `from_email` falso. O bloqueio só ocorre na hora do envio, gerando `unauthorized_from_email` em todos os dispatches da batch. UX ruim e gasto de fila desnecessário. Não é regressão de segurança (de fato bloqueia), mas é regressão de UX em relação ao que se esperaria de um fix "completo".

### 🟡 REGRESSÃO-E — `ProcessCampaignJob.quiet_hours` reagendamento descarta reserve idempotency
- **Arquivo:** `backend/app/Jobs/ProcessCampaignJob.php:57-75`.
- **Detalhe:** Se cair em quiet hours, transiciona `processing → draft → scheduled`. Não há `release()` proativo (não foi feito reserve ainda; OK). MAS quando o job re-roda no horário novo, ele re-faz `reserve()` — protegido pela idempotência no `BillingService::reserve` (`BillingService.php:35-44` usa `BalanceTransaction::where(reference_type=campaign_dispatch, reference_id=campaignId)->exists()`). Funciona para retry rápido, MAS se houver mudança de **preço** entre o quiet defer e a re-execução, o snapshot `unit_cents_at_dispatch` salvo no campaign pelo run-1 sobrevive (`save()` da linha 150-157 só ocorre APÓS o reserve, e o reserve nem aconteceu). Portanto run-2 ignora o preço antigo e usa preço novo. Inconsistência entre BLQ-04/BLQ-13 (snapshot) e BLQ-09 (quiet defer). Confirmar com teste.

### 🟡 REGRESSÃO-F — Auto-clear do `low_balance` cache key não cobre `release`
- **Arquivo:** `backend/app/Services/Billing/BillingService.php:204` (em `recharge`) e linhas 100-109 (em `reserve`).
- **Detalhe:** `Cache::forget("webhook:billing.low_balance:tenant:{$tenantId}")` só é chamado em `recharge`, não em `release`. Quando um refund grande devolve saldo acima do threshold, o tenant fica "marcado" no Cache até `recharge` próximo. Próximo `reserve` que voltar a passar pelo threshold **não dispara** evento outbound `billing.low_balance` (cache atrasa 24h). Cliente pode perder alerta. Bug funcional novo introduzido pela refatoração do reserve idempotente.

---

## 5. Dívida Técnica Nova

### 🟡 DT-01 — Snapshot persistido em dois lugares (colunas + JSON)
- **Arquivo:** `backend/app/Jobs/ProcessCampaignJob.php:150-157`.
- O snapshot é gravado tanto em `unit_cents_at_dispatch`/`reserved_cents` (colunas) quanto em `settings['unit_cents_at_dispatch']`/`settings['reserved_cents']` (JSON). Justificativa documentada ("backward compatibility"). Já é dívida: `then()`/`catch()` leem com fallback `?? $snapshot[...]`. Eventual refactor que remover o JSON path precisa migrar dados existentes. Em prod, o duplo write significa que se alguém atualizar só uma das vias, ficam inconsistentes.

### 🟡 DT-02 — `EmailDomainsController` sem trait `ChecksTenant`
- **Arquivo:** `backend/app/Http/Controllers/API/V1/EmailDomainsController.php:21-29`.
- Reimplementa `findOwned()` em vez de usar `$this->ensureTenantOwns($model)` do trait `Concerns\ChecksTenant` (usado em `CampaignsController` e `ReportController`). Lógica diverge sutilmente: `findOwned` filtra antes do `findOrFail`; `ensureTenantOwns` checa após. Risco de inconsistência em refactor futuro.

### 🟡 DT-03 — `CampaignsController` ainda usa `Request` direto + closures inline
- **Arquivo:** `backend/app/Http/Controllers/API/V1/CampaignsController.php:66-128`.
- BLQ-02 não foi resolvido na forma: ainda é `Request $request` + `validate()` + closure local `$licensedChannel`. Duplicação entre `store` e `update` (mesma closure declarada 2x). Refactor para FormRequest com `authorize()` permitiria policy real e simplificaria testes.

### 🟡 DT-04 — Status string solto vs Enum
- **Arquivo:** `backend/app/Services/CampaignStateMachine.php:11-20`.
- Estados ainda são strings em array constante. Laravel 11 + PHP 8.2 já suporta backed enums em casts. Próxima manutenção (adicionar `paused`?) será error-prone.

### 🟡 DT-05 — `tenant_id` removido do `User::$fillable` sem teste de regressão em `Admin\TenantsController::store`
- **Arquivo:** `backend/app/Http/Controllers/API/V1/Admin/TenantsController.php:60+` cria o User admin de tenants. Como `tenant_id` saiu de `$fillable`, qualquer `User::create([..., 'tenant_id' => $id])` agora ignora silenciosamente o tenant_id e cria User com `tenant_id = NULL`. Verifiquei `TenantsController::store` (apenas vi as primeiras 60 linhas, mas o pattern é comum): precisa estar usando `forceFill` ou setter direto. Risco de criação silenciosa de admin órfão. Recomendo teste explícito.

### 🟡 DT-06 — `centsFromRequest` ainda aceita 2 nomes de parâmetro (legacy)
- **Arquivo:** `backend/app/Http/Controllers/API/V1/PaymentController.php:31-34`.
- Aceita tanto `amount_cents` quanto `credits_amount`. P0-28 (UI vs API ambiguidade) foi corrigido na UI mas o backend continua aceitando os 2 nomes. Em produção, monitorar qual chega para acabar com legacy e simplificar.

---

## 6. Novos Achados

### 🔴 NOVO-01 — Sem UNIQUE constraint em `balance_transactions(tenant_id, type, reference_type, reference_id)`
- **Arquivo:** migrations em `backend/database/migrations/` — só existe `unique('bt_monthly_unique')` para `(tenant_id, reference_type, monthly_cycle_key)`. Não há índice único garantindo idempotência das transações de `recharge`/`reserve` por reference.
- **Impacto:** O P0-05 (double-credit MP) está mitigado **APENAS pela checagem `exists()` + `lockForUpdate()` em PHP** (`BillingService.php:35-44` e `BillingService.php:164-173`). Em race extrema entre `PaymentController::credits` síncrono e webhook MP rodando em outro worker, ambas as transações podem chegar ao `lockForUpdate` em pontos onde a outra ainda não fez COMMIT, e ambos verão `exists() === false`. O lock no `Tenant` row reduz mas não elimina — só funciona porque ambas trancam o mesmo tenant. Em produção, basta deadlock retry para passar. **Defesa correta é UNIQUE index no DB**.
- **Recomendação:** migration `ALTER TABLE balance_transactions ADD UNIQUE(tenant_id, type, reference_type, reference_id) WHERE type IN ('reserve', 'recharge')` (parcial). MySQL não suporta unique parcial — usar UNIQUE total com `monthly_cycle_key` substituindo `reference_id` quando aplicável.

### 🔴 NOVO-02 — `campaign_dispatches` continua sem UNIQUE em `(campaign_id, contact_id)` / `(campaign_id, phone)`
- **Arquivo:** `backend/database/migrations/2026_03_12_160010_create_campaign_dispatches_table.php:11-28` (original sem UNIQUE) + `backend/database/migrations/2026_05_28_000001_add_unsubscribe_to_campaign_dispatches.php` (adiciona apenas UNIQUE em `unsubscribe_token`).
- ATENÇÃO-03 do relatório anterior nunca virou bloqueador no consolidado P0-02 menciona, mas a migration SQL para impor unique constraint NÃO foi criada nesta rodada.
- `SendCampaignBatchJob.php:329-335` continua usando `DB::table('campaign_dispatches')->updateOrInsert(['campaign_id', 'contact_id'], ...)` — vulnerável a race em dois workers do mesmo batch.

### 🔴 NOVO-03 — `UnsubscribeController` retorna 404 silencioso quando contato foi deletado
- **Arquivo:** `backend/app/Http/Controllers/API/V1/Messaging/UnsubscribeController.php:46-52`.
- Em campanha email com contact_list, se admin deletou um Contact entre o envio e o clique no link, `Contact::find($dispatch->contact_id)?->email` retorna null. Fallback `?? $campaignDispatch->phone` — mas em campanha por lista o `phone` armazena o **destination string** (que pode ter sido um phone se a campanha era SMS, mas para email o `recordDispatch` linha 313 grava `'phone' => $destination` que **é o email**). Então fallback funciona acidentalmente. Mas **se for adhoc para SMS**, o `phone` é phone real e o opt-out vai para canal email com identificador errado. Não há filtro `$campaign->type === 'email'` antes do fallback. Confirmar.

### 🟡 NOVO-04 — `TenantContext` ainda estático (ATENÇÃO-09 não corrigida)
- **Arquivo:** `backend/app/Services/TenantContext.php:5-22`.
- Variável estática global. Já estava como ATENÇÃO-09. Permanece. Continua funcionando em queue `database` single-worker; quebra no momento de migrar para Redis + Octane. Não bloqueia lançamento conservador, mas é dívida bombarda relógio.

### 🟡 NOVO-05 — `CampaignsController::schedule` sem `AuditLog::record`
- **Arquivo:** `backend/app/Http/Controllers/API/V1/CampaignsController.php:173-205`.
- `sendNow` audita (linha 169), `cancel` audita (linha 216), `reset` audita (linha 235), `destroy` audita (linha 135). `schedule` **não audita**. Gap explícito de cobertura LGPD/SOC2.

### 🟡 NOVO-06 — `PaymentController` continua sem qualquer `AuditLog::record`
- **Arquivo:** `backend/app/Http/Controllers/API/V1/PaymentController.php` — `grep AuditLog | wc -l = 0`.
- `BillingService::recharge` audita o evento `billing.recharge` (`BillingService.php:189`), o que é redundante e melhor que nada — mas eventos de **negação** (PIX expirado, boleto cancelado, MP retornou rejected) não são auditados em lugar nenhum. Fluxo de fraude (atacante tentando 100x cartões) não fica rastreado.

### 🟡 NOVO-07 — `SubscriptionController` sem auditoria
- **Arquivo:** `backend/app/Http/Controllers/API/V1/SubscriptionController.php` — 0 matches `AuditLog`.
- Criar/cancelar assinatura não fica registrado em audit log. Único trace é `Log::info` no MP service. LGPD art. 37 não cumprido para subscriptions.

### 🟡 NOVO-08 — `WebhookController::mercadopago` não audita ingestão
- **Arquivo:** `backend/app/Http/Controllers/API/V1/WebhookController.php:22-84`.
- Webhook MP é o gatilho de alteração financeira (vide `processPaymentWebhook → activatePayment → billing->recharge`). Não há audit log do **fato de o webhook ter chegado** — só do recharge que ele dispara. Em incidente onde MP envia webhook mas processWebhook quebra antes de chegar ao recharge, fica zero trace além de `Log::info`.

### 🟡 NOVO-09 — `OutboundWebhookGuard::resolve` faz DNS via `dns_get_record` que falha em ambiente offline
- **Arquivo:** `backend/app/Services/Security/OutboundWebhookGuard.php:60-71`.
- Em CI sem DNS, retorna lista vazia e o método lança `Webhook host did not resolve`. Confirmei na suíte: `Tests\Pentest\BillingSecurityTest::20_lgpd_deleted_tenant_history_preserved_for_audit` e múltiplos testes pentest **falham** quando rodados após `MigrationRoundtripTest`, mas também há warning DNS no FireOutboundWebhookJobTest. Em produção, qualquer hiccup de DNS torna webhooks lentos (dns_get_record sem timeout). Considerar `gethostbyname` + cache curto.

### 🟢 NOVO-10 — Falta teste para `quiet_hours` reagendamento + idempotência de reserve
- Cenário: `ProcessCampaignJob` cai em quiet hours, reschedule, segundo run não deve dobrar reserve. Não há teste cobrindo essa combinação (verifiquei `tests/Feature/CampaignQuietHoursComplianceTest.php` e `tests/Feature/Billing/ReserveIdempotencyTest.php` separadamente; nenhum encadeia ambos).

### 🟢 NOVO-11 — Continua sem teste de concorrência em `lockCampaignIfInsufficient`
- ATENÇÃO-34 original não foi coberta. Race ainda é teórica mas há `lockForUpdate()` em `Tenant` agora, então o risco diminuiu. Vale teste para fechar.

---

## 7. Veredito Final

### Pode lançar? **Não, ainda não.**

Justificativa técnica:

1. **Cobrança e fluxo de batch corrigidos** (12 dos 14 BLQs do relatório anterior). O caminho crítico de receita está estatisticamente seguro: `reserve` idempotente, `WithoutOverlapping`, snapshot de preço persistido em colunas, lock no `sendNow`. Esse é o avanço mais importante.
2. **Mas o P0-05 (double-credit MP) está protegido APENAS em PHP, sem UNIQUE no DB** (NOVO-01). Sob carga real com workers concorrentes, o exists()+lockForUpdate em transações distintas não garante exclusão mútua absoluta. UNIQUE constraint física é mandatória.
3. **`campaign_dispatches` continua sem UNIQUE constraint** (NOVO-02). Mesmo problema do (1), mas no batch: dois workers do mesmo batch enviam para o mesmo contato e o `updateOrInsert` aceita ambos.
4. **Suíte de testes quebrada em execução combinada** (REGRESSÃO-A/B). CI/CD inutilizado. Próxima entrega entra sem rede de segurança.
5. **AuditLog: cobertura sobe mas continua incompleta** em pagamentos, subscriptions, schedule e ingestão de webhooks (NOVO-05/06/07/08). LGPD art. 37 ainda não totalmente atendido para operações financeiras.
6. **`then()` calculando refund com base em `sent_count` lido fora de transação** (REGRESSÃO-C). Possível leak de receita em race com SendCampaignBatchJob final.

### Pré-condições mínimas para nova reavaliação (3-5 dias de trabalho):

1. Criar migration UNIQUE em `balance_transactions(tenant_id, type, reference_type, reference_id)` (parcial via NULL handling) + `campaign_dispatches(campaign_id, contact_id)` + `campaign_dispatches(campaign_id, phone)`.
2. Isolar `MigrationRoundtripTest` em testsuite separado ou usar `DatabaseMigrations` (não `RefreshDatabase`) com `@group migration` excluído do default.
3. Mover cálculo de refund para dentro de `DB::transaction(fn() => Campaign::lockForUpdate()->refresh() …)` ou agendar como job dedicado `RefundCampaignReserveJob` com `delay(60)`.
4. Adicionar `AuditLog::record` em: `CampaignsController::schedule`, `PaymentController::pix/boleto/credits` (sucesso e falha), `SubscriptionController::store/destroy`, `WebhookController::mercadopago` (ingestão crua).
5. Adicionar trigger SQL append-only em `audit_logs` (`BEFORE UPDATE/DELETE → SIGNAL SQLSTATE`).

### Riscos secundários a monitorar pós go-live:

- `TenantContext` estático (NOVO-04) — não escalar para Octane/multi-worker Redis sem reescrever.
- DT-01 (snapshot duplicado em coluna + JSON) — pode causar inconsistência se uma das vias for alterada por hotfix.
- DT-05 (User.tenant_id removido do fillable) — auditar todos os callers de `User::create([...])` que esperavam atribuir tenant_id automaticamente.

---

**Resumo numérico:**
- 14 BLQs originais → ✅ 12 corrigidos · ⚠️ 2 parciais · ❌ 0 totalmente em aberto.
- 21 ATENÇÕES originais → ainda não auditei item-a-item (escopo do relatório foi focar nos BLQ + regressões); pontos como ATENÇÃO-09 (TenantContext static) e ATENÇÃO-03 (UNIQUE campaign_dispatches) reaparecem como NOVO-04 e NOVO-02 (regressão por omissão).
- 6 regressões/novos achados 🔴 BLOQUEADOR.
- 6 novos achados 🟡 ATENÇÃO.
- 2 novos achados 🟢 MELHORIA.
- Suíte: 389 testes passam isolados por testsuite; combinado falha 63+ por regressão de DB DDL.
