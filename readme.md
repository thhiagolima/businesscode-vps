# CampaignAI — README

SaaS multitenancy de campanhas de marketing via SMS, Voz e Email
com geração de conteúdo por IA (Grok) e síntese de voz (ElevenLabs).

---

## Índice

1. [Arquitetura](#arquitetura)
2. [Estrutura de Pastas](#estrutura-de-pastas)
3. [Banco de Dados](#banco-de-dados)
4. [Autenticação](#autenticação)
5. [Multitenancy](#multitenancy)
6. [Integrações](#integrações)
7. [Sistema de Créditos](#sistema-de-créditos)
8. [Fluxo de Campanha](#fluxo-de-campanha)
9. [Geração de Conteúdo IA](#geração-de-conteúdo-ia)
10. [Filas e Jobs](#filas-e-jobs)
11. [Variáveis de Ambiente](#variáveis-de-ambiente)
12. [Comandos Úteis](#comandos-úteis)

---

## Arquitetura

```
┌─────────────────────────────────────────────────────┐
│                    Frontend (Vue 3)                  │
│         Tabler UI · Pinia · Vue Router · Vite        │
└──────────────────────┬──────────────────────────────┘
                       │ HTTP (Sanctum SPA)
┌──────────────────────▼──────────────────────────────┐
│                  Laravel 11 API                      │
│                                                      │
│  Controllers → Services → Jobs → Models              │
│                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────┐   │
│  │ Infobip  │  │   Grok   │  │   ElevenLabs     │   │
│  │SMS/Voz/  │  │  (xAI)   │  │     (TTS)        │   │
│  │  Email   │  │          │  │                  │   │
│  └──────────┘  └──────────┘  └──────────────────┘   │
│                                                      │
│  MySQL · Queue (database) · Storage (local/S3)       │
└─────────────────────────────────────────────────────┘
```

---

## Estrutura de Pastas

```
campaignai/
│
├── backend/                          # Laravel 11
│   ├── app/
│   │   ├── Exceptions/
│   │   │   └── InvalidCampaignTransitionException.php
│   │   ├── Http/
│   │   │   ├── Controllers/API/V1/
│   │   │   │   ├── Admin/            # Superadmin only
│   │   │   │   │   ├── InfobipSettingsController.php
│   │   │   │   │   ├── AiSettingsController.php
│   │   │   │   │   ├── ElevenLabsSettingsController.php
│   │   │   │   │   ├── TenantsController.php
│   │   │   │   │   └── PlansController.php
│   │   │   │   ├── AuthController.php
│   │   │   │   ├── CampaignController.php
│   │   │   │   ├── ContactListController.php
│   │   │   │   ├── ContactController.php
│   │   │   │   ├── ImportController.php
│   │   │   │   ├── AiGeneratorController.php
│   │   │   │   ├── AudioGenerationController.php
│   │   │   │   └── AiContentModelController.php
│   │   │   ├── Middleware/
│   │   │   │   └── SuperAdmin.php
│   │   │   └── Responses/
│   │   │       └── ApiResponse.php
│   │   ├── Jobs/
│   │   │   ├── ProcessCampaignJob.php
│   │   │   └── SyncElevenLabsVoicesJob.php
│   │   ├── Models/
│   │   │   ├── Tenant.php
│   │   │   ├── User.php
│   │   │   ├── Plan.php
│   │   │   ├── Setting.php
│   │   │   ├── Campaign.php
│   │   │   ├── CampaignDispatch.php
│   │   │   ├── ContactList.php
│   │   │   ├── Contact.php
│   │   │   ├── Import.php
│   │   │   ├── AiGenerationSession.php
│   │   │   ├── AiContentModel.php
│   │   │   ├── AiPrompt.php
│   │   │   ├── AiGeneration.php
│   │   │   ├── ElevenLabsVoice.php
│   │   │   ├── AudioGeneration.php
│   │   │   └── CreditTransaction.php
│   │   └── Services/
│   │       ├── SettingsService.php
│   │       ├── CreditService.php
│   │       ├── CampaignStateMachine.php
│   │       ├── Infobip/
│   │       │   ├── InfobipClientFactory.php
│   │       │   └── InfobipSmsService.php
│   │       ├── Ai/
│   │       │   └── GrokService.php
│   │       └── ElevenLabs/
│   │           ├── ElevenLabsService.php
│   │           └── AudioGenerationService.php
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   │       └── GlobalSettingsSeeder.php
│   └── routes/
│       └── api.php
│
└── frontend/                         # Vue 3
    └── resources/js/
        ├── components/
        │   ├── campaigns/
        │   │   ├── CampaignWizard.vue
        │   │   ├── PhonePreview.vue
        │   │   ├── VoicePlayer.vue
        │   │   ├── VoiceSelector.vue
        │   │   ├── AiHistoryModal.vue
        │   │   └── AiModelSelectorModal.vue
        │   └── layout/
        │       ├── AppSidebar.vue
        │       └── AppTopbar.vue
        ├── composables/
        │   ├── useApi.ts
        │   ├── useToast.ts
        │   └── useCampaignStatus.ts
        ├── pages/
        │   ├── auth/
        │   │   └── Login.vue
        │   ├── dashboard/
        │   │   └── Index.vue
        │   ├── campaigns/
        │   │   ├── Index.vue
        │   │   ├── Create.vue
        │   │   └── Detail.vue
        │   ├── contacts/
        │   │   └── Index.vue
        │   ├── ai/
        │   │   ├── Index.vue       # lista modelos salvos
        │   │   └── Generate.vue    # formulário de geração
        │   ├── settings/
        │   │   └── Index.vue
        │   └── admin/
        │       ├── Dashboard.vue
        │       ├── Tenants.vue
        │       ├── Plans.vue
        │       └── settings/
        │           ├── InfobipSettings.vue
        │           ├── AiSettings.vue
        │           └── ElevenLabsSettings.vue
        ├── router/
        │   └── index.ts
        ├── stores/
        │   ├── auth.ts
        │   ├── campaign.ts
        │   └── wizard.ts
        └── types/
            └── index.ts
```

---

## Banco de Dados

### Diagrama de Relacionamentos

```
tenants ──< users
tenants ──< settings (tenant_id nullable = global)
tenants ──< campaigns
tenants ──< contact_lists ──< contacts
tenants ──< credit_transactions
tenants ──< ai_generation_sessions
tenants ──< ai_content_models
tenants ──< audio_generations
campaigns ──< campaign_dispatches
campaigns ──< ai_generation_sessions
```

### Tabelas

#### tenants
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| name | string | Nome da empresa |
| slug | string unique | Identificador URL |
| plan_id | FK plans | Plano atual |
| credits_balance | int default 0 | Saldo de créditos |
| status | enum | active / suspended / trial |
| trial_ends_at | timestamp nullable | |

#### users
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK tenants | |
| name | string | |
| email | string unique | |
| password | string | bcrypt |
| role | enum | superadmin / admin / user |

#### plans
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| name | string | Ex: Starter, Pro, Enterprise |
| slug | string unique | |
| price_monthly | decimal(10,2) | |
| credits_included | int | Créditos mensais incluídos |
| overage_rate_sms | decimal(10,4) | Créditos por SMS acima do plano |
| overage_rate_voice | decimal(10,4) | |
| overage_rate_email | decimal(10,4) | |
| overage_rate_ai | decimal(10,4) | |
| max_contacts | int | |
| max_campaigns | int | |
| features | json | Lista de features disponíveis |

#### settings
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK nullable | NULL = global (sistema) |
| group | string | infobip / ai / elevenlabs / billing |
| key | string | api_key / sender_sms / etc |
| value | text | Valor (pode ser encrypted) |
| type | enum | string / encrypted / json / boolean |
| UNIQUE | (tenant_id, group, key) | |

#### campaigns
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| name | string | |
| type | enum | sms / voice / email |
| status | enum | draft / processing / running / completed / failed / scheduled |
| content | text nullable | Texto da mensagem |
| subject | string nullable | Assunto (email) |
| audio_url | string nullable | URL do áudio (voice) |
| contact_list_id | FK nullable | |
| ai_generation_session_id | FK nullable | |
| strategy_locked | boolean default false | Canal imutável após gerar IA |
| settings | json | Configurações extras |
| scheduled_at | timestamp nullable | |
| started_at | timestamp nullable | |
| completed_at | timestamp nullable | |
| sent_count | int default 0 | |
| failed_count | int default 0 | |
| estimated_contacts | int default 0 | |
| warning_message | string nullable | Ex: créditos insuficientes |

#### campaign_dispatches
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| campaign_id | FK | |
| tenant_id | FK | |
| contact_id | FK | |
| status | enum | pending / sent / delivered / failed / read |
| phone | string | |
| message_content | text | |
| external_message_id | string nullable | ID retornado pela Infobip |
| sent_at | timestamp nullable | |
| delivered_at | timestamp nullable | |
| failed_at | timestamp nullable | |
| error_message | text nullable | |

#### contact_lists
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| name | string | |
| description | text nullable | |
| contact_count | int default 0 | |

#### contacts
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| contact_list_id | FK | |
| name | string nullable | |
| phone | string | |
| email | string nullable | |
| opted_out | boolean default false | |
| custom_fields | json nullable | |

#### imports
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| contact_list_id | FK | |
| filename | string | |
| status | enum | pending / processing / completed / failed |
| total_rows | int default 0 | |
| imported_rows | int default 0 | |
| failed_rows | int default 0 | |
| column_mapping | json | { phone: "telefone", name: "nome" } |
| error_log | json nullable | |

#### ai_prompts
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| service | string | sms / voice / email |
| version | string | v1 / v2 / v3... |
| system_prompt | text | |
| user_template | text | Template com variáveis |
| model | string | grok-beta |
| is_active | boolean | Apenas 1 ativo por serviço |

#### ai_generations
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| generation_id | uuid unique | |
| service | string | sms / voice / email |
| prompt_version | string | |
| input_payload | json | |
| output | json | |
| tokens_input | int | |
| tokens_output | int | |
| cost_usd | decimal(10,6) | |
| model | string | |
| status | enum | pending / completed / failed |

#### ai_generation_sessions
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| campaign_id | FK nullable | |
| channel | enum | sms / voice / email |
| status | enum | pending / completed / failed |
| briefing | json | produto, público, benefício, cta, tom |
| variations | json | Array de variações geradas |
| selected_variation_id | string nullable | UUID da variação escolhida |
| expires_at | timestamp nullable | |

#### ai_content_models
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| name | string | Nome do modelo salvo |
| channel | enum | |
| briefing | json | |
| variations | json | |
| selected_variation_index | int default 0 | |
| tone | string nullable | |
| status | enum | draft / ready |
| credits_used | int default 0 | |

#### elevenlabs_voices
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| voice_id | string unique | ID da API ElevenLabs |
| name | string | |
| category | string nullable | premade / cloned |
| gender | string nullable | male / female |
| accent | string nullable | |
| language | string nullable | pt / en / es |
| preview_url | string nullable | |
| labels | json nullable | |
| is_active | boolean default true | |

#### audio_generations
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| campaign_id | FK nullable | |
| elevenlabs_voice_id | string | |
| script | text | Roteiro gerado pelo Grok |
| audio_path | string nullable | Caminho no storage |
| audio_url | string nullable | URL pública |
| duration_seconds | int nullable | |
| characters_used | int nullable | |
| cost_price | decimal(10,4) | Custo pago à ElevenLabs |
| sale_price | decimal(10,4) | Valor cobrado do tenant |
| credits_charged | int default 0 | |
| status | enum | pending / processing / completed / failed |
| error_message | text nullable | |

#### credit_transactions
| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | bigint PK | |
| tenant_id | FK | |
| type | enum | debit / credit |
| amount | int | |
| balance_after | int | Saldo após transação |
| reference_type | string | campaign_dispatch / ai_generation / etc |
| reference_id | bigint | |
| description | string | |

---

## Autenticação

Laravel Sanctum em modo SPA (cookies).

**Fluxo:**
1. `POST /api/v1/auth/login` → retorna token + user
2. Frontend salva token no `useAuthStore` (Pinia)
3. `useApi` injeta `Authorization: Bearer {token}` em toda request
4. Em 401: `useAuthStore.logout()` + redirect `/login`

**Rotas públicas:**
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/register`

**Rotas protegidas:** `auth:sanctum`

**Rotas admin:** `auth:sanctum` + middleware `superadmin`
(verifica `user->role === 'superadmin'`)

---

## Multitenancy

- Dados por tenant: todos os models têm `tenant_id` + GlobalScope
- Settings globais: `tenant_id = NULL` → sempre `withoutGlobalScopes()`
- Jobs: não têm usuário autenticado → sempre passar `tenant_id` explicitamente
- Nunca usar `updateOrInsert` com `tenant_id = null` (NULL != NULL no SQL)

Consultar: `.trae/skills/multitenancy.md`

---

## Integrações

### Infobip
- Credenciais: `settings` (group=infobip, tenant_id=NULL)
- API key: encrypted no banco
- Base URL: `https://{base_url}.api.infobip.com`
- Canais: SMS (`/sms/2/text/advanced`), Voz (`/tts/3/single`), Email (`/email/3/send`)
- Log channel: `infobip`

### Grok (xAI)
- Credenciais: `settings` (group=ai, tenant_id=NULL)
- Modelo padrão: `grok-beta`
- Uso: geração de roteiros de campanha
- Log channel: `ai`

### ElevenLabs
- Credenciais: `settings` (group=elevenlabs, tenant_id=NULL)
- Modelo padrão: `eleven_multilingual_v2`
- Uso: síntese de voz para campanhas de torpedo de voz
- Vozes sincronizadas via `SyncElevenLabsVoicesJob`
- Log channel: `elevenlabs`
- Precificação: `cost_per_char` (custo), `sale_per_char` (venda), `credits_per_char`

Consultar: `.trae/skills/settings-global.md`

---

## Sistema de Créditos

**Custo por ação (defaults em settings):**
| Ação | Créditos |
|------|----------|
| SMS enviado | 1 |
| Voz iniciada | 5 |
| Email enviado | 2 |
| Geração IA (roteiro) | 10 |
| Geração áudio (por char) | configurável |

**Regras:**
- Saldo nunca vai negativo
- Envio parcial permitido se saldo < contatos
- Toda movimentação registrada em `credit_transactions`
- Deducção sempre via `CreditService::debit()` com row lock

---

## Fluxo de Campanha

### Wizard (5 passos)
```
Step 1 → Escolher canal (SMS / Voz / Email)
Step 2 → Conteúdo com IA (briefing → Grok → variações → escolher)
           Para Voz: escolher voz ElevenLabs → gerar áudio
Step 3 → Configurar campanha (lista de contatos ou CSV)
Step 4 → Agendar envio (imediato ou data futura)
Step 5 → Revisar e confirmar → Iniciar campanha
```

### Auto-save
- Rascunho criado imediatamente ao entrar no wizard
- URL atualizada para `/campaigns/create?draft={id}`
- Debounce de 1500ms em todo campo do wizard
- `buildPayload()` envia apenas campos relevantes

### State Machine
```
draft → processing → running → completed
draft → scheduled  → processing → running → completed
running → failed → draft (reset manual)
```

Consultar: `.trae/skills/campaign-state-machine.md`

---

## Geração de Conteúdo IA

### Fluxo
```
1. Frontend envia briefing (produto, público, benefício, cta, tom)
2. Backend chama ai-governance para compor prompt
3. Prompt carregado de ai_prompts (versão ativa)
4. Registro em ai_generations (status=pending) ANTES da chamada
5. Chamada ao Grok API
6. Registro atualizado (status=completed, tokens, custo)
7. Variações retornadas ao frontend
8. Usuário escolhe uma variação
9. Variação salva na campanha (ai_generation_session)
```

### Modelos Salvos
- Usuário pode salvar variações como `ai_content_models`
- Reutilizável em novas campanhas via modal de seleção
- Filtrável por canal

---

## Filas e Jobs

**Driver:** `database`

**Jobs:**
| Job | Trigger | Timeout | Tries |
|-----|---------|---------|-------|
| `ProcessCampaignJob` | sendNow / scheduler | 3600s | 3 |
| `SyncElevenLabsVoicesJob` | manual / scheduled | 120s | 3 |

**Rodar worker:**
```bash
php artisan queue:work --queue=default --tries=3 --timeout=3600
```

**Monitorar:**
```bash
# Windows
Get-Content storage\logs\campaign.log -Wait -Tail 50

# Linux
tail -f storage/logs/campaign.log
```

---

## Variáveis de Ambiente

```env
APP_NAME=CampaignAI
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://dash.businesscode.com.br

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=campaignai
DB_USERNAME=root
DB_PASSWORD=

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
FILESYSTEM_DISK=local

# Integrações ficam na tabela settings (não no .env)
# APP_KEY é obrigatória para encrypt/decrypt funcionar
```

---

## Comandos Úteis

```bash
# Setup inicial
php artisan migrate --seed
php artisan storage:link

# Desenvolvimento
php artisan serve            # backend :8000
npm run dev                  # frontend :5173

# Produção
npm run build
php artisan optimize
php artisan queue:work

# Diagnóstico
php artisan tinker
  > App\Models\Campaign::count()
  > App\Models\Setting::withoutGlobalScopes()->whereNull('tenant_id')->get()

# Logs
tail -f storage/logs/laravel.log
tail -f storage/logs/campaign.log
tail -f storage/logs/ai.log
tail -f storage/logs/infobip.log
tail -f storage/logs/elevenlabs.log

# Limpar cache
php artisan optimize:clear
```
