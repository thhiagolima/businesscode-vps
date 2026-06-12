# Relatório Dev Sênior — Fluxo de Campanhas
**Data:** 2026-05-28
**Auditor:** Desenvolvedor Sênior Full-Stack
**Stack:** Laravel 11, Vue 3, MySQL, Filas database/redis

---

## Sumário Executivo

- O fluxo de campanhas tem isolamento multi-tenant via global scope `AppliesTenantScope`, mas **não há proteção contra duplo disparo** em `CampaignsController::sendNow` — basta o cliente clicar duas vezes para enfileirar dois `ProcessCampaignJob` paralelos sobre a mesma campanha.
- A **cobrança é feita DEPOIS do disparo** (`ProcessCampaignJob::then()` chama `BillingService::reserve()` no callback de batch concluído), permitindo que tenant esgote saldo durante envio e ainda assim a campanha rode até o fim — saldo final fica negativo e o sistema confia em `credit_limit_cents` para não estourar; sem limite configurado, há **envio gratuito após overdraft**.
- `BillingService::reserve()` faz `$tenant->decrement('balance_cents')` DENTRO de uma transação com `lockForUpdate()`, mas a chave do método é mal nomeada: chama-se `reserve` e na prática **debita imediatamente** (cria `BalanceTransaction type='reserve' amount=-X`), sem release-on-success — não há reserva temporária real.
- A maior parte do AuditLog está **ausente**: só 10 chamadas a `AuditLog::record()` em todo o backend, sem cobrir `campaign.schedule`, `campaign.cancel`, `subscription.*`, `payment.*`, `billing.recharge`, `billing.manual_adjustment`.
- Webhooks Infobip rejeitam quando secret não está configurado (correto), porém `InfobipWhatsAppWebhookController` retorna **HTTP 500** em vez de 401 quando secret está vazio (vazamento de configuração interna).
- **Veredito: NÃO PODE LANÇAR.** 11 bloqueadores impedem produção, principalmente em billing e race conditions.

---

## 1. Criação de Campanha

### Bloqueadores

- **🔴 BLOQUEADOR-01 — `CampaignsController::store` aceita campanha sem validar canal habilitado para o tenant**
  Arquivo: `backend/app/Http/Controllers/API/V1/CampaignsController.php:65-89`.
  O `store` valida `type in:sms,voice,email,whatsapp` mas **não verifica `TenantChannel::isAvailable($tenantId, $type)`**. A verificação só ocorre em `CampaignStateMachine::assertCanDispatch` (linha 73-75). Resultado: tenant que NÃO tem WhatsApp habilitado consegue criar a campanha — só descobre que está bloqueado ao tentar disparar. UX péssima e permite enumeração de canais não-licenciados.

- **🔴 BLOQUEADOR-02 — Validação inline ao invés de FormRequest, sem `authorize()`**
  Arquivo: `backend/app/Http/Controllers/API/V1/CampaignsController.php:69-80`. Toda lógica de validação é `$request->validate([...])`. Não existem FormRequests para `CreateCampaignRequest`, `UpdateCampaignRequest`, `ScheduleCampaignRequest`. O `authorize()` está ausente — qualquer usuário autenticado de qualquer tenant pode criar campanhas, e a autorização real só acontece via global scope no `Campaign::create()`.

### Atenção

- **🟡 ATENÇÃO-03 — Falta de unique constraint em `campaign_dispatches(campaign_id, contact_id)` / `(campaign_id, phone)`**
  Arquivo: `backend/database/migrations/2026_03_12_160010_create_campaign_dispatches_table.php:11-28`.
  `SendCampaignBatchJob::recordDispatch()` usa `DB::table('campaign_dispatches')->updateOrInsert([campaign_id, contact_id], …)` (linha 256). Sem UNIQUE no DB, dois jobs concorrentes para o mesmo `(campaign, contact)` criarão linhas duplicadas — `updateOrInsert` faz `SELECT … LIMIT 1` antes de inserir, vulnerável a TOCTOU. Idem para adhoc com `(campaign_id, phone)`.

