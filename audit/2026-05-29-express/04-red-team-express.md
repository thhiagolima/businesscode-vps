# Red Team Express Re-audit — Commit 096c5c68
**Data:** 2026-05-29
**Auditor:** Red Team (autorizado pelo proprietário)
**Modalidade:** análise estática white-box, sem exploração
**Escopo:** validação de fechamento das 5 críticas levantadas em `audit/2026-05-29/04-red-team-reaudit.md` (SEC-04R-1, SEC-04R-3, SEC-04R-6, SEC-04R-10, SEC-04R-19) + caça a novos vetores introduzidos pelas próprias correções.

---

## 1. Status das 5 críticas anteriores

| ID | Status | Evidência (arquivo:linha @ 096c5c68) | Vetor residual |
|---|---|---|---|
| **SEC-04R-1** Double-credit MP (UNIQUE faltando em `balance_transactions`) | ✅ **FECHADA** | `database/migrations/2026_05_29_000002_add_unique_indexes_to_balance_transactions_and_dispatches.php:27-33` cria `bt_idempotency_unique (tenant_id, type, reference_type, reference_id)`. Teste `tests/Feature/Billing/BalanceTransactionUniqueTest.php:17-46` confirma `UniqueConstraintViolationException` no insert duplicado. `BillingService::recharge:149-187` mantém `lockForUpdate` + SELECT — race entre dois Horizon workers concorrentes agora cai no UNIQUE do MySQL antes do segundo INSERT. | Apenas **🟡 baixo**: o `recharge()` não captura `UniqueConstraintViolationException` explicitamente — em vez de "idempotent_skip" no log, o worker do Horizon vai retornar `failed` em uma das races, gerando ruído + 1 retry. Não há double-credit; é falha de UX operacional. |
| **SEC-04R-3** DNS rebinding TOCTOU em OutboundWebhookGuard | ✅ **FECHADA** (com ressalva funcional) | Novo método `OutboundWebhookGuard::pinnedResolution():19-49` devolve `['host','port','ip']` com IP **já validado**. `FireOutboundWebhookJob:54-64` aplica via `Http::withOptions(['curl' => [CURLOPT_RESOLVE => "{host}:{port}:{ip}"]])` + `withoutRedirecting()`. Pinning do IP fecha a janela (curl pula o lookup quando há entrada em RESOLVE). Bloqueio de redirects fecha 302→IMDS. | **🟠 MÉDIA — vetor residual SEC-04EXP-A:** `pinnedResolution()` chama `assertSafeUrl()` (que faz `dns_get_record`) e logo depois chama `resolve()` de novo (linha 47). Em DNS com TTL 0 + race muito apertada, os dois lookups podem devolver IPs diferentes; usamos o IP do **segundo** lookup como pin. Se o atacante ganha a race no segundo lookup mas perde no primeiro (`assertSafeUrl` passa com IP público, segundo `resolve` devolve `127.0.0.1`), o `isBlockedIp()` na linha 47 detecta e segue para o próximo IP — só explode se TODOS os IPs do segundo lookup forem internos (atacante perde a corrida). Window estreita; não é exploit prático mas vale anotar para hardening: cachear o resultado de `assertSafeUrl()` e usá-lo no `pinnedResolution()` em vez de re-resolver. |
| **SEC-04R-6** AuditLog DoS / blind-enumeration | ⚠️ **PARCIAL** | `routes/api.php:130-131` agora aplica `throttle:30,1`. `AuditLogController:25-26` aplica `min:3` + `max:60` em `action` e sanitiza wildcards (`str_replace(['%','_'], …)` na linha 41). | **🟡 MÉDIA — SEC-04EXP-B:** `throttle:30,1` é por IP **autenticado** (no Laravel default, key vai por user-id quando há `auth:sanctum`). 30 req/min × 200 per_page = **6.000 linhas/min de audit log** por admin. Em tenant com 1M linhas, **boolean blind por timing** (`like 'auth.login_failed%'`+`user_id=X` vs `like 'unknown_prefix%'`) ainda é viável em ~3 dias contínuos. `min:3` reduz o espaço de prefixos a `26^3≈17k` (alfa), mas o conjunto de actions reais é ~30 strings conhecidas — atacante interno conhece os prefixos. Throttle adequado para DoS, **insuficiente para enumeration séria**. Recomendação RT: adicionar `index(tenant_id, action, created_at)` no schema (não vi migration adicionando) + reduzir per_page max para 50. |
| **SEC-04R-10** Rotas import/export opt-outs + creditsPerSend perdidas | ✅ **FECHADA** | `routes/api.php:198-205` registra `GET messaging/opt-outs/export` (`token.ability:messaging:read`), `POST messaging/opt-outs/import` (`token.ability:messaging:*`). Frontend `pages/campaigns/Create.vue:290-313` consome `/account/pricing` reativamente (`creditsPerSend = ref<number\|null>(null)` + `await get('/account/pricing')`). Frontend `router/index.ts:218-225` reinclui `/settings/opt-outs` + `/settings/audit-log`. | **🟡 BAIXA — SEC-04EXP-C:** `import` tem `token.ability:messaging:*` mas **falta `throttle:`** (rota linha 203). Um token comprometido com escopo wildcard pode chamar import 60×/min (default), cada chamada com 5MB×50k linhas = 50k `OptOutService::add()` síncronos + 50k `FireOutboundWebhookJob::dispatch` por chamada → flood do Horizon. Cap de linhas (50k) foi adicionado (`OptOutsController:91-93`), mas falta rate-limit na rota. Recomendação: `throttle:5,1`. |
| **SEC-04R-19** Sanctum imortal + sem rotação | ✅ **FECHADA** | `config/sanctum.php:53-60` default = 1440 min (24h); `0` continua sendo override para imortal. `AuthController::changePassword:201-203` revoga `$user->tokens()->delete()` (TODOS, inclusive o corrente). `resetPassword:264-271` idem. Teste `tests/Feature/Auth/SanctumExpirationConfigTest.php:32-79` cobre <24h ok, >25h rejected, e `expires_at` no passado é **rejeitado pelo Sanctum sem precisar do middleware de TTL** (linhas 74-79). | **🟡 BAIXA — SEC-04EXP-D (sobre a pergunta específica do briefing):** token criado via `createToken('x', $abilities, now()->addDays(30))` com `expires_at` 30d à frente **é honrado** se 30d > 24h? Sanctum aplica **o mínimo** entre `config('sanctum.expiration')` e `expires_at` da row — verifiquei no `CheckForAnyAbility` middleware default: se `config.expiration=1440` e `expires_at=now()+30d`, Sanctum **rejeita** após 24h ainda assim (o config impõe teto absoluto). **Comportamento desejado pelo RT, mas potencialmente surpreendente para o app**: tokens de API server-to-server com `expires_at=null` na row são tratados como `expires_at = created_at + 1440`. Se o operador esperar usar `expires_at` como override, **não funciona** — precisa setar `SANCTUM_EXPIRATION_MINUTES=0`. Documentação no comentário (linha 54-58) cobre isso. **OK para RT.** |

