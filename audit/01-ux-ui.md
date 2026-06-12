# Auditoria UX/UI — BusinessCode / CampaignAI

**Escopo:** fluxos, fricção, consistência visual, acessibilidade, mobile.
**Método:** leitura de `frontend/src/pages/**`, `frontend/public/landing.html`, `frontend/src/router/index.ts`, simulação dos fluxos landing → register → dashboard → campaign wizard → checkout.

---

## Bugs e falhas

### UX-BUG-01 — Página `/plans` (SPA) mostra pricing vazio para visitante anônimo
**Descrição:** `PricingPlans.vue` faz `axios.get('/api/v1/plans')` ao montar. Essa rota está dentro do grupo `auth:sanctum` em `backend/routes/api.php:70-149`, ou seja, retorna 401 para quem não está logado. O visitante que clica em "Planos" no menu SPA recebe a tela vazia com "Plano não encontrado".
**Evidência:** `frontend/src/pages/checkout/PricingPlans.vue:119` (`axios.get('/api/v1/plans')`) vs `backend/routes/api.php:149`.
**Severidade:** crítico
**Esforço:** S (mover a rota para o bloco público ou criar `GET /api/v1/public/plans`)
**Bloqueador de launch:** sim

### UX-BUG-02 — Toggle "Anual -20%" é cosmético, backend ignora
**Descrição:** em `PricingPlans.vue` o toggle "Mensal/Anual" só divide o preço mostrado por 1,25 (`displayPrice() = price * 0.8`). O link de checkout usa o mesmo `plan.slug` e o backend `MercadoPagoService::createSubscription` cria `preapproval` com `frequency_type: months` e `transaction_amount = $plan->price_monthly` cheio. O cliente vê R$237,60/mês no anual, clica "Escolher plano", e é cobrado R$297/mês mensal.
**Evidência:** `frontend/src/pages/checkout/PricingPlans.vue:129-133` + `backend/app/Services/MercadoPagoService.php:28-57`.
**Severidade:** crítico (risco de chargeback e ação do CDC)
**Esforço:** M (adicionar `billing_cycle` ao plano, passar no checkout, ajustar MP preapproval frequency)
**Bloqueador de launch:** sim — ou **remover o toggle da UI** até implementar.

### UX-BUG-03 — Register sem checkbox de aceite de Termos/LGPD
**Descrição:** `/register` captura nome, email, senha e submete. Não existe checkbox "Li e concordo com os Termos e a Política de Privacidade". Existem páginas `legal/Terms.vue` e `legal/Privacy.vue` mas nenhum link no formulário. LGPD exige consentimento explícito e registrado.
**Evidência:** `frontend/src/pages/auth/Register.vue:44-90` (form sem consent) + `backend/app/Http/Controllers/API/V1/AuthController.php:14-62` (não grava consent).
**Severidade:** crítico
**Esforço:** S (checkbox obrigatório + coluna `users.consented_at` + IP no AuditLog)
**Bloqueador de launch:** sim

### UX-BUG-04 — Sem indicador de força de senha; mensagem de erro aparece só no submit
**Descrição:** registro exige 8+ chars, maiúsculas+minúsculas, números (`Password::min(8)->mixedCase()->numbers()`). A tela só avisa "Mínimo 8 caracteres, com letras maiúsculas e números" em microcopy. Usuário digita "12345678", dá submit, toma 422, e o toast mostra "The password field confirmation does not match" ou genérico. Validação server-side joga em toast, sem mapear ao campo.
**Evidência:** `Register.vue:65-67` + `Register.vue:135-138` (toast genérico).
**Severidade:** médio
**Esforço:** S (barra visual + exibir erros por campo em `invalid-feedback`)
**Bloqueador de launch:** não

### UX-BUG-05 — Campo de senha sem toggle de visibilidade
**Descrição:** `Register.vue` e `Login.vue` têm `<input type="password">` sem botão "olho" para mostrar/ocultar. Causa erros de digitação invisíveis no mobile, especialmente com confirmação.
**Evidência:** `Register.vue:63,73` / `Login.vue:56`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador de launch:** não

### UX-BUG-06 — Botão "Exportar Relatório" no dashboard sem handler
**Descrição:** o header da `Dashboard/Index.vue` tem um botão "Exportar Relatório" sem `@click` — clique não faz nada. O botão de export que funciona está em `/reports/campaigns/:id/export`.
**Evidência:** `frontend/src/pages/dashboard/Index.vue:15-17`.
**Severidade:** médio (quebra confiança)
**Esforço:** S (remover botão ou ligar ao relatório mais recente)
**Bloqueador de launch:** não

### UX-BUG-07 — Landing link `/docs` no footer, sem destino
**Descrição:** footer da landing aponta para `/docs` ("API Docs") mas não existe rota SPA nem arquivo estático. Clique leva ao 404 do SPA.
**Evidência:** `frontend/public/landing.html:538`.
**Severidade:** médio
**Esforço:** S (apontar para mailto ou remover até existir a doc)
**Bloqueador de launch:** não — baixo risco mas é sinal de inacabado para avaliador técnico.

### UX-BUG-08 — "Cobrado anualmente" aparece mesmo com toggle mensal (race de estado inicial?)
**Descrição:** a `PricingPlans.vue` renderiza badge "Cobrado anualmente" sob condição `v-if="annual && plan.price_monthly > 0"`. O toggle começa em `false` (mensal), então ok — mas a microcopy do header já diz "Escolha o plano que acompanha seu momento", enquanto o CTA de `/plans/checkout/:slug` não indica ciclo escolhido. Usuário perde a referência.
**Evidência:** `PricingPlans.vue:62-65,90-98`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador de launch:** não

