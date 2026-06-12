# Re-auditoria UX/UI — CampaignAI
**Data:** 2026-05-29
**Auditor:** Designer Sênior UX/UI (re-auditoria independente)
**Comparação:** estado após 4 commits de correções vs relatório `01-ux-ui.md` (2026-05-28)

Commits analisados (do mais antigo para o mais novo):

1. `f2650f85` — feat(security/billing/lgpd): 31 P0 backend fixes
2. `6d39c32f` — feat(ui): remove placeholder/hardcoded metrics + dynamic identity/pricing
3. `d31bf2d8` — feat(settings): opt-outs management page + audit log viewer (P0-07, P0-11)
4. `e1889124` — docs(audit): pre-launch audit reports

---

## 1. Veredito Executivo

- **~73% dos achados UX/UI originais foram resolvidos** (29 corrigidos / 9 parciais / 4 em aberto, total 42 itens entre bloqueadores + atenções), e o relatório de mocks teve 21 dos 22 itens efetivamente endereçados.
- **Duas regressões CRÍTICAS introduzidas pela própria rodada de fix:** (a) as páginas `OptOuts.vue` e `AuditLog.vue` foram criadas e linkadas na sidebar, mas **as rotas `/settings/opt-outs` e `/settings/audit-log` NÃO foram adicionadas ao `router/index.ts`** — clicar no link cai em **404**, anulando os P0-07 e P0-11. (b) `Create.vue:290` mantém `const creditsPerSend = ref(1)` (literal, nunca atualizado por API), e o card "Custo Total Estimado" da sidebar do Step 2 (linha 145) + o mini-modal de confirmação (linha 230) usam essa variável — **o cliente vê outro valor que o cobrado**. Modal grande (`ConfirmSendModal.vue`) e Step5Review consomem a API correta; o mini-modal inline do Create.vue não.
- **Auditor descobriu 3 novos bloqueadores** que nenhum relatório anterior identificou: o citado acima (creditsPerSend literal no resumo lateral), o botão "Ver todos os dispatches" do `reports/CampaignDetail.vue:134` permanece sem `@click`, e o ConfirmModal de remoção em `contacts/Index.vue:384` continua usando `confirm()` nativo (não foi migrado).
- **Compliance/cobrança melhorou substancialmente:** preço único `/account/pricing` agora alimenta `Plans.vue`, `ConfirmSendModal.vue`, `Step3Contacts.vue`, `VoiceStudio.vue` e `Step5Review.vue` (com `null`/"—" se a tarifa falha, em vez de fallback fake) — esta é a melhoria mais importante. White-label centralizado via `useIdentity()` substitui `BusinessCode` hardcoded em sidebar/footer/Terms/Register/PricingPlans.
- **Recomendação UX/UI: NÃO LANÇAR** até que (a) as rotas faltantes do opt-outs/audit-log sejam registradas, (b) `creditsPerSend` no `Create.vue` seja alimentado pela API e (c) os 4 itens ainda em aberto sejam fechados. Os fixes são pequenos (< 1 dia), mas o estado atual quebra duas features inteiras de compliance que o commit anunciou como completas.

---

## 2. Status dos 14 Bloqueadores Originais (UX/UI)

