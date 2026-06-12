# Landing: Pricing Fix + Modernização Visual — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corrigir o pricing da landing pública (NaN, Enterprise "Grátis", plano fantasma, valores divergentes do banco) e modernizar o visual (hero, ícones, cards), mantendo a landing como HTML estático.

**Architecture:** Backend Laravel expõe planos públicos filtrados por nova coluna `plans.listed` e uma nova rota `GET /api/v1/public/pricing` com as tarifas por canal (apenas `sale_cents`, nunca `cost_cents`). A `frontend/public/landing.html` (HTML/CSS/JS vanilla) passa a renderizar saldo e tarifas em R$ ao vivo do banco e ganha hero/ícones/cards modernizados em CSS + SVG inline. Magic é usado só como referência visual.

**Tech Stack:** Laravel 12 / PHP 8.2 / PHPUnit (backend); HTML+CSS+JS vanilla (landing); Playwright (verificação). Magic MCP (`21st_magic_component_inspiration`, `logo_search`) como referência.

**Convenção de commit:** todos os commits terminam com a linha:
`Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
Commitar **apenas** os arquivos de cada task (o working tree tem mudanças não relacionadas — nunca usar `git add -A`).

**Comandos base:**
- Testes backend: `cd backend && php artisan test --filter <NomeDoTeste>`
- DB local (verificação): `cd backend && php artisan tinker --execute="..."`

---

## File Structure

| Arquivo | Responsabilidade | Ação |
|---------|------------------|------|
| `backend/database/migrations/2026_06_05_000001_add_listed_to_plans.php` | adicionar coluna `listed` + ocultar custom-pedro existente | Criar |
| `backend/app/Http/Controllers/API/V1/PublicPlansController.php` | filtrar `listed`, novo método `pricing()` | Modificar |
| `backend/routes/api.php` | registrar `GET /public/pricing` | Modificar |
| `backend/tests/Feature/PublicPlansTest.php` | testes de `listed` + `pricing` | Modificar |
| `backend/tests/Feature/Security/PublicRoutesWhitelistTest.php` | whitelist da nova rota | Modificar |
| `frontend/public/landing.html` | render saldo/Enterprise em R$, tarifas ao vivo, hero/ícones/cards | Modificar |

---

## Task 1: Coluna `plans.listed` + filtro no endpoint público

**Files:**
- Create: `backend/database/migrations/2026_06_05_000001_add_listed_to_plans.php`
- Modify: `backend/app/Http/Controllers/API/V1/PublicPlansController.php:17`
- Test: `backend/tests/Feature/PublicPlansTest.php`

- [ ] **Step 1: Escrever o teste que falha (planos não-listados são excluídos)**

Adicionar em `backend/tests/Feature/PublicPlansTest.php` (dentro da classe):

```php
    public function test_index_excludes_unlisted_plans(): void
    {
        Plan::factory()->create(['slug' => 'starter', 'price_monthly' => 89.00, 'listed' => true]);
        Plan::factory()->create(['slug' => 'custom-pedro', 'price_monthly' => 0.00, 'listed' => false]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertStatus(200);
        $slugs = collect($response->json('data'))->pluck('slug')->all();
        $this->assertContains('starter', $slugs);
        $this->assertNotContains('custom-pedro', $slugs);
    }
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `cd backend && php artisan test --filter test_index_excludes_unlisted_plans`
Expected: FAIL — coluna `listed` não existe (SQL error) ou custom-pedro aparece no payload.

- [ ] **Step 3: Criar a migration**

Criar `backend/database/migrations/2026_06_05_000001_add_listed_to_plans.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('listed')->default(true)->after('slug');
        });

        // Plano de parceria criado direto no banco (id 6) não deve aparecer na landing.
        DB::table('plans')->where('slug', 'custom-pedro')->update(['listed' => false]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('listed');
        });
    }
};
```

- [ ] **Step 4: Adicionar `listed` ao model Plan (fillable/casts)**

Em `backend/app/Models/Plan.php`, adicionar `'listed'` ao array `$fillable` e `'listed' => 'boolean'` ao array `$casts` (seguir o estilo existente do arquivo).

- [ ] **Step 5: Filtrar no controller**

Em `backend/app/Http/Controllers/API/V1/PublicPlansController.php:17`, trocar:

```php
        $plans = Plan::orderBy('price_monthly')->get()->map(function (Plan $plan) {
```

por:

```php
        $plans = Plan::where('listed', true)->orderBy('price_monthly')->get()->map(function (Plan $plan) {
```

- [ ] **Step 6: Rodar a migration e o teste**

Run: `cd backend && php artisan migrate && php artisan test --filter test_index_excludes_unlisted_plans`
Expected: PASS. Rodar também `php artisan test --filter PublicPlansTest` (os 3 testes antigos seguem verdes).

- [ ] **Step 7: Verificar dado real (custom-pedro oculto)**

Run: `cd backend && php artisan tinker --execute="echo \App\Models\Plan::where('listed',true)->pluck('slug');"`
Expected: lista com free, starter, pro, business, enterprise — **sem** custom-pedro.

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_06_05_000001_add_listed_to_plans.php backend/app/Models/Plan.php "backend/app/Http/Controllers/API/V1/PublicPlansController.php" backend/tests/Feature/PublicPlansTest.php
git commit -m "feat(plans): coluna listed oculta planos não-públicos da landing

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Endpoint público de tarifas `GET /api/v1/public/pricing`

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/PublicPlansController.php`
- Modify: `backend/routes/api.php:116`
- Test: `backend/tests/Feature/PublicPlansTest.php`
- Test: `backend/tests/Feature/Security/PublicRoutesWhitelistTest.php:29`

- [ ] **Step 1: Escrever os testes que falham**

Adicionar em `backend/tests/Feature/PublicPlansTest.php`:

```php
    public function test_pricing_endpoint_returns_sale_prices_in_brl(): void
    {
        \App\Models\ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8]);
        \App\Models\ServicePrice::create(['service' => 'email', 'cost_cents' => 0, 'sale_cents' => 2]);

        $response = $this->getJson('/api/v1/public/pricing');

        $response->assertStatus(200);
        $sms = collect($response->json('data'))->firstWhere('service', 'sms');
        $this->assertSame(8, $sms['sale_cents']);
        $this->assertSame('SMS', $sms['label']);
    }

    public function test_pricing_endpoint_never_leaks_cost_cents(): void
    {
        \App\Models\ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8]);

        $response = $this->getJson('/api/v1/public/pricing');

        $response->assertStatus(200);
        foreach ($response->json('data') as $row) {
            $this->assertArrayNotHasKey('cost_cents', $row);
            $this->assertArrayNotHasKey('cost_micros', $row);
        }
    }

    public function test_pricing_endpoint_only_exposes_public_channels(): void
    {
        \App\Models\ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8]);
        \App\Models\ServicePrice::create(['service' => 'whatsapp_utility', 'cost_cents' => 4, 'sale_cents' => 8]);

        $response = $this->getJson('/api/v1/public/pricing');

        $services = collect($response->json('data'))->pluck('service')->all();
        $this->assertContains('sms', $services);
        $this->assertNotContains('whatsapp_utility', $services);
    }
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter test_pricing_endpoint`
Expected: FAIL — rota `/api/v1/public/pricing` não existe (404).

- [ ] **Step 3: Implementar o método `pricing()` no controller**

Em `backend/app/Http/Controllers/API/V1/PublicPlansController.php`, adicionar o `use` no topo (junto aos outros) e o método (após `stats()`):

```php
use App\Models\ServicePrice;
```

```php
    /**
     * Tarifas públicas por canal, em R$ (sale_cents apenas).
     * NUNCA expõe cost_cents/margem. Cacheado 5 min.
     */
    public function pricing(): JsonResponse
    {
        // service => label exibido na landing. Só canais públicos por envio.
        $publicChannels = [
            'email'              => 'Email',
            'sms'                => 'SMS',
            'voice'              => 'Voz',
            'whatsapp_marketing' => 'WhatsApp',
            'ai_generation'      => 'IA Geração',
        ];

        $data = Cache::remember('public.pricing.v1', 300, function () use ($publicChannels) {
            $prices = ServicePrice::whereIn('service', array_keys($publicChannels))
                ->get()
                ->keyBy('service');

            $rows = [];
            foreach ($publicChannels as $service => $label) {
                if (! isset($prices[$service])) {
                    continue;
                }
                $rows[] = [
                    'service'    => $service,
                    'label'      => $label,
                    'sale_cents' => (int) $prices[$service]->sale_cents,
                ];
            }
            return $rows;
        });

        return response()->json(['data' => $data]);
    }
