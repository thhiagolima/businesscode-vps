# Relatório de Dados Mockados no Frontend — CampaignAI
**Data:** 2026-05-29
**Auditor:** Auditor Frontend Sênior
**Escopo:** `frontend/src` — todas as páginas (`pages/`), componentes (`components/`), stores (`stores/`) e utils.

---

## Sumário Executivo

- **5 placeholders críticos** detectados em telas que o cliente consome como métrica real (3 deles na página Contatos, conforme já apontado pelo proprietário, e 2 adicionais em Plans e VoiceStudio).
- **3 hardcodes financeiros graves** em uma área onde o cliente toma decisão de compra ou de campanha: tabela de preços fixa na tela "Planos", tabela de "créditos por canal" duplicada em DOIS arquivos do fluxo de criação de campanha (não vem da API).
- **Áreas mais afetadas:** `pages/contacts/Index.vue` (KPIs inteiramente fabricados), `pages/settings/Plans.vue` (preços fixos no rodapé), `components/campaigns/ConfirmSendModal.vue` + `Step3Contacts.vue` (tabela de tarifa hard-coded usada no "Custo estimado"), `pages/checkout/CheckoutThankYou.vue` (fallback "Pro"), `pages/settings/Saldo.vue` (telefone de suporte default).
- **Áreas limpas** (puxam tudo da API real): Dashboard (`/dashboard/stats`), Reports/Campaigns, Reports/Credits, Reports/CampaignDetail, Saldo (todos os valores vêm do tenant), painel admin de Billing/Reports, BalanceBanner. Boa cobertura defensiva via `?? 0` quando dado falta.
- **VEREDITO: NÃO PODE LANÇAR no estado atual.** Há cálculos financeiros visíveis ao cliente (custo estimado de disparo, tabela de preços) que vêm de arrays literais no frontend e podem divergir do backend a qualquer momento — risco de cobrar diferente do exibido (PROCON). KPIs da página Contatos enganam o cliente e violam confiança. Correções mínimas demandam 0,5–1 dia de trabalho.

---

## 1. Placeholders CRÍTICOS exibidos ao cliente como métrica real

### 1.1 `frontend/src/pages/contacts/Index.vue:260`
```ts
const activeAutomations = computed(() => 3) // placeholder
```
- **Descrição:** valor literal `3` exibido no card "Automações Ativas" (linha 193 do template).
- **Impacto:** ALTO — cliente acredita que tem 3 automações rodando mesmo numa conta nova vazia. Indicador 100% falso.
- **Correção mínima:** chamar `/funnels?status=active&count=1` ou expor `active_automations_count` no `/dashboard/stats` e remover o literal.

### 1.2 `frontend/src/pages/contacts/Index.vue:261`
```ts
const newContactsThisMonth = computed(() => Math.min(pagination.value.total, 42)) // placeholder
```
- **Descrição:** "Novos Contatos este mês" trava em 42 (Math.min com fake). Para contas <42 mostra o total, dando ilusão de "novos no mês".
- **Impacto:** CRÍTICO — número simula crescimento que não existe.
- **Correção mínima:** endpoint `/contacts/count?created_after=startOfMonth`.

### 1.3 `frontend/src/pages/contacts/Index.vue:255-259` (Taxa de Engajamento)
```ts
const engagementRate = computed(() => {
  if (!contacts.value.length) return 0
  const active = contacts.value.filter(c => c.status === 'active').length
  return Math.round((active / contacts.value.length) * 100)
})
```
- **Descrição:** rótulo no template (linha 187-189) é "Taxa de Engajamento — nos últimos 30 dias", mas a métrica é **(contatos active / total da página atual)**. Não é engajamento; é taxa de status × somente sobre a página corrente (não usa `pagination.total`).
- **Impacto:** ALTO — vende como "engajamento de 30 dias" algo que é só status do contato, ignorando cliques/respostas/aberturas. Confunde compra de plano.
- **Correção mínima:** renomear para "Contatos ativos" ou plugar em endpoint real de engajamento.