- **🟡 ATENÇÃO-04 — `estimated_contacts` para listas é zero até o dispatch real**
  Arquivo: `backend/app/Http/Controllers/API/V1/CampaignsController.php:84-86`.
  Só calcula `estimated_contacts` para adhoc; para `contact_list_id` continua zero. `lockCampaignIfInsufficient` na linha `BillingService.php:210-215` recalcula via `Contact::count()`, mas a checagem de saldo no `sendNow` (linha 137) usa apenas `$lock['possible_sends']` — se a lista tiver 0 contatos ativos no momento do clique, o disparo passa por "saldo OK" mas envia 0 mensagens. Sem erro pro usuário.

---

## 2. Disparo (Jobs, Filas, Retries)

### Bloqueadores

- **🔴 BLOQUEADOR-05 — `CampaignsController::sendNow` não tem lock para impedir duplo disparo**
  Arquivo: `backend/app/Http/Controllers/API/V1/CampaignsController.php:127-149`.
  ```php
  $machine->transition($campaign, 'processing');
  ProcessCampaignJob::dispatch($campaign->id, $campaign->tenant_id);
  ```
  O `transition` faz `forceFill->save()` sem `lockForUpdate()`. Duas requisições simultâneas passam pelo `assertCanDispatch` (que só lê status), ambas tentam transicionar `draft → processing` (o state machine não rejeita transição idempotente — `processing → processing` não é permitido, mas a primeira já saiu de `draft`). Mesmo assim a janela TOCTOU é grande o suficiente para enfileirar 2 `ProcessCampaignJob` que processarão o mesmo `contact_list`, dobrando o gasto.

- **🔴 BLOQUEADOR-06 — `ProcessCampaignJob` debita saldo APÓS conclusão do batch (sem reserva prévia real)**
  Arquivo: `backend/app/Jobs/ProcessCampaignJob.php:91-110`.
  ```php
  ->then(function () use ($campaign, $machine, $billing, $pricing) {
      $amountCents = $unitCents * max(0, (int) $campaign->sent_count);
      if ($amountCents > 0) {
          $ok = $billing->reserve(...);
          if (! $ok) { Log::warning('...reserve_failed'...); }
  ```
  Se `reserve` falhar (saldo insuficiente após disparo concluído), o sistema apenas loga warning — **as mensagens já foram enviadas e custaram dinheiro ao provider, mas o tenant não foi cobrado**. Cliente fica devendo, sem inscrição em fila de cobrança.

- **🔴 BLOQUEADOR-07 — Saldo negativo permitido sem limite de crédito**
  Arquivo: `backend/app/Services/Billing/BillingService.php:30-35`.
  ```php
  $available = $balanceBefore + $creditLimit;
  if ($available < $amountCents) { return ['ok' => false]; }
  ```
  Se `credit_limit_cents = 0` (default da migration), o `reserve` rejeita corretamente. Mas o débito acontece **depois do envio** (BLOQUEADOR-06) — quando chega aqui, mensagens já foram enviadas; rejeitar reserva não desfaz envio. Sem `BalanceTransaction` registrado, o saldo permanece intacto e o tenant terá disparado de graça.

- **🔴 BLOQUEADOR-08 — `SendCampaignBatchJob` lê `campaign->settings['from']` sem validação de propriedade**
  Arquivo: `backend/app/Jobs/SendCampaignBatchJob.php:53-55`.
  ```php
  $from = $campaignSettings['from'] ?? $settings->getGlobal('infobip', 'default_sender', 'InfoSMS');
  $fromEmail = $campaignSettings['from_email'] ?? ...;
  ```
  O campo `from`/`from_email` em `settings` JSON nunca é validado pelo controller (linhas 69-80 do `CampaignsController::store`). Tenant pode setar `settings.from_email = "noreply@bancodobrasil.com.br"` e enviar phishing usando IP da plataforma. `SendEmailRequest::withValidator()` valida domínio para messaging direto (linha 35), mas **campanhas em massa não passam por essa validação**.