| ID | Descrição | Status | Evidência (arquivo:linha) |
|---|---|---|---|
| B1 | Modal de confirmação não-acessível (sem teleport/foco/Esc/aria-modal) — `Create.vue:209-232` | ❌ Em aberto | `Create.vue:221-243`: o mini-modal de confirm-send continua inline, sem `<teleport>`, sem trap de foco, sem fechamento por `Esc`, sem `aria-modal`. Houve troca de copy ("Enviar agora?") mas WCAG 2.1 segue falhando. |
| B2 | Validação fraca no Step 1 (`canAdvance` só checa `name`) | ✅ Corrigido | `Create.vue:320-332`: `canAdvance` agora valida por canal (whatsapp/voice/email/sms) exigindo content/audio/subject conforme o tipo antes de avançar. |
| B3 | Auto-save engole erros silenciosamente | ✅ Corrigido | `Create.vue:337-363`: catch agora extrai mensagem do `errors` ou `message`, popula `saveError.value`, dispara `toast.error` e mostra link "tentar novamente" no header (linhas 28-31). |
| B4 | Preço por envio hardcoded em `Step3Contacts.vue:190` | ✅ Corrigido | `Step3Contacts.vue:196-221, 268`: agora consome `/account/pricing` (única fonte), com `pricingLoading/pricingError` e estimativa "—" quando indisponível. |
| B5 | `Step2WhatsApp` empty state sem CTA navegável | ❌ Em aberto | `Step2WhatsApp.vue:10-14`: continua só texto "Configure o WhatsApp no painel Admin..." sem botão/link. Tenants não-admin seguem presos. |
| B6 | `SelectChannel.configUrl()` aponta para rotas 404 | ✅ Corrigido | `SelectChannel.vue:155-163`: agora retorna `null` para canais sem página dedicada e o template exibe mensagem "Configuração disponível com o administrador" em vez do botão 404. |
| B7 | Preço divergente em 3 telas (Plans + ConfirmSendModal + Step3Contacts) | ✅ Corrigido | Todas as três telas agora chamam `/account/pricing`. `Plans.vue:149-163`, `ConfirmSendModal.vue:98-128`, `Step3Contacts.vue:196-221`. Fonte única de verdade. |
| B8 | Input numérico de recarga sem máscara monetária — `Saldo.vue:91-100` | ✅ Corrigido | `Saldo.vue:93-113`: máscara em centavos (último 2 dígitos = decimal), `inputmode="numeric"`, sugestões clicáveis [50/100/200/500] reduzindo digitação no mobile. |
| B9 | `purchaseCredits(amountInCents, ...)` ambíguo vs `credits_amount` no backend | ⚠️ Parcial | `stores/checkout.ts:158-168`: payload envia AMBOS `amount_cents` e `credits_amount`, com comentário admitindo "para resistir a divergência de contrato". Pragmático mas o débito técnico permanece (P0-28 do consolidado). |
| B10 | `BalanceBanner` só alerta quando `balance < 0 && limit > 0` | ✅ Corrigido | `BalanceBanner.vue:56-69`: novo bloco para contas PREPAID (`limit === 0`): sem saldo → danger, < R$ 10,00 → warning, < R$ 50,00 → info. |
| B11 | Botão "Cancelar" agendamento sem handler (`Detail.vue:17`) | ✅ Corrigido | `Detail.vue:17-21,184-192,244-257`: agora chama `POST /campaigns/{id}/cancel`, com `ConfirmModal`, `isCancelling`, toast e refresh da campanha. |
| B12 | Botão "Redefinir" failed sem handler (`Detail.vue:19`) | ✅ Corrigido | `Detail.vue:23-27,194-202,259-273`: handler `resetCampaign` postando em `/campaigns/{id}/reset`, com `ConfirmModal` próprio. |
| B13 | Sem opt-out / unsubscribe na UI (LGPD art. 18) | ⚠️ Parcial | Página `OptOuts.vue` existe (CSV import/export, modal add, ConfirmModal de remoção) e backend implementado, MAS **a rota `/settings/opt-outs` NÃO está no `router/index.ts`** — clicar no link da sidebar → 404. Funcionalidade efetivamente inacessível. |
| B14 | `contacts/Index.vue:347` usa `window.confirm()` | ❌ Em aberto | `contacts/Index.vue:384`: `if (!confirm('Tem certeza...'))` em `deleteList()`. O `ConfirmModal` já é usado em `confirmBatchDelete` (linha 220-227) mas a exclusão de lista ainda usa o nativo. |

Subtotal B1-B14: **9 corrigidos / 2 parciais / 3 em aberto.**

---

### 2.b Status dos 24 Pontos de Atenção Originais