### 1.4 `frontend/src/pages/contacts/Index.vue:264-268` (Saúde da Base)
```ts
const dbHealthPct = computed(() => {
  if (!contacts.value.length) return 100
  ...
})
```
- **Descrição:** quando a lista está VAZIA retorna `100%` ("Saúde da Base: 100%, contatos válidos") — sidebar renderiza isso (linha 75) em destaque.
- **Impacto:** ALTO — onboarding do cliente sem contatos vê "100% saudável", entende que está tudo certo e pode disparar campanhas sem público. Quebra confiança e induz erro operacional.
- **Correção mínima:** retornar `0` ou `null` (mostrar "—") quando não há dados; pct deve usar `pagination.total` e não só a página exibida.

### 1.5 `frontend/src/components/campaigns/steps/VoiceStudio.vue:527`
```ts
const creditsEstimate = computed(() => Math.ceil(charCount.value * 0.5))
```
- **Descrição:** estimativa de créditos do "Estúdio de Voz" multiplica caracteres por `0.5` literal. Não bate com a tarifa real configurada no painel admin (`admin/SettingsElevenLabs.vue` expõe `credits_per_char`).
- **Impacto:** ALTO — usuário planeja campanha de voz baseado em estimativa inventada. Vai cobrar diferente do orçado.
- **Correção mínima:** ler `credits_per_char` da configuração via endpoint `/voices/pricing` ou store admin.

---

## 2. Hardcodes em áreas financeiras (cobrança/preço/saldo)

### 2.1 `frontend/src/pages/settings/Plans.vue:77`
```html
<p>SMS R$ 0,10 · Voz R$ 0,30 · WhatsApp R$ 0,40 · IA R$ 0,80 por envio</p>
```
- **Descrição:** rodapé da tela "Planos" exibe tabela de preços **fixa no HTML**.
- **Impacto:** CRÍTICO/REGULATÓRIO — preços por canal são dinâmicos (`/admin/billing/pricing`) e podem ser overridados por tenant (`TenantDetail.vue` tem essa funcionalidade). Cliente lê "R$ 0,10" e é cobrado outro valor → quebra de Código de Defesa do Consumidor.
- **Correção mínima:** buscar `/pricing/public` e renderizar dinamicamente; remover string fixa.

### 2.2 `frontend/src/components/campaigns/ConfirmSendModal.vue:91, 95`
```ts
const unitRate: Record<string, number> = { sms: 1, voice: 5, email: 2, whatsapp: 3 }
const estimatedCost = computed(() => (props.campaign.estimated_contacts ?? 0) * (unitRate[props.campaign.type] ?? 1))
```
- **Descrição:** modal de confirmação de disparo calcula "Custo estimado" multiplicando por uma **tabela de tarifa hard-coded** que não veio da API.
- **Impacto:** CRÍTICO — esse é o valor que o cliente vê antes de clicar "Confirmar Disparo". A linha 34 (`{{ brl(estimatedCost) }}`) e linha 38 ("Saldo após envio") tomam decisão com base nele. Se a tarifa real do backend mudar, modal mente.
- **Correção mínima:** receber `estimated_cost_cents` pronto do endpoint `POST /campaigns/{id}/quote` (já implementado no backend, presumivelmente).

### 2.3 `frontend/src/components/campaigns/steps/Step3Contacts.vue:190, 237`
```ts
const unitRates: Record<string, number> = { sms: 1, voice: 5, email: 2, whatsapp: 3 }
const estimatedCredits = computed(() => totalContacts.value * (unitRates[props.channel] ?? 1))
```
- **Descrição:** **MESMO array duplicado** no passo 3 do wizard de criação, exibindo "créditos estimados" durante a montagem da campanha.
- **Impacto:** CRÍTICO — cliente decide tamanho da campanha com base nesse número. Duplicação garante divergência futura.
- **Correção mínima:** mover constante para `utils/pricing.ts` no curto prazo e, no médio prazo, derivar de endpoint.

### 2.4 `frontend/src/pages/checkout/CheckoutThankYou.vue:47`
```ts
const planName = computed(() => route.query.plan as string || 'Pro')
```
- **Descrição:** se a query string `?plan=` vier ausente, mostra "Bem-vindo ao plano **Pro**!" (linha 9 do template).
- **Impacto:** MÉDIO — usuário que comprou Starter pode chegar via deep-link e ver "Pro". Causa suporte e confusão de cobrança.
- **Correção mínima:** fallback `'Selecionado'` ou ler do tenant após `auth.refreshUser()`.