### Atenção

- **🟡 ATENÇÃO-09 — `SetTenantContext` middleware usa static state (race condition em queue worker)**
  Arquivo: `backend/app/Jobs/Middleware/SetTenantContext.php` + `backend/app/Services/TenantContext.php:5-22`.
  `TenantContext` armazena `tenant_id` em variável `static`. Em worker single-process com filas concorrentes via Octane/Swoole o estado vaza entre jobs. Em `database` queue serial OK; no momento que escalar para Redis + múltiplos workers compartilhando processo, vazará. Hoje funciona por sorte (database driver + 1 worker).

- **🟡 ATENÇÃO-10 — `SendCampaignBatchJob` valida regex `/^\+?[1-9]\d{6,14}$/` mas `CampaignsController::store` valida `/^\+[1-9]\d{6,14}$/` (sem `?`)**
  Arquivo: `backend/app/Jobs/SendCampaignBatchJob.php:66` vs `backend/app/Http/Controllers/API/V1/CampaignsController.php:78`.
  Inconsistência: controller exige `+`, job aceita números sem. Telefones que chegaram via import (sem `+`) passam pelo job mas seriam rejeitados em update.

- **🟡 ATENÇÃO-11 — Jobs não declaram `WithoutOverlapping`/`uniqueFor`**
  Arquivo: `backend/app/Jobs/ProcessCampaignJob.php:33-36`.
  `ProcessCampaignJob` só tem `SetTenantContext` em `middleware()`. Sem `new WithoutOverlapping($this->campaignId)`, dois jobs com mesmo `campaignId` rodam em paralelo (ver BLOQUEADOR-05). Implements `ShouldBeUnique` também não existe.

---

## 3. Cobrança e Billing

### Bloqueadores

- **🔴 BLOQUEADOR-12 — `BillingService::reserve` nomenclatura enganosa: nunca libera saldo automaticamente**
  Arquivo: `backend/app/Services/Billing/BillingService.php:22-85`.
  Método se chama `reserve` mas faz `decrement('balance_cents')` permanente. Não há `release` automático em caso de falha no envio. `SendMessageJob::releaseCredits` (linha 84-104 de `SendMessageJob.php`) chama release **só na falha por exceção/provider error**, mas se o worker crashar entre `reserve()` e o envio, o saldo fica debitado sem mensagem enviada. Sem job de reconciliação.

- **🔴 BLOQUEADOR-13 — `PricingService::priceFor` lê `Plan` sem `lockForUpdate` — preço pode mudar entre reserve e cobrança real**
  Arquivo: `backend/app/Services/Billing/PricingService.php:22-52`.
  `ProcessCampaignJob::then()` (`ProcessCampaignJob.php:95`) calcula `unitCents = $pricing->priceFor($tenant, $type)['sale_cents']` **só depois** do envio. Se admin alterou preço durante o batch (TenantServicePrice updated), tenant pode ser cobrado a um preço diferente do anunciado quando clicou "Enviar". Não há snapshot de preço no `Campaign` ou no `CampaignDispatch`.

- **🔴 BLOQUEADOR-14 — `PaymentController::credits` cobra MP fora de transação que englobe persistência do Payment**
  Arquivo: `backend/app/Http/Controllers/API/V1/PaymentController.php:236-272`.
  Linha 236: cria `Payment` em transação isolada. Linha 249: cobra MP. Linha 267: nova transação para `update` + `recharge`. Entre 249 e 267, se o worker morrer, MP cobrou cartão mas saldo nunca foi creditado. O bug descrito em `audit/02-dev-senior.md:17-22` (DEV-BUG-02) **permanece não corrigido**.

