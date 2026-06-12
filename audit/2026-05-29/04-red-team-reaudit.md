# Re-auditoria Red Team — CampaignAI
**Data:** 2026-05-29
**Auditor:** Red Team (autorizado pelo proprietário)
**Modalidade:** análise estática white-box sem exploração
**Escopo:** revisão das correções aplicadas nos commits `f2650f85`, `6d39c32f`, `d31bf2d8`, `1ae8feb7` + auditoria dos componentes novos (`OutboundWebhookGuard`, `AuditLogController`, `OptOutsController`, `useIdentity.ts`, trait `bootAppliesTenantScope`).
**Referência:** `audit/2026-05-28/04-red-team.md`.

---

## 1. Veredito Executivo

- **Status geral:** progresso forte. Das **5 críticas originais**, **4 foram corretamente fechadas** (Infobip WhatsApp secret, mass-assignment User, mass-assignment Campaign, IDOR EmailDomains). A 5ª (double-credit MP) foi corrigida no nível lógico (idempotência por reference_id no `BillingService::recharge`), mas o `BalanceTransaction` continua **sem UNIQUE constraint** no banco — a defesa é só app-layer.
- **SSRF Guard (OutboundWebhookGuard) cobre bem o caso AWS IMDS e RFC1918**, mas tem duas brechas residuais: (a) **DNS rebinding TOCTOU** entre `assertSafeUrl()` e `Http::post()` porque o Laravel HTTP Client re-resolve via cURL, e (b) **redirects HTTP não estão bloqueados** — um host público pode 302 para `http://169.254.169.254`.
- **Nova superfície (`AuditLog`, `OptOuts`)** introduziu **2 bugs funcionais que viram ataques baratos:** rotas `/messaging/opt-outs/import` e `/messaging/opt-outs/export` **não estão registradas em `routes/api.php`** apesar do frontend chamar (`OptOuts.vue:256, 271`) — usuário fica sem capacidade de import/export real; e o `AuditLogController::index` **não tem rate-limit**, permite `like 'campaign.%'` para enumeração e blind-timing.
- **Regressões pelo fix do trait `bootAppliesTenantScope`:** silenciosamente ativou GlobalScope em `AiContentModel` e `AudioGeneration` (que antes funcionavam sem). Análise mostra que o impacto é positivo (defesa em profundidade), porém **um caminho admin pode quebrar:** `BillingReportController::stats` chama `Tenant::query()` puro — não há regressão porque Tenant não usa o trait, **mas** o controller é montado em `middleware('role:finance')` com `EnsureRole` que NÃO promove o role para `superadmin`. Se `finance` ≠ superadmin tentar acessar, o trait global agora poderia filtrar `BalanceTransaction` (que **tem** o trait) para o tenant do próprio user de finance, distorcendo o MRR. Análise estática mostra que `BalanceTransaction::withoutGlobalScopes()` é chamado, então salvo — mas o padrão é frágil.
- **Regressão crítica explícita:** tokens Sanctum agora **nunca expiram por padrão** (commit `1ae8feb7`, `config/sanctum.php:53`). Combinado com SEC-04-1.1 (changePassword não rotaciona tokens), o vetor "token roubado → válido para sempre" deixou de ser teórico.
- **Pode lançar?** **NÃO** ainda. **2 novas críticas** (rotas import/export ausentes + token sem expiration combinado com não-rotação) e **1 crítica residual** (UNIQUE constraint ausente em balance_transactions) bloqueiam. Mais 4 altas e 5 médias novas.

---

## 2. Status das 5 Críticas Originais

### SEC-04-2.1 — IDOR EmailDomainsController → ✅ Corrigido
**Arquivo:** `backend/app/Http/Controllers/API/V1/EmailDomainsController.php:21-29` (`findOwned()`).
**Análise:** o helper aplica `where('tenant_id', $user->tenant_id)` ANTES do `findOrFail`, com bypass apenas para `superadmin` (correto). `show()`, `verify()` e `destroy()` agora usam `findOwned()`. Defesa em profundidade está presente; mesmo se o GlobalScope falhar, IDOR é bloqueado.
**Resíduo (🟡 BAIXA):** `index()` (linha 31-38) ainda lista sem `where('tenant_id')` explícito — depende 100% do GlobalScope. Tenant authenticated com user_id corrompido (sem tenant_id) listaria 0 registros (OK), mas é inconsistente com o resto do controller. Adicionar `->where('tenant_id', $request->user()->tenant_id)` para uniformidade.