```

- [ ] **Step 4: Registrar a rota**

Em `backend/routes/api.php`, após a linha 116 (`/public/stats`), adicionar:

```php
    // Public per-channel pricing in BRL (sale prices only — used by landing calculator)
    Route::get('/public/pricing', [PublicPlansController::class, 'pricing'])->middleware('throttle:60,1');
```

- [ ] **Step 5: Atualizar a whitelist de segurança**

Em `backend/tests/Feature/Security/PublicRoutesWhitelistTest.php:27`, adicionar à array `$whitelist` (após `'GET api/v1/public/stats'`):

```php
            'GET api/v1/public/pricing',
```

- [ ] **Step 6: Rodar todos os testes afetados**

Run: `cd backend && php artisan test --filter "PublicPlansTest|PublicRoutesWhitelistTest"`
Expected: PASS (todos, incluindo o whitelist de rotas públicas).

- [ ] **Step 7: Verificar payload real**

Run: `cd backend && php artisan tinker --execute="\$r=app()->call('App\\Http\\Controllers\\API\\V1\\PublicPlansController@pricing'); echo \$r->getContent();"`
Expected: JSON com email/sms/voz/whatsapp/ia geração, cada um com `sale_cents`, **sem** `cost_cents`.

- [ ] **Step 8: Commit**

```bash
git add "backend/app/Http/Controllers/API/V1/PublicPlansController.php" backend/routes/api.php backend/tests/Feature/PublicPlansTest.php backend/tests/Feature/Security/PublicRoutesWhitelistTest.php
git commit -m "feat(api): endpoint público de tarifas por canal em R$ (sale_cents only)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Landing — saldo em R$ (fim do NaN) + card Enterprise

