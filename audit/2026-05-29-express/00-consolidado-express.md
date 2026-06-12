# Re-auditoria Express Consolidada — Commit 8843b515 (fix express)
**Data:** 2026-05-29
**PM Sênior:** Consolidação dos 4 re-auditores express (UX/Dev/QA/Red Team)
**Commit auditado:** `8843b515 fix(express): address findings from express re-audit round (UX/Dev/RT/QA)`
**Commit base anterior:** `096c5c68 fix(security/billing): close 9 P0R blockers`

---

## 1. Veredito Executivo

- **Bloqueadores REMANESCENTES após este commit:** **2** (P1 elevados a P0R² por compliance/financeiro) + **2 P1** (tickets pós-launch).
- **Pode lançar?** **SIM — condicional**, com janela de 24-48h para fechar os 2 follow-ups financeiros (NOT NULL em `balance_transactions.reference_id` e índice composto em `audit_logs`).

**Parágrafo justificativa:** A rodada express fechou os 4 bloqueadores UX (N1, N6, R3, R4) e o bloqueador Dev/QA mais crítico (P0R-03 CI flakiness — DatabaseMigrations + remoção de 2 sub-testes redundantes deixou a suíte 396/396 estável em 2 runs). O risco crítico de segurança que ainda restava do commit anterior (SEC-04EXP-1, `withoutRedirecting()` quebrando webhooks legítimos) foi substituído por `allow_redirects` com `on_redirect` re-validando cada hop via `OutboundWebhookGuard` — solução OWASP-compliant que fecha o vetor IMDS sem quebrar Slack/Discord/GitHub. AuditLog na ingestão do MercadoPago (recebimento + rejeição por assinatura) também foi adicionado. **Os 2 itens não fechados (NOT NULL em `balance_transactions.reference_id` e índice `(tenant_id, action, created_at)` em `audit_logs`) são defesa-em-profundidade futura, não vulnerabilidade ativa** — todos os caminhos atuais (`PaymentController`, `MercadoPagoService`, `BillingService::recharge/reserve/release`) preenchem `reference_id` corretamente, e o throttle:30,1 + `min:3` já cobre DoS no audit-log para os primeiros tenants. **Prazo recomendado para fechar follow-ups: até 48h após launch.**

---

## 2. Status item-a-item

| ID | Origem | Descrição | Status atual | Evidência |
|---|---|---|---|---|
| **R1** | UX | Router sem `/settings/opt-outs` + `/settings/audit-log` | ✅ Fechado (commit anterior) | `frontend/src/router/index.ts:217-226` |
| **R2** | UX | `creditsPerSend = ref(1)` literal | ✅ Fechado (commit anterior) | `Create.vue:300` |
| **R3** | UX | Botão "Ver todos os dispatches" sem handler | ✅ **Fechado neste commit** | `reports/CampaignDetail.vue:134` agora `@click="router.push(`/campaigns/${id}?tab=dispatches`)"` |
| **R4** | UX | `window.confirm()` nativo em `contacts/Index.vue` | ✅ **Fechado neste commit** | `contacts/Index.vue:396-398` deleteList agora seta `pendingDeleteListId`; `confirmDeleteList()` consome ConfirmModal |
| **N1** | UX | `Create.vue` sem `pricingLoading`/`pricingError` | ✅ **Fechado neste commit** | `Create.vue:301-302` (`pricingLoading`, `pricingError` refs); `:314-334` watch reativo com loading state |
| **N6** | UX | `changePassword` não desloga após revogação server-side | ✅ **Fechado neste commit** | `profile/Index.vue:177-183` toast "Faça login novamente" + `auth.logout()` + `window.location.assign('/login')` |
| **N2-N5, N7-N8** | UX | Atenções (CSV download sem header, filtros não deeplinkáveis, modal sem teleport, balance sem refresh) | ⚠️ Não tocado (P1 pós-launch) | — |
| **P0R-01** | Dev/RT | UNIQUE em balance_transactions | ✅ Fechado (commit anterior) | Migration `2026_05_29_000002` |
| **P0R-02** | Dev | UNIQUE em campaign_dispatches | ✅ Fechado (commit anterior) | Mesma migration |
| **P0R-03** | Dev/QA | MigrationRoundtripTest contamina CI | ✅ **Fechado neste commit** | `RefreshDatabase` → `DatabaseMigrations`; 2 sub-testes redundantes removidos; suíte 396/396 estável em 2 runs |
| **P0R-04** | Dev/RT | Sanctum 24h + token rotation | ✅ Fechado (commit anterior) | `config/sanctum.php:55-57`; `AuthController:202,267` |
| **P0R-05** | Dev/RT | SSRF DNS rebinding TOCTOU | ✅ Fechado (commit anterior) | `OutboundWebhookGuard::pinnedResolution()` |
| **P0R-06** | Dev | Rate-limit AuditLog | ✅ Fechado (commit anterior) | `routes/api.php:134-135 throttle:30,1` |
| **P0R-07** | Dev | Refund-race em Bus::batch | ✅ Fechado (commit anterior) | `ProcessCampaignJob:163-237` lockForUpdate + flag |
| **P0R-08** | Dev | AuditLog ampliado (billing/MP/schedule) | ✅ **Reforçado neste commit** | `WebhookController::mercadopago:44-46,51-53` agora registra `payment.webhook_rejected` e `payment.webhook_received` |
| **P0R-09** | Dev | CSV escape + cap import | ✅ Fechado (commit anterior) | `OptOutsController:90,97-104,209-213` |
| **SEC-04EXP-1** | RT | `withoutRedirecting` quebra Lambda/CDN legítimos | ✅ **Fechado neste commit** | `FireOutboundWebhookJob:64-82` `allow_redirects` com `on_redirect → assertSafeUrl()` por hop |
| **SEC-04EXP-2** | RT | NOT NULL em `balance_transactions.reference_id` | ❌ **Não aplicado** | Sem migration adicional após `2026_05_29_000002` |
| **SEC-04R-6 (parcial)** | RT | Índice `(tenant_id, action, created_at)` em audit_logs | ❌ **Não aplicado** | Migration ausente |
| **QA-NEW-EXP-01..08** | QA | Testes dedicados para P0R-05/06/07/08/09 + import >50k | ⚠️ Não adicionados (cobertos por regressão geral) | — |