### SEC-04-2.2/9.2 — Mass-assignment User (role/tenant_id) → ✅ Corrigido
**Arquivo:** `backend/app/Models/User.php:22-32`.
**Análise:** `role` e `tenant_id` foram REMOVIDOS de `$fillable`. Fluxos legítimos (`AuthController::register:60-70`, `Admin\TenantsController::store:71-77`) usam `forceFill()`, que ignora `$fillable` mas exige código explícito. O comentário inline impede regressão futura por developer descuidado.
**Resíduo:** nenhum significativo. Não há observer adicional como o PM sugeriu (defesa em profundidade extra), mas a remoção do `$fillable` já fecha o vetor primário.

### SEC-04-3.1 — Infobip WhatsApp secret via query string → ✅ Corrigido
**Arquivo:** `backend/app/Http/Controllers/API/V1/InfobipWhatsAppWebhookController.php:34-46`.
**Análise:** secret agora aceito SÓ via header (`ibm-signature-v2` ou `Authorization: Bearer …`). Sem `$request->query('secret')`. Retorno mudou para **401 quando não há secret configurado** (antes era 500 vazando estado). `hash_equals` mantido.
**Resíduo (🟡 MÉDIA):** sem replay protection (timestamp). Atacante que vaze o secret pode replay o mesmo POST infinito. Reforço de SEC-04-3.2.

### SEC-04-4.3/4.6 — Double-credit MercadoPago → ⚠️ Parcial
**Arquivo:** `backend/app/Services/Billing/BillingService.php:149-212` (`recharge`).
**Análise positiva:** `recharge` agora é idempotente em nível de aplicação por chave `(tenant_id, type='recharge', reference_type, reference_id)`, dentro de `DB::transaction` com `lockForUpdate` no `Tenant` e SELECT-then-INSERT do `BalanceTransaction`. Testes `RechargeIdempotencyTest`/`BillingRechargeGuardTest` confirmam comportamento.
**Brecha residual (🔴 CRÍTICA SEC-04R-1):** a defesa é **única e em app-layer**. Não há `UNIQUE(tenant_id, type, reference_type, reference_id)` na migration `balance_transactions`. Em 2 processos PHP concorrentes (PHP-FPM + Horizon), o `SELECT … FOR UPDATE` só protege se ambos os fluxos passarem pela mesma transação Eloquent. Em uma janela específica de race (ex.: replay do webhook MP enquanto o sync-flow ainda não commitou a transação), o select pode não enxergar o INSERT pendente. A defesa em profundidade no nível do banco (UNIQUE) NÃO está aplicada — o PM cobrou isso no critério de Go/No-Go #2 e ainda não foi cumprido.
**Recomendação:** migration adicionando `UNIQUE KEY uk_bt_idempotency (tenant_id, type, reference_type, reference_id)` em `balance_transactions`, EXCLUINDO linhas pré-existentes com `null` em reference. Sem isso, dois Horizon workers em redes/hosts diferentes podem produzir duplicata.

### SEC-04-8.1 — SSRF outbound webhooks → ⚠️ Parcial
**Arquivo:** `backend/app/Services/Security/OutboundWebhookGuard.php:19-49` + uso em `CreateOutboundWebhookRequest:22-32`, `UpdateOutboundWebhookRequest`, `FireOutboundWebhookJob:54-57`, `WebhooksController::test:189-190`.
**Análise positiva:** o guard cobre cenários clássicos:
- Esquemas inválidos (file/ftp/gopher/dict) → bloqueado (linhas 26-29);
- Hostname `localhost` literal → bloqueado (linha 35);
- RFC1918 (10/8, 172.16/12, 192.168/16), loopback (127/8), link-local (169.254/16 — inclui IMDS AWS), IPv6 ULA (`fc00::/7`), IPv6 link-local (`fe80::/10`), multicast, reserved → tudo bloqueado via `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE` (linhas 89-93);
- Tanto A quanto AAAA são resolvidos (linhas 60-71);
- Re-validação a cada disparo (`FireOutboundWebhookJob:57`, `WebhooksController:190`) — bom contra mudança de DNS pós-criação.

**Brechas residuais:**

🟠 **SEC-04R-2 (ALTA) — IPv4-mapped IPv6 (`::ffff:127.0.0.1`).** A análise: PHP `FILTER_FLAG_NO_RES_RANGE` para IPv6 bloqueia `::ffff:0:0/96` se o input for esse formato literal. Teste rápido: `filter_var('::ffff:127.0.0.1', FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)` retorna `false` (OK). MAS se o atacante usar formato `[::ffff:7f00:1]` ou DNS que resolve para `::ffff:127.0.0.1`, o `parse_url()` faz strip de brackets na linha 31 e a validação ocorre — verificar caso a caso. Recomendação: adicionar bloqueio explícito de qualquer string contendo `::ffff:` antes do filter_var.