**Files:**
- Modify: `frontend/public/landing.html` (função `renderPlans`, linhas ~853-901)

Nota: a landing é HTML estático; não há harness de unit test. A verificação é via Playwright na Task 7. Cada mudança aqui é validável abrindo a página.

- [ ] **Step 1: Detectar Enterprise antes de `isFree`**

Em `frontend/public/landing.html:855`, logo após `var isFree = priceMonthly === 0;`, adicionar:

```javascript
      var isEnterprise = plan.slug === 'enterprise';
```

- [ ] **Step 2: Corrigir a feature de saldo (linha 863)**

Trocar a linha 863:

```javascript
      li.push('<li><span class="ck">✓</span>' + Number(plan.credits_included).toLocaleString('pt-BR') + ' créditos/mês</li>');
```

por:

```javascript
      var saldoBrl = 'R$ ' + (Number(plan.included_balance_cents || 0) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
      if (!isEnterprise) li.push('<li><span class="ck">✓</span>' + saldoBrl + ' de saldo/mês</li>');
```

- [ ] **Step 3: Corrigir o bloco de valor/saldo do card (linhas 885-899)**

Substituir o bloco que monta `var card = ...` (linhas 885-899) por:

```javascript
      var valBlock = isEnterprise
        ? '<div class="price-val"><span class="num">Sob consulta</span></div>'
        : '<div class="price-val">' +
            (isFree
              ? '<span class="num">Grátis</span>'
              : '<span class="cur">R$</span><span class="num">' + formatBrl(monthlyShown) + '</span><span class="per">/mês</span>') +
          '</div>';

      var crBlock = isEnterprise
        ? '<div class="price-cr">Saldo e limites customizados</div>'
        : (isFree
            ? '<div class="price-cr">' + saldoBrl + ' de saldo para testar</div>'
            : '<div class="price-cr">' + saldoBrl + ' de saldo/mês</div>');

      var card = '' +
        '<div class="price' + (isPop ? ' pop' : '') + '">' +
          '<div class="price-name">' + plan.name + '</div>' +
          valBlock +
          crBlock +
          annualNote +
          '<ul>' + li.join('') + '</ul>' +
          '<a href="' + ctaHref + '" class="cta ' + (isFree ? 'cta-ghost' : 'cta-main') + '">' + ctaLabel + '</a>' +
        '</div>';
```

