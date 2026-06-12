# Landing: correção de pricing + modernização visual

**Data:** 2026-06-05
**Status:** Aprovado (brainstorming)
**Branch:** feat/admin-tenant-drill-down

## Problema

A landing pública (`frontend/public/landing.html`, HTML estático servido pelo Apache em `/`)
apresenta:

1. **NaN nos planos** — o JS lê `plan.credits_included`, mas a API (`PublicPlansController::index`)
   retorna `included_balance_cents` (centavos). `Number(undefined)` → `NaN`.
   Mesmo bug em `frontend/src/pages/checkout/PricingPlans.vue:214`.
2. **Enterprise renderiza "Grátis"** — Enterprise tem `price_monthly = 0`, caindo no ramo `isFree`.
   Deveria mostrar "Sob consulta" + CTA "Falar com vendas".
3. **Plano fantasma** — existe no banco `Personalizado — Parceria Pedro` (slug `custom-pedro`, id 6),
   que aparece como card público indevidamente.
4. **Valor diverge do banco** — a calculadora usa `R$ 0,18/SMS` hardcoded; a tabela `service_prices`
   diz `R$ 0,08/SMS` (sale_cents=8). A seção "Quanto custa cada envio?" usa ainda uma 3ª escala
   ("créditos" 1/1/1/3/4/8). Três fontes de verdade diferentes.
5. **Visual genérico** — hero com background pobre (2 orbs + linhas estáticas), ícones em emoji,
   cards sem polish.

## Decisões (confirmadas com o usuário)

- **Fonte da verdade de preço:** o banco (`service_prices.sale_cents`). Landing puxa ao vivo.
  SMS R$0,08 · Voz R$0,06 · Email R$0,02 · WhatsApp R$0,12 · IA Geração R$0,25.
- **Cobrança/exibição em R$**, não em créditos.
- **Ocultar `custom-pedro`** da landing pública.
- **Manter landing estática** (SEO + first-paint). Modernizar no lugar com CSS/JS vanilla + SVG inline.
  Magic (`21st_magic_component_inspiration`, `logo_search`) usado só como referência visual —
  o builder do Magic gera React e não entra direto na HTML estática.
- **Remover "Chat IA"** da lista de custo por envio (não há `service_price` para chat; não é
  cobrança por envio). Lista final: Email, SMS, Voz, WhatsApp, IA Geração.

## Arquitetura da solução

### Parte A — Backend (API pública)

**A1. Coluna `listed` em `plans`**
- Migration: `boolean listed default true` na tabela `plans`.
- Seeder (`PlansSeeder` ou seeder do custom-pedro): `custom-pedro` recebe `listed = false`.
  Os 5 planos padrão permanecem `listed = true`.
- `PublicPlansController::index` filtra `->where('listed', true)`.
- Razão de ser coluna (vs. whitelist de slug): escala se surgirem outros planos de parceria;
  intenção explícita no dado.

**A2. Endpoint público de tarifas** `GET /api/v1/public/pricing`
- Throttle `60,1` (igual aos demais públicos).
- Novo método em `PublicPlansController` (ou controller dedicado `PublicPricingController`).
- Retorna **apenas `sale_cents`** por canal — **nunca `cost_cents`** (custo/margem é sensível).
- Payload:
  ```json
  {
    "data": [
      { "service": "email",              "label": "Email",       "sale_cents": 2  },
      { "service": "sms",                "label": "SMS",         "sale_cents": 8  },
      { "service": "voice",              "label": "Voz",         "sale_cents": 6  },
      { "service": "whatsapp_marketing", "label": "WhatsApp",    "sale_cents": 12 },
      { "service": "ai_generation",      "label": "IA Geração",  "sale_cents": 25 }
    ]
  }
  ```
- Cache 5 min (como `stats`). Mapa serviço→label fixo no controller (só canais públicos).

### Parte B — Correção de dados (landing.html)