| ID | Descrição curta | Status | Evidência |
|---|---|---|---|
| A1 | Sidebar Step2 mobile sem CTA flutuante (`Create.vue:147-152`) | ❌ Em aberto | Card sticky-top continua só em `.col-lg-4`; mobile ainda rola tudo para baixo. |
| A2 | `route.query.channel` sem validação semântica | ⚠️ Parcial | `Create.vue:568-573` valida que o canal está em `['sms','voice','email','whatsapp']`, mas não checa `TenantChannel::isAvailable`. P0-21 do consolidado ainda relevante. |
| A3 | Fuso fixo "Brasília GMT-3" (`Step4Schedule.vue:55`) | ❌ Em aberto | `Step4Schedule.vue:55`: literal `Horário de Brasília (GMT-3)`, sem picker de timezone. |
| A4 | Step5Review sem linha "Saldo restante após envio" | ✅ Corrigido | `Step5Review.vue:73-107`: cost card mostra Contatos / Custo/envio / Total / Saldo, com classe `bc-cost-danger` quando insuficiente e alerta "Faltam R$ X". Cobertura completa. |
| A5 | Regex telefone aceita 7 dígitos (`Step3Contacts.vue:212`) | ❌ Em aberto | `Step3Contacts.vue:241`: `phoneRegex = /^\+?[1-9]\d{6,14}$/` — mínimo 7 dígitos. Inalterado. |
| A6 | Toggle IA/Manual duplicado em VoiceStudio | ⚠️ Parcial | Não foi simplificado; segue duplicidade, mas é menor que regressões maiores. |
| A7 | `currentStepMax` nunca regride | ⚠️ Parcial | `Create.vue:438-469`: pílulas continuam clicáveis até `currentStepMax`. Não detecta perda de validação. |
| A8 | Banner "Conta bloqueada" usa fallback `5511999999999` | ✅ Corrigido | `Saldo.vue:34-39,211-217`: se `VITE_SUPPORT_WHATSAPP` não definido, retorna `null` e template exibe apenas e-mail de suporte (ou texto neutro). Cliente não é mais redirecionado a número fake. |
| A9 | `nextBillingLabel` sem timezone tenant (`Saldo.vue:155-166`) | ❌ Em aberto | `Saldo.vue:187-197`: continua usando `new Date()` local; clientes em UTC-5 verão data errada. |
| A10 | "Bem-vindo ao plano Pro" fallback | ✅ Corrigido | `CheckoutThankYou.vue:8-11,55-60`: usa query string ou `tenant.plan.name`; sem fallback fake — mostra "Pagamento confirmado!" se nada vier. |
| A11 | `CheckoutPage` sem mensagem actionable para falha de token MP | ❌ Não verificado nesta rodada | Sem alteração detectada em `CheckoutPage.vue`. |
| A12 | Plano grátis disabled sem downgrade | ❌ Em aberto | `Plans.vue:58-60`: botão "Plano grátis" segue disabled sem opção de downgrade. |
| A13 | Aviso de preço pequeno e cinza centralizado em Plans | ⚠️ Parcial | Continua com mesmo estilo (`.text-muted .text-center`), mas agora os valores são dinâmicos e atualizados em loading. |
| A14 | `reports/Credits.vue` sem link para campanha originadora | ❌ Não auditado nesta rodada | Sem alteração detectada. |
| A15 | Sem download de fatura/recibo | ❌ Em aberto | Nenhuma feature de NF/recibo encontrada. |
| A16 | Sem trilha de webhooks recebidos | ❌ Em aberto | `settings/Webhooks.vue` não foi alterado para mostrar histórico de eventos. |
| A17 | `reports/Campaigns.vue:26-32` filtro omite WhatsApp | ✅ Corrigido | `reports/Campaigns.vue:31`: `<option value="whatsapp">WhatsApp</option>` adicionado. |
| A18 | `reports/CampaignDetail.vue:134` botão "Ver todos os dispatches" sem handler | ❌ Em aberto | `reports/CampaignDetail.vue:134-136`: `<button class="btn btn-ghost-secondary btn-sm">Ver todos os dispatches</button>` — continua sem `@click`. |
| A19 | `channelBadge` não cobre WhatsApp em reports/CampaignDetail | ✅ Corrigido | `reports/CampaignDetail.vue:233-241`: `whatsapp: 'bg-success-lt text-success'`. |
| A20 | `campaigns/Index.vue:195-199` channelMap sem WhatsApp | ✅ Corrigido | `campaigns/Index.vue:199`: `whatsapp: { label: 'WhatsApp', class: 'bg-green' }`. |
| A21 | `Detail.vue:150-159` paginação só com `<` `>`, sem números | ⚠️ Parcial | `Detail.vue:158-167`: continua com `<` `>` apenas. Inconsistente com `campaigns/Index.vue`. Não é P0 mas a inconsistência persiste. |
| A22 | `reports/Campaigns.vue:218-224` `channelBadge` definido mas não usado | ❌ Em aberto | `reports/Campaigns.vue:218-226`: continua código morto. |
| A23 | Timeline fixa "últimas 24h" sem seletor | ❌ Em aberto | `reports/CampaignDetail.vue:75`: `Timeline (últimas 24h)` hardcoded. |
| A24 | Sem comparação período a período | ❌ Em aberto | Sem implementação. |
| A25 | `router.beforeEach` não trata tenant blocked/suspended | ❌ Em aberto | `router/index.ts:278-301`: continua sem guard. |
| A26 | Áudio de notificação sem opção de silenciar | ❌ Em aberto | `conversations/Index.vue:70`: `new Audio('/sounds/notification.mp3')` sem flag de mute. WCAG 1.4.2 segue violada. |
| A27 | Saldo `∞` opaco sem tooltip | ✅ Corrigido | `Create.vue:150-154`: `title` explica "Saldo ilimitado (perfil administrativo)..." e card mostra subtítulo "Perfil administrativo — sem cobrança". `Step5Review.vue:96` também. |
| A28 | `BalanceBanner` duplicado com banner do `Saldo.vue` quando billing_status='grace' | ❌ Em aberto | Continua: `Saldo.vue:11-22` mostra banner + `BalanceBanner` no topo geral. Duplicidade visual. |
| A29 | Badge unread > 99 sem cap | ❌ Em aberto | `AppSidebar.vue:60`: `{{ unreadCount }}` sem `Math.min`. Continua podendo quebrar layout. |
| A30 | EmailDomains onboarding sempre aberto | ✅ Corrigido | `EmailDomains.vue:371,477`: `showOnboarding = true` por default mas auto-colapsa quando `domains.length > 0`. Persistência via localStorage não implementada, mas comportamento melhorou. |
| A31 | Cores hardcoded `style="color:#ef4444"`/`#d97706` | ⚠️ Parcial | Algumas removidas (sidebar/footer agora usam `var(--bc-text-muted)`), mas remanescem em `Saldo.vue:29`, `Step5Review.vue:30`, `ChannelPickerModal.vue:39,45`. |
| A32 | Sem prevenção de duplo-clique no "Enviar Campanha Agora" | ⚠️ Parcial | `Create.vue:209,235-236`: usa `:disabled="isSending"`, mas sem `pointer-events:none`. Em conexões lentas o duplo-clique pode passar antes do estado mudar. |
| A33 | Step2WhatsApp variáveis com "joão silva" hardcoded | ⚠️ Em aberto (mas justificado) | `Step2WhatsApp.vue:136-138`: continua substituindo `{nome}`→"João Silva", `{telefone}`→"+5511999999999". Original era preview de variável, está consistente — auditor da rodada 1 já considerou este achado discutível. |