🔴 **SEC-04R-3 (CRÍTICA) — DNS rebinding TOCTOU não fechado.** O guard resolve o host **na hora da validação**, mas o `Http::post($webhook->url, $body)` na linha seguinte usa cURL internamente, que faz **nova resolução DNS no momento do fetch**. Atacante hospeda DNS com TTL=0 que retorna IP público no primeiro lookup (passa o guard) e `127.0.0.1` no segundo (executa). Comentário no `OutboundWebhookGuard.php:14-15` ADMITE explicitamente o limite ("the HTTP client should re-resolve at fetch time to fully close DNS-rebinding holes") mas o código consumidor NÃO faz `CURLOPT_RESOLVE` para fixar o IP validado.
**Vetor:** registrar webhook com hostname controlado, esperar dispatch real → roubo de credenciais EC2/IMDS, portscan interno.
**Recomendação:** dentro de `FireOutboundWebhookJob` e `WebhooksController::test`, capturar o IP resolvido em `assertSafeUrl()` e passar via `Http::buildClient()` com `curl_setopt(CURLOPT_RESOLVE)`. Ou usar Guzzle middleware que faz lookup + valida + fetch atomicamente.

🟠 **SEC-04R-4 (ALTA) — Redirects HTTP não bloqueados.** `Illuminate\Http\Client\Factory::post` por padrão segue redirects (Guzzle `allow_redirects=true`). Host público inicial pode 302 para `http://169.254.169.254/latest/meta-data/`. O guard só valida a URL inicial, não a final. Recomendação: `Http::withoutRedirecting()` antes do `post`, ou implementar handler que valide cada URL na cadeia de redirect via `on_redirect`.

🟢 **SEC-04R-5 (BAIXA) — DNS provider trust.** `dns_get_record` confia no resolver local. Em ambiente cloud com DNS de tenant configurado, atacante pode plant `A internal.cdn.aws → 8.8.8.8` para passar guard e usar split-horizon DNS interno. Mitigação: usar resolver público (`@8.8.8.8` ou `@1.1.1.1`) ou validar via Cloudflare DOH. Baixo risco em produção típica.

---

## 3. Status das 8 Altas Originais

| ID | Status | Comentário |
|---|---|---|
| **SEC-04-4.1** Brute-force cupons | ❌ Em aberto | `routes/api.php:90` ainda em `throttle:10,1` por IP. Sem RateLimiter::for por código/IP/length. |
| **SEC-04-4.2** Race coupon_usages | ❌ Em aberto | Não vi migration adicionando UNIQUE `(coupon_id, tenant_id)`. Listado no Go/No-Go #2. |
| **SEC-04-4.4** Plano desativado pode cobrar | ❌ Em aberto | `PaymentController::pix:51`, `boleto:120` ainda usam `Plan::findOrFail($request->plan_id)` sem filtro `is_active=true`. |
| **SEC-04-5.1** EmailDomains sem paginação | ❌ Em aberto | `EmailDomainsController::index:33-35` continua com `.get()` sem paginate. Vetor DoS resta. |
| **SEC-04-6.1** Custo IA fixo | ❌ Em aberto | Não foram tocadas linhas em `AiGeneratorController::generate`. |
| **SEC-04-6.2** Prompt injection IA | ❌ Em aberto | Inputs continuam concatenados sem delimitadores. |
| **SEC-04-8.2** Leak response body webhook test | ⚠️ Parcial | `WebhooksController::test:197` continua retornando `response_body` 2KB ao usuário. Com guard contra hosts internos (8.1) o risco baixou: atacante não consegue mais apontar para 127.0.0.1, mas se conseguir um intermediário público que reflita conteúdo interno o vetor segue (CSRF→target empresa). |
| **SEC-04-10.1** CSP unsafe-inline | ❌ Em aberto | `SecurityHeaders.php:19` mantém `'unsafe-inline'` em script-src e style-src. |
| **SEC-04-12.1** Logs PII telefone | ❌ Em aberto | `InfobipWhatsAppWebhookController:74,129` ainda loga `to`/`from` sem mascarar. |

---

## 4. Auditoria dos Novos Componentes

### 4.1 `OutboundWebhookGuard` (`backend/app/Services/Security/OutboundWebhookGuard.php`)
**Achados:** ver SEC-04R-2, SEC-04R-3, SEC-04R-4, SEC-04R-5 acima. Cobre 80% do espaço de SSRF clássico; DNS rebinding e redirects são os gaps relevantes.

### 4.2 `AuditLogController` (`backend/app/Http/Controllers/API/V1/AuditLogController.php`)

