# Auditoria Red Team — BusinessCode / CampaignAI

**Escopo:** autenticação, autorização, injeção, exposição de dados, OWASP Top 10.
**Método:** leitura de `AuthController`, middleware, webhooks, routes, models, `CheckoutController`, `PaymentController`, `SecurityHeaders`.

---

## Bugs e falhas

### SEC-BUG-01 — Webhook Infobip aceita `secret` via query string (`?secret=xxx`)
**Descrição:** em `WebhookController::validateSecret`, o código aceita o secret em 3 lugares: header `ibm-signature-v2`, header `Authorization`, **OU query string `?secret=`**. Secrets em URL **são logados por padrão** em access logs Apache/Nginx/Cloudflare/Sentry, ficam no histórico do navegador se alguém clicar, e podem vazar via `Referer` para terceiros. É um anti-pattern conhecido (CWE-598).
**Evidência:** `backend/app/Http/Controllers/API/V1/WebhookController.php:163-169`.
**Severidade:** alto
**Esforço:** S (remover `$request->query('secret')`)
**Bloqueador de launch:** sim

### SEC-BUG-02 — `AuditLog::record` não registra IP nem User-Agent
**Descrição:** eventos de segurança (login, registro, mudança de senha, logout) chamam `AuditLog::record('auth.xxx')` apenas com o nome do evento. Sem IP/UA não dá para rastrear tentativa de fraude, e fere requisito LGPD art. 37 (registro das operações de tratamento).
**Evidência:** `AuthController.php:50,81,97,151`.
**Severidade:** alto
**Esforço:** S
**Bloqueador de launch:** sim

### SEC-BUG-03 — Sem aceite LGPD/Termos no registro
**Descrição:** nenhum campo de consentimento é obrigatório no form nem armazenado. LGPD art. 8 exige consentimento livre, informado, inequívoco e **registrado** (com data, versão dos termos, origem).
**Evidência:** `frontend/src/pages/auth/Register.vue` + `backend/app/Http/Controllers/API/V1/AuthController.php:16-20`.
**Severidade:** crítico
**Esforço:** S (checkbox + coluna `users.lgpd_consented_at`, `users.terms_version`)
**Bloqueador de launch:** sim

### SEC-BUG-04 — Subscription/Payment models sem GlobalScope de tenant
**Descrição:** `Subscription` e `Payment` fiam-se em filtros manuais `->where('tenant_id', $tenant->id)` em cada método. Hoje os controllers fazem isso, mas:
1. Se um developer adicionar rota nova e esquecer o filtro → IDOR (cross-tenant data leak).
2. Endpoints com `findOrFail($id)` sem filtro são alvos triviais (manipular `{id}` na URL).
**Evidência:** `backend/app/Models/Payment.php`, `Subscription.php` — ausência de `AppliesTenantScope`.
**Severidade:** alto
**Esforço:** S
**Bloqueador de launch:** sim

### SEC-BUG-05 — Error message em `login` distingue usuário inexistente vs senha errada? Vamos ver
**Descrição:** `AuthController::login` retorna **a mesma** mensagem "Credenciais inválidas" em ambos casos (422). ✅ Bom. Porém timing attack residual: `Hash::check` só é chamado quando user existe — em carga alta dá pra inferir email. Usar hash dummy quando user não existe para tempo constante.
**Evidência:** `AuthController.php:65-77`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

### SEC-BUG-06 — Sem verificação de email após registro
**Descrição:** user registra, `createToken` imediato, acesso total. `User` model tem `email_verified_at` cast, mas não há envio de email de verificação. Qualquer bot/humano pode criar conta com email alheio e usar 50 créditos → vetor de abuso (spam SMS/voz mandado de nome alheio via o trial).
**Evidência:** `AuthController.php:41-49` (sem `sendEmailVerificationNotification`).
**Severidade:** alto
**Esforço:** M (implementar `MustVerifyEmail` + middleware + tela)
**Bloqueador de launch:** sim

### SEC-BUG-07 — Throttle `3,1` no registro é insuficiente para evitar abuso de créditos grátis
**Descrição:** `throttle:3,1` = 3 requests/minuto/IP. Atacante com VPN rotativa cria dezenas de contas com 50 créditos cada → abusa da infraestrutura Infobip/ElevenLabs paga pela empresa.
**Evidência:** `routes/api.php:41`.
**Severidade:** alto
**Esforço:** M (adicionar captcha/turnstile + email verification + throttle mais agressivo por IP/ASN)
**Bloqueador de launch:** sim

### SEC-BUG-08 — CSP permite `script-src 'unsafe-inline'` e `style-src 'unsafe-inline'`
**Descrição:** `Content-Security-Policy` com `'unsafe-inline'` em scripts neutraliza CSP contra XSS. Vue 3 **não precisa** de `'unsafe-inline'` para scripts; apenas para estilos dinâmicos em alguns casos. Landing usa `<style>` inline e `<script>` inline no fim (scroll handler) — migrar para arquivo separado libera retirar `'unsafe-inline'`.
**Evidência:** `backend/app/Http/Middleware/SecurityHeaders.php:19`.
**Severidade:** médio
**Esforço:** M
**Bloqueador:** backlog