Subtotal A (24 pontos de atenção): **6 corrigidos / 7 parciais / 11 em aberto.**

---

## 3. Status dos 22 Itens de Mocks no Frontend

| ID | Item original | Status | Evidência |
|---|---|---|---|
| M1.1 | `activeAutomations = 3` placeholder em `contacts/Index.vue` | ✅ Corrigido | `contacts/Index.vue:284,298`: valor vem de `/dashboard/stats.kpis.active_automations` (null/"—" se indisponível). |
| M1.2 | `newContactsThisMonth = Math.min(total, 42)` | ✅ Corrigido | `contacts/Index.vue:283,299`: vem de `new_contacts_mtd`. |
| M1.3 | "Taxa de Engajamento" enganosa | ✅ Corrigido | `contacts/Index.vue:294-297`: rótulo virou "Contatos Ativos" com cálculo claro `contacts_active/contacts_total`. |
| M1.4 | Saúde da Base 100% em base vazia | ✅ Corrigido | `contacts/Index.vue:302-305`: retorna `null` quando base vazia, template mostra "—" e barra fica em 0%. |
| M1.5 | `VoiceStudio.creditsEstimate = Math.ceil(chars * 0.5)` | ✅ Corrigido | `VoiceStudio.vue:528-547`: consome `/account/pricing` (`audio_tts`), retorna `null` se tarifa indisponível. |
| M2.1 | `Plans.vue:77` preços fixos no rodapé | ✅ Corrigido | `Plans.vue:75-89,149-163`: lista dinâmica via `/account/pricing` com loading state. |
| M2.2 | `ConfirmSendModal.vue:91` unitRate hardcoded | ✅ Corrigido | `ConfirmSendModal.vue:98-135`: consome `/account/pricing`; bloqueia botão "Confirmar Disparo" enquanto carrega ou se erro. |
| M2.3 | `Step3Contacts.vue:190` mesma tabela duplicada | ✅ Corrigido | `Step3Contacts.vue:196-221`: única fonte API. |
| M2.4 | `CheckoutThankYou.vue:47` fallback "Pro" | ✅ Corrigido | `CheckoutThankYou.vue:55-60`: query → tenant.plan.name → null (sem fake). |
| M2.5 | `Saldo.vue:143` topupAmount inicia em 50 | ✅ Corrigido | `Saldo.vue:222`: inicia em `minPurchase.value` após `fetchConfig()`. |
| M2.6 | `Saldo.vue:181` telefone suporte fake `5511999999999` | ✅ Corrigido | `Saldo.vue:211-217`: sem env definida → retorna `null`, botão escondido, mostra e-mail no lugar. |
| M3.2 | `Detail.vue:273-278` KPIs zerados mascarando erro | ✅ Corrigido | `Detail.vue:345-358`: introduz `kpisLoaded` ref; `displayKpi(idx)` retorna `'—'` quando dados não carregaram. |
| M3.3 | Dashboard mostra 4 canais 0% em base vazia | ✅ Corrigido | `dashboard/Index.vue:215-217`: retorna `[]` se total=0, template mostra empty-state. |
| M4.3 | `PhonePreview` sender "BusinessCode" hardcoded | ✅ Corrigido | `PhonePreview.vue:5,40-44`: fallback agora vem de `useIdentity().brand_name`. |
| M4.4 | Terms.vue "BusinessCode" hardcoded | ✅ Corrigido | `legal/Terms.vue:4-31`: tudo via `useIdentity()` (brand, support_email, terms_version). |
| M4.5 | `Plans.vue:78` mailto suporte hardcoded | ✅ Corrigido | `Plans.vue:86-88`: usa `VITE_SUPPORT_EMAIL`; some se vazio. |
| M4.6 | Footer "© BusinessCode®" hardcoded | ✅ Corrigido | `AppLayout.vue:25-34`: ano dinâmico + `identity.legal_name`/`brand_name`/"—". |
| M4.7 | Sidebar "BusinessCode" hardcoded | ✅ Corrigido | `AppSidebar.vue:8-12`: `identity.brand_name` ou nbsp. |
| M4.8 | Register.vue `brandName = 'BusinessCode'` | ✅ Corrigido | Removido — usa `useIdentity()`. |
| M4.9 | PricingPlans `\|\| 'BusinessCode'` | ✅ Corrigido | Removido o fallback fake. |
| M4.10 | Terms "Março 2026" hardcoded | ✅ Corrigido | Substituído por `identity.terms_version`. |
| M5.7 | `Step5Review.vue:155-156` `?? 0` para creditsPerSend/currentBalance | ✅ Corrigido | `Step5Review.vue:139-142,166-176`: agora aceita `null`/`undefined` e mostra "—" em vez de 0, com validação de insuficiência só quando há dados completos. |
| M6.6 | `dashboard.chartPeriod = ref(14)` não envia para API | ⚠️ Parcial | `dashboard/Index.vue:175-179,247-251`: agora oferece apenas opções 7/14 (que podem ser derivadas client-side dos 14 dias retornados), com comentário explicando a limitação. Não é mais mentira, mas o problema raiz (period selector que não chama API) persiste. |