🔴 **SEC-04R-6 (CRÍTICA) — Audit log endpoint sem rate-limit + LIKE indexado por usuário.**
**Arquivo:** `routes/api.php:133` — `Route::get('audit-log', …)` SEM nenhum `middleware('throttle:…')`.
**Combinação fatal:**
1. Sem throttle, admin malicioso (próprio tenant) consegue **disparar 1000s req/s** com filtros `action=campaign.send_now`, `user_id=N`, `from=…`, etc.
2. O filtro `like` (linha 38) com prefix-match (`'…' . '%'`) força full-scan em `audit_logs.action`. Em tenants grandes, 100k+ linhas, gera CPU spike no MySQL → DoS lateral cross-tenant (mesmo DB).
3. **Boolean blind**: `like 'auth.login_failed%' AND user_id=N AND created_at BETWEEN …` deixa atacante interno enumerar timestamps de login de outros usuários do mesmo tenant via tempo de resposta diferencial.

**Impacto:** DoS interno + inferência de atividade de outros admins do mesmo tenant.
**Recomendação:** `middleware('throttle:30,1')` na rota; adicionar índice `(tenant_id, action, created_at)`; limitar `from`/`to` a no máx 90 dias.

🟠 **SEC-04R-7 (ALTA) — XSS por metadata controlada por atacante no frontend.**
**Arquivo backend:** `AuditLog::record(..., $metadata)` aceita array arbitrário. Várias chamadas registram dados controlados externamente (ex.: `auth.login_failed` linha 117 do `AuthController` registra o **email digitado** sem sanitizar).
**Arquivo frontend:** `frontend/src/pages/settings/AuditLog.vue:93-94` renderiza:
```vue
<pre v-if="expandedId === row.id" …>{{ JSON.stringify(row.metadata, null, 2) }}</pre>
```
Vue interpolation com `{{ }}` ESCAPA por padrão — portanto não há XSS direto **HOJE**. Porém: o input do email NÃO é validado contra payloads-bomba (10MB string Unicode RTL char `U+202E`) que travariam o JSON.stringify ou poluiriam visualmente o log para outro admin. Em conjunto com SEC-04R-6 (sem rate-limit), atacante pode "poluir" o histórico com megabytes de lixo para esconder ações reais.
**Recomendação:** truncar metadata a 8KB em `AuditLog::record`; sanitizar `auth.login_failed` para hash do email, não o email cru; adicionar índice em metadata-bomba defense em depth.

🟡 **SEC-04R-8 (MÉDIA) — RBAC só checa role no índice; pode ser cross-tenant para superadmin sem confirmação.**
Linha 20-22 bloqueia para `admin`/`superadmin` — OK. Mas superadmin de plataforma vê **todos os tenants** (GlobalScope skipa, linha 21 do trait). Se algum dia um endpoint superadmin admin-impersonation tiver bug, isso vaza PII cross-tenant. Não é vulnerabilidade hoje, é gap de defesa.

🟢 **SEC-04R-9 (BAIXA) — Pequeno gap LGPD:** endpoint não loga sua própria consulta (admin lê audit log → não gera registro de que consultou). Para conformidade LGPD art.37 estrita, "consulta a registros pessoais" deveria também ser auditada.

### 4.3 `OptOutsController` (`backend/app/Http/Controllers/API/V1/Messaging/OptOutsController.php`)

🔴 **SEC-04R-10 (CRÍTICA — funcional) — Rotas `/import` e `/export` ausentes.**
**Arquivo:** `routes/api.php:197-202` registra apenas `index`, `store`, `destroy`. O controller (`OptOutsController.php:85-175`) tem `import()` e `export()` implementados, e o **frontend chama**:
- `frontend/src/pages/settings/OptOuts.vue:256` — `POST /messaging/opt-outs/import`
- `frontend/src/pages/settings/OptOuts.vue:271` — `window.open('/api/v1/messaging/opt-outs/export', '_blank')`

Ambas retornam **404**. Resultado:
- Cliente não consegue importar lista de opt-out de telemarketing legacy → vai disparar campanha para contatos que pediram para sair → **multa Procon LGPD** que o P0-07 deveria evitar.
- Export tem outro problema (mesmo se a rota existisse): `window.open` faz GET sem header `Authorization: Bearer …` (o token está no localStorage do SPA, não em cookie). Como a rota está em `auth:sanctum`, retornaria 401. Precisa de download via `<a href>` com token-blob ou de uma rota assinada temporária.

