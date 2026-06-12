# Auditoria Consolidada — BusinessCode / CampaignAI (PM)

**Data:** 2026-04-18
**Produto:** SaaS multitenancy de campanhas SMS / Voz / Email / WhatsApp com IA (Grok) e TTS (ElevenLabs).
**Stack:** Laravel 11 + Vue 3 + Sanctum + Mercado Pago + Infobip + ElevenLabs + Grok.
**Status da branch:** `master` com mudanças grandes não-commitadas em `new_saas/` e `bottrade/`.

---

## Resumo executivo

O produto tem **arquitetura sólida no core** (multitenancy via `AppliesTenantScope`, state machine de campanha, créditos com `lockForUpdate`, webhook MP com validação de assinatura HMAC + janela de 5 min + idempotência por request_id). **O risco de lançamento está na superfície comercial e em dois buracos financeiros críticos**: (1) a página de pricing da SPA chama uma rota autenticada — visitante anônimo vê tela vazia; (2) toggle "Anual -20%" engana o cliente sem backend; (3) subscription pendente após PIX expirado bloqueia retry. Marketing tem stats e testimonials inventados com CNPJ placeholder — combo que pode virar ação no CDC. Testes cobrem auth, isolation e cupom, mas **zero para Payment/Subscription/CreditService**, onde estão todos os caminhos de dinheiro. Consertando os bloqueadores abaixo, o lançamento é viável em 2-3 sprints.

---

## Bloqueadores de launch consolidados

Ordenados por severidade e depois por esforço. "S=≤1d, M=2-5d, L=1-2 semanas".

| # | Área | Item | Sev | Esforço | Ref |
|---|------|------|-----|---------|-----|
| 1 | Billing | Toggle "Anual -20%" mostra preço falso mas backend cobra mensal cheio | Crítico | M | UX-BUG-02 / MKT-BUG-05 |
| 2 | Billing | Subscription pendente (PIX expirado) bloqueia retry do cliente | Crítico | M | DEV-BUG-03 |
| 3 | Billing | `PaymentController::credits` credita saldo fora de transação única com o pagamento — risco de cliente pagar e não receber | Crítico | M | DEV-BUG-02 |
| 4 | Marketing | Stats inventadas na landing ("847K+", "94,7%", "4:23") | Crítico | S | MKT-BUG-01 |
| 5 | Marketing | Testimonials inventados sem disclaimer | Crítico | S | MKT-BUG-02 |
| 6 | Marketing | Planos divergentes entre landing (4 tiers) e API (3 tiers com preços diferentes) | Crítico | S | MKT-BUG-06 / DEV-BUG-04 |
| 7 | UX | `/plans` SPA bate em rota protegida — visitante anônimo vê pricing vazio | Crítico | S | UX-BUG-01 |
| 8 | Compliance | Sem checkbox de LGPD/Termos no registro | Crítico | S | UX-BUG-03 / SEC-BUG-03 |
| 9 | Compliance | `AuditLog::record` não grava IP nem User-Agent (viola LGPD art. 37) | Alto | S | SEC-BUG-02 / DEV-BUG-08 |
| 10 | Security | Sem verificação de email após registro — abuso de 50 créditos grátis via emails alheios | Alto | M | SEC-BUG-06 |
| 11 | Security | Register throttle 3/min + sem captcha — bot farm de contas grátis | Alto | M | SEC-BUG-07 |
| 12 | Security | Webhook Infobip aceita secret via query string (`?secret=`) | Alto | S | SEC-BUG-01 |
| 13 | Security | Models `Payment` e `Subscription` sem `AppliesTenantScope` — IDOR silencioso se nova rota esquecer filtro | Alto | S | SEC-BUG-04 / DEV-BUG-01 |
| 14 | Observability | Sem Sentry/APM — exceções em produção sem visibilidade | Alto | S | ENG-BUG-06 |
| 15 | QA | Zero testes para Payment/Subscription/MP webhook/CreditService | Crítico | L | QA-BUG-01 / QA-BUG-02 |
| 16 | Marketing | CNPJ placeholder `XX.XXX.XXX/0001-XX` no footer | Alto | S | MKT-BUG-03 |
| 17 | Marketing | WhatsApp de vendas hardcoded `5511999999999` | Alto | S | MKT-BUG-04 |
| 18 | Security | `.env.example` com `APP_DEBUG=true` — risco de vazar stack trace em prod | Alto | S | SEC-BUG-09 |
| 19 | Dev | `APP_DEBUG` em `.env` do repo | Alto | S | (inspeção direta) |