### 2.5 `frontend/src/pages/settings/Saldo.vue:143`
```ts
const topupAmount = ref(50)
```
- **Descrição:** valor de recarga inicia em R$ 50 e é exibido como sugestão de valor. (mitigado depois por `topupAmount.value = minPurchase.value`).
- **Impacto:** BAIXO — apenas valor inicial, é editável; risco quase nulo. Listado para completude.

### 2.6 `frontend/src/pages/settings/Saldo.vue:181`
```ts
const phone = (import.meta as any).env?.VITE_SUPPORT_WHATSAPP || '5511999999999'
```
- **Descrição:** se a env `VITE_SUPPORT_WHATSAPP` não estiver configurada, botão "Falar com suporte" leva o cliente para um número genérico inválido em deploy.
- **Impacto:** ALTO em ambiente de produção sem env — cliente bloqueado/inadimplente clica e cai em número falso.
- **Correção mínima:** desabilitar botão se a env não estiver definida, em vez de fallback fake.

---

## 3. Dados estáticos em dashboards / KPIs

### 3.1 `frontend/src/pages/contacts/Index.vue:184-201` (linha 254 comentário "KPI computeds (placeholders based on available data)")
- 3 cards de KPI inteiros já cobertos em §1.1–1.4.

### 3.2 `frontend/src/pages/campaigns/Detail.vue:273-278` (estado inicial)
```ts
const kpis = ref([
  { title: 'Total', value: 0 },
  { title: 'Enviados', value: 0 },
  ...
])
```
- **Descrição:** estado inicial OK (todos zerados), mas o fallback no `catch` (linha 340-342) silencia e mantém zeros sem mostrar "—". Cliente vê "0 enviados, 0 entregues" como se a campanha rodasse sem ninguém entregar.
- **Impacto:** MÉDIO — não é placeholder, mas mascara erro de rede como métrica real.
- **Correção mínima:** estado `kpisLoaded` para diferenciar zero-real de erro-de-fetch.

### 3.3 `frontend/src/pages/dashboard/Index.vue:209-214` (canais com performance 0)
```ts
if (total === 0) return [
  { name: 'WhatsApp', percent: 0, color: '#22c55e' },
  ...
]
```
- **Descrição:** quando `by_channel` retorna tudo zero mostra os 4 canais como "0%" em vez de empty-state.
- **Impacto:** BAIXO — apenas UX confusa; não engana métrica.

---

## 4. Strings/textos placeholder visíveis ao usuário

### 4.1 `frontend/src/components/contacts/ImportCsvModal.vue:269` — Template CSV com nomes fakes (`Joao Silva`, `joao@email.com`, `Maria Santos`). **OK** — é template de download legítimo.

### 4.2 `frontend/src/components/campaigns/steps/Step2WhatsApp.vue:136-138` — Preview de template renderiza:
```ts
.replace(/\{nome\}/g, '<span class="badge bg-blue-lt">João Silva</span>')
.replace(/\{telefone\}/g, '<span class="badge bg-green-lt">+5511999999999</span>')
.replace(/\{email\}/g, '<span class="badge bg-purple-lt">joao@email.com</span>')
```
- **Status:** **OK** — é preview de variáveis no template WhatsApp, esperado.

### 4.3 `frontend/src/components/campaigns/PhonePreview.vue:5`
```html
<div class="sender">BusinessCode</div>
```
- **Descrição:** preview de SMS mostra remetente "BusinessCode" hard-coded em vez do `brand_name` do tenant.
- **Impacto:** MÉDIO — white-label quebrado para tenants com identidade própria.
- **Correção mínima:** receber `senderName` como prop ou ler do `identity` store.

### 4.4 `frontend/src/pages/legal/Terms.vue:7,9,15` — Termos com "BusinessCode" e "suporte@businesscode.com.br" hard-coded.
- **Impacto:** MÉDIO — não respeita white-label.

### 4.5 `frontend/src/pages/settings/Plans.vue:78` — `mailto:suporte@businesscode.com.br` hard-coded.

