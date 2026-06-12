# Auditoria Engenheiro de Software — BusinessCode / CampaignAI

**Escopo:** performance, escalabilidade, observabilidade, error handling.
**Método:** leitura de jobs, services, migrations, controllers, configs.

---

## Bugs e falhas

### ENG-BUG-01 — `ReportController::credits` não filtra por `tenant_id` — confia no GlobalScope
**Descrição:** a rota `GET /reports/credits` consulta `CreditTransaction::query()` sem filtro manual. Como `CreditTransaction` tem `AppliesTenantScope`, o filtro é aplicado — **mas** se o trait for removido no futuro (refactor), é vazamento silencioso. Defesa em profundidade: o filtro também deveria ser explícito no controller.
Adicional: `totalDebited30d` e `totalCredited30d` também dependem só do scope.
**Evidência:** `ReportController.php:221-260`.
**Severidade:** médio
**Esforço:** S
**Bloqueador:** backlog

### ENG-BUG-02 — `CreditTransaction::create` dentro de `CreditService::reserve` cria race com `fresh()->credits_balance`
**Descrição:** após `$tenant->decrement(...)`, o código lê `$tenant->fresh()->credits_balance` para `balance_after`. O decrement é atômico (SQL), mas o `fresh()` é nova query dentro da mesma transação — dois decrements concorrentes poderiam intercalar se a transaction isolation for READ COMMITTED e não `SERIALIZABLE`. O `lockForUpdate()` antes protege, mas o `balance_after` do audit pode sair levemente errado se o ORM reordenar. Reescrever para calcular `balance_after` puro aritmético: `$tenant->credits_balance - $amount` antes do decrement, evita round-trip.
**Evidência:** `backend/app/Services/Billing/CreditService.php:20-42`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

### ENG-BUG-03 — `ProcessCampaignJob` com `timeout=3600` e `tries=3`
**Descrição:** 3 tentativas de 1h cada = até 3h de retry em caso de falha. Sem backoff explícito documentado, pode gerar picos de Infobip/Grok API limit. Configurar `public $backoff = [60, 300, 900]` e `retryUntil`.
**Evidência:** README linhas 546-554 (documentado) — verificar se está no código do Job.
**Severidade:** médio
**Esforço:** S
**Bloqueador:** backlog

### ENG-BUG-04 — Queue driver `database` em produção não escala
**Descrição:** README prescreve `QUEUE_CONNECTION=database` para produção. Acima de 50 campanhas/dia simultâneas, polling da tabela `jobs` vira gargalo. Para SaaS com promessa de "5.000 contatos em 2 cliques", recomendar Redis + Horizon.
**Evidência:** `readme.md:569-586`.
**Severidade:** médio
**Esforço:** M
**Bloqueador:** backlog (mas virar problema no primeiro cliente que mande 5k)

### ENG-BUG-05 — Cache driver `database`
**Descrição:** mesma história — cache em DB para rate limiting Sanctum/throttle é OK para MVP, mas o throttle de `ai`, `audio`, `webhook`, `campaign-dispatch` (definidos via custom limiter, não visível) bate em SELECT+INSERT por request. Redis custa R$30/mês e resolve.
**Severidade:** baixo
**Esforço:** S (variável de ambiente)
**Bloqueador:** backlog

### ENG-BUG-06 — Sem APM / tracing / error monitoring
**Descrição:** repo não referencia Sentry, Bugsnag, New Relic, Honeybadger. Exceções são logadas em arquivo texto (`storage/logs/*.log`). Impossível rastrear erro em produção sem acesso SSH — SaaS em produção deveria ter Sentry mínimo.
**Severidade:** alto (operacional)
**Esforço:** S (Sentry free tier)
**Bloqueador de launch:** sim (pelo menos Sentry básico)

### ENG-BUG-07 — Índices de performance: confiar na migration `add_performance_indexes`
**Descrição:** migration `2026_03_25_200000_add_performance_indexes.php` existe mas não conferi seu conteúdo. Tabelas críticas que precisam índice:
- `campaign_dispatches(campaign_id, status)`
- `campaign_dispatches(external_message_id)` — webhook Infobip faz lookup por aqui
- `credit_transactions(tenant_id, created_at)`
- `audit_logs(tenant_id, created_at, event)`
- `contacts(tenant_id, opted_out)`
Se faltar, explain vira table scan em poucos meses.
**Evidência:** migration existe — auditar conteúdo.
**Severidade:** médio
**Esforço:** S
**Bloqueador:** verificar — se faltar, é bloqueador