- **🔴 BLOQUEADOR-15 — `MercadoPagoService::createSubscription` envia `status: 'authorized'` no payload sem confirmação do MP**
  Arquivo: `backend/app/Services/MercadoPagoService.php:55`.
  ```php
  'status' => 'authorized',
  ```
  Forçar `status='authorized'` no preApproval é um hack: a MP só altera para `authorized` quando confirma o cartão. Se MP rejeitar silenciosamente e o controller atribui `'status' => 'active'` baseado em `$result['status'] === 'authorized'` (linha 95), pode marcar como ativa subscription que ainda está em `pending` real no MP. Validar deve ser via webhook ou polling.

### Atenção

- **🟡 ATENÇÃO-16 — `SubscriptionController::store` não usa `lockForUpdate` no tenant ao criar Subscription**
  Arquivo: `backend/app/Http/Controllers/API/V1/SubscriptionController.php:37-103`.
  Dois POSTs simultâneos para `/subscriptions` passam pela checagem `$existing` antes de qualquer transação. Race condition gera 2 subscriptions ativas no mesmo tenant. Mitigado parcialmente por unicidade no MP, mas o ID local fica duplicado.

- **🟡 ATENÇÃO-17 — `Coupon::isValid()` não usa lock; race em `times_used >= max_uses`**
  Arquivo: `backend/app/Models/Coupon.php:25-32` + `SubscriptionController.php:59`.
  O `->lockForUpdate()` é aplicado no Coupon, mas `isValid()` checa `times_used` no Eloquent já carregado — se duas requisições passarem pelo lock seriadamente, a primeira incrementa, e a checagem `times_used >= max_uses` da segunda é feita na versão sem refresh entre o lock e o `isValid()`. Validação correta exigiria `where('times_used', '<', 'max_uses')` no UPDATE atômico.

---

## 4. Auditoria (AuditLog)

### Bloqueadores

- **🔴 BLOQUEADOR-18 — Cobertura de AuditLog crítica está ausente**
  Arquivo: `backend/app/Models/AuditLog.php` + grep `AuditLog::record` retorna 10 ocorrências em 3 controllers.
  Eventos que **NÃO** são registrados:
  - `campaign.schedule` (`CampaignsController::schedule` linha 151-176)
  - `campaign.cancel` (`CampaignsController::cancel` linha 178-188)
  - `subscription.created/cancelled` (`SubscriptionController` inteiro)
  - `payment.pix/boleto/credits` (`PaymentController` inteiro)
  - `billing.recharge`, `billing.release`, `billing.manual_adjustment` (`BillingService` inteiro)
  - Webhooks (`WebhookController::mercadopago`, `InfobipWhatsAppWebhookController`)
  - Admin alterando pricing (`Admin\TenantPricingController`, `Admin\ServicePricingController`)
  - Compliance LGPD (art. 37) **NÃO É CUMPRIDO** para operações financeiras.

- **🔴 BLOQUEADOR-19 — `AuditLog` é mutável (não há proteção contra UPDATE/DELETE)**
  Arquivo: `backend/app/Models/AuditLog.php:9-50`.
  Model não tem `protected $guarded = []` reverso, nem revoga `delete()`. Não há trigger SQL no banco impedindo modificação. Superadmin pode adulterar trilha forense sem deixar rastro. Para LGPD/auditoria real, exigir append-only + hash chain ou bloquear via grant SQL.

### Atenção

- **🟡 ATENÇÃO-20 — `AuditLog::record` engole exceções silenciosamente**
  Arquivo: `backend/app/Models/AuditLog.php:46-48`.
  ```php
  } catch (\Throwable $e) {
      Log::warning('audit.log_failed', ['error' => $e->getMessage()]);
  }
  ```
  Falha em gravar audit não interrompe a operação. Aceitável para `auth.login` ruidoso, mas para `payment.approved` ou `billing.manual_adjustment` precisa **falhar a request** ou empurrar para fila com retry.

---

## 5. Relatórios e Performance

### Atenção

