# Prompt — Iniciar Desenvolvimento (Fase 1)

Cole esse prompt no chat do SOLO Coder para iniciar o projeto.

---

## Texto do Prompt

```
Leia README.md, .trae/rules.md e todas as skills em .trae/skills/ antes de começar.

---

Vamos iniciar o desenvolvimento do CampaignAI do zero.

Trabalhe em fases. Ao final de cada fase, pare e aguarde minha aprovação antes de continuar.

---

## FASE 1 — Base do Projeto

### 1.1 Instalação e Configuração

Backend:
- Laravel 11 fresh install em /backend
- Configurar .env com os valores do README.md
- Instalar e configurar Laravel Sanctum (SPA mode)
- Criar middleware SuperAdmin
- Criar ApiResponse (skill: api-response.md)
- Criar SettingsService (skill: settings-global.md)
- Configurar canais de log: laravel, campaign, ai, infobip, elevenlabs

Frontend:
- Vue 3 + TypeScript + Vite em /frontend
- Instalar @tabler/core
- Instalar pinia, vue-router, axios
- Criar composable useApi (skill: use-api.md)
- Aplicar override de paleta do Tabler (skill: tabler-ui.md):
  --tblr-primary: #0064ff
  --tblr-primary-rgb: 0, 100, 255
  --tblr-body-bg: #f8f8f8
  --tblr-border-color: #e4e8ef

---

### 1.2 Banco de Dados — Migrations

Criar nesta ordem exata:

1. create_tenants_table
   - id, name, slug (unique), plan_id (nullable), credits_balance (int default 0)
   - status (enum: active/suspended/trial, default: trial)
   - trial_ends_at (timestamp nullable)
   - timestamps

2. create_plans_table
   - id, name, slug (unique), price_monthly (decimal 10,2)
   - credits_included (int), max_contacts (int), max_campaigns (int)
   - overage_rate_sms, overage_rate_voice, overage_rate_email, overage_rate_ai (decimal 10,4)
   - features (json nullable)
   - timestamps

3. create_users_table (modificar a default do Laravel)
   - adicionar: tenant_id (FK tenants nullable), role (enum: superadmin/admin/user, default: user)

4. create_settings_table
   - id, tenant_id (FK nullable nullOnDelete), group (string 50), key (string 100)
   - value (text nullable), type (enum: string/encrypted/json/boolean, default: string)
   - UNIQUE(tenant_id, group, key), INDEX(group, key)
   - timestamps

Rodar: php artisan migrate

---

### 1.3 Models

Criar com fillable, casts e GlobalScope onde aplicável:

- Tenant (sem GlobalScope — superadmin acessa todos)
- User (sem GlobalScope — auth cuida do isolamento)
- Plan (sem GlobalScope — público)
- Setting (sem GlobalScope — SettingsService cuida do filtro)

---

### 1.4 Seeder de Dados Iniciais

GlobalSettingsSeeder — inserir em settings (tenant_id = null):
  group=infobip:  api_key(''), base_url(''), sender_sms('CampaignAI'), sender_voice(''), sender_email('')
  group=ai:       grok_api_key(''), grok_model('grok-beta')
  group=elevenlabs: api_key(''), model_id('eleven_multilingual_v2'), cost_per_char('0.0003'), sale_per_char('0.001'), credits_per_char('1')
  group=billing:  credits_per_sms('1'), credits_per_voice('5'), credits_per_email('2'), credits_per_ai_generation('10')

PlansSeeder — inserir 3 planos:
  Starter: R$97/mês, 1000 créditos, 500 contatos, 10 campanhas
  Pro:     R$297/mês, 5000 créditos, 5000 contatos, 50 campanhas
  Enterprise: R$697/mês, 20000 créditos, ilimitado contatos, ilimitado campanhas

SuperAdminSeeder — criar usuário superadmin:
  name: Admin, email: admin@campaignai.com, password: admin123, role: superadmin

Rodar: php artisan db:seed

---

### 1.5 Autenticação — Backend

Criar AuthController com:
- POST /api/v1/auth/login    → valida credenciais → retorna { user, token }
- POST /api/v1/auth/logout   → revoga token atual
- GET  /api/v1/auth/me       → retorna usuário autenticado com tenant

Resposta do login:
{
  "user": { id, name, email, role, tenant_id, tenant: { name, credits_balance, status } },
  "token": "..."
}

Rotas em routes/api.php:
  Públicas: login, register
  Protegidas (auth:sanctum): logout, me, e todos os demais endpoints

---

### 1.6 Autenticação — Frontend

useAuthStore (Pinia):
  state: user, token, isAuthenticated, isLoading
  actions: login(email, password), logout(), initialize()
  persist: token no localStorage via plugin pinia-plugin-persistedstate

router/index.ts:
  Navigation guard: se não autenticado → redirect /login
  Lazy loading em todas as rotas
  Rotas: /login, /dashboard, /campaigns, /contacts, /ai/generate, /settings
  Rotas admin (role=superadmin): /admin/dashboard, /admin/tenants, /admin/plans, /admin/settings/*

pages/auth/Login.vue:
  Layout Tabler: form centralizado com logo CampaignAI
  Campos: email, senha
  Loading state no botão
  Erro de credenciais com alert Tabler

---

### 1.7 Layout Principal

AppSidebar.vue:
  Fundo: #080c25 (--tblr-navbar-bg)
  Logo CampaignAI no topo
  Itens de navegação com ícones Tabler:
    PRINCIPAL:   Dashboard (ti-dashboard), Campanhas (ti-speakerphone)
    CANAIS:      SMS (ti-message), Torpedo de Voz (ti-phone), Email Marketing (ti-mail)
    IA:          Gerador de Conteúdo (ti-sparkles), Relatórios (ti-chart-bar)
    SISTEMA:     Configurações (ti-settings)
  Item ativo com highlight em #0064ff
  Badge de créditos no rodapé (nome do usuário + plano + saldo)
  Link para /admin/* visível apenas para superadmin

AppTopbar.vue:
  Título da página atual
  Badge de créditos: "⚡ {balance} créditos"
  Ícone de notificação (ti-bell)

AppLayout.vue:
  Sidebar + Topbar + <router-view>
  Background da área de conteúdo: #f8f8f8

---

### 1.8 Dashboard (página inicial)

pages/dashboard/Index.vue:
  Page header: "Dashboard"
  4 KPI cards (Tabler stats):
    - Total de campanhas
    - Mensagens enviadas (30 dias)
    - Taxa de entrega média
    - Créditos disponíveis
  Todos os valores vindos de GET /api/v1/dashboard/stats
  Loading skeleton enquanto carrega
  Tabela das 5 últimas campanhas com status badge

DashboardController:
  GET /api/v1/dashboard/stats → retorna os 4 KPIs + últimas 5 campanhas

---

### 1.9 Revisão

Ao terminar toda a Fase 1, chamar o agent `reviewer` com escopo completo:
- Auth (login, token, guard)
- Multitenancy (GlobalScope, settings globais)
- Tabler UI aplicado corretamente
- Todas as rotas protegidas
- ApiResponse em todos os controllers

Apresentar o checklist do reviewer preenchido.

---

## Entrega da Fase 1

Liste todos os arquivos criados organizados por pasta.
Informe os comandos necessários para rodar o projeto.
Aguarde aprovação para iniciar a Fase 2.
```