**Impacto:** quebra do P0-07/P0-08 — opt-out promete UI completa mas duas funcionalidades essenciais não funcionam.
**Recomendação:** adicionar:
```php
Route::post('messaging/opt-outs/import', [MessagingOptOutsController::class, 'import'])
    ->middleware(['token.ability:messaging:*', 'throttle:5,1']);
Route::get('messaging/opt-outs/export', [MessagingOptOutsController::class, 'export'])
    ->middleware('token.ability:messaging:read');
```
E refatorar `downloadCsv()` para usar `useApi().get(... { responseType: 'blob' })` + `URL.createObjectURL`.

🟠 **SEC-04R-11 (ALTA) — `import()` sem proteção contra CSV injection ofensivo.**
**Arquivo:** `OptOutsController.php:106-130`.
- Linha 88: `mimes:csv,txt`, `max:5120` (5MB OK).
- Linha 106-132: `fgetcsv` parsing. `identifier` é trimado mas NÃO sanitizado contra prefixos `=`, `+`, `-`, `@` (CSV formula injection no momento do **export** posterior).
- Quando um admin/outro tenant chamar o `export()` (que usa `fputcsv`), uma célula `=cmd|'/c calc'!A1` será reincorporada → Excel/LibreOffice no PC do admin executa fórmula → **RCE indireta no host do admin**.

**Recomendação:** dentro do `import()` e/ou `export()`, prefixar com `'` qualquer célula que comece com `=`, `+`, `-`, `@`, `\t`, `\r`. Padrão OWASP de defesa.

🟠 **SEC-04R-12 (ALTA) — `import()` sem limite de linhas + sem rate-limit.**
- Sem `throttle:` na rota proposta.
- Loop `while (fgetcsv($handle) !== false)` (linha 106) processa o arquivo inteiro. Com 5MB CSV cabem ~150k linhas. Cada linha chama `$this->optOuts->add()` que faz **`updateOrCreate`** (SELECT+INSERT) + `FireOutboundWebhookJob::dispatch` (linha 22 de `OptOutService`). 150k jobs enfileirados em 1 segundo → flood do Horizon, lentidão geral.

**Recomendação:** cap em 5000 linhas por upload; **disable** do `FireOutboundWebhookJob` no caminho bulk (passar flag `silent=true` para evitar disparar webhook por cada linha importada).

🟡 **SEC-04R-13 (MÉDIA) — Race na criação paralela.**
`OptOutService::add()` usa `updateOrCreate(['tenant_id','channel','identifier_hash'], …)` — Eloquent faz SELECT+INSERT, sem UNIQUE garantido no schema (verifiquei: não encontrei migration adicionando UNIQUE em `(tenant_id, channel, identifier_hash)`). Duas requests concorrentes para mesmo identifier produzem 2 rows duplicados (sem `lockForUpdate`).

**Recomendação:** UNIQUE no schema + transaction + catch QueryException.

🟢 **SEC-04R-14 (BAIXA) — `export()` confia 100% no `where('tenant_id', $tenantId)` (linha 156-157).**
Sem `withoutGlobalScopes` + `where`, então até depende do trait. Confirmei: `MessageOptOut` tem `AppliesTenantScope` aplicado, o filtro é manual + scope — defesa em duas camadas, OK. Risco reside em FUTURO refactor que remova o `where` manual achando que o scope basta. Manter o `where` explícito (já está) é correto.

🟢 **SEC-04R-15 (BAIXA) — Sem path traversal:** uploads são salvos em `storage/imports/` (verificado em `ImportController`). `OptOutsController::import` lê via `getRealPath()` do Symfony UploadedFile e processa imediatamente sem persistir. Nenhum vetor de path traversal direto.

### 4.4 `useIdentity.ts` (`frontend/src/composables/useIdentity.ts`)

🟡 **SEC-04R-16 (MÉDIA) — Cria axios sem wrapper de auth/token/CSRF.**
**Arquivo:** linha 39 — `await axios.get('/api/v1/public/identity')` usa **instância raiz do axios global**, não `useApi()` interno. Hoje a rota `/public/identity` é pública (sem auth), então OK. Mas:
1. **Falha de design:** se algum dev futuro adicionar `await axios.post('/api/v1/something-sensitive')` por engano, ele bypassa o interceptor de auth do `useApi`.
2. **CSRF state:** com `withCredentials: true` no `useApi`, cookies de sessão são enviados; já no `useIdentity` (sem `withCredentials`), não. Inconsistência que pode dar comportamento divergente em prod.
3. **Cache poisoning local:** o ref `identity` é singleton no módulo. Se primeira chamada falhar (rede), `identity.value = null` é cacheado e `useIdentity()` continua disparando inflight infinito até reload manual. Linhas 41-44: ok, mas usuário verá "—" para sempre.