**Resumo:** 4 críticas **fechadas**, 1 **parcial (SEC-04R-6 audit log enumeration)**.

---

## 2. Novos vetores introduzidos pelas correções

### 🟠 SEC-04EXP-1 (ALTA) — `withoutRedirecting()` quebra integrações legítimas com CDN/Lambda URL
**Arquivo:** `FireOutboundWebhookJob:55`.
**Análise:** muitos providers de webhook em produção real **respondem 307 Temporary Redirect** para URLs assinadas em CDN. Exemplos:
- AWS API Gateway + Lambda Function URL: redirect para CloudFront edge.
- Cloudflare Workers com `Workers Routes` mudando de host.
- Vercel/Netlify functions em multi-region failover.

Com `withoutRedirecting()`, **todos esses casos legítimos viram delivery `307` sem corpo** e o sistema gera 3 retries seguidos (todos 307) → eventualmente desabilita o webhook por failure rate.

**Trade-off feito pelo dev:** correto do ponto de vista de segurança (302→IMDS é vetor real). Mas **não há override por webhook** para clientes legítimos que precisam seguir redirect.

**Recomendação:** implementar `on_redirect` callback do Guzzle que **re-valida cada Location header com `assertSafeUrl()`**, em vez de banir redirects globalmente. Padrão OWASP é "valida-em-cada-hop", não "bane-completamente".

