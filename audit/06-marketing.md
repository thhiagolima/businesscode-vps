# Auditoria Marketing — BusinessCode / CampaignAI

**Escopo:** copy, proposta de valor, onboarding, ativação, pricing page, SEO básico.
**Método:** leitura de `frontend/public/landing.html`, `frontend/src/pages/checkout/PricingPlans.vue`, `frontend/src/pages/auth/Register.vue`, `frontend/src/pages/dashboard/Index.vue`.

---

## Bugs e falhas

### MKT-BUG-01 — Estatísticas sociais **inventadas** na landing (risco jurídico e de credibilidade)
**Descrição:** a landing estampa:
- "847.392 mensagens entregues"
- "Taxa média: 94,7%"
- "Setup em 4 min 23 seg (média)"
- "847K+ Mensagens entregues" (hero stat bar)

Essas métricas são específicas demais para serem honestas em pré-launch. Se forem inventadas (aparentemente são), há:
1. Risco de **propaganda enganosa** (CDC art. 37).
2. Primeiro jornalista/competidor que pedir prova derruba a credibilidade.
3. Contradição com a tabela VERSUS ("R$0 grátis") — nenhum produto novo entrega ~850k msgs sem histórico.
**Evidência:** `landing.html:230,247-252`.
**Severidade:** crítico
**Esforço:** S (trocar por "Novidade: em beta público" ou números verificáveis)
**Bloqueador de launch:** sim

### MKT-BUG-02 — Testimonials **inventados** sem disclaimer
**Descrição:** "Ricardo S. — Restaurante", "Ana M. — Agência", "Felipe S. — Influencer" com dados específicos ("Recuperei 2 vendas por semana", "Cobro R$500/mês de cada um"). Mesma questão legal e ética do MKT-BUG-01. Se forem protótipos/mockups, o commit mais recente `0dcd383c fix: ... testimonial disclaimer` sugere que já houve ajuste em outro projeto — aqui está **sem disclaimer**.
**Evidência:** `landing.html:400-414`.
**Severidade:** crítico
**Esforço:** S
**Bloqueador de launch:** sim

### MKT-BUG-03 — CNPJ placeholder no footer: `XX.XXX.XXX/0001-XX`
**Descrição:** rodapé da landing exibe CNPJ não-preenchido. Sinal claro de inacabado — qualquer visitante que role até o fim vê. Em SaaS B2B que aceita pagamento, CNPJ visível transmite seriedade.
**Evidência:** `landing.html:542`.
**Severidade:** alto
**Esforço:** S
**Bloqueador:** sim

### MKT-BUG-04 — Contato de vendas hardcoded para `5511999999999`
**Descrição:** botão "Falar com Vendas" no plano Enterprise abre WhatsApp para número placeholder. Quem clicar recebe erro do WhatsApp ou fala com alguém alheio à empresa (esse número é um placeholder comum mas, em tese, pode pertencer a uma pessoa real).
**Evidência:** `PricingPlans.vue:158`.
**Severidade:** alto
**Esforço:** S
**Bloqueador:** sim

### MKT-BUG-05 — Toggle "Anual -20%" engana o cliente
**Descrição:** ver UX-BUG-02. Do ponto de vista de marketing, isso é propaganda enganosa: anúncio preço reduzido, cobra preço cheio.
**Severidade:** crítico
**Esforço:** S (remover toggle ou implementar real)
**Bloqueador de launch:** sim

### MKT-BUG-06 — Planos conflitantes entre landing e app
**Descrição:** landing vende **Grátis / Starter R$97 / Pro R$297 / Business R$697**. SPA Pricing carrega o que API retorna (provavelmente 3 planos e com preços diferentes — ver CouponTest que usa R$79 como "Pro"). Prospect lê R$297 no Google Ads, entra no checkout, vê R$79 → perde confiança / reporta como bait.
**Evidência:** `landing.html:459-488` vs `CouponTest.php:25-28` (Pro R$79).
**Severidade:** crítico
**Esforço:** S (fonte única: API)
**Bloqueador:** sim

### MKT-BUG-07 — Não-LGPD: sem política de cookies, sem banner de consentimento
**Descrição:** landing não tem cookie banner nem menção a Google Analytics/Meta Pixel. Se algum tracking for adicionado depois sem banner, multa LGPD.
**Evidência:** `landing.html` — nenhum script de tracking e nenhum banner.
**Severidade:** médio (vira alto quando ligarem pixel)
**Esforço:** S
**Bloqueador:** backlog (mas OBRIGATÓRIO antes de rodar ads)

### MKT-BUG-08 — SEO: `<meta>` OK, mas sem `<link rel="canonical">`, sem `hreflang`, sem JSON-LD
**Descrição:** landing tem `<title>`, `<meta description>`, `og:title`, `og:description`, `og:type` (✅). Mas falta:
- `<link rel="canonical" href="https://businesscode.com.br/">`
- `og:image` para preview em links (WhatsApp/LinkedIn renderizam vazio)
- `og:url`
- `twitter:card`
- JSON-LD (Organization, Product, SoftwareApplication + AggregateRating — sem inventar reviews)