**Recomendação:** trocar `axios.get` por uma instância dedicada criada com `axios.create({ baseURL: '/api/v1', headers: { Accept: 'application/json' } })`, separada e nunca usada para outros fins.

### 4.5 Trait `bootAppliesTenantScope` (`backend/app/Models/Traits/AppliesTenantScope.php`)

🟠 **SEC-04R-17 (ALTA — Regressão Crítica) — Ativação inadvertida do GlobalScope em models que dependiam de ausência dele.**
**Arquivos afetados (uso da trait + booted próprio):**
- `app/Models/AuditLog.php` — antes do fix, scope NÃO instalava (porque `booted()` do model sobrescrevia o do trait). Após o fix, scope ativa. Análise: TODAS as chamadas a `AuditLog::record(...)` em `BillingService`, `AuthController`, etc. agora filtram por `tenant_id` do `auth()->user()` quando chamadas em request authenticated. Isto era o **desejo do PM** (P0-10).
- `app/Models/AiContentModel.php` (linha 26-33) — antes, NÃO instalava scope; agora SIM instala. Verificado em `AiContentModelController:14,30,36,42`. **Impacto:** tudo OK — controller já filtra implicitamente; defesa em profundidade GANHOU.
- `app/Models/AudioGeneration.php` (linha 27-35) — mesmo cenário, ganhou scope. Verificado em `AudioGenerationController:56,64,71` + `ensureTenantOwns`: combinação OK.

**Risco real residual:** em `BillingReportController::stats` (linha 19-101) — rota `admin/billing/stats` com `middleware('role:finance')`. **Tenant não usa AppliesTenantScope** (verificado), então `Tenant::query()` não é afetado. **MAS** `BalanceTransaction` USA o trait. Linhas 47 e 66 do controller chamam `BalanceTransaction::withoutGlobalScopes()` — explícito, OK. Não vi nenhum lugar onde a regressão TRAVE o admin.

🟡 **SEC-04R-18 (MÉDIA) — `creating` callback fallback inseguro.**
Linha 29-37: `static::creating()` injeta `tenant_id` de `auth()->user()->tenant_id` **OU** de `TenantContext::get()` (estático). Em job que esquecer de chamar `SetTenantContext` middleware e roda sem auth, **`tenant_id` fica vazio** → model salva com `tenant_id=null`. Dependendo da schema (sem `NOT NULL`), permite criação órfã. Verificar todas as migrations.
**Recomendação:** alterar para `throw new RuntimeException` quando não conseguir resolver tenant_id. Defesa em profundidade.

---

## 5. Regressões Críticas Detectadas

### REG-1 🔴 (CRÍTICA — SEC-04R-19) — Tokens Sanctum sem expiration por padrão
**Arquivo:** `backend/config/sanctum.php:53` — `'expiration' => env('SANCTUM_EXPIRATION_MINUTES') !== null ? … : null`.
**Histórico:** commit `1ae8feb7` mudou default de 480 minutos para `null` para resolver bug funcional do "Pedro Freitas".
**Vetor agravado:** SEC-04-1.1 anterior (changePassword NÃO rotaciona tokens) tinha impacto limitado a 8h. Agora, **token roubado via XSS / log / Postman caché / wsadmin** vale para sempre. Combinado com:
- ausência de logout-em-todos-dispositivos visível para o usuário,
- audit log que não mostra tokens ativos,
- `useApi` que persiste token em localStorage (não httpOnly),
…o vetor "phishing reverso ou XSS único = comprometimento permanente" é real.
**Recomendação:** restaurar `expiration` default de 7 dias (10080 min) + adicionar `Route::post('auth/logout-all', …)` que invalida todos tokens + UI em settings/security listando tokens ativos + revogação tokens em `changePassword`/`resetPassword`.

### REG-2 🟡 (MÉDIA — SEC-04R-20) — `AuditLog::booted()` continua válido após rename do trait
**Arquivo:** `AuditLog.php:23-31`.
**Análise:** ao renomear `booted()` do trait para `bootAppliesTenantScope()`, o método `booted()` do model não é mais sobrescrito — agora os DOIS rodam. Verificado. Comportamento **correto**, mas frágil: dev futuro que adicionar `static::booted()` em qualquer outro model com `use AppliesTenantScope` espera que seja "o único booted", quando agora há dois ciclos. Documentar explicitamente.