### 4.6 `frontend/src/components/layout/AppLayout.vue:26` — Footer "© 2026 BusinessCode®" hard-coded (não usa brand do tenant).

### 4.7 `frontend/src/components/layout/AppSidebar.vue:9` — Logo "BusinessCode" hard-coded na sidebar.

### 4.8 `frontend/src/pages/auth/Register.vue:143` — `const brandName = ref('BusinessCode')` (mitigado depois pelo `identity` API, linha 191).

### 4.9 `frontend/src/pages/checkout/PricingPlans.vue:5` — Fallback `|| 'BusinessCode'` no `brand_name`.

### 4.10 `frontend/src/pages/legal/Terms.vue:4` — "Última atualização: Março 2026" hard-coded e potencialmente desatualizado.

---

## 5. Fallbacks que mascaram falta de dados

### 5.1 `frontend/src/pages/contacts/Index.vue:265` — `if (!contacts.value.length) return 100` — já em §1.4 (CRÍTICO).

### 5.2 `frontend/src/pages/contacts/Index.vue:261` — `Math.min(pagination.value.total, 42)` — já em §1.2 (CRÍTICO).

### 5.3 `frontend/src/components/campaigns/ConfirmSendModal.vue:95` — `?? 1` no cálculo do custo:
```ts
const estimatedCost = computed(() => (props.campaign.estimated_contacts ?? 0) * (unitRate[props.campaign.type] ?? 1))
```
- **Descrição:** se o canal não estiver no dicionário, multiplica por 1 silenciosamente.
- **Impacto:** ALTO — custo errado para canais novos (ex.: telegram quando adicionado).

### 5.4 `frontend/src/components/campaigns/steps/Step3Contacts.vue:237` — mesma lógica `?? 1`.

### 5.5 `frontend/src/pages/checkout/CheckoutThankYou.vue:47` — `|| 'Pro'` — já em §2.4.

### 5.6 `frontend/src/pages/settings/Saldo.vue:181` — `|| '5511999999999'` — já em §2.6.

### 5.7 `frontend/src/components/campaigns/steps/Step5Review.vue:155-156` — `?? 0` no `creditsPerSend` e no `currentBalance` — ambos placeholders silenciosos do que deveria ser obrigatório.

### 5.8 `frontend/src/funnels/Editor.vue:150` — `Math.random()` para id de nó. **OK** — apenas string de id local, não é métrica.

### 5.9 `frontend/src/components/conversations/MessageBubble.vue:27` — `{{ msg.content || 'Documento' }}` — fallback hard-coded para mensagem multimedia. **OK** — UX legítima.

---

## 6. Componentes que renderizam sem fonte de API

### 6.1 `frontend/src/pages/contacts/Index.vue` — os 3 cards de KPI (linhas 184-201) NÃO fazem chamada de network para calcular essas métricas. Renderizam apenas com a página atual de `/contacts`. JÁ COBERTO em §1.

### 6.2 `frontend/src/pages/settings/Plans.vue:77` — rodapé com preços não chama `/pricing`. JÁ COBERTO em §2.1.

### 6.3 `frontend/src/components/campaigns/ConfirmSendModal.vue` — calcula `estimatedCost` localmente sem `/campaigns/{id}/quote`. JÁ COBERTO em §2.2.

### 6.4 `frontend/src/components/campaigns/steps/VoiceStudio.vue:527` — `creditsEstimate` não chama API. JÁ COBERTO em §1.5.

### 6.5 `frontend/src/components/campaigns/steps/VoiceStudio.vue:531-533` — `durationEstimateFromText` usa fórmula fixa "150 palavras/minuto" sem configuração.
```ts
function durationEstimateFromText(text: string): number {
  return Math.ceil((wordCount(text) / 150) * 60)
}
```
- **Impacto:** BAIXO — duração estimada não vira cobrança direta, mas pode confundir.

### 6.6 `frontend/src/pages/dashboard/Index.vue:168` — `chartPeriod = ref(14)` controla seleção de gráfico mas **NÃO** é enviado para a API (`fetchDashboard()` não recebe parâmetro). O gráfico renderiza sempre os mesmos dados de 30d independentemente do botão clicado. **Bug visual de UX**.