**B1. Saldo do plano (fim do NaN)** — `landing.html:863, 894-895`
- Trocar `Number(plan.credits_included)` por `plan.included_balance_cents / 100`
  formatado em R$ (`formatBrl`). Label: `"R$ X de saldo/mês"` (e `"R$ X de saldo para testar"` no Free).

**B2. Enterprise** — `renderPlans()`
- Detectar `plan.slug === 'enterprise'` **antes** de `isFree`.
- Card especial: valor "Sob consulta", CTA "Falar com vendas" (wa.me já implementado em :872-874).

**B3. custom-pedro** — resolvido em A1 (não vem mais da API). Nenhuma mudança no JS.

**B4. Calculadora** — substituir `R$ 0,18` hardcoded pela tarifa de SMS de `/public/pricing`
(R$ 0,08). Recalcular custo e narrativa com base no valor vindo do banco.

**B5. "Quanto custa cada envio?"** — `#credit-rates` (linhas 657-664)
- Renderizar dinamicamente de `/public/pricing`: cada canal mostra o `sale_cents` em R$.
- Sem "Chat IA" (decisão).

### Parte C — Modernização visual (estática, CSS/JS + SVG inline)

**C1. Hero** (`section.hero` :335, `.hero-bg` :336)
- Background moderno em CSS/JS vanilla: aurora/mesh-gradient animado + grid sutil + glow nos orbs.
- Garantir animações de entrada (`anim d2/d3/d4`).
- Magic como referência de composição; implementação 100% vanilla.

**C2. Ícones SVG inline** — substituir emojis por set coeso (estilo Lucide, traço único):
- Brief-to-Boom: 📝🧠🎙️🚀 (linhas ~397,405,413,421)
- Features: 🧠🎙️📱🤖📊 (linhas ~483-503)
- Custo por envio: ícone por canal.

**C3. Cards** (Brief-to-Boom + features) — borda em gradiente, hover, espaçamento consistente.

## Componentes / unidades

| Unidade | Responsabilidade | Depende de |
|---------|------------------|------------|
| `plans.listed` (coluna) | marcar plano como público | migration |
| `PublicPlansController::index` | listar planos públicos | `plans.listed` |
| `PublicPlansController::pricing` (novo) | tarifas públicas por canal em R$ | `ServicePrice` |
| `landing.html renderPlans()` | render correto de saldo/Enterprise | API plans |
| `landing.html` pricing/calc JS | tarifas em R$ ao vivo | `/public/pricing` |
| CSS/JS hero + SVG icons | visual moderno | — |

## Testes (obrigatório — padrão do projeto: unit + pentest a cada task)

**Backend (PHPUnit feature tests):**
- `/public/pricing` retorna `sale_cents` e **nunca** `cost_cents` para nenhum serviço.
- `/public/pricing` lista apenas os canais públicos definidos (sem whatsapp_utility/auth/audio_tts).
- `/api/v1/plans` **exclui** planos com `listed = false` (custom-pedro ausente).
- `/api/v1/plans` expõe `included_balance_cents` correto por plano.

**Pentest:**
- Rate-limit ativo (`throttle:60,1`) em `/public/pricing` e `/plans`.
- Payload público não vaza `cost_cents`, margem, nem o plano `custom-pedro` por nenhuma rota pública.
- Sem necessidade de auth para endpoints públicos (esperado), mas confirmar que não expõem dados
  de tenant/billing internos.

**Frontend (verificação Playwright):**
- Landing sem "NaN" em nenhum card.
- 5 planos padrão visíveis; custom-pedro ausente; Enterprise como "Sob consulta".
- Saldo e tarifas exibidos em R$ batendo com o banco.

## Fora de escopo

- Migração da landing para Vue (avaliada e descartada — SEO/first-paint).
- `PricingPlans.vue` (área autenticada) tem o mesmo bug `credits_included`; corrigir é desejável,
  mas o foco desta task é a landing pública. Incluir só se barato (mesma troca de campo).
- Reprecificação comercial (preços do banco são tratados como corretos).