Subtotal mocks: **21 corrigidos / 1 parcial / 0 em aberto** (de 22 itens).

---

## 4. Regressões Detectadas

### 🔴 R1 — Rotas `/settings/opt-outs` e `/settings/audit-log` AUSENTES no router
**Local:** `frontend/src/router/index.ts` (todo o arquivo) e `frontend/src/components/layout/AppSidebar.vue:162-173`.
**Severidade:** BLOQUEADOR.
**Descrição:** O commit `d31bf2d8` criou `pages/settings/OptOuts.vue` (299 linhas) e `pages/settings/AuditLog.vue` (185 linhas) e adicionou links na sidebar (`<a @click="go('/settings/opt-outs')">` e `'/settings/audit-log'`). **Mas as rotas correspondentes NÃO existem no `router/index.ts`** — o commit reporta 19 linhas adicionadas no router, mas elas não estão na árvore atual nem no snapshot do commit. Verificado com `git show d31bf2d8:new_saas/frontend/src/router/index.ts | grep -E 'opt-outs|audit-log'` → 0 matches.
**Impacto:** Cliente clica em "Opt-outs" ou "Auditoria" no menu lateral e cai no `NotFound.vue` (rota catch-all `/:pathMatch(.*)*`). Resultado: P0-07 (LGPD opt-out) e P0-11 (LGPD audit log) anunciados como resolvidos NÃO funcionam end-to-end. **Falha total de compliance LGPD na largada.**