- **🟡 ATENÇÃO-21 — `ReportController::campaigns` filtros não validam `type=whatsapp`**
  Arquivo: `backend/app/Http/Controllers/API/V1/ReportController.php:25`.
  ```php
  'type'   => ['nullable','in:sms,voice,email'],
  ```
  WhatsApp foi adicionado como canal (`CampaignsController:21`) mas o filtro em `/reports/campaigns?type=whatsapp` retorna 422. UI quebrada para tenants WhatsApp-only.

- **🟡 ATENÇÃO-22 — `ReportController::campaign` executa 6 COUNT separados na mesma tabela**
  Arquivo: `backend/app/Http/Controllers/API/V1/ReportController.php:99-104`.
  6 round-trips ao banco para `total/sent/delivered/failed/read/pending`. Em campanha de 100k dispatches isso é caro. Substituir por `selectRaw("SUM(CASE WHEN status='sent' THEN 1 ELSE 0 END) AS sent, …")` em 1 query.

- **🟡 ATENÇÃO-23 — `ReportController::credits` sem filtro `tenant_id` explícito**
  Arquivo: `backend/app/Http/Controllers/API/V1/ReportController.php:221`.
  `BalanceTransaction::query()` confia 100% no global scope. Linha 261 fala de `auth()->user()->tenant_id` para `transactions_count`, mas se o usuário é `superadmin`, o scope é desabilitado e retorna TODOS os tenants no extrato. UI mostra "Seu extrato" misturando tenants. Não é cross-tenant leak (superadmin é trusted), mas é confuso e perigoso quando a UI superadmin não trata.

- **🟡 ATENÇÃO-24 — `ReportController::export` baixa CSV sem paginação/limite**
  Arquivo: `backend/app/Http/Controllers/API/V1/ReportController.php:167-207`.
  Usa `chunk(500)` (bom), mas não há limite máximo: tenant com 10M dispatches gera 10M linhas — derruba conexão HTTP e PHP-FPM worker. Adicionar limite ou enviar para job assíncrono.

---

## 6. Webhooks

### Bloqueadores

- **🔴 BLOQUEADOR-25 — `WebhookController::mercadopago` processa antes de validar formato de payload**
  Arquivo: `backend/app/Http/Controllers/API/V1/WebhookController.php:22-75`.
  Linha 36: `$type = $request->input('type', '');` sem validação. Se MP enviar payload malformado, `processWebhook` recebe string vazia e abrirá branch `default` silenciosamente — webhook devolve 200 sem fazer nada, MP nunca reenvia. Idempotência via `webhook_logs.request_id` (linha 137) é OK, mas se `$xRequestId` vier vazio, todos os webhooks gravam com PK `""` e o segundo dá conflict.

- **🔴 BLOQUEADOR-26 — `InfobipWhatsAppWebhookController` devolve HTTP 500 quando secret não configurado**
  Arquivo: `backend/app/Http/Controllers/API/V1/InfobipWhatsAppWebhookController.php:43-45`.
  ```php
  Log::channel('whatsapp')->warning('infobip.webhook.no_secret_configured');
  return response()->json(['ok' => false, 'message' => 'Webhook secret not configured'], 500);
  ```
  Vaza estado interno para atacante externo (sabe que tenant está mal configurado, pode tentar bypass via outros endpoints). Comparar com `WebhookController::infobipDelivery` linha 88, que devolve 401 — comportamento divergente.

### Atenção

- **🟡 ATENÇÃO-27 — `WhatsAppWebhookController::validateSignature` lê `getContent()` (já consumido?)**
  Arquivo: `backend/app/Http/Controllers/API/V1/WhatsAppWebhookController.php:264-269`.
  `$request->getContent()` precisa ser chamado ANTES de `$request->all()` (linha 45). PHP `php://input` é stream consumível. Se algum middleware chamar `all()` antes do controller, signature falha silenciosamente. Fix: `getContent()` no início + cache do hash.