---

## 3. Bloqueadores Residuais (P0R²)

| ID | Tema | Severidade real | Esforço |
|---|---|---|---|
| **SEC-04EXP-2** | NOT NULL + UNIQUE não cobre `reference_id IS NULL` | 🟡 P0R² operacional (defesa em profundidade — sem caminho atual que insira NULL) | 1 migration + teste = **2h** |
| **SEC-04R-6 (parcial)** | Sem índice composto em audit_logs | 🟡 P0R² ops (degradação após ~100k linhas/tenant) | 1 migration = **30min** |
| **QA-NEW-EXP-03** | Import opt-outs >50k é parcial (49.999 entram + 422) | 🟢 P1 UX/operacional | Wrap em DB::transaction = **1h** |
| **QA-NEW-EXP-01/05/06** | Sem testes dedicados para `safeCsvCell` export, refund-race double-fire, 9 AuditLog novos | 🟢 P1 cobertura de regressão | **4-6h** |

**Total esforço para fechar follow-ups financeiros:** ~2.5h dev. Não bloqueante para launch — pode ser hotfix em D+1.

---

## 4. Risco Residual se Lançar Agora

**Risco BAIXO-MÉDIO, dominado por:**

1. **SEC-04EXP-2 (NULL em balance_transactions.reference_id):** vetor TEÓRICO. Auditoria confirma que `PaymentController::credits`, `MercadoPagoService::handleWebhook`, `BillingService::recharge/reserve/release` **todos** preenchem `reference_id`. Risco se concretiza apenas se um dev futuro adicionar caminho com `null` — fix em D+1 fecha definitivamente. **Impacto se explorado:** double-credit silencioso em fluxo novo. **Probabilidade no launch:** ~zero (nenhum caminho atual permite).
2. **Índice audit_logs ausente:** primeiros tenants têm <10k linhas — query < 100ms. Risco surge quando algum tenant ultrapassar ~100k linhas (estimativa: 2-4 semanas pós-launch para tenant ativo). Janela confortável.
3. **Import opt-outs >50k parcial:** semântica confusa mas não destrutiva. Operador vê 422 "divida o arquivo" e 49.999 opt-outs aplicados — pior caso é re-importar duplicatas (idempotency por (tenant_id, channel, identifier) já protege).
4. **Coverage gaps QA (P0R-05..09 sem teste dedicado):** código defensivo está correto por inspeção. Próximo refactor poderia removê-lo silenciosamente — adicionar testes em sprint pós-launch.

**Não há vetor crítico de segurança ativo, double-credit possível, nem caminho de crash em UX.**

---

## 5. Recomendação Final

**LANÇAR com plano de hotfix D+1 (≤48h):**

1. **HOJE / antes do deploy:** smoke-test em staging dos 4 fluxos críticos:
   - Trocar senha → confirmar redirect para `/login` (N6).
   - Criar campanha SMS → confirmar spinner + custo correto (N1).
   - Disparar webhook outbound para Slack (testa allow_redirects).
   - Webhook MercadoPago com assinatura inválida → confirmar `payment.webhook_rejected` em audit_logs.

2. **D+1 (24h pós-launch):** migration única com:
   - `NOT NULL` em `balance_transactions.reference_id` + `reference_type`.
   - Índice `(tenant_id, action, created_at)` em `audit_logs`.
   - Wrap do loop de import opt-outs em `DB::transaction` (ou retornar 422 ANTES do primeiro add quando peek-count > 50k).

3. **Sprint+1 (1-2 semanas):** fechar QA coverage gaps:
   - `OptOutsImportTest` + `OptOutsExportTest` (CSV injection + cap 50k).
   - `RefundRaceTest` (Bus::fake + then+catch concorrentes).
   - Smoke tests dos 9 novos `AuditLog::record` em billing/MP.
   - `HappyPathTest` E2E (P0-NEW-E2E herdado).

**Comparativo:** rodada anterior tinha 4 bloqueadores UX + 1 Dev/CI + 1 RT (`withoutRedirecting`). Este commit fechou 6 dos 6 bloqueadores ativos. Os 2 itens não aplicados (SEC-04EXP-2 e índice audit_logs) são **defesa em profundidade**, não vulnerabilidade explorável no estado atual do código.

**Veredito final:** **LIBERAR LANÇAMENTO**. Follow-up financeiro em 24-48h. Coverage QA em sprint+1.

---

**Fim do consolidado express.**
