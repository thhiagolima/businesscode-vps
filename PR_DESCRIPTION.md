# fix(ui): Sprint 1 — 8 bugs fechados (banner, role, mobile, plans, filtros, cards, criar)

Auditoria UX/UI feita via Playwright em 29–30/05/2026 (`/ui-ux-pro-max`) cobriu landing → registro → dashboard → contatos → criar campanha → relatórios → saldo/checkout → planos.
Esta PR fecha os **8 blockers do Sprint 1** identificados nos 2 round-trips com o usuário.

**2 commits:**
- `8d20d59f` — primeira rodada (B1, B2, B3, B6, B7)
- `a22daca1` — segunda rodada após revisão visual do usuário (B8, B9, B10)

## Bugs corrigidos

### B1 — `BalanceBanner` cortado pelo sidebar em todas as páginas autenticadas
- **Causa:** o banner era renderizado como irmão root de `<AppLayout>` em `App.vue`, então o sidebar fixo `navbar-vertical` (z-index maior) cobria sua faixa esquerda. Texto começava em "gue para evitar interrupção" em vez de "Saldo crítico: R$ 5,00. Recarre...".
- **Fix:** mover `<BalanceBanner>` para dentro de `AppLayout`, no topo do `page-wrapper`, antes do `AppTopbar`. Agora só ocupa a área à direita do sidebar.
- **Arquivos:** `frontend/src/App.vue`, `frontend/src/components/layout/AppLayout.vue`

### B6 — Topbar mostrava "Acesso Admin" para todo não-superadmin
- **Causa:** `userRole` no `AppTopbar.vue` era hardcoded `role === 'superadmin' ? 'Administrador' : 'Acesso Admin'`. Todo usuário recém-registrado (que vira `admin` do próprio tenant) aparecia como "Acesso Admin".
- **Fix:** mapa `role → label` cobrindo `superadmin/admin/finance/agent/member` com fallback `Usuário`.
- **Arquivo:** `frontend/src/components/layout/AppTopbar.vue`
- **Verificação:** adicionada asserção em `AuthFlowTest::test_register_creates_tenant_records...` garantindo `data.user.role === 'admin'` e `user->role === 'admin'`, blindando contra escalonamento de privilégio no registro.

### B7 — Header do Dashboard quebrava no mobile
- **Causa:** `d-flex align-items-center justify-content-between` forçava título + 4 botões num único row, causando overflow horizontal e título espremido.
- **Fix:** `flex-column flex-md-row` + `gap-3` + `flex-wrap` no grupo de ações. Adicionado também `aria-label` no `<select>` de período e `<label class="visually-hidden">` (a11y P1 do relatório).
- **Arquivo:** `frontend/src/pages/dashboard/Index.vue`

### B2 — `/plans` (público) mostrava "Nenhum plano disponível no momento."
- **Causa:** lista vazia caía no fallback genérico, matando a conversão "Landing → Planos → Checkout".
- **Fix:** empty state contextual com ícone, copy "Planos personalizados sob consulta" e CTA duplo:
  - Anônimo: "Criar conta grátis"
  - Autenticado: "Recarregar saldo"
  - Sempre que `identity.sales_whatsapp` configurado: botão "Falar com Vendas" via WhatsApp
- **Arquivo:** `frontend/src/pages/checkout/PricingPlans.vue`

### B3 — `/settings/plans` (autenticado) também ficava praticamente vazio
- **Causa:** mesmo problema dentro do app — sem `v-else-if="!plans.length"` no template, o `v-for` vazio não renderizava nada.
- **Fix:** empty state explicando o modelo pré-pago + CTA "Recarregar Saldo".
- **Arquivo:** `frontend/src/pages/settings/Plans.vue`

### B8 — Filtro de campanhas com selects ocupando linhas inteiras
- **Causa:** Bootstrap aplica `.form-select { width: 100% }` por padrão. Dentro do `FilterBar` (flex + wrap), cada select preenchia toda a linha e empilhava search/canal/status em 3 linhas.
- **Fix:** Sobrescrever `width: auto` + `flex: 0 1 auto` no `:deep(.form-select)` do FilterBar. Search ganhou `flex: 1 1 220px` para crescer dominante.
- **Arquivo:** `frontend/src/components/ui/FilterBar.vue`