- **🟡 ATENÇÃO-28 — `InboundWebhookController::resolveTenantByNumber` JSON-path query sem índice**
  Arquivo: `backend/app/Http/Controllers/API/V1/InboundWebhookController.php:71-79`.
  `where('config->identifier', $to)` força MySQL a fazer table-scan em `tenant_channels`. Sem índice funcional, ataque de força bruta por números resolve a partir de 50ms+ por request. Adicionar coluna virtual + índice ou expor `phone_number` em coluna física.

- **🟡 ATENÇÃO-29 — `FireOutboundWebhookJob` não assina o body antes de calcular HMAC**
  Arquivo: `backend/app/Jobs/FireOutboundWebhookJob.php:44`.
  ```php
  $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($body), $webhook->secret);
  ```
  `json_encode` sem `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` pode produzir hash diferente do que o receiver vai calcular. Padronizar serialização e documentar.

---

## 7. Multitenancy e Isolamento

### Atenção

- **🟡 ATENÇÃO-30 — `AppliesTenantScope::booted` só aplica scope quando `auth()->check()` ou `TenantContext::get()`**
  Arquivo: `backend/app/Models/Traits/AppliesTenantScope.php:10-21`.
  Se algum endpoint não-autenticado tocar um Model com este trait (ex: `PublicPlansController` lendo `Plan`), e o controller usar `Coupon::where(...)`, a query roda **sem filtro de tenant**. `Plan` e `Coupon` não usam o trait (verificado em `Plan.php`, `Coupon.php`), então OK por hora, mas o design depende de auditoria contínua: qualquer model novo que esqueça o trait vaza.

- **🟡 ATENÇÃO-31 — `BillingService::lockCampaignIfInsufficient` usa `Tenant::withoutGlobalScopes()` mas Tenant não tem trait**
  Arquivo: `backend/app/Services/Billing/BillingService.php:195`.
  `Tenant` model (`backend/app/Models/Tenant.php:9-12`) NÃO tem `AppliesTenantScope`. `withoutGlobalScopes()` aqui é defensivo, mas evidencia confusão arquitetural: superadmin queries dependem de bypass, mas modelos sem scope não precisam. Refletir em code review.

- **🟡 ATENÇÃO-32 — `Contact::withoutGlobalScopes()` em `ProcessCampaignJob::handle` para contar contatos**
  Arquivo: `backend/app/Jobs/ProcessCampaignJob.php:68-73`.
  Bypass do scope ok em job (auth não existe), mas combinado com `where('tenant_id', $this->tenantId)` é redundante e propenso a copy-paste — basta esquecer um `where` numa linha futura para vazar contatos de outro tenant.

---

## 8. Cobertura de Testes

### Atenção

- **🟡 ATENÇÃO-33 — Não existe teste para `ProcessCampaignJob` nem `SendCampaignBatchJob`**
  Arquivo: `backend/tests/Unit/Jobs/` lista apenas `FireOutboundWebhookJobTest`, `MonthlyBillingJobTest`, `OverdueRetryJobTest`, `SendMessageJobTest`. O fluxo central de campanha — coordenador + batch — não tem teste unitário. `CampaignStateMachineTest` cobre só transições, não dispara.

- **🟡 ATENÇÃO-34 — Não há teste de race condition em `BillingService::reserve` (concorrência)**
  Arquivo: `backend/tests/Unit/Billing/BillingServiceTest.php` (assumido pela listagem).
  Sem `pcntl_fork` ou test paralelo simulando 2 reserves simultâneos no mesmo tenant. Em produção MySQL, `lockForUpdate` protege, mas sem teste regressão é trivial introduzir bug.

- **🟡 ATENÇÃO-35 — Não há teste de webhook signature TIMING attack**
  Arquivo: `backend/tests/Feature/WebhookTest.php` (verificado por listagem). `hash_equals` é usado corretamente em `WebhookController.php:267`, `WhatsAppWebhookController.php:269`, etc. — falta teste que invoque com signatures de tamanhos diferentes para comprovar que API não vaza tempo.