---

## 7. Achados em arquivos auxiliares (stores, composables, utils)

### 7.1 `frontend/src/stores/checkout.ts:64,97,119,141,176` — fallbacks de erro `|| 'Cupom inválido.'` etc. **OK** — mensagens de erro, não dados de cobrança.

### 7.2 `frontend/src/stores/report.ts` — limpo, tudo via API.

### 7.3 `frontend/src/stores/billing.ts` — limpo, tudo via API.

### 7.4 `frontend/src/composables/useTheme.ts:4` — `|| 'dark'` como tema default. **OK** — preferência UI.

### 7.5 `frontend/src/utils/currency.ts:9` — `return 'R$ 0,00'` para nulo. **OK** — formatação defensiva.

### 7.6 **Não há mock-interceptor, fixture ou MSW** registrado no projeto — todos os dados vêm de endpoints reais, *exceto* nos pontos listados acima.

---

## 8. Veredito Final

- **Total de placeholders críticos:** 5 (Contacts §1.1, §1.2, §1.3, §1.4; VoiceStudio §1.5)
- **Total de hardcodes financeiros graves:** 3 (Plans §2.1, ConfirmSendModal §2.2, Step3Contacts §2.3)
- **Total de hardcodes de baixo risco / branding:** 9 (telefone suporte, fallback "Pro", footer, sidebar, Terms, PhonePreview, fórmula de duração, chartPeriod sem efeito, Saldo topupAmount)
- **Total de itens identificados no relatório:** **22**

### Pode lançar? **NÃO.**

**Justificativa:** três grupos de problemas inviabilizam lançamento:

1. **Risco regulatório/financeiro:** preço exibido (`Plans.vue:77`) e custo estimado de disparo (`ConfirmSendModal.vue:91`, `Step3Contacts.vue:190`) saem de literais no frontend. Se o admin alterar preço no painel `/admin/billing/pricing` ou em override de tenant, o cliente vê valor diferente do que é cobrado → exposição a PROCON/ARCO/CDC.
2. **Confiança quebrada:** página Contatos mostra 4 métricas onde 3 são inventadas e 1 mente em estado vazio (100% saúde). Cliente percebe a fraude rapidamente e churn aumenta.
3. **White-label não funciona:** sidebar, footer, PhonePreview, Terms e suporte estão com "BusinessCode" hard-coded, anulando a feature `white_label` anunciada nos planos (`Plans.vue:112`).

### Top 10 priorizado para corrigir ANTES do lançamento

| # | Arquivo:linha | Problema | Esforço |
|---|---|---|---|
| 1 | `pages/settings/Plans.vue:77` | Preços fixos no rodapé | 30 min — chamar `/pricing/public` |
| 2 | `components/campaigns/ConfirmSendModal.vue:91-95` | Custo de disparo calculado local | 1 h — endpoint `quote` |
| 3 | `components/campaigns/steps/Step3Contacts.vue:190-237` | Mesma tabela duplicada | 30 min — após corrigir #2, reutilizar |
| 4 | `pages/contacts/Index.vue:260` | `activeAutomations = 3` placeholder | 30 min — endpoint funnels/count |
| 5 | `pages/contacts/Index.vue:261` | `newContactsThisMonth = min(total, 42)` | 30 min — endpoint contatos filtrado |
| 6 | `pages/contacts/Index.vue:255-259` | Rótulo "Taxa de Engajamento" mentiroso | 10 min — renomear ou plugar API real |
| 7 | `pages/contacts/Index.vue:264-268` | Saúde 100% em base vazia | 5 min — retornar `null`/"—" |
| 8 | `components/campaigns/steps/VoiceStudio.vue:527` | Créditos voz fórmula `* 0.5` | 30 min — endpoint pricing voz |
| 9 | `pages/checkout/CheckoutThankYou.vue:47` | Fallback "Pro" no plano | 5 min — fallback genérico |
| 10 | `pages/settings/Saldo.vue:181` | Telefone de suporte fake | 10 min — desabilitar botão se env vazia |

**Itens adicionais a tratar pós-lançamento (white-label):** §4.3 a §4.10 (sidebar, footer, terms, brand_name fallback).