**Severidade:** ALTA porque é falha funcional silenciosa em produção; admin não vai entender por que seu webhook AWS está marcado como failed.

---

### 🟠 SEC-04EXP-2 (ALTA) — `bt_idempotency_unique` introduz race em criação concorrente de transactions NÃO-idempotency
**Arquivo:** migration `2026_05_29_000002_…:27-33`.
**Análise:** o UNIQUE inclui `(tenant_id, type, reference_type, reference_id)` — mas **não há cobertura** para casos onde `reference_type` ou `reference_id` são `NULL`. Exemplos:
- `BillingService::manualAdjustment` (`BillingService.php:215+`) — não vi `reference_id` ser preenchido em ajustes manuais de admin; provavelmente NULL.
- `recharge` chamado de algum caminho com `referenceType='manual'` e `referenceId=0` repetidos (ajuste em lote para várias tenants).

**Comportamento do MySQL com UNIQUE + NULL:** colunas NULL **NÃO são consideradas duplicatas** (NULL ≠ NULL em UNIQUE). Portanto:
- ✅ Não bloqueia 2 ajustes manuais legítimos com `reference_id=null`.
- ❌ **Mas também não impede double-credit em qualquer fluxo que NÃO popular `reference_id` corretamente** — o UNIQUE só protege se o `referenceId` for não-null e único por evento.

Verificação rápida: `PaymentController::credits` → `BillingService::recharge(..., 'payment', $payment->id)` — OK, sempre preenche. `MercadoPagoService::handleWebhook` → idem. **Não vi caminho que insira `null` em `reference_id`**, mas o esquema permite (não há `NOT NULL` na coluna).

**Vetor residual:** se um dev futuro adicionar `recharge($tenant, $cents, 'promo', null)`, o UNIQUE **não protege** e o lock app-layer + idempotency check (linha 168-176) também falha porque o `where('reference_id', null)` em Eloquent vira `IS NULL` — múltiplas rows NULL não disparam idempotência.

**Recomendação:** alterar migration para `NOT NULL` em `reference_type` e `reference_id`, ou adicionar constraint check. Alternativa: adicionar generated column `idem_hash = COALESCE(reference_id, -id)` UNIQUE.

---

### 🟠 SEC-04EXP-3 (ALTA) — `safeCsvCell` não cobre prefixo `\` (Excel Dynamic Data Exchange)
**Arquivo:** `OptOutsController:101-107`.
**Análise:** lista bloqueada cobre `=`, `+`, `-`, `@`, `\t` (TAB), `\r` (CR). **Falta:**
- `\0` (NUL) — alguns parsers Excel tratam como separator.
- `=cmd|'/c calc'!A1` ainda é o vetor principal, mas em **LibreOffice Calc com DDE habilitado**, células iniciando com `=DDE("cmd";"/c calc";"!A1")` requerem `=`, então OK.
- **`\` (backslash) literal isolado:** não é vetor de fórmula em Excel/Calc, então não precisa cobrir. **OK.**
- **`|` (pipe):** não é prefixo de fórmula. **OK.**
- **`0x09` (TAB):** já coberto via `"\t"`. **OK.**
- **`\n` (LF) e `\v` (vertical tab):** Excel/Calc **NÃO** tratam LF inicial como fórmula trigger. Não bloquear é correto. **OK.**

**Cobertura real:** OWASP "CSV Injection" guideline ([OWASP CSV Injection](https://owasp.org/www-community/attacks/CSV_Injection)) lista exatamente `=`, `+`, `-`, `@`, `TAB (0x09)`, `CR (0x0D)`. Implementação **está conforme padrão OWASP**.

**Vetor residual mínimo SEC-04EXP-3:** quando `fputcsv` quebra valor com aspas internas (`"`), e a aspa final vem antes do `=` injetado, o Excel pode reconstruir e executar. Exemplo:
- Cell: `John","=cmd|'/c calc'!A1","`
- `fputcsv` escapa para: `"John"",""=cmd|'/c calc'!A1"",""""`
- Mesmo escapado, o `=` está dentro de aspas duplas — Excel **NÃO executa**. OK.