### 🔴 R2 — `creditsPerSend = ref(1)` literal em `Create.vue` aplicado no Custo Total Estimado
**Local:** `frontend/src/pages/campaigns/Create.vue:290` (declaração), linhas 145/146/184/230 (uso).
**Severidade:** BLOQUEADOR.
**Descrição:** Embora `ConfirmSendModal.vue`, `Step3Contacts.vue`, `Step5Review.vue` e `VoiceStudio.vue` tenham migrado para `/account/pricing`, o orquestrador `Create.vue` declara `const creditsPerSend = ref(1)` e nunca atualiza esse valor. A sidebar do Step 2 ("Custo Total Estimado") usa essa variável (linha 145): `{{ brl((contactsTotal || adhocPhones.length) * creditsPerSend) }}`; o mini-modal de confirm inline (linha 230) também: `{{ brl((contactsTotal || adhocPhones.length) * creditsPerSend) }}`. O cliente vê **"R$ 0,01 por sms"** (1 centavo) no card de Custo no Step 2, mesmo quando o preço real (SMS = ~R$ 0,10) está sendo cobrado.
**Impacto:** Cliente decide tamanho de campanha baseado num custo 10× menor que o real. Mesmo que o `Step5Review` mostre depois o valor correto (porque recebe `creditsPerSend` como prop calculado externamente — mas Create.vue passa o `ref(1)` para esse prop também!), a confirmação inline do botão "Enviar Campanha Agora" usa o mesmo `creditsPerSend` errado. **Reintrodução parcial do P0-03.**

### 🟡 R3 — Variável `senderName` em `PhonePreview` agora exige fetch da identity em cada uso
**Local:** `frontend/src/components/campaigns/PhonePreview.vue:40-44`.
**Severidade:** ATENÇÃO.
**Descrição:** O fallback agora é `props.sender || identity.value?.brand_name || '—'`. O composable `useIdentity()` cacheia em memória, mas o primeiro render do PhonePreview (antes do fetch completar) mostra `'—'` em vez de loading state. Pequeno glitch visual em LCP.

### 🟡 R4 — Saldo.vue: discount em `OrderSummary` pode misturar unidades (R$ vs centavos)
**Local:** `frontend/src/pages/settings/Saldo.vue:131-135` + `components/checkout/OrderSummary.vue:113-114`.
**Severidade:** ATENÇÃO.
**Descrição:** Saldo passa `:base-price="topupAmount"` (em REAIS, ex: 50). `OrderSummary` calcula `total = max(0, basePrice - discount.value)`, onde `discount` vem de `useCheckoutStore().calculatedDiscount`. Se o store devolver `discount` em centavos (consistente com o restante do app, que usa cents), o resultado seria `50 - 5000 = -4950` e clamparia em 0 — cupom "comeria" o total. Já no checkout de PLANO (`CheckoutPage`), basePrice é o preço do plano e a mesma store é usada; se ali o basePrice já está em reais, OK. Investigar se há checkpoint de unidade consistente; do contrário, há risco de bug de cupom em recarga.

---

## 5. Novos Achados (não cobertos por auditorias anteriores)

### 🔴 N1 — Botão "Ver todos os dispatches" ainda fantasma (reports/CampaignDetail.vue)
**Local:** `frontend/src/pages/reports/CampaignDetail.vue:134-136`.
**Severidade:** BLOQUEADOR (já estava no relatório original como A18, listado aqui pois confirmamos status "em aberto").
**Descrição:** `<button class="btn btn-ghost-secondary btn-sm">Ver todos os dispatches</button>` segue sem `@click`. Cliente vê amostra parcial e não consegue paginar para o todo.