---

## Conflitos e trade-offs entre auditores

### Conflito 1 — Dev Sênior quer refatorar billing / Marketing quer lançar ontem
**Dev:** refatorar `credit_transactions` em `CreditService::record`, extrair FormRequests padrão, adicionar FactoryBot completo, OpenAPI — 2 sprints.
**Marketing:** estatísticas inventadas e CNPJ faltando sinalizam "inacabado"; cada semana parado custa orçamento de ads não gasto.
**Veredicto PM:** ataque os **bloqueadores reais** (billing, LGPD, pricing page vazio) antes de refatorar. Refactor puro vai para backlog imediato. **Go-live em 2 semanas, não 6.**

### Conflito 2 — Red Team quer CSP estrito / UX quer velocidade de iteração
**Red Team:** tirar `'unsafe-inline'` da CSP (SEC-BUG-08).
**UX:** landing tem estilos e JS inline; refactor em arquivos separados pode quebrar critical CSS.
**Veredicto PM:** deferir para pós-launch. Hoje o CSP restringe `connect-src` a hosts confiáveis, que é onde está o maior ganho. `unsafe-inline` vai para sprint 2.

### Conflito 3 — QA quer cobertura 60% / Engenheiro quer CI rápido
**QA:** suite E2E + snapshot testing.
**Eng:** CI passou de 3min para 15min trava velocidade de merge.
**Veredicto PM:** priorizar **testes de billing** (alto risco, baixo volume) primeiro; E2E full suite fica no pós-launch com nightly. Unit tests de CreditService e validateWebhookSignature rodam em <30s.

### Conflito 4 — Marketing quer toggle anual / Dev quer implementar direito
**Marketing:** toggle anual converte melhor.
**Dev:** implementar ciclo anual no MP exige `frequency_type: 'years'`, migração de plano, UI de "assinar mensal vs anual", lógica de pro-rata em upgrade.
**Veredicto PM:** **remover o toggle da landing e da SPA agora.** Implementar corretamente em sprint 2 com feature flag. Lançar com anúncio enganoso é risco jurídico que supera ganho de conversão.

### Conflito 5 — UX quer design system unificado / PM quer não-bloqueador
**UX:** Tabler (dashboard) vs custom (landing/auth) cria dissonância de marca.
**PM:** refactor completo = 2 semanas. Não bloqueia launch.
**Veredicto PM:** backlog pós-launch. Unificar cores primárias e botões agora (1 dia) é suficiente para reduzir dissonância.

### Conflito 6 — QA quer verificar que `tests` rodam em MySQL / Dev não quer matriz
**QA:** `DATE_FORMAT` em `ReportController::campaign` quebra em SQLite.
**Dev:** matriz MySQL+SQLite dobra tempo de CI.
**Veredicto PM:** rodar CI só em MySQL. SQLite não é o ambiente de produção; testar onde importa. Documentar no README.

---

## Roadmap sugerido

### Pré-launch (bloqueadores — obrigatório)
**Objetivo:** ~10 dias úteis. 1 dev + 1 QA dedicado.

**Sprint 1 (dias 1-5) — billing + compliance**
1. DEV-BUG-02 — envelopar `PaymentController::credits` em transação conjunta com cobrança MP.
2. DEV-BUG-03 — ignorar subscriptions `pending` criadas há mais de 24h no check de retry + job de expiração.
3. UX-BUG-02 / MKT-BUG-05 — **remover toggle anual** da landing e SPA (ship correto em sprint futuro).
4. UX-BUG-01 — mover `GET /plans` para rota pública.
5. MKT-BUG-06 / DEV-BUG-04 — alinhar planos landing × seeder × API (fonte única).
6. UX-BUG-03 / SEC-BUG-03 — checkbox LGPD no registro + coluna `users.lgpd_consented_at`, `terms_version`.
7. SEC-BUG-02 — enriquecer `AuditLog::record` com IP + UA.
8. MKT-BUG-03 / MKT-BUG-04 — preencher CNPJ real e WhatsApp real.