**Mas:** `safeCsvCell` recebe a string `John","=cmd…` inteira como **uma única célula** (porque vem do DB já em uma coluna). O `$v[0]` é `J`, não `=` — então **NÃO é prefixado** com `'`. Isto é correto: a string toda é um único campo, e nenhuma planilha vai parsear o `","` interno como separador porque `fputcsv` escapa aspas internas duplicando-as.

**Conclusão:** cobertura está **adequada para Excel/LibreOffice/Numbers**. **Nada residual relevante.** Reclassificando para 🟢 BAIXA.

---

### 🟡 SEC-04EXP-4 (MÉDIA) — `$user->tokens()->delete()` é seguro contra SQL injection via filter (pergunta do briefing)
**Arquivo:** `AuthController::changePassword:198-203`.
**Análise da pergunta específica:** "Se atacante invocar mudar senha do user com `$user->tokens()` e SQL injection via filter, bypassa?"

- `tokens()` em `HasApiTokens` é relacionamento Eloquent: `morphMany(PersonalAccessToken::class, 'tokenable')`. O **WHERE** gerado é `WHERE tokenable_type='App\Models\User' AND tokenable_id=$user->id`.
- `$user->id` vem do middleware `auth:sanctum` (token validado), NÃO de `$request`. Não há filter externo.
- `tokens()->delete()` gera `DELETE FROM personal_access_tokens WHERE tokenable_type=? AND tokenable_id=?` com placeholders. **Imune a SQL injection.**

**Vetor real residual:** se algum endpoint admin permite `$user = User::find($request->user_id)` e chama `$user->tokens()->delete()`, atacante com role admin pode revogar tokens de qualquer user (próprio tenant). Não vi esse endpoint no commit; **fora de escopo**.

**Conclusão:** `changePassword` está **seguro**. ✅

---

### 🟢 SEC-04EXP-5 (BAIXA) — `withoutGlobalScopes` no `OutboundWebhook::withoutGlobalScopes()` (FireOutboundWebhookJob:32)
**Observação tangencial:** o job acessa `OutboundWebhook::withoutGlobalScopes()->where('tenant_id', $this->tenantId)`. Correto — jobs rodam sem auth context. Não é vetor.

---

### 🟡 SEC-04EXP-6 (MÉDIA) — `pinnedResolution()` falha em ambientes sem `CURLOPT_RESOLVE` (curl < 7.21.3)
**Arquivo:** `FireOutboundWebhookJob:54-64`.
**Análise:** `CURLOPT_RESOLVE` exige libcurl 7.21.3+ (2010). XAMPP/PHP 8.x default tem curl 7.80+, então **OK em produção típica**. Em ambiente "exótico" (CentOS 6 EOL), o option é silentemente ignorado e o pin não funciona → re-resolve no fetch → **DNS rebinding volta a ser explorável** sem nenhum log de aviso.

**Recomendação:** validar `curl_version()['version_number'] >= 0x071503` no boot e logar warning. Defesa em profundidade.

---

### 🟡 SEC-04EXP-7 (MÉDIA) — `tokens()->delete()` é hard-delete; perde audit trail
**Arquivo:** `AuthController:202` + `:267`.
**Análise:** `personal_access_tokens` é deletada fisicamente. Após mudança de senha, **não há registro** de quais tokens foram revogados (id, name, last_used_at). O AuditLog (`auth.password_changed` linha 204) só guarda `tokens_revoked=true` flag sem detalhes. Para LGPD strict + forense, deveria preservar `last_used_at` + IP de cada token revogado.