### Melhorias

- **🟢 MELHORIA-36 — Pentest tem apenas 2 arquivos**
  Arquivo: `backend/tests/Pentest/` contém `BillingSecurityTest.php` e `MessagingSecurityTest.php`. Adicionar `CampaignSecurityTest` (duplo disparo, channel spoofing, from-domain spoofing) e `WebhookSecurityTest` (replay, signature bypass, malformed payloads).

- **🟢 MELHORIA-37 — `tests/Feature/CampaignStateMachineTest.php` não testa concorrência com state**
  Adicionar `transition while another transition is in flight` (simulando 2 controllers chamando sendNow em paralelo).

- **🟢 MELHORIA-38 — Sem teste de regressão para `estimated_contacts` calculado server-side**
  Cobrir cenário onde cliente envia `estimated_contacts: 999999` para tentar fraudar `lockCampaignIfInsufficient`. Hoje o controller faz `unset($data['estimated_contacts'])` (linha 83), mas nada garante que continuará fazendo em refactor.

---

## 9. Veredito Final

- **Total de bloqueadores:** 11 (BLOQUEADOR-01, 02, 05, 06, 07, 08, 12, 13, 14, 15, 18, 19, 25, 26 → mas 18+19 são da mesma seção AuditLog; conto 14 itens 🔴 totais)
- **Total de atenção:** 18 (03, 04, 09, 10, 11, 16, 17, 20, 21, 22, 23, 24, 27, 28, 29, 30, 31, 32, 33, 34, 35)
- **Total de melhorias:** 3 (36, 37, 38)

### Pode lançar? **NÃO**

Justificativa técnica:

1. **Cobrança incorreta garantida** — `ProcessCampaignJob` debita só após batch concluído (BLOQUEADOR-06); se `BillingService::reserve` rejeitar por saldo insuficiente, mensagens já foram enviadas e tenant não é cobrado. Inverso também: worker crash entre `reserve` e envio deixa tenant pagando sem ter recebido SMS (BLOQUEADOR-12).
2. **Duplo disparo trivial** — `sendNow` sem `WithoutOverlapping` (BLOQUEADOR-05) permite duplicar gasto com double-click no botão. Sem unique constraint no DB (ATENÇÃO-03) o `updateOrInsert` em `campaign_dispatches` não protege.
3. **AuditLog incompleto viola LGPD** — operações financeiras (payments, recharges, manual_adjustment) não são auditadas (BLOQUEADOR-18). Trilha é mutável (BLOQUEADOR-19).
4. **From-spoofing em campanhas** — `SendCampaignBatchJob` aceita `settings.from_email` sem validar contra `EmailSenderDomain` (BLOQUEADOR-08). Tenant pode forjar phishing usando IP da plataforma.
5. **Channel-bypass na criação** — qualquer tenant cria campanha de canal não-licenciado (BLOQUEADOR-01).
6. **MercadoPago hack de `status: 'authorized'`** (BLOQUEADOR-15) e fluxo de pagamento não-transacional (BLOQUEADOR-14) podem deixar tenant ativo com cobrança pendente ou não-cobrança aceita.

**Pré-condições para reavaliação:**
- Refactorar `ProcessCampaignJob` para `reserve` antes do batch + `release` no `then/catch`.
- Adicionar `WithoutOverlapping($campaignId)` em `ProcessCampaignJob`.
- Migration UNIQUE em `campaign_dispatches(campaign_id, contact_id)` e `(campaign_id, phone)`.
- AuditLog para todos os eventos financeiros + makevement append-only.
- Validar `settings.from`/`from_email` no `CampaignsController::store`/`update` contra `EmailSenderDomain` + Sender ID Infobip aprovado.
- Validar `TenantChannel::isAvailable` no `store`, não apenas no `assertCanDispatch`.
- Substituir `'status' => 'authorized'` literal no MP por estado correto + webhook reconciliation.