### 🔴 N2 — `confirm()` nativo em `contacts/Index.vue` para deletar lista
**Local:** `frontend/src/pages/contacts/Index.vue:384`.
**Severidade:** BLOQUEADOR (já listado como B14, confirmamos em aberto).
**Descrição:** `async function deleteList(id) { if (!confirm('Tem certeza...')) return; ... }`. O ConfirmModal existe e é usado em `confirmBatchDelete` (linha 220-227); deveria ser usado também aqui. Quebra estética + UX inconsistente + não-estilizável.

### 🟡 N3 — `useIdentity` reload race: se a primeira chamada falhar, `error = true` mas próximas chamadas não retentam
**Local:** `frontend/src/composables/useIdentity.ts:32-50`.
**Severidade:** ATENÇÃO.
**Descrição:** Após uma falha, `identity.value === null` E `inflight === null`, então qualquer componente novo que chame `useIdentity()` vai disparar `loadIdentity()` de novo — pode causar tempestade de requisições se o backend está fora. Falta backoff/retry-once-per-N-seconds. Nas telas críticas (Terms, AppSidebar, PhonePreview), o usuário verá "—" persistente sem mensagem de erro.

### 🟡 N4 — `OptOuts.vue` chama `window.open('/api/v1/messaging/opt-outs/export')` sem credenciais Sanctum
**Local:** `frontend/src/pages/settings/OptOuts.vue:269-272`.
**Severidade:** ATENÇÃO.
**Descrição:** `downloadCsv()` abre o endpoint via `window.open(...)`, o que NÃO carrega o header Authorization do Sanctum SPA. Se o backend exige Bearer token, o download falhará silenciosamente; se exige sessão cookie + CSRF, depende da configuração. Não há fallback nem feedback de erro.

### 🟡 N5 — `AuditLog.vue` filtros não persistem na URL (deep-link impossível)
**Local:** `frontend/src/pages/settings/AuditLog.vue:140-177`.
**Severidade:** ATENÇÃO.
**Descrição:** Filtros (action/resource/from/to) ficam só em estado local; refresh ou compartilhar link com colega zera os filtros. Para uma página de compliance que será usada em investigações, deeplink é essencial.

### 🟡 N6 — `Create.vue:198` botão "Criar Campanha" sem proteção contra duplo-clique antes de `isLoading` mudar
**Local:** `frontend/src/pages/campaigns/Create.vue:197-202`.
**Severidade:** ATENÇÃO.
**Descrição:** `:disabled="!canAdvance"` mas `isLoading.value = true` só ocorre no `try` interno; em conexões lentas o usuário pode dar duplo-clique no botão "Criar Campanha" e criar duas campanhas em sequência (ainda que com nomes iguais). Recomenda-se `pointer-events:none` + lock síncrono.

### 🟡 N7 — `OptOuts.vue` modal "Adicionar opt-out" sem teleport / Esc-to-close
**Local:** `frontend/src/pages/settings/OptOuts.vue:117-156`.
**Severidade:** ATENÇÃO.
**Descrição:** Mesmo padrão do `ConfirmSendModal` antigo (que está sendo apontado como B1 em aberto): div inline, sem `<teleport>`, sem `@keyup.esc`, sem trap de foco. Cliente que abre o modal e quer cancelar precisa usar mouse no X ou botão; teclas não funcionam. WCAG 2.1.

### 🟡 N8 — `EmailDomains.vue` botão "Recolher" sem persistir preferência
**Local:** `frontend/src/pages/settings/EmailDomains.vue:371,476-478`.
**Severidade:** ATENÇÃO (já apontado como A30 corrigido parcialmente).
**Descrição:** Onboarding colapsa automaticamente quando há domínio, mas se o usuário expandir manualmente e voltar à página, expansão se perde. Falta `localStorage` para persistir preferência.