### B9 — Cards de canal pendente/locked com texto fantasma e botão Upgrade fora
- **Causa:** `opacity:0.55` no card raiz + `opacity:0.3` nos elementos internos → **multiplicação** (0.165 final). Texto invisível. Pior, o bloco "Disponível no plano Starter+" + botão Upgrade ficava fora do flex centralizado, parecendo flutuar abaixo do card.
- **Fix:** trocar opacity por `filter: saturate(0.5/0.7)` + cores muted nos elementos. Novo bloco `.sc-card__upgrade` (flex column centralizado) que encaixa o CTA dentro do card. Aplicado a `pending_setup` e `locked`.
- **Arquivo:** `frontend/src/pages/campaigns/SelectChannel.vue`

### B10 — Etapa 1 "Identidade da Campanha" dominava visualmente mesmo após criar
- **Causa:** hero centralizado (ícone 56px + h2 + descrição + input grande) sempre renderizava no Step 1, ocupando toda a tela mesmo quando a campanha já tinha sido criada e o foco devia ir para o Briefing.
- **Fix:** `v-if="!campaignId"` no hero, `v-else` renderiza header compacto inline (badge "01" + título pequeno + input editável com autosave on blur + badge do canal). Briefing vira o foco da etapa.
- **Arquivo:** `frontend/src/pages/campaigns/Create.vue`

## Validação

- ✅ `vite build` passa (16.17s)
- ✅ `php artisan test --filter=test_register_creates_tenant_records_lgpd_consent_and_returns_token` — **1 passed, 9 assertions**
- ✅ Validação visual via Playwright: screenshots `FIX-*.jpeg` na raiz do projeto:
  - `FIX-dashboard-desktop.jpeg` — banner completo, label "Administrador"
  - `FIX-dashboard-mobile.jpeg` — header empilhado, botões com wrap
  - `FIX-plans-public.jpeg` — empty state com CTA
  - `FIX-settings-plans.jpeg` — empty state pré-pago
  - `FIX-campaigns-list.jpeg` — filtros lado a lado (B8)
  - `FIX-select-channel.jpeg` — cards autocontidos (B9)
  - `FIX-create-step1-after.jpeg` — Identidade compacta, Briefing como foco (B10)

## Diff resumido

```
backend/tests/Feature/AuthFlowTest.php           |  5 +++++
frontend/src/App.vue                             |  2 --
frontend/src/components/layout/AppLayout.vue     |  4 ++++
frontend/src/components/layout/AppTopbar.vue     | 10 +++++++++-
frontend/src/components/ui/FilterBar.vue         |  7 ++-
frontend/src/pages/campaigns/Create.vue          | 18 +++++-
frontend/src/pages/campaigns/SelectChannel.vue   | 72 +++++++++++++++-------
frontend/src/pages/checkout/PricingPlans.vue     | 20 ++++++++++++++++++--
frontend/src/pages/dashboard/Index.vue           |  9 +++++----
frontend/src/pages/settings/Plans.vue            | 11 +++++++++++
10 files changed, 124 insertions(+), 34 deletions(-)
```

## Próximos sprints (do mesmo relatório de auditoria)

**Sprint 2 — UX e a11y:**
- Emojis 🤖👤✓ em conversas → ícones SVG tabler
- Date inputs PT-BR (`dd/mm/yyyy`)
- `aria-label` em todos botões icon-only (paginação, dropdown "...", ações de tabela)
- Tabs ARIA em `campaigns/Detail.vue`
- Color-not-only em badges/status

**Sprint 3 — Polimento:**
- `prefers-reduced-motion`
- Virtualizar tabela de contatos (5k+ alvo declarado)
- `100vh` → `100dvh` + `env(safe-area-inset-*)` em conversas
- Breadcrumb em rotas 3+ níveis
- Skeleton/loading em todas tabelas
- Hierarquizar header de `campaigns/Detail.vue` (6 botões → 1 primário + overflow)

Quando quiser, abro PRs separados para Sprint 2 e Sprint 3.
