# Auditoria Dev Sênior — BusinessCode / CampaignAI

**Escopo:** arquitetura, dívida técnica, padrões, manutenibilidade.
**Método:** leitura de controladores, services, models, traits, routes e testes.

---

## Bugs e falhas

### DEV-BUG-01 — Models `Subscription`, `Payment`, `WhatsAppPhoneNumber`, `InfobipWhatsAppNumber` sem `AppliesTenantScope`
**Descrição:** a isolamento multi-tenant depende hoje de **dois mecanismos**: (a) trait global nos models que tenho tenant, (b) filtro manual `->where('tenant_id', $tenant->id)` em cada controller. Os models acima **não têm o trait** — toda segurança vira responsabilidade de quem escreve o controller. Qualquer rota nova que use `Payment::find($id)` sem filtro manual vaza dados entre tenants.
**Evidência:** `backend/app/Models/Subscription.php`, `Payment.php` — sem `use AppliesTenantScope;`. O `grep AppliesTenantScope backend/app/Models/*.php` não os lista.
**Severidade:** alto
**Esforço:** S (adicionar trait; rodar suíte)
**Bloqueador de launch:** sim

### DEV-BUG-02 — `PaymentController::credits` credita saldo fora de transação conjunta com o pagamento
**Descrição:** no fluxo credit_card, o método chama `(new PaymentClient)->create([...])`, cria `Payment::create` e só **depois** abre `DB::transaction` para debitar créditos. Se o insert do `Payment` cair entre a cobrança MP e a transação, o cliente pagou mas não recebe saldo e não há linha para reconciliar. A transação MP não é revertida.
**Evidência:** `backend/app/Http/Controllers/API/V1/PaymentController.php:195-233`.
**Severidade:** alto
**Esforço:** M (envelopar toda a operação num único `DB::transaction` e só cobrar MP após persistir o Payment `pending`)
**Bloqueador:** sim

### DEV-BUG-03 — `SubscriptionController::store` bloqueia retry após PIX/boleto pendente expirar
**Descrição:** checagem inicial: `whereIn('status', ['active','authorized','pending'])->first()`. Se o cliente tenta assinatura via PIX, a Subscription é criada `pending`, o QR expira sem pagamento, o status fica `pending` indefinidamente. Próxima tentativa retorna "Já existe uma assinatura ativa. Cancele antes de assinar outro plano." — user fica travado sem ter assinatura real.
**Evidência:** `SubscriptionController.php:29-35` + criação de `Subscription::create([..'status'=>'pending'..])` em `PaymentController::pix` (linha 42-50).
**Severidade:** crítico
**Esforço:** M (job de expiração automática OU ignorar `pending` com `created_at < now()->subHours(24)`)
**Bloqueador:** sim

### DEV-BUG-04 — Landing com 4 tiers, PricingPlans com 3, sem seeder para `business`
**Descrição:** `landing.html` CTA vai para `/plans/checkout/business`. `PricingPlans.vue` só renderiza o que vem da API. Se o banco não tiver plano com `slug='business'`, o usuário recebe "Plano não encontrado." no checkout.
**Evidência:** `landing.html:486` (`/plans/checkout/business`) + `CheckoutPage.vue:66-75` (busca na lista).
**Severidade:** alto
**Esforço:** S (definir seed canônico e re-checar landing)
**Bloqueador:** sim

### DEV-BUG-05 — `CampaignsController::sendNow` pula verificação de crédito para superadmin
**Descrição:** `if (auth()->user()->role !== 'superadmin')` envolve o `lockCampaignIfInsufficient`. Intenção provável: superadmin "testar em nome de tenant". Mas a dispensa **também pula registro de débito** no fluxo, e como o Job usa `CreditService::debit`/`deduct` no envio real, ocorre débito no tenant — resultado é inconsistente: bypass parcial. Melhor: superadmin dispara como "dry-run" ou mantém verificação.
**Evidência:** `CampaignsController.php:135-143`.
**Severidade:** médio
**Esforço:** S (decidir política explícita e documentar)
**Bloqueador:** backlog

### DEV-BUG-06 — Trait `AppliesTenantScope::creating` silenciosamente sobrescreve `tenant_id` não-nulo?
**Descrição:** o hook `creating` só define `tenant_id` se `empty($model->tenant_id)`. Está correto — preserva valor explícito. Mas o `booted` é executado após `Tenant::factory()` em testes e pode puxar `auth()->user()->tenant_id` indevidamente se o teste tiver actingAs. Verificar em CI.
**Evidência:** `backend/app/Models/Traits/AppliesTenantScope.php:23-31`.
**Severidade:** baixo
**Esforço:** S (adicionar teste de regressão)
**Bloqueador:** backlog

### DEV-BUG-07 — Logs sensíveis espalhados: `Log::error('[MercadoPago] ...', ['error' => $e->getMessage()])`
**Descrição:** exceções de MP podem conter mensagens com fragmentos de dados (email, id MP). OK para log interno, mas sem PII-scrubbing e sem rotação. `config/logging.php` default grava em `laravel.log` sem rotação explícita em alguns canais.
**Evidência:** `PaymentController.php:77,134,241`; `SubscriptionController.php:105-109`.
**Severidade:** médio
**Esforço:** S (escolher canal `daily` + mascarar emails)
**Bloqueador:** backlog

### DEV-BUG-08 — `AuditLog::record` não captura IP nem user-agent
**Descrição:** `AuditLog::record('auth.login')` só registra nome do evento; para efeito forense (LGPD art. 37 — registro de operação), precisa IP, UA e identidade do agente. A tabela `audit_logs` existe (migration `2026_03_25_100000`), mas o método de fachada não parece popular esses campos (não lido, inferido pela chamada).
**Evidência:** `AuthController.php:50,81,97,151`.
**Severidade:** alto (LGPD)
**Esforço:** S (enriquecer o static method com `$request`)
**Bloqueador:** sim