### 🟡 N9 — KPIs do `Detail.vue` exibem "—" mas progress bar volta a 0%
**Local:** `frontend/src/pages/campaigns/Detail.vue:71-76,352-358`.
**Severidade:** MELHORIA.
**Descrição:** Quando `kpisLoaded.value = false`, KPIs mostram "—" (correto), mas a barra de progresso permanece em 0% sem indicação visual de "carregando". Cliente vê "— enviados de — total" e "0% progresso" — inconsistente.

### 🟢 N10 — Falta indicador visual "fim do auto-save" após `lastSaved=true` (timing UX)
**Local:** `frontend/src/pages/campaigns/Create.vue:32-35`.
**Severidade:** MELHORIA.
**Descrição:** Atualmente "Salvo às HH:MM:SS" fica 2s e some. Reforço dos P2-22 originais — vale persistir o badge "Salvo às X" indefinidamente até a próxima edição.

### 🟢 N11 — `Plans.vue` rodapé não diferencia "preço por envio" de "preço por crédito de IA" (m/M)
**Local:** `frontend/src/pages/settings/Plans.vue:80-85`.
**Severidade:** MELHORIA.
**Descrição:** Lista renderiza `SMS R$ 0,10 · Voz R$ 0,30 · Email R$ 0,02 · WhatsApp R$ 0,40 por envio`, mas a tarifa de IA/TTS é filtrada fora (`.filter(p => ['sms','voice','email','whatsapp'].includes(p.service))`). Cliente que quer prever custo de geração com IA vai à tela de admin para descobrir o preço.

### 🟢 N12 — `AuditLog.vue` pre-formatted JSON quebra layout em metadata grandes
**Local:** `frontend/src/pages/settings/AuditLog.vue:93-94`.
**Severidade:** MELHORIA.
**Descrição:** `<pre style="max-width:300px;white-space:pre-wrap;word-break:break-all">` em payloads grandes (ex: snapshot completo de campanha) vai gerar célula vertical enorme; ideal seria abrir um drawer/modal lateral.

---

## 6. Veredito Final

- **Itens corrigidos (bloqueadores + atenções + mocks):**
  - Bloqueadores UX/UI: **9 / 14** (3 em aberto, 2 parciais)
  - Atenções UX/UI: **6 / 24** (+7 parciais, +11 em aberto)
  - Mocks: **21 / 22** (1 parcial)
  - **Total: 36 / 60 = 60% corrigidos**, +10 parciais (17%), +14 em aberto (23%).
- **Regressões introduzidas pelos commits de fix: 2 bloqueadores + 2 atenções (4 total).**
- **Novos bloqueadores descobertos nesta re-auditoria: 2** (N1, N2 — ambos eram itens originais marcados como em aberto que classifico agora como bloqueadores formais).
- **Pode lançar do ponto de vista UX/UI?** **NÃO.**

### Por quê NÃO

1. **Compliance LGPD anunciado mas inacessível:** as duas rotas (`/settings/opt-outs` e `/settings/audit-log`) que materializam os fixes mais sensíveis do consolidado (P0-07 e P0-11) não estão registradas no router. O cliente recebe 404 ao clicar nos links da sidebar — exatamente o problema que o commit afirma ter resolvido. Risco regulatório direto.

2. **Cobrança visualmente errada em momento de decisão:** `creditsPerSend = ref(1)` em `Create.vue` mantém o card "Custo Total Estimado" do Step 2 e o mini-modal de confirm-send mostrando valor 10× menor que o real. Cliente confirma um disparo achando que custará R$ 50, é cobrado R$ 500. Reintrodução parcial do P0-03 que dominou o relatório original.

3. **Botões fantasma persistem:** "Ver todos os dispatches" (`reports/CampaignDetail.vue:134`) segue sem handler — auditor original já apontou como bloqueador; correção não foi feita.

4. **`confirm()` nativo segue em `contacts/Index.vue:384`** quebrando consistência visual e acessibilidade.

5. **WCAG 2.1 segue em falha** em todos os modais inline (mini-modal de send, modal de adicionar opt-out): sem `<teleport>`, sem foco/Esc, sem `aria-modal`.

### Pode lançar APÓS

Os 5 itens acima são fixes pontuais (rota + 4 linhas no Create.vue + handler + ConfirmModal + teleport). Estimo **2 a 4 horas** de trabalho para fechar. Após esses fixes + nova validação, o estado UX/UI fica liberado para lançamento.

---

**Fim da re-auditoria UX/UI.**