### SEC-BUG-09 — `APP_DEBUG=true` default e `APP_KEY` no `.env` local commitado (verificar `.env.example`)
**Descrição:** `backend/.env.example:15` tem `APP_DEBUG=true`. Usuário copia pra `.env` de prod e esquece — stack traces com paths, SQL e dados sensíveis ficam expostos em qualquer 500.
**Evidência:** `backend/.env.example:15`.
**Severidade:** alto (risco operacional)
**Esforço:** S (flipar para `false` no example + deployment checklist)
**Bloqueador de launch:** sim (se `.env` de produção herdar)

### SEC-BUG-10 — `LIKE %search%` sem escape de wildcard completo
**Descrição:** `CampaignsController::index` e `ReportController::campaigns` fazem `str_replace(['%', '_'], ['\\%', '\\_'], $search)` → ✅ escapa wildcards. Porém concatenam com `%{$search}%` em SQL cru via Eloquent `where('name', 'like', ...)`. Eloquent escapa parâmetros, então não há SQL injection; **mas** atacante pode forçar fullscan lento com strings curtas. Considerar limite mínimo de 2-3 chars.
**Evidência:** `CampaignsController.php:27-30`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

### SEC-BUG-11 — Exposição de `mp_public_key` pública OK, mas rota `/api/v1/checkout/config` sem rate limit
**Descrição:** endpoint é público e sem throttle. Irrelevante para DoS em Cloudflare, mas se exposto direto, pode ser enumerado. Adicionar `throttle:60,1`.
**Evidência:** `routes/api.php:64` (sem throttle).
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

### SEC-BUG-12 — `PricingPlans.vue` faz `axios.get('/api/v1/plans')` sem baseURL axios configurado explicitamente
**Descrição:** se a SPA for embedada em outro domínio ou houver reverse proxy, o path relativo pode ir para host errado. Além disso, essa rota hoje está **protegida** (DEV-BUG-04/UX-BUG-01) — visitante sem login vê tela vazia. Impacto de segurança baixo, funcional alto.
**Severidade:** baixo (segurança) / alto (UX — ver relatório UX)
**Esforço:** S
**Bloqueador:** backlog (sec) / sim (UX)

### SEC-BUG-13 — `audio_url` aceita qualquer URL http(s)
**Descrição:** em `CampaignsController::store/update`, `audio_url` valida `['url', 'regex:/^https?:\/\//']`. Permite apontar o áudio da campanha para qualquer host externo não-confiável. Ataque: tenant usa `audio_url` do tenant rival (endereço interno do próprio storage), ou usa como sonda SSRF se o backend fizer fetch. Hoje o fluxo esperado é `audio_url` vir do ElevenLabs armazenado. Restringir a host allowlist (dominio de storage/ElevenLabs).
**Evidência:** `CampaignsController.php:74,101`.
**Severidade:** médio
**Esforço:** S
**Bloqueador:** backlog

### SEC-BUG-14 — Senha em texto claro no payload do PATCH `/auth/password`
**Descrição:** aceitável via HTTPS + Sanctum, mas `Log::info` no middleware HTTP padrão do Laravel pode logar request body em certas configs. Verificar `config/logging.php` e garantir que `password`, `current_password`, `card_token`, `cvc` estejam em `App\Http\Middleware\TrimStrings::$except` ou filtrados. Hoje o arquivo não mostra filtro global explícito.
**Severidade:** médio
**Esforço:** S
**Bloqueador:** backlog (confirmar em produção)

---

## Melhorias

### SEC-IMP-01 — HSTS preload e `X-Frame-Options: DENY` já OK
Apenas adicionar `Cross-Origin-Opener-Policy: same-origin` e `Cross-Origin-Embedder-Policy: require-corp` se embutir iframes de MP.

### SEC-IMP-02 — Política de senha: adicionar blacklist de top 10k senhas vazadas
Usar `Illuminate\Validation\Rules\Password::uncompromised()` (HIBP) — sem custo de implementação, ganho real contra credential stuffing.

### SEC-IMP-03 — 2FA para superadmin
Qualquer conta `role=superadmin` deve ter TOTP obrigatório. Alvo fácil hoje.

### SEC-IMP-04 — Rotação de Personal Access Tokens
Sanctum tokens são emitidos sem expiração explícita. Configurar `config/sanctum.php` `expiration` (ex: 7 dias) e usar refresh.

### SEC-IMP-05 — Verificar se `settings` armazenando API keys está realmente encriptando
`SettingsService::getGlobal('infobip','api_key')` — verificar se o decrypt acontece e se a coluna `value` tem `type='encrypted'` usado no accessor. Se não, API keys estão em texto claro na tabela — comprometer DB expõe todas as integrações pagas de todos os tenants.

---

## Novas features

### SEC-FEAT-01 — Cloudflare Turnstile ou reCAPTCHA no registro
Baixa fricção, alto impacto contra bots que abusam de créditos grátis.

### SEC-FEAT-02 — Política de "opt-out" auditável para contatos
Campanha que dispara para contato com `opted_out=true` é bug/multa. Garantir teste unitário + bloqueio no `ProcessCampaignJob`.

### SEC-FEAT-03 — Painel de LGPD: exportar/excluir dados do próprio tenant
Obrigatório em ~6 meses de operação. Começar com export JSON + hard delete com confirmação.