### ENG-BUG-08 — `CampaignsController::show`/`update`/`destroy` fazem `findOrFail` e **depois** `ensureTenantOwns`
**Descrição:** se o campaign pertence a outro tenant, o `findOrFail` funciona (GlobalScope filtra?) — o `AppliesTenantScope` filtra em listas, mas `findOrFail` invocado em model com scope **também respeita**. OK. Mas `ensureTenantOwns` é checagem redundante (defesa em profundidade, bom). Só note que se `auth()->user()->role === 'superadmin'` o scope desliga → superadmin vê tudo. Aceitável se documentado.
**Severidade:** n/a (informativo)
**Esforço:** n/a
**Bloqueador:** não

### ENG-BUG-09 — `fputcsv(..., ';')` no export não escapa campo com `;` no nome do contato
**Descrição:** PHP `fputcsv` escapa só `"`, `\\` e delimitador. Se `contact->name` for `Silva; Ltda`, o Excel brasileiro vai interpretar split errado. Validar com `--enclosure="` e testar com inputs adversariais.
**Evidência:** `ReportController.php:186-197`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

### ENG-BUG-10 — Polling de MP no frontend (`useCheckoutStore.stopPolling`) sem backoff
**Descrição:** não li o store, mas pattern típico é polling GET /payments/{id}/status a cada 2-3s. Se o usuário deixar aba aberta por horas e o servidor ficar offline por qualquer motivo, gera backpressure. Implementar backoff exponencial + maxAttempts.
**Evidência:** `CheckoutPage.vue:77-79` (`store.stopPolling`).
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

---

## Melhorias

### ENG-IMP-01 — Log estrutural (JSON) em vez de texto
**Descrição:** logs hoje usam `Log::channel('campaign')->info(...)` que provavelmente grava linha texto. Migrar para `structured` JSON permite ingestão em Loki/ELK sem parsing frágil.
**Esforço:** S

### ENG-IMP-02 — Observability de créditos em tempo real
**Descrição:** dashboard superadmin deveria ter "créditos consumidos last 1h/24h", "top 5 tenants em consumo", "taxa de erro por canal (Infobip)". Hoje o dashboard `admin/Dashboard.vue` existe mas não confirmei conteúdo — se for só placeholder, priorizar.
**Esforço:** M
**Bloqueador:** backlog

### ENG-IMP-03 — Retry/circuit breaker em chamadas Grok/Infobip/ElevenLabs
**Descrição:** services como `GrokService`, `InfobipSmsService` devem ter timeout configurado, retry com jitter e circuit breaker (ex: `jpnp/laravel-exponential-backoff` ou manual). Se Grok cair, hoje as campanhas quebram em cascata.
**Esforço:** M

### ENG-IMP-04 — Rate limit por tenant (não só por IP)
**Descrição:** `throttle:ai`, `throttle:audio` por rota. Necessário também quota por tenant (ex: plano Starter = 10 gerações/dia). Hoje o `max_campaigns` e `credits_balance` são únicos limites — atacante interno do tenant pode drenar créditos sem limite de velocidade.
**Esforço:** M

### ENG-IMP-05 — Idempotency-Key em POST sensíveis
**Descrição:** `POST /subscriptions`, `POST /payments/pix`, `POST /payments/boleto`, `POST /payments/credits` podem duplicar em caso de retry cliente. Exigir header `Idempotency-Key` e gravar em cache por 24h.
**Esforço:** M

---

## Novas features

### ENG-FEAT-01 — Métricas Prometheus / `/metrics`
Queue depth, jobs failed, campaign dispatch rate, MP webhook latency. Indispensável para escalar além de 10 clientes pagantes.
**Esforço:** M

### ENG-FEAT-02 — Soft-delete + lixeira para campanhas e listas
Hoje `destroy` faz hard delete. Usuário que excluir lista de 10k contatos por engano não recupera. `SoftDeletes` + view de lixeira.
**Esforço:** S

### ENG-FEAT-03 — Scheduler de relatórios (digest semanal por email)
Complementa `ReportController` sem custo, aumenta retenção. Cron weekly → render PDF/CSV → email.
**Esforço:** M