### REG-3 🟡 (MÉDIA — SEC-04R-21) — `Campaign::reset()` deixa `reserved_cents` mas zera `sent_count`
**Arquivo:** `CampaignsController::reset:234` — `forceFill(['scheduled_at' => null, 'sent_count' => 0, 'failed_count' => 0])->save();`.
**Brecha:** se campanha estava em `failed` com `reserved_cents=R$100` e `sent_count=20`, reset zera `sent_count` mas **mantém `reserved_cents`**. Se o caminho `.then()/.catch()` do batch original já tinha liberado a diferença, o reset+novo send vai recalcular `unit_cents_at_dispatch` no `ProcessCampaignJob:150-157` mas reservar **outros R$100** sem liberar o resíduo antigo. Resultado: **debit duplicado interno por campanha resetada**. Não é fraude do tenant, é prejuízo do operador.
**Recomendação:** no `reset()`, chamar `BillingService::release($campaign->tenant_id, $campaign->reserved_cents, 'campaign_reset_release', $campaign->id)` antes de zerar.

### REG-4 🟢 (BAIXA — SEC-04R-22) — `AppliesTenantScope::creating` pode override por jobs
Antes da padronização, jobs como `SendMessageJob` usavam `withoutGlobalScopes()` explicitamente em todo lugar — agora a maioria dos models tem GlobalScope ativo, e qualquer job sem middleware `SetTenantContext` precisaria de `withoutGlobalScopes()` em cada query. **Inventário rápido confirma que `withoutGlobalScopes` é usado consistentemente** nos jobs críticos (`ProcessCampaignJob:51,100`, `SendCampaignBatchJob:48,87,132,138,197`, `FireOutboundWebhookJob:31`, webhooks). Não vi job que tenha quebrado, mas o padrão é frágil — qualquer novo job esquecerá.

---

## 6. Novos Achados

### 6.1 🟡 SEC-04R-23 (MÉDIA) — `OptOutsController::import` sem token ability nas rotas projetadas
Como a rota não existe ainda (SEC-04R-10), recomendação inclui exigir `token.ability:messaging:*` na nova rota — para import; e `token.ability:messaging:read` para export. Manter alinhado com as outras rotas em `routes/api.php:197-202`.

### 6.2 🟡 SEC-04R-24 (MÉDIA) — `useIdentity` cache compartilhado entre tenants em modo dev
**Arquivo:** `useIdentity.ts:27` — `const identity = ref<PlatformIdentity | null>(null)` é **module-level**. Em dev com hot-reload trocando de tenant, o cache pode reter brand antiga. Em prod (SPA single-tenant white-label), não é um vetor real. Risco baixo, mas vale `clear()` em logout.

### 6.3 🟡 SEC-04R-25 (MÉDIA) — Plan::findOrFail aceita planos `is_active=false` ainda
Reforço de SEC-04-4.4 ainda não corrigido. `PaymentController::pix:51`, `boleto:120`. PaymentController::credits NÃO precisa pois é recarga sem plan.

### 6.4 🟢 SEC-04R-26 (BAIXA) — `Tenant::status='suspended'` não bloqueia endpoints
Não vi middleware global que rejeite users de tenant `suspended`/`blocked` (P1-09 ainda em aberto). Tenants suspensos ainda podem fazer login, criar campanhas, recarregar saldo — só falham no momento do dispatch. UX horrível + risco de chargeback (cliente bloqueado paga e não tem o crédito).

### 6.5 🟡 SEC-04R-27 (MÉDIA) — `AuditLog::record` mete IP em sub-job sem request real
Linha 48: `request()->ip()` em job (sem HTTP request) retorna `null` (Symfony Request default em CLI). Os audit logs gerados via `BillingService::reserve`/`recharge` chamados de `ProcessCampaignJob` ficam **sem IP**, sem indicação de "system" no campo. Atacante pode analisar audit log para identificar "ações system" vs "ações user" → enumeração indireta da arquitetura. Recomendação: passar IP como parâmetro explícito quando chamado de job, ou marcar com `ip_address='system'`.

### 6.6 🟡 SEC-04R-28 (MÉDIA) — `WithoutOverlapping("campaign:{$id}")` sem cache config
`ProcessCampaignJob::middleware:41` — `WithoutOverlapping` por chave Redis/cache. Se cache não estiver configurado (em produção sem Redis), Laravel default cai em `file`/`database`. Em multi-server, lock de arquivo não protege concorrência entre hosts. Em DB, sim. Confirmar que `config/cache.php` está em `redis` em prod.

### 6.7 🟢 SEC-04R-29 (BAIXA) — `MessageOptOut` PII em texto puro
A coluna `identifier` armazena `+5511999991234` ou `user@example.com` em texto puro. Tem `identifier_hash` (SHA256, OK para lookup). Para LGPD strict (art. 46), considerar guardar SÓ o hash + criptografar o original com chave per-tenant. Mitigação atual: filter na exportação. Risco baixo, melhoria futura.

