# CampaignAI — Rules

## Projeto
SaaS multitenancy de campanhas de marketing via SMS, Voz e Email,
com geração de conteúdo por IA.

## Stack Obrigatória
| Camada       | Tecnologia                                      |
|--------------|-------------------------------------------------|
| Backend      | Laravel 11, PHP 8.3, MySQL 8                    |
| Frontend     | Vue 3, TypeScript, Vite, Pinia, Vue Router 4    |
| UI Library   | Tabler UI (@tabler/core)                        |
| Auth         | Laravel Sanctum (SPA cookies)                   |
| Queue        | Database driver                                 |
| Storage      | Local (dev) / S3 (prod)                         |
| Integrações  | Infobip, Grok (xAI), ElevenLabs                 |

## Paleta de Cores
```css
--color-primary:  #0064ff   /* botões, links, accent, foco         */
--color-dark:     #080c25   /* textos, sidebar, headers escuros     */
--color-muted:    #e4e8ef   /* bordas, backgrounds secundários      */
--color-light:    #f8f8f8   /* background principal das páginas     */
```
Mapear sobre as variáveis do Tabler:
```css
:root {
  --tblr-primary:      #0064ff;
  --tblr-primary-rgb:  0, 100, 255;
  --tblr-body-bg:      #f8f8f8;
  --tblr-border-color: #e4e8ef;
}
```

---

## Regras Gerais

- Nunca usar `dd()`, `var_dump()` ou `dump()` em código que vai para produção
- Nunca hardcodar credenciais — sempre via `.env` ou tabela `settings`
- Nunca deletar registros de geração IA ou histórico de créditos
- Commits em português, descritivos e no imperativo ("Adiciona", "Corrige", "Remove")
- Arquivos de configuração de integração nunca sobem para o repositório

---

## Regras de Backend

### Controllers
- Sempre usar `ApiResponse::success()` e `ApiResponse::error()`
- Sempre validar input com `$request->validate()` ou Form Request
- Nunca retornar `response()->json()` diretamente
- Rotas de tenant protegidas com `auth:sanctum`
- Rotas de admin protegidas com `auth:sanctum` + middleware `superadmin`

### Models
- Sempre declarar `$fillable` e `$casts`
- Models de tenant sempre com GlobalScope por `tenant_id`
- Settings globais sempre com `withoutGlobalScopes()->whereNull('tenant_id')`

### Services de Integração
- Sempre logar com canal dedicado: `infobip`, `ai`, `elevenlabs`, `campaign`
- Sempre tratar exceções com try/catch e logar o erro antes de relançar
- API keys: sempre `encrypt()` ao salvar, `decrypt()` ao ler
- Timeout explícito em todas as chamadas HTTP externas

### Jobs
- Sempre `public int $tries = 3`
- Sempre implementar `failed(\Throwable $e): void`
- Jobs pesados nunca são síncronos — sempre via `dispatch()`

### Migrations
- Sempre implementar `down()` funcional
- Sempre usar `foreignId()->constrained()->cascadeOnDelete()` para FKs
- Sempre adicionar índices em colunas usadas em filtros/buscas frequentes

### Multitenancy
- Todo model de tenant tem GlobalScope filtrando por `tenant_id`
- Nenhuma query de dados de tenant sem filtro de `tenant_id`
- Settings globais (Infobip, Grok, ElevenLabs): `tenant_id = NULL`
- Settings de tenant: `tenant_id = auth()->user()->tenant_id`

### Créditos e Billing
- Nunca debitar créditos sem usar `billing-engine → DeductUsage()`
- Nunca disparar campanha sem checar `billing-engine → LockCampaignIfInsufficient()`
- Toda transação de crédito tem `reference_type` + `reference_id`
- Saldo nunca vai abaixo de zero

### IA
- Nunca chamar API de IA sem registrar `ai_generations` primeiro (status=pending)
- Toda geração tem `generation_id` (UUID)
- Prompts sempre carregados do banco (`ai_prompts`) — nunca hardcodados no service
- Tokens e custo sempre registrados após cada geração