**Sprint 2 (dias 6-10) — segurança + testes + landing**
9. SEC-BUG-06 — email verification obrigatório pós-registro.
10. SEC-BUG-07 — Cloudflare Turnstile no registro.
11. SEC-BUG-01 — remover aceite de secret via query no webhook Infobip.
12. SEC-BUG-04 / DEV-BUG-01 — adicionar `AppliesTenantScope` em Payment/Subscription/WhatsAppPhoneNumber.
13. SEC-BUG-09 — flipar `APP_DEBUG=false` em `.env.example` e em `.env` de produção; documentar deploy checklist.
14. QA-BUG-01 / QA-BUG-02 — testes de Payment/Subscription/MP webhook (prioridade: happy path, signature inválida, idempotência duplicada, cupom já usado).
15. ENG-BUG-06 — integrar Sentry (free tier).
16. MKT-BUG-01 / MKT-BUG-02 — substituir stats e testimonials por versão honesta ("em beta público", "casos reais em breve") ou coletar 3 beta testers para depoimento real.

### Semana 1 pós-launch (estabilização)
- ENG-FEAT-01 — métricas Prometheus (queue depth, MP webhook latency).
- DEV-FEAT-01 — job de reconciliação MP noturno (fechar pending abandonados).
- UX-FEAT-01 — onboarding guiado pós-registro (3 passos).
- QA-BUG-05 — teste E2E happy path de campanha SMS.
- ENG-IMP-05 — Idempotency-Key nos POSTs de pagamento.
- SEC-IMP-02 — Password HIBP (`uncompromised()`).
- DEV-IMP-06 — OpenAPI com `dedoc/scramble` (libera `/docs` prometido na landing).
- MKT-BUG-12 — ajustar copy "Setup em 4 min 23 seg" para expectativa real.
- DEV-BUG-07 — logs diários + rotação + PII scrubbing.

### Backlog
- Implementar ciclo anual de cobrança (feature com flag).
- Unificação de design system (Tabler → custom ou vice-versa).
- `/docs` API publicado.
- Biblioteca de templates prontos por segmento (valida copy da landing).
- 2FA para superadmin (SEC-IMP-03).
- Horizon + Redis para queue e cache.
- Painel LGPD (export + hard delete do próprio tenant).
- Testes E2E com Playwright.
- Programa de afiliados.
- Soft-delete + lixeira de campanhas/listas.
- CSP sem `unsafe-inline`.

---

## Riscos aceitos conscientemente (e por quê)

1. **Queue driver `database` em vez de Redis** — 20-30 campanhas/dia operam bem. Migrar no primeiro cliente que mande >5k contatos/dispatch. Troca de 1 variável de ambiente, baixo risco. *Não vale gastar sprint em infra que ninguém está usando ainda.*

2. **`unsafe-inline` em CSP da landing** — ganho de XSS nesta página é marginal (sem inputs, sem cookies de sessão). Refactor é trabalhoso e quebra critical CSS. Aceitar até pós-launch — manter `X-Frame-Options: DENY` e `Permissions-Policy` já fortes.

3. **Dois design systems coexistindo (Tabler + custom)** — desagradável mas não bloqueia conversão nem gera bug funcional. Cliente paga pela dor resolvida, não pela coesão tipográfica. Corrigir quando virar fricção medida.

4. **Cobertura de testes < 60%** — nunca vai dar tempo de testar tudo antes do launch. Priorizar billing (cobertura alvo: 80%) + tenant isolation (alvo: 100% dos models). Restante segue teste manual + Sentry. Métricas pós-launch mostram onde investir.

5. **Sem CI pipeline formal** — equipe pequena, deploy manual aceita. Primeiro PR de fora ou segundo dev força adoção. Preparar `.github/workflows/test.yml` mas só ligar quando houver colaborador externo.

6. **Polling MP frontend sem backoff exponencial** — impacta só o cliente que deixou aba aberta esperando PIX. Fix simples mas baixa prioridade.

7. **Export CSV não escapa `;` em nome do contato** — afeta só relatórios de tenant específico; ninguém morre. Fix de 10 linhas no backlog.

8. **API keys de integrações possivelmente em texto claro na tabela `settings`** — só verificável lendo `SettingsService`. **Se confirmado texto claro, vira bloqueador**; se já encriptado, é só reforço de teste. *Próxima ação: verificar 15 minutos antes do launch.*

---

## Métricas de sucesso do launch

Propostas para acompanhar no primeiro mês:

- **Ativação** (criou conta → disparou primeira campanha em ≤24h): alvo 40%.
- **Conversão gratis → pago** em 14 dias: alvo 5%.
- **Taxa de entrega** (dispatch `delivered` / `sent`): alvo 90% (Infobip default).
- **MRR real** vs projetado.
- **Tickets de suporte / 100 usuários**: alvo <15 (sinal de UX).
- **Exceções Sentry / dia**: alvo <10 após sprint 2.
- **Tempo médio do wizard** (signup → campanha criada): medir — nunca confiou no "4m23s" da landing.