### 6.8 🟡 SEC-04R-30 (MÉDIA) — `audit_log` action via like aceita SQL wildcards do user
`AuditLogController:38` faz `str_replace(['%', '_'], ['\\%', '\\_'], …)` ANTES de concatenar `%`. Excelente — isto bloqueia SQL wildcard injection no filtro `action`. Mas o `resource` (linha 41) usa `=` exato sem sanitização — OK. Análise mostra que `action`-LIKE é sanitizado contra wildcards. **Não há SQL injection** porque Eloquent usa placeholders. Não é vulnerabilidade — registro só.

### 6.9 🟢 SEC-04R-31 (BAIXA) — Pagamento PIX usa `mp_payment_id` mas sem UNIQUE
**Arquivo:** `PaymentController::pix:76-88`. Cria Payment com `mp_payment_id` retornado pelo MP. Não vi UNIQUE constraint no schema de `payments` em `mp_payment_id`. Race teórica: 2 calls paralelos para `mp_payment_id` igual (impossível normalmente, MP gera unique IDs) — risco residual ~0, mas falta defesa em profundidade.

### 6.10 🟢 SEC-04R-32 (BAIXA) — `SendCampaignBatchJob` opt-out hash não normaliza E.164
`OptOutService::hashFor` faz `mb_strtolower(trim($identifier))`. Mas E.164 vs nacional não é normalizado: cliente que opt-out de `+5511999990000` segue recebendo se a base tiver `11999990000`. `recordAdhocDispatch` passa `$phone` direto (linha 78 valida regex), porém em opt-out manual pelo admin via UI um identifier escrito sem `+` não vai casar com o phone do contato. Recomendação: aplicar `PhoneNormalizer` antes do hash em `OptOutService::add`/`isOptedOut`.

---

## 7. Veredito Final

### Resumo Estatístico
- **5 críticas originais:** 4 ✅ corrigidas, 1 ⚠️ parcial (double-credit sem UNIQUE no DB).
- **8 altas originais:** 0 corrigidas, 1 ⚠️ parcial (8.2 mitigada pelo guard), 7 ❌ em aberto.
- **Componentes novos:** 4 ✅ bem implementados em estrutura, mas 3 com falhas reais críticas/altas (rotas import/export ausentes, SSRF DNS rebinding, audit log sem rate-limit).
- **Regressões:** 4 (1 crítica — tokens sem expiration; 3 médias/baixas).
- **Novos achados:** 10 (1 crítica, 4 altas, 4 médias, 1 baixa).

### Bloqueadores CRÍTICOS abertos (4)
1. **SEC-04R-1** — UNIQUE constraint ausente em `balance_transactions` (double-credit defesa só app-layer).
2. **SEC-04R-3** — DNS rebinding TOCTOU no OutboundWebhookGuard (não fecha SSRF completamente).
3. **SEC-04R-6** — `AuditLogController` sem rate-limit + LIKE não indexado (DoS + boolean blind).
4. **SEC-04R-10** — Rotas `/messaging/opt-outs/import` e `/export` AUSENTES (P0-07 ainda quebrado funcionalmente; LGPD risco).
5. **SEC-04R-19** — Tokens Sanctum nunca expiram + ChangePassword não revoga (token roubado vale ad eternum).

### Atenções ALTAS adicionais (4 novas)
- **SEC-04R-2** — IPv4-mapped IPv6 incompleto.
- **SEC-04R-4** — Redirects HTTP em outbound webhooks não bloqueados.
- **SEC-04R-7** — Audit log frontend tolerável a flooding de metadata.
- **SEC-04R-11/12** — CSV injection no opt-out import + ausência de cap de linhas.

### Pode lançar?
**NÃO.** As 5 críticas listadas isoladamente bloqueiam o launch:
- (1) UNIQUE constraint ausente em balance_transactions = double-credit ainda possível em race entre web e queue worker.
- (3) DNS rebinding = SSRF cloud metadata ainda viável.
- (6) Audit log DoS-amplification de tenant interno.
- (10) Opt-out UI promete LGPD compliance mas import/export 404 — primeira queixa Procon = multa.
- (19) Sessions imortais = qualquer XSS único = takeover permanente da conta.

**Estimativa de correção:** 3-5 dias úteis de 1 dev sênior se priorizado. Re-auditoria obrigatória pós-fix.

---

**Auditor:** Red Team (autorizado pelo proprietário)
**Confidencialidade:** uso interno BusinessCode — circulação restrita ao time de engenharia + PM.