---

## Regras de Frontend

### Componentes Vue
- Sempre Composition API com `<script setup lang="ts">`
- Sempre TypeScript — nunca JavaScript puro
- Props sempre tipadas com interface + `defineProps<Props>()`
- Emits sempre tipados com `defineEmits<{ event: [type] }>()`

### UI / Design
- Sempre usar classes do Tabler UI — nunca recriar o que o Tabler já oferece
- Cores sempre via variáveis CSS — nunca valores hexadecimais hardcoded no template
- Ícones sempre via Tabler Icons: `<i class="ti ti-{name}"></i>`
- Responsividade usando grid do Tabler (`col-12 col-md-6`, etc.)

### Requisições HTTP
- Nunca usar `axios` diretamente — sempre `useApi()` composable
- Sempre tratar erros com toast de erro no catch
- Sempre ter estado de loading em ações assíncronas

### Estado Global
- Nunca acessar `localStorage` diretamente — usar `useAuthStore`
- Stores Pinia sempre com Composition API (`defineStore('name', () => {})`)
- Estado de loading sempre como `ref<boolean>(false)` dentro da store

### Roteamento
- Rotas protegidas com navigation guard verificando `useAuthStore().isAuthenticated`
- Rota 404 sempre configurada como catch-all
- Lazy loading em todas as páginas: `() => import('@/pages/...')`

---

## Estrutura de Pastas

### Backend
```
app/
├── Http/
│   ├── Controllers/API/V1/
│   │   ├── Admin/          # Superadmin only
│   │   └── *.php           # Tenant controllers
│   ├── Middleware/
│   └── Responses/
│       └── ApiResponse.php
├── Models/
├── Services/
│   ├── Infobip/
│   ├── ElevenLabs/
│   └── Ai/
├── Jobs/
└── Exceptions/
```

### Frontend
```
resources/js/
├── components/
│   ├── campaigns/      # Wizard, cards, preview
│   └── layout/         # Sidebar, Topbar, PageHeader
├── composables/
│   ├── useApi.ts
│   └── useToast.ts
├── pages/
│   ├── admin/
│   ├── campaigns/
│   ├── contacts/
│   └── ...
├── router/
│   └── index.ts
├── stores/
│   ├── auth.ts
│   ├── campaign.ts
│   └── ...
└── types/
    └── index.ts
```

---

## Variáveis de Ambiente Obrigatórias

```env
# App
APP_ENV=production
APP_URL=https://dash.businesscode.com.br

# Banco
DB_CONNECTION=mysql
DB_DATABASE=campaignai

# Integrações (valores via tabela settings — não no .env)
# .env só guarda a APP_KEY para o encrypt/decrypt funcionar
APP_KEY=base64:...

# Queue
QUEUE_CONNECTION=database

# Storage
FILESYSTEM_DISK=local
```

---

## Ordem das Fases de Desenvolvimento

```
FASE 1 — Base
  Estrutura Laravel + Vue + Tabler + Sanctum
  Migrations: tenants, users, plans, settings
  ApiResponse + useApi + useAuthStore
  Login / logout / autenticação SPA

FASE 2 — Admin
  Settings: Infobip, Grok, ElevenLabs
  Sync de vozes ElevenLabs
  Gerenciar planos e tenants

FASE 3 — Contatos
  contact_lists + contacts
  Import CSV com mapeamento
  CreditService

FASE 4 — Campanhas
  Wizard 5 passos (SMS, Voz, Email)
  Auto-save de rascunho
  ProcessCampaignJob

FASE 5 — IA
  ai_prompts + ai_generations
  Grok: geração de roteiros
  ElevenLabs: geração de áudio
  Histórico e modelos salvos

FASE 6 — Relatórios
  Dashboard com métricas
  Detalhes por campanha
  Exportação CSV
```