- [ ] **Step 4: Garantir CTA correto do Enterprise**

Confirmar que o bloco `ctaHref/ctaLabel` (linhas 869-878) já trata `plan.slug === 'enterprise'` com "Falar com vendas". Como Enterprise agora não é mais tratado como `isFree` para preço, ajustar a condição da linha 871-872: trocar

```javascript
      if (isFree) { ctaHref = '/register'; ctaLabel = 'Criar conta grátis'; }
```

por

```javascript
      if (isEnterprise) {
        if (state.identity && state.identity.sales_whatsapp) {
          ctaHref = 'https://wa.me/' + state.identity.sales_whatsapp.replace(/\D/g, '') + '?text=' + encodeURIComponent(state.identity.sales_whatsapp_prompt || '');
        } else {
          ctaHref = '#pricing';
        }
        ctaLabel = 'Falar com vendas';
      } else if (isFree) { ctaHref = '/register'; ctaLabel = 'Criar conta grátis'; }
```

E remover o `else if (plan.slug === 'enterprise' ...)` duplicado (linhas 872-874), deixando só o `else` final do checkout.

- [ ] **Step 5: Verificação rápida manual**

Abrir a landing local no navegador (ver Task 7 para o comando do servidor). Conferir: nenhum "NaN", saldo em R$ por plano, Enterprise mostra "Sob consulta" + "Falar com vendas".

- [ ] **Step 6: Commit**

```bash
git add frontend/public/landing.html
git commit -m "fix(landing): saldo em R\$ (fim do NaN) e card Enterprise sob consulta

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Landing — tarifas ao vivo (custo por envio + calculadora)

**Files:**
- Modify: `frontend/public/landing.html` (state, boot, credit-rates, setupCalc)

- [ ] **Step 1: Adicionar `pricing` ao state**

Em `frontend/public/landing.html:790`, trocar:

```javascript
  var state = { plans: [], identity: null, stats: null, cycle: 'monthly', annualDiscount: 20 };
```

por:

```javascript
  var state = { plans: [], identity: null, stats: null, pricing: [], cycle: 'monthly', annualDiscount: 20 };
```

- [ ] **Step 2: Buscar `/public/pricing` no boot**

Em `frontend/public/landing.html:940-949`, trocar o `Promise.all` por:

```javascript
    Promise.all([
      fetchJson('/api/v1/public/identity'),
      fetchJson('/api/v1/public/stats'),
      fetchJson('/api/v1/plans'),
      fetchJson('/api/v1/public/pricing')
    ]).then(function (results) {
      state.identity = results[0];
      state.stats = results[1];
      state.pricing = (results[3] && results[3].data) ? results[3].data : [];
      applyIdentity(state.identity);
      applyStats(state.stats);
      applyPlans(results[2]);
      applyPricing(state.pricing);
    });
```

- [ ] **Step 3: Renderizar `#credit-rates` em R$ (função nova)**

Adicionar antes de `function boot()` uma função `applyPricing`:

```javascript
  function applyPricing(rates) {
    var row = document.getElementById('credit-rates');
    if (!row || !rates || !rates.length) return;
    row.innerHTML = rates.map(function (r) {
      var brl = 'R$ ' + (Number(r.sale_cents) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      return '<div class="cr"><div class="v">' + brl + '</div><div class="l">' + r.label + '</div></div>';
    }).join('');
    // Atualiza a calculadora com a tarifa real de SMS
    var sms = rates.filter(function (r) { return r.service === 'sms'; })[0];
    if (sms) { window.__smsRate = Number(sms.sale_cents) / 100; recalcCalc(); }
  }
```

- [ ] **Step 4: Tornar a calculadora data-driven**

Em `frontend/public/landing.html`, dentro de `setupCalc` (linha 1056), a função `recalc` usa `n * 0.18` hardcoded. Refatorar para usar a tarifa global e expor `recalcCalc`:

Trocar a linha 1077:

```javascript
      var c = n * 0.18;
```

por:

```javascript
      var rate = (typeof window.__smsRate === 'number' && window.__smsRate > 0) ? window.__smsRate : 0.08;
      var c = n * rate;
      var row1 = document.querySelector('.calc-result .row1');
      if (row1) row1.textContent = 'A R$ ' + rate.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' por SMS, sua próxima campanha custa';
```

E logo após a definição de `recalc` (antes de `input.addEventListener`), expor:

```javascript
    window.recalcCalc = recalc;
```

- [ ] **Step 5: Corrigir narrativa e textos estáticos divergentes**

Em `frontend/public/landing.html:1072`, trocar o divisor obsoleto `297` pelo preço atual do Pro (R$ 219):

```javascript
      if (n <= 15000) return 'Essa campanha sozinha paga ' + Math.floor(c / 219) + ' meses do Pro. E você ainda tem disparos sobrando inclusos no plano.';
```

Em `frontend/public/landing.html:696`, trocar o texto do rodapé que cita preços de crédito divergentes:

```html
    <p class="calc-foot">Sem assinatura recorrente — você usa o saldo em R$ do seu plano. Sobrou saldo? Vira disparo. O preço por canal acima é o que sai do seu saldo.</p>
```

(O texto estático da linha 692 será sobrescrito em runtime pela Step 4; deixar como está.)

- [ ] **Step 6: Verificação manual**

Abrir a landing: a seção "Quanto custa cada envio?" mostra R$ por canal (SMS R$ 0,08 etc.) e a calculadora usa R$ 0,08/SMS (10.000 contatos → R$ 800).

- [ ] **Step 7: Commit**

```bash
git add frontend/public/landing.html
git commit -m "fix(landing): tarifas e calculadora puxam R\$ ao vivo do banco

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Modernização visual — hero + set de ícones SVG inline

**Files:**
- Modify: `frontend/public/landing.html` (CSS do `.hero-bg`, markup dos ícones)

- [ ] **Step 1: Referência visual via Magic (opcional, só inspiração)**

Carregar os schemas: `ToolSearch` com query `select:mcp__magic__21st_magic_component_inspiration,mcp__magic__logo_search`.
Chamar `21st_magic_component_inspiration` com pedido de "modern dark SaaS hero with animated aurora/gradient mesh background". Usar **apenas como referência** — implementação será CSS vanilla (não colar React). Usar `logo_search` para obter o SVG do WhatsApp (canal). Se a ferramenta falhar/indisponível, seguir com o set abaixo.

- [ ] **Step 2: Definir o set canônico de ícones SVG (estilo Lucide, 24x24, stroke)**

Padrão de cada ícone (substituir `PATHS`):
`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">PATHS</svg>`

Mapa de `PATHS` por ícone:

- **edit** (Brief/"Você fala"): `<path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.4 2.6a2 2 0 0 1 3 3L12 15l-4 1 1-4z"/>`
- **sparkles** (Brain/IA): `<path d="M11.5 2.5 13 8l5.5 1.5L13 11l-1.5 5.5L10 11 4.5 9.5 10 8z"/><path d="M19 4v3"/><path d="M20.5 5.5h-3"/><path d="M5 17v2"/><path d="M6 18H4"/>`
- **mic** (Voice/Voz): `<rect x="9" y="2" width="6" height="13" rx="3"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><path d="M12 19v3"/>`
- **rocket** (Boom): `<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/>`
- **messages** (WhatsApp+SMS+Email): `<path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/>`
- **bot** (Chatbot): `<path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/>`
- **chart** (Dashboard): `<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>`
- **mail** (Email/custo): `<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>`
- **message** (SMS/custo): `<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>`
- **phone** (Voz/custo): `<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.97.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.84.57 2.81.7A2 2 0 0 1 22 16.92z"/>`

WhatsApp (custo): usar o SVG retornado pelo `logo_search`; fallback = ícone **messages** acima.

- [ ] **Step 3: Substituir emojis do Brief-to-Boom (linhas ~397,405,413,421)**

Trocar o conteúdo `📝`/`🧠`/`🎙️`/`🚀` de cada `<div class="icon" ...>` pelos SVGs **edit / sparkles / mic / rocket** respectivamente (manter os `style` de cor/background existentes; o `aria-hidden="true"` continua).

Exemplo (linha 397):

```html
      <div class="icon" style="background:rgba(0,100,255,.08);color:var(--blue)" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.4 2.6a2 2 0 0 1 3 3L12 15l-4 1 1-4z"/></svg></div>
```

Garantir no CSS de `.mech-step .icon` que o SVG tenha tamanho (adicionar regra, ver Step 6): `width:24px;height:24px`.

- [ ] **Step 4: Substituir emojis das features (linhas ~483,488,493,498,503)**

Trocar `🧠`/`🎙️`/`📱`/`🤖`/`📊` pelos SVGs **sparkles / mic / messages / bot / chart** respectivamente, dentro de cada `<div class="bento-icon" ...>` (manter `style` existentes).

- [ ] **Step 5: Ícones por canal no custo por envio**

A renderização de `#credit-rates` agora é dinâmica (Task 4 Step 3). Atualizar `applyPricing` para incluir o ícone por `service`. Trocar o `return` dentro do `.map` por:

```javascript
      var icons = {
        email: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
        sms: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        voice: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.97.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.84.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        whatsapp_marketing: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2z"/><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1"/></svg>',
        ai_generation: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.5 2.5 13 8l5.5 1.5L13 11l-1.5 5.5L10 11 4.5 9.5 10 8z"/><path d="M19 4v3"/><path d="M20.5 5.5h-3"/></svg>'
      };
      var brl = 'R$ ' + (Number(r.sale_cents) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      return '<div class="cr"><div class="cr-ic">' + (icons[r.service] || '') + '</div><div class="v">' + brl + '</div><div class="l">' + r.label + '</div></div>';
```

- [ ] **Step 6: Hero background animado + estilos de ícone (CSS)**

No `<style>` do `<head>` da landing, adicionar (no fim do bloco de estilos):