### DEV-BUG-09 — `CheckoutController::config` expõe `mp_public_key` sem autenticação (ok) mas também `credit_unit_price` e `min_purchase`
**Descrição:** rota `GET /api/v1/checkout/config` é pública. Retornar preço unitário de crédito é OK; porém qualquer alteração nessas configs muda comportamento de cálculo de preço no frontend sem signing — usuário pode forjar `credits_amount` mas o backend recalcula o valor servidor-side, então atenuado. Ainda assim, é superfície exposta.
**Evidência:** `CheckoutController.php:40-47`.
**Severidade:** baixo
**Esforço:** S (cache + header antifraude opcional)
**Bloqueador:** backlog

### DEV-BUG-10 — Arquivos `prompt.md`, `prompt2.md`, `prompt3.md`, `prompt4.md` no root do repo
**Descrição:** arquivos de brainstorm/instruções ficaram comitados na raiz (`c:/xampp/htdocs/new_saas/prompt.md` etc.). Nada crítico, mas sinal de processo — e podem conter instruções internas que não deveriam ir pra público.
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

---

## Melhorias

### DEV-IMP-01 — Namespace inconsistente no controller de campanhas
**Descrição:** trait `Concerns\ChecksTenant` é referenciado como `use Concerns\ChecksTenant;` em `CampaignsController` e `ReportController`, mas a resolução relativa só funciona porque a trait está no mesmo namespace. Em refactor, mover para `App\Http\Controllers\Concerns` e importar explicitamente com `use App\Http\Controllers\API\V1\Concerns\ChecksTenant;` ajuda IDE e static analysis.
**Esforço:** S
**Bloqueador:** backlog

### DEV-IMP-02 — Inline `DB::table('credit_transactions')->insert` em 4 lugares
**Descrição:** `MercadoPagoService::activatePayment`, `PaymentController::credits`, `SubscriptionController::store` e `CreditService::debit` duplicam a lógica de inserção na `credit_transactions`. Extrair para `CreditService::record(tenant_id, type, amount, balance_after, reference_type, reference_id, description)` elimina divergência (alguns usam `meta`, outros não).
**Evidência:** `MercadoPagoService.php:239-250,264-275`; `PaymentController.php:220-231`; `SubscriptionController.php:87-98`.
**Esforço:** M
**Bloqueador:** backlog (alto payoff em manutenção)

### DEV-IMP-03 — `env()` usado no meio do ciclo de request
**Descrição:** procurar `env('CREDIT_UNIT_PRICE'...)` — está em `config/services.php`, bom. Mas `config('services.credits.unit_price')` é lido no controller 3x (`PaymentController::credits`). Quando `config:cache` é executado em produção, `env()` deixa de funcionar. Está OK hoje, só reforçar nunca chamar `env()` fora de `config/*.php`.
**Esforço:** S (regra de lint)
**Bloqueador:** backlog

### DEV-IMP-04 — `SubscriptionController::current` retorna `null` como sucesso — frontend checa e mostra "nenhuma"
**Descrição:** melhorar contrato: retornar 404 ou objeto com `has_subscription: false`. Reduz lógica condicional no frontend.
**Esforço:** S
**Bloqueador:** backlog

### DEV-IMP-05 — Faltam Form Requests dedicados em vários endpoints
**Descrição:** `AuthController`, `CampaignsController`, `ReportController` validam inline via `$request->validate([...])`. `SubscriptionController`, `PaymentController` já usam Form Requests (`CreatePixPaymentRequest` etc.). Padronizar — Form Request separa regras, fica testável e documentável via OpenAPI.
**Esforço:** M
**Bloqueador:** backlog

### DEV-IMP-06 — API docs ausente
**Descrição:** repo não tem OpenAPI/Scramble/L5-Swagger. Rota `/docs` na landing leva a 404. Para lançamento de SaaS com promessa de "API completa" no plano Business, documentação é obrigatória. Recomendo `dedoc/scramble`.
**Esforço:** M
**Bloqueador:** backlog (mas obrigatório antes de vender Business)

### DEV-IMP-07 — Tests/Unit praticamente vazio
**Descrição:** `tests/Unit/` só tem `ExampleTest.php`. Toda cobertura está em `Feature`, o que aumenta tempo de execução e não testa services isoladamente. `CreditService`, `CampaignStateMachine`, `MercadoPagoService::validateWebhookSignature`, `Coupon::calculateDiscount` são alvos óbvios de unit test puro.
**Esforço:** M
**Bloqueador:** backlog

---

## Novas features

### DEV-FEAT-01 — Job de reconciliação MP noturno
**Descrição:** cron que busca pagamentos `pending` há >24h, consulta MP e atualiza status (inclui fechar subscriptions abandonadas de DEV-BUG-03). Protege contra falhas de webhook.
**Esforço:** M
**Bloqueador:** backlog (mas muito recomendado pré-launch)

### DEV-FEAT-02 — Health checks e status page
**Descrição:** endpoint `/health` que bate em DB, Redis/cache, fila, MP, Infobip, Grok, ElevenLabs. Integra com UptimeRobot/Better Uptime. Crítico para SLA com plano Business.
**Esforço:** S
**Bloqueador:** backlog

### DEV-FEAT-03 — CI pipeline (GitHub Actions)
**Descrição:** não há `.github/workflows/` no repo. Adicionar pipeline com `composer install`, `phpunit`, `npm run build`, `npm run lint`. Sem isso, qualidade não é verificável em PR.
**Esforço:** M
**Bloqueador:** backlog (obrigatório para equipe >1 pessoa)