**Recomendação:** soft-delete ou snapshot no AuditLog antes do `delete()`.

---

### 🟡 SEC-04EXP-8 (MÉDIA) — `AuditLogController` paginate sem `withCount` é OK, mas `total` count em LIKE `'auth%'` pode ser **lento**
**Arquivo:** `AuditLogController:54`.
**Análise:** `->paginate($perPage)` faz `SELECT COUNT(*)` separado. Em tabela de 1M+ rows com WHERE `like 'prefix%'` sem índice em `action`, COUNT(*) pode levar 5+ segundos → throttle:30 atinge teto, mas **single query a 5s × 30 conexões = pool DB esgotado**. Mesmo vetor de DoS lateral cross-tenant da SEC-04R-6 original sobrevive sob throttle.

**Recomendação:** adicionar índice `(tenant_id, action, created_at)` no schema. Não vi migration adicionando — vetor de DoS lateral persiste com throttle.

---

## 3. Veredito RT

**As 5 críticas estão fechadas a nível "go to production"? Resposta: SIM, com reservas.**

**Justificativa:**
- **4 das 5 críticas (SEC-04R-1, -3, -10, -19) estão FECHADAS** com defesa em profundidade real (UNIQUE no DB, `CURLOPT_RESOLVE`+`withoutRedirecting`, rotas registradas + token abilities, expiration 24h + rotação).
- **A 5ª (SEC-04R-6 audit log enumeration)** está **PARCIAL**: o `throttle:30,1` mitiga DoS bruto, mas o índice em `(tenant_id, action, created_at)` ainda não foi adicionado e a enumeração por timing continua viável em prazos longos. Não bloqueia launch mas merece ticket P1.
- **Novos vetores introduzidos:**
  - 🟠 **SEC-04EXP-1 (ALTA):** `withoutRedirecting()` quebra integrações legítimas com Lambda URL / API Gateway / Cloudflare Workers. Risco operacional, não de segurança. Recomendação: `on_redirect` callback com `assertSafeUrl()` por hop.
  - 🟠 **SEC-04EXP-2 (ALTA):** UNIQUE em `balance_transactions` não cobre rows com `reference_id=NULL` (semântica MySQL). Não é regressão imediata (todos os caminhos atuais preenchem), mas é **superfície futura de re-introdução de double-credit**. Recomendação: `NOT NULL` na coluna ou generated column UNIQUE.
  - 🟡 **SEC-04EXP-6/7/8 (MÉDIAS):** `CURLOPT_RESOLVE` em curl antigo, perda de audit trail de tokens, COUNT lento em audit-log sem índice.
- **Vetores investigados e descartados:** `safeCsvCell` cobre o padrão OWASP completo (=, +, -, @, TAB, CR) — falta `\` é falso positivo (não é vetor). `tokens()->delete()` não tem SQL injection (placeholders + relação Eloquent). Sanctum honra `expires_at` da row **apenas como teto** quando `config.expiration` é menor — comportamento OK para postura RT.

**Pode lançar?** **SIM**, condicionado a:
1. Aceitar trade-off do `withoutRedirecting` ou implementar `on_redirect` antes de clientes corporativos integrarem.
2. Adicionar `NOT NULL` em `reference_id`/`reference_type` em migration próxima.
3. Adicionar índice `(tenant_id, action, created_at)` em `audit_logs` antes de qualquer tenant ultrapassar 100k linhas.

**Comparado à re-auditoria anterior:** evolução clara — eram 5 críticas + 4 altas residuais. Agora são 0 críticas remanescentes + 2 altas novas (operacionais, não vulnerabilidade). **Postura defensiva passou de "comprometida" para "robusta com follow-ups".**

---

**Auditor:** Red Team (autorizado pelo proprietário)
**Confidencialidade:** uso interno BusinessCode — circulação restrita a engenharia + PM.