```css
.hero-bg{position:absolute;inset:0;overflow:hidden;z-index:0}
.hero-bg .orb{position:absolute;border-radius:50%;filter:blur(80px);opacity:.5;animation:floatOrb 18s ease-in-out infinite}
.hero-bg .o1{width:520px;height:520px;background:radial-gradient(circle,#0064ff,transparent 70%);top:-120px;left:-80px}
.hero-bg .o2{width:460px;height:460px;background:radial-gradient(circle,#8b5cf6,transparent 70%);bottom:-140px;right:-60px;animation-delay:-9s}
.hero-bg::before{content:"";position:absolute;inset:0;background:
  radial-gradient(60% 50% at 50% 0%, rgba(0,100,255,.18), transparent 60%),
  conic-gradient(from 180deg at 50% 50%, rgba(139,92,246,.06), rgba(0,100,255,.06), rgba(13,204,106,.05), rgba(139,92,246,.06));
  animation:auroraShift 24s linear infinite}
.hero-bg .lines{position:absolute;inset:0;background-image:
  linear-gradient(rgba(255,255,255,.04) 1px,transparent 1px),
  linear-gradient(90deg,rgba(255,255,255,.04) 1px,transparent 1px);
  background-size:48px 48px;mask-image:radial-gradient(circle at 50% 0%,#000,transparent 70%)}
@keyframes floatOrb{0%,100%{transform:translate(0,0)}50%{transform:translate(40px,30px)}}
@keyframes auroraShift{0%{transform:rotate(0deg) scale(1.1)}100%{transform:rotate(360deg) scale(1.1)}}
.hero-inner{position:relative;z-index:1}
.anim{opacity:0;transform:translateY(16px);animation:rise .7s cubic-bezier(.2,.7,.2,1) forwards}
.anim.d1{animation-delay:.08s}.anim.d2{animation-delay:.18s}.anim.d3{animation-delay:.3s}.anim.d4{animation-delay:.42s}
@keyframes rise{to{opacity:1;transform:translateY(0)}}
.mech-step .icon svg,.bento-icon svg{width:24px;height:24px}
.cr .cr-ic{display:flex;justify-content:center;margin-bottom:6px;color:var(--blue)}
.cr .cr-ic svg{width:22px;height:22px}
@media (prefers-reduced-motion:reduce){.hero-bg .orb,.hero-bg::before{animation:none}.anim{animation:none;opacity:1;transform:none}}
```

Nota: se já existir uma regra `.anim`/`@keyframes rise` no CSS atual, **não duplicar** — ajustar a existente para bater com os delays acima.

- [ ] **Step 7: Verificação manual**

Abrir a landing: hero com aurora/grid animados; todos os ícones são SVG (sem emoji); ícones por canal no custo por envio. Conferir `prefers-reduced-motion` (sem animação quando ativo).

- [ ] **Step 8: Commit**