Com LCP bom e essas tags, SEO score vai para 95+.
**Evidência:** `landing.html:3-12`.
**Severidade:** médio
**Esforço:** S
**Bloqueador:** backlog

### MKT-BUG-09 — Dashboard pós-registro vazio, sem guia
**Descrição:** usuário cria conta com 50 créditos, cai em `/dashboard` com KPI "0 campanhas, 0 ativas, 50 créditos" + gráfico vazio. Não vê "próximo passo" óbvio. Curva de ativação sofre. Onboarding é o divisor de águas entre signup e usuário ativado.
**Evidência:** `Dashboard/Index.vue:1-80`.
**Severidade:** alto (impacta LTV)
**Esforço:** M (ver UX-FEAT-01)
**Bloqueador:** backlog (ship sem isso, mas perca de ativação)

### MKT-BUG-10 — FAQ cita "Templates pronto no plano Starter" — não visível no produto
**Descrição:** landing afirma "Template pronto no plano Starter" (blind bullet da pizzaria). No app, a seção IA / Generator é apenas briefing → Grok. Não existe "biblioteca de templates por segmento". Promessa vazia.
**Evidência:** `landing.html:326`.
**Severidade:** médio
**Esforço:** S (reescrever copy OU construir biblioteca mínima)
**Bloqueador:** backlog

### MKT-BUG-11 — Promessa "IA responde em 3 segundos" sem SLA
**Descrição:** hero + pricing FAQ: "o chatbot responde em 3 segundos". Grok tem latência típica 2-8s dependendo do prompt e carga. Sem SLA documentado, cliente cobra em ticket.
**Evidência:** `landing.html:273,513`.
**Severidade:** baixo
**Esforço:** S (amortecer copy: "segundos")
**Bloqueador:** backlog

### MKT-BUG-12 — CTA "Setup em 4 min 23 seg (média)" é premissa falsa
**Descrição:** para disparar primeira campanha, usuário precisa: conectar canal (Infobip/WhatsApp — dependente de superadmin), importar contatos, gerar IA, escolher voz etc. Em 4m23s? Improvável.
**Severidade:** médio
**Esforço:** S (remover ou medir real e ajustar)
**Bloqueador:** backlog

---

## Melhorias

### MKT-IMP-01 — Trocar hero atual por prova visual
Hero hoje é texto + stat fake. Substituir por GIF/video curto do dashboard real: wizard → disparando → relatório chegando. "Show, don't tell". Se não tiver vídeo, um print animado do wizard.

### MKT-IMP-02 — Adicionar case de uso real (mesmo que beta)
Ao lançar, trazer 3-5 beta testers reais com nome, cidade e foto (com permissão). Substitui testimonials fake e resolve MKT-BUG-02.

### MKT-IMP-03 — Remover CTA "Sem cartão" se Pro/Starter exigem cartão
Grátis não exige cartão (OK). Starter/Pro exigem. Reforçar "Comece grátis, sem cartão — pague só se escalar" para alinhar expectativa.

### MKT-IMP-04 — Página `/docs` + "Comparação com competidores" em SEO
Plano Business promete "API completa". Sem `/docs`, ninguém compra. Criar OpenAPI (ver DEV-IMP-06) e página `/comparacao-zenvia` / `/comparacao-manychat` — caça tráfego long-tail.

### MKT-IMP-05 — Email de boas-vindas com checklist + 2 ações sugeridas
Commit recente `e807a1dd feat: wire up welcome email on register` sugere que já existe algo. Verificar se está disparando e se o conteúdo é acionável.

### MKT-IMP-06 — Meta title + description mais orientados a keyword
Hoje: "BusinessCode® — Pare de perder vendas por falta de resposta". Ranqueia pouco. Alternativa: "Plataforma WhatsApp + SMS + Email com IA | BusinessCode" — bate em 3 queries populares.

---

## Novas features

### MKT-FEAT-01 — Calculadora "quanto vou gastar por mês"
Baseado em contatos + canais + frequência, estima créditos/mês. Acelera decisão de plano e reduz abandono no checkout.

### MKT-FEAT-02 — Programa de indicação
Revenda/afiliado. Landing já menciona "agências revendem" — transformar em programa formal com link de indicação, R$50 ou 10% vitalício.

### MKT-FEAT-03 — Case studies (blog) após primeiros pagantes
CMS leve (Statamic ou Markdown) com posts `/blog/case-pizzaria-abc`. Combate MKT-BUG-10 e gera SEO orgânico.

### MKT-FEAT-04 — Trial estendido para empresas com >500 contatos
Plano Pro 14 dias free quando cadastra 500+ contatos — reduz atrito para cliente de maior ticket.