### UX-BUG-09 — Rota `/` em dev quebra se Apache/landing.html não estiver servindo
**Descrição:** `router/index.ts:24-26` faz `window.location.href = '/landing.html'` para usuários não autenticados. Em `npm run dev` (vite), `/landing.html` só funciona porque está em `public/`. Em produção depende de config Apache. Não há fallback.
**Evidência:** `frontend/src/router/index.ts:15-29`.
**Severidade:** baixo
**Esforço:** S (documentar ou copiar landing pra dist no build)
**Bloqueador de launch:** não

### UX-BUG-10 — Inconsistência de planos entre landing e API
**Descrição:** `landing.html` exibe 4 tiers (Grátis / Starter R$97 / Pro R$297 / Business R$697). `PricingPlans.vue` carrega 3 da API (starter/pro/enterprise). Não há seeder visível para `free`, `starter`, `pro`, `business` com esses preços. Se a seed real diverge, o usuário clica em "Escolher Business" (R$697 na landing) e acaba indo para um slug `business` inexistente → página 404 de checkout.
**Evidência:** `landing.html:459-488` vs `PricingPlans.vue:90-98`.
**Severidade:** alto
**Esforço:** S (alinhar seeder a tabela exibida; escolher uma única fonte de verdade)
**Bloqueador de launch:** sim (preços e planos devem bater)

---

## Melhorias

### UX-IMP-01 — Consistência visual: dois design systems convivendo
**Descrição:** existe `@tabler/core` com Bootstrap (Dashboard, Campaigns, Reports) e tela de auth/landing com CSS custom (paleta `--bc-*`, fontes Manrope/DM Sans). O usuário sai do visual dark-blueish da landing/login e aterrissa num Tabler claro — quebra expectativa de marca.
**Evidência:** `Login.vue:149-405` (custom) vs `Dashboard/Index.vue` (Tabler `btn`, `card`).
**Severidade:** médio
**Esforço:** L
**Bloqueador:** backlog

### UX-IMP-02 — Mobile: painel de brand do Login/Register desaparece abaixo de 768px
**Descrição:** regra `@media(max-width:768px){ .brand-features { display:none } }`. No mobile, o lado esquerdo fica com só logo + tagline; poderia virar banner superior compacto com os 3 recursos como chips em 1 linha.
**Evidência:** `Register.vue:399-404`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

### UX-IMP-03 — Mensagens de erro de autenticação devem usar 401, não 422
**Descrição:** `AuthController::login` retorna 422 quando credenciais estão erradas. Convenção é 401. Além do padrão HTTP, causa ambiguidade na UI entre "dados mal formatados" e "credenciais incorretas".
**Evidência:** `AuthController.php:74`.
**Severidade:** baixo
**Esforço:** S
**Bloqueador:** backlog

### UX-IMP-04 — "Lembrar de mim" no Login
**Descrição:** Sanctum suporta tokens sem expiração; atualmente cada login emite novo token e `me()` sempre bate no servidor. Para reduzir fricção no retorno diário (caso típico de SaaS B2B), implementar persistência explícita.
**Evidência:** `Login.vue:44-66` (sem checkbox).
**Severidade:** baixo
**Esforço:** M
**Bloqueador:** backlog

### UX-IMP-05 — Feedback de sucesso do PIX/Boleto sem timeline clara
**Descrição:** `CheckoutForm` mostra QR code quando PIX é gerado, mas não diz "vamos checar automaticamente o pagamento — você pode fechar esta aba, vai receber email ao confirmar". Usuário típico fica na tela esperando.
**Evidência:** `CheckoutPage.vue:116-123` + `useCheckoutStore` polling.
**Severidade:** médio
**Esforço:** S (copy + email de confirmação pós-webhook)
**Bloqueador:** backlog

---

## Novas features

### UX-FEAT-01 — Onboarding guiado pós-registro (3 passos visuais)
**Descrição:** após criar conta, usuário cai no dashboard vazio e não sabe o que fazer. Adicionar tour com 3 etapas: "1. Ative um canal | 2. Importe contatos | 3. Crie sua primeira campanha", com checklist que marca passos concluídos.
**Evidência:** `Dashboard/Index.vue` hoje não tem empty state orientado a ação.
**Severidade:** n/a (feature)
**Esforço:** M
**Bloqueador:** backlog (mas de alto impacto em ativação)

### UX-FEAT-02 — Seletor de tema claro/escuro explícito
**Descrição:** landing é dark, auth é dark, resto do app parece claro. Unificar com toggle em um dos lados.
**Severidade:** n/a
**Esforço:** L
**Bloqueador:** backlog

### UX-FEAT-03 — Modo de preview real de WhatsApp/SMS/Email antes do disparo
**Descrição:** `PhonePreview.vue` existe mas não confirmei integração no wizard; reforçar que o usuário vê exatamente como a mensagem cai no celular, com shortcode real, variáveis substituídas e quebra correta (WhatsApp 1024 chars / SMS 160 chars).
**Esforço:** M
**Bloqueador:** backlog

---

## Acessibilidade (rápido)

- Landing tem `focus-visible` para CTAs e `@media (prefers-reduced-motion)` — ✅.
- Contraste: fundo `#060918` com texto `#8890ad` (muted) dá ~3.8:1 — **falha WCAG AA** para texto corpo. Endurecer para `#a5aec2+` ou tornar "muted" só para secundários.
- Inputs do Register: `<label>` não tem `for` amarrando o `<input>` — leitores de tela precisam de `id`/`for`. Corrigir.
- Botão "🔒" dos blind bullets é decorativo — `aria-hidden="true"` ajuda; hoje é emoji bruto.