```bash
git add frontend/public/landing.html
git commit -m "feat(landing): hero animado + ícones SVG inline (Magic como referência)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Modernização visual — cards (Brief-to-Boom + bento)

**Files:**
- Modify: `frontend/public/landing.html` (CSS de `.mech-step` e `.bento`)

- [ ] **Step 1: Polir os cards (CSS)**

No `<style>`, adicionar (sem remover regras existentes — sobrescrever no fim):

```css
.mech-step,.bento{position:relative;transition:transform .25s ease,border-color .25s ease,box-shadow .25s ease}
.mech-step::before,.bento::before{content:"";position:absolute;inset:0;border-radius:inherit;padding:1px;
  background:linear-gradient(135deg,rgba(0,100,255,.35),rgba(139,92,246,.18),transparent 60%);
  -webkit-mask:linear-gradient(#000 0 0) content-box,linear-gradient(#000 0 0);
  -webkit-mask-composite:xor;mask-composite:exclude;opacity:0;transition:opacity .25s ease;pointer-events:none}
.mech-step:hover,.bento:hover{transform:translateY(-4px);box-shadow:0 18px 40px -20px rgba(0,100,255,.45)}
.mech-step:hover::before,.bento:hover::before{opacity:1}
.mech-step .icon,.bento-icon{transition:transform .25s ease}
.mech-step:hover .icon,.bento:hover .bento-icon{transform:scale(1.08)}
```

- [ ] **Step 2: Verificação manual**

Abrir a landing: cards com hover (borda gradiente, leve elevação) consistentes entre Brief-to-Boom e bento.

- [ ] **Step 3: Commit**

```bash
git add frontend/public/landing.html
git commit -m "feat(landing): polish nos cards (borda gradiente + hover)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Verificação end-to-end (Playwright) + pentest

**Files:** nenhum (verificação). Serve a landing localmente e valida.

Pré-requisito: subir backend + servir a landing. Em dois terminais:
- `cd backend && php artisan serve` (API em :8000)
- Servir `frontend/public/` (ex.: `cd frontend/public && php -S localhost:8080`), garantindo que `/api/v1/*` resolva para :8000 — se não houver proxy, testar a landing apontando os fetch para a API real do ambiente, ou rodar o seeder e usar o host Apache existente que já serve `landing.html` + API juntos.

- [ ] **Step 1: Seed do banco (dados reais)**

Run: `cd backend && php artisan migrate --force && php artisan db:seed --class=PlansSeeder && php artisan db:seed --class=ServicePriceSeeder` (usar o seeder de service prices existente; se o nome diferir, rodar `php artisan db:seed`).
Expected: planos + service_prices populados.

- [ ] **Step 2: Playwright — sem NaN e planos corretos**

Carregar schemas: `ToolSearch` query `select:mcp__plugin_playwright_playwright__browser_navigate,mcp__plugin_playwright_playwright__browser_snapshot,mcp__plugin_playwright_playwright__browser_evaluate`.
Navegar até a landing. Rodar `browser_evaluate` com:

```js
() => ({
  nan: document.body.innerText.includes('NaN'),
  planNames: [...document.querySelectorAll('#pricing-grid .price-name')].map(e => e.textContent),
  enterprise: document.body.innerText.includes('Sob consulta'),
  hasPedro: document.body.innerText.includes('Parceria Pedro'),
  creditRates: [...document.querySelectorAll('#credit-rates .v')].map(e => e.textContent)
})
```

Expected: `nan:false`; `planNames` contém Grátis/Starter/Pro/Business/Enterprise; `enterprise:true`; `hasPedro:false`; `creditRates` em R$ (ex.: "R$ 0,08").

- [ ] **Step 3: Playwright — calculadora bate com o banco**

`browser_evaluate`:

```js
() => { const i=document.getElementById('calc-contacts'); i.value='10000'; i.dispatchEvent(new Event('input')); return document.getElementById('calc-cost').textContent; }
```

Expected: `R$ 800` (10.000 × R$ 0,08).

- [ ] **Step 4: Pentest — payload público não vaza custo/margem nem custom-pedro**

Run:
```bash
curl -s http://localhost:8000/api/v1/public/pricing
curl -s http://localhost:8000/api/v1/plans
```
Expected: `/public/pricing` sem `cost_cents`/`cost_micros`/`margin`; `/plans` sem o slug `custom-pedro`.

- [ ] **Step 5: Pentest — rate limit ativo**

Run: `for i in $(seq 1 65); do curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8000/api/v1/public/pricing; done | tail -5`
Expected: aparecem respostas `429` após ~60 reqs/min (throttle:60,1).

- [ ] **Step 6: Rodar a suíte de segurança e de planos completa**

Run: `cd backend && php artisan test --filter "PublicPlansTest|PublicRoutesWhitelistTest|Security"`
Expected: PASS.

- [ ] **Step 7: Commit (se houver ajustes de verificação)**

Se nada mudou no código, pular. Caso contrário, commitar os ajustes.

---

## Self-Review (autor)

**Cobertura do spec:**
- A1 coluna `listed` → Task 1 ✅
- A2 endpoint `/public/pricing` (sale_cents only) → Task 2 ✅
- B1 saldo R$ / NaN → Task 3 ✅
- B2 Enterprise sob consulta → Task 3 ✅
- B3 custom-pedro oculto → Task 1 (filtro) + Task 7 (pentest) ✅
- B4 calculadora ao vivo → Task 4 ✅
- B5 custo por envio em R$ (sem Chat IA) → Task 4 ✅
- C1 hero animado → Task 5 ✅
- C2 ícones SVG → Task 5 ✅
- C3 cards → Task 6 ✅
- D testes backend + pentest + Playwright → Tasks 1,2,7 ✅

**Placeholders:** nenhum — todo passo tem código/comando concreto.
**Consistência de tipos:** `included_balance_cents`, `sale_cents`, `service`, `label`, `listed`, `window.__smsRate`, `recalcCalc`, `applyPricing` usados de forma consistente entre tasks.

> **Fora de escopo (registrado):** `PricingPlans.vue` (área autenticada) tem o mesmo bug `credits_included`; não incluído aqui. Migração da landing para Vue descartada (SEO).
