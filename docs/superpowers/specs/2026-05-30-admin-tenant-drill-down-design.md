# Admin · Drill-down individual por tenant

**Data**: 2026-05-30
**Status**: Design aprovado, pronto para plano de implementação
**Branch sugerida**: `feat/admin-tenant-drill-down`

---

## 1. Contexto e problema

Hoje o superadmin enxerga os tenants da plataforma de forma **agregada** ou **superficial**:

- `/admin/tenants` (`frontend/src/pages/admin/Tenants.vue`) — listagem com colunas básicas (nome, plano, saldo, status, canais). Sem drill-down operacional.
- `/admin/billing/tenants/:id` (`frontend/src/pages/admin/billing/TenantDetail.vue`) — drill-down **apenas financeiro** (saldo, pricing custom, histórico de transações).
- `/admin/billing/reports` — agregado global (top 10 consumidores, MRR, margem).

**O que falta**: o superadmin não consegue inspecionar nem agir sobre as **operações** de um tenant individualmente — campanhas, contatos, conversas, funis, usuários, relatórios operacionais, auditoria. Hoje, para investigar o problema de um cliente, o admin precisaria ter acesso ao banco ou logar como tenant — ambos os caminhos são inseguros e operacionalmente ruins.

## 2. Objetivo

Criar uma página de drill-down `/admin/tenants/:id` com abas, replicando o padrão já adotado em `/admin/billing/tenants/:id`, mas cobrindo **toda a operação** do tenant. Permitir leitura completa e edição administrativa nas áreas operacionais, mantendo Relatórios e Auditoria como read-only.

## 3. Não-objetivos (YAGNI explícito)

- ❌ Impersonação / login-as-tenant.
- ❌ Criação de campanhas, funis ou listas novas como admin (admin apenas gere os existentes; criação continua com o tenant).
- ❌ Bulk actions (selecionar N itens e operar em lote).
- ❌ Export CSV por aba.
- ❌ Gestão de webhooks / API tokens do tenant.
- ❌ Refatoração do drill-down financeiro `/admin/billing/tenants/:id` — apenas linkamos para ele.

## 4. Abas e permissões

| # | Aba | Permissão | Conteúdo |
|---|-----|-----------|----------|
| 1 | **Visão geral** | edit (tenant) | Cards KPI (campanhas totais, contatos, msgs 30d, conversas abertas), form de edição do tenant (nome, slug, plano, status, trial). Reusa lógica do modal atual em `Tenants.vue`. |
| 2 | **Campanhas** | edit | Tabela paginada; ações: ver detalhes, pausar, retomar, cancelar, excluir. Sem criação. |
| 3 | **Contatos** | edit | Tabela paginada (com listas e opt-outs visíveis); criar, editar, excluir contato. |
| 4 | **Conversas** | edit | Tabela paginada; atribuir agente, fechar, arquivar. |
| 5 | **Funis** | edit | Listar, ativar/desativar, excluir. Botão "Editar" abre `/funnels/:id/edit?admin_tenant={id}` (editor existente, contexto de tenant via query). |
| 6 | **Usuários** | edit | Listar usuários do tenant. Criar (com role), editar, suspender (`status` — novo campo), redefinir senha (gera temporária, mostra 1x). |
| 7 | **Canais** | edit | Migra o modal atual de `Tenants.vue:222-275` para esta aba — gerenciar SMS/WhatsApp/Email/Voz status + provider WhatsApp. |
| 8 | **Relatórios** | **read-only** | KPIs operacionais por canal (enviado/entregue/falha), gráfico de envios 30d, top 5 campanhas por volume. |
| 9 | **Auditoria** | **read-only** | Lista do `audit_log` filtrada por `tenant_id`, paginada, com filtros (action, período, user). |
| 10 | **Financeiro** | link externo | Botão "Abrir financeiro" navega para `/admin/billing/tenants/:id`. Não duplica conteúdo. |

## 5. Arquitetura — Backend

### 5.1 Padrão geral

- Novos controllers sob `App\Http\Controllers\API\V1\Admin\Tenant{Resource}Controller`.
- Reusam **services existentes** quando disponíveis (`BillingService`, etc.) passando `tenant_id` explicitamente.
- Quando não há service, usam o **model** diretamente. Como `AppliesTenantScope` já libera superadmin (`AppliesTenantScope.php:21-22`), basta filtrar `where('tenant_id', $tenantId)` explicitamente.

### 5.2 Rotas (todas sob middleware `superadmin`)

```php
Route::middleware('superadmin')->prefix('admin/tenants/{tenant}')->group(function () {
    // Overview / KPIs
    Route::get('overview',  [TenantOverviewController::class, 'index']);

    // Campanhas — listar/ver/atualizar status/excluir
    Route::get   ('campaigns',         [TenantCampaignsController::class, 'index']);
    Route::get   ('campaigns/{id}',    [TenantCampaignsController::class, 'show']);
    Route::patch ('campaigns/{id}',    [TenantCampaignsController::class, 'update']);
    Route::delete('campaigns/{id}',    [TenantCampaignsController::class, 'destroy']);

    // Contatos
    Route::get   ('contacts',          [TenantContactsController::class, 'index']);
    Route::post  ('contacts',          [TenantContactsController::class, 'store']);
    Route::put   ('contacts/{id}',     [TenantContactsController::class, 'update']);
    Route::delete('contacts/{id}',     [TenantContactsController::class, 'destroy']);
    Route::get   ('contact-lists',     [TenantContactsController::class, 'lists']);

    // Conversas
    Route::get   ('conversations',     [TenantConversationsController::class, 'index']);
    Route::patch ('conversations/{id}',[TenantConversationsController::class, 'update']);

    // Funis
    Route::get   ('funnels',           [TenantFunnelsController::class, 'index']);
    Route::patch ('funnels/{id}',      [TenantFunnelsController::class, 'update']);
    Route::delete('funnels/{id}',      [TenantFunnelsController::class, 'destroy']);

    // Usuários
    Route::get   ('users',             [TenantUsersController::class, 'index']);
    Route::post  ('users',             [TenantUsersController::class, 'store']);
    Route::put   ('users/{id}',        [TenantUsersController::class, 'update']);
    Route::post  ('users/{id}/reset-password', [TenantUsersController::class, 'resetPassword']);
    Route::delete('users/{id}',        [TenantUsersController::class, 'destroy']);

    // Canais — já existem em /admin/tenants/{tenantId}/channels (mantém)

    // Read-only
    Route::get   ('reports',           [TenantReportsController::class, 'index']);
    Route::get   ('audit',             [TenantAuditController::class, 'index']);
});
```

### 5.3 Regras de segurança e auditoria

1. **`tenant_id` vem sempre do path param**, nunca do body. Mesmo que o body envie, é descartado.
2. **Toda escrita admin grava `audit_log`** com:
   - `user_id` = superadmin
   - `tenant_id` = tenant impactado (mesmo padrão do `AuditLog::record` atual)
   - `action` = `admin.{resource}.{verb}` (ex.: `admin.campaign.cancel`, `admin.user.password_reset`)
   - `metadata` = `{ target_resource_id, before, after, reason? }`
3. **Reset de senha**: gera senha temporária de 16 chars (cripto-segura), seta `users.force_password_reset = true` (campo novo a adicionar via migration), invalida todos os tokens (`$user->tokens()->delete()`). Retorna a senha **uma única vez** no response.
4. **Operações destrutivas** (`destroy`) checam invariantes do domínio antes (mesmo padrão de `TenantsController::destroy` que bloqueia tenant com saldo devedor).
5. **Campanhas em execução**: `destroy` é negado; admin pode `cancel` primeiro.

### 5.4 Migrations novas

```
2026_05_30_000001_add_admin_fields_to_users.php
  - users.force_password_reset BOOLEAN DEFAULT false
  - users.status ENUM('active', 'suspended') DEFAULT 'active'
```

**`force_password_reset`**: ao logar, se `true`, força redirect para tela de troca de senha antes de qualquer outra ação. Após troca bem-sucedida, vira `false`.

**`status`**: usuários `suspended` recebem 403 no login (`AuthController` checa antes de emitir token). Existing sessions/tokens são invalidados ao suspender. `superadmin` é imune a suspensão (validação na request).

### 5.5 Endpoint `/overview` — formato

```json
{
  "tenant": { "id": 1, "name": "...", "slug": "...", "status": "active", "plan": {...}, "balance_cents": 12345, "credit_limit_cents": 50000 },
  "kpis": {
    "campaigns_total": 42,
    "campaigns_active": 3,
    "contacts_total": 1234,
    "messages_sent_30d": 5678,
    "messages_delivered_30d": 5432,
    "conversations_open": 7,
    "users_total": 5
  }
}
```

### 5.6 Endpoint `/reports` — formato

```json
{
  "by_channel_30d": [
    { "channel": "sms",      "sent": 1000, "delivered": 950, "failed": 50 },
    { "channel": "whatsapp", "sent": 200,  "delivered": 195, "failed": 5  },
    { "channel": "email",    "sent": 500,  "delivered": 480, "failed": 20 },
    { "channel": "voice",    "sent": 0,    "delivered": 0,   "failed": 0  }
  ],
  "daily_sent_30d": [
    { "date": "2026-05-01", "sent": 120 },
    ...
  ],
  "top_campaigns_30d": [
    { "id": 12, "name": "Black Friday", "sent": 450, "delivered_rate": 0.97 },
    ...
  ]
}
```

## 6. Arquitetura — Frontend

### 6.1 Estrutura de arquivos

```
frontend/src/
├── pages/admin/tenants/
│   └── Detail.vue                       # casca com tabs, recebe :id
├── components/admin/tenants/
│   ├── TabOverview.vue
│   ├── TabCampaigns.vue
│   ├── TabContacts.vue
│   ├── TabConversations.vue
│   ├── TabFunnels.vue
│   ├── TabUsers.vue
│   ├── TabChannels.vue                  # migra modal de Tenants.vue
│   ├── TabReports.vue                   # read-only
│   ├── TabAudit.vue                     # read-only
│   └── modals/
│       ├── ContactEditModal.vue
│       ├── UserEditModal.vue
│       ├── PasswordResetResultModal.vue # mostra senha temporária 1x
│       └── ConfirmDestructiveModal.vue
├── stores/adminTenantDetail.ts          # tenant carregado uma vez, abas leem dele
└── components/shared/
    ├── CampaignsTable.vue               # extraído de pages/campaigns/Index.vue
    ├── ContactsTable.vue
    ├── ConversationsTable.vue
    └── FunnelsTable.vue
```

### 6.2 Roteamento

- Adicionar rota em `frontend/src/router/index.ts`:
  ```ts
  {
    path: '/admin/tenants/:id',
    component: () => import('@/pages/admin/tenants/Detail.vue'),
    meta: { superadmin: true, title: 'Admin • Tenant' },
  }
  ```
- Aba ativa via query string (`?tab=campaigns`). Default = `overview`.
- Em `pages/admin/Tenants.vue`, linha da tabela vira clicável (`<tr @click="$router.push(\`/admin/tenants/${t.id}\`)">`). Os ícones de ação atuais permanecem (já abrem modais isolados).

### 6.3 Reuso de componentes

- Os componentes `*Table.vue` em `components/shared/` são extraídos das páginas de tenant atuais (`pages/campaigns/Index.vue`, etc.) com props `:items, :loading` e events `@action, @select`. Tanto a página do tenant quanto a aba admin renderizam o mesmo componente — zero divergência de UX.
- Modais (`ContactEditModal`, `UserEditModal`) também extraídos para uso em ambos contextos.

### 6.4 Estado compartilhado

`stores/adminTenantDetail.ts` (Pinia) carrega o tenant uma única vez e cacheia. Cada aba dispara o fetch do seu próprio recurso ao ativar (lazy).

### 6.5 UX detalhes

- **Header**: nome do tenant + badge de status + breadcrumb "Admin › Tenants › {nome}". Botão "Voltar" e "Atualizar".
- **Aba ativa**: persistida em query string para deep-link e refresh.
- **Confirmação destrutiva**: modal exige digitar nome do recurso (padrão GitHub) em `destroy` de contato/usuário/funil.
- **Reset de senha**: ao confirmar, modal de resultado mostra senha temporária + botão "Copiar" + aviso "esta senha só será exibida agora". Fecha = senha esquecida.
- **Indicador admin**: quando funil/contato é editado em modo admin, badge "Editando como superadmin" no topo do editor existente (`/funnels/:id/edit?admin_tenant=X`).

## 7. Testes obrigatórios

### 7.1 Backend — Feature tests por controller

Para cada controller novo, no mínimo:

- ✓ `it('superadmin can list resources of any tenant')`
- ✓ `it('superadmin can edit/delete resources of any tenant')`
- ✓ `it('non-superadmin gets 403')`
- ✓ `it('unknown tenant returns 404')`
- ✓ `it('paginates with default 20 per page')`
- ✓ `it('write operation creates audit_log with target_tenant_id and admin user_id')`
- ✓ `it('body tenant_id is ignored, path param wins')` ← regressão crítica

### 7.2 Pentest leve (regressões esperadas)

- **IDOR via path**: superadmin trocando `:tenant` continua tendo acesso (esperado), mas `audit_log` registra o tenant correto.
- **Privilege escalation via body**: usuário não-admin chama `POST /admin/tenants/{id}/contacts` → 403 antes de qualquer leitura.
- **Token reuse após reset**: após `POST /users/{id}/reset-password`, todos os tokens daquele user param de funcionar (401 no próximo request).
- **Race em destroy**: tentar excluir contato simultaneamente em duas requisições → uma 200, outra 404 (não 500).
- **Funnel editor admin**: query `?admin_tenant=X` só funciona para superadmin; user normal ignora a query e edita seu próprio funil.

### 7.3 Frontend — testes mínimos (Vitest)

- Detail.vue carrega tenant e renderiza aba correta via query string
- Cada aba faz lazy fetch só quando ativa
- Modal de senha temporária só permite fechar após copiar ou clicar "Entendi"

## 8. Plano de migração / rollout

1. Migration `add_admin_fields_to_users` (`force_password_reset` + `status`) com rollback.
2. Atualizar `AuthController` para honrar `status = 'suspended'` (403) e `force_password_reset` (redirect).
3. Backend: controllers + rotas + audit logging + testes feature.
4. Frontend: extrair `*Table.vue` compartilhados (refactor sem alterar páginas atuais).
5. Frontend: criar `Detail.vue` + abas + rotas.
6. Frontend: linkar `pages/admin/Tenants.vue` para o novo `/admin/tenants/:id`.
7. Manter modal de canais atual funcionando durante transição; remover quando aba "Canais" estiver estável.
8. Documentar em `docs/PROJECT.md` o fluxo admin.

## 9. Riscos e mitigações

| Risco | Mitigação |
|-------|-----------|
| Bug em controller admin permite editar tenant errado | `tenant_id` vem sempre do path, body é descartado; audit registra `target_tenant_id` |
| Refatoração de `*Table.vue` quebra páginas tenant | Refactor em commit separado, com testes; PR isolado antes de qualquer feature nova |
| Reset de senha vaza senha em log | Senha **nunca** é logada nem persistida em plaintext; aparece só no response da request única |
| Admin acidentalmente exclui dados de tenant | Modal de confirmação destrutiva com nome digitado + audit irreversível |
| Performance: `/audit` retorna milhões de linhas | Paginação obrigatória + índice composto `(tenant_id, action, created_at)` já existe (commit `b2348c8d`) |

## 10. Critérios de aceite

- [ ] `/admin/tenants/:id` carrega para superadmin, 403 para outros
- [ ] As 10 abas funcionam, com lazy fetch
- [ ] Toda escrita admin aparece em `audit_log` com `action` prefixado `admin.*`
- [ ] Reset de senha invalida tokens existentes e mostra senha 1x
- [ ] Funnel editor com `?admin_tenant=X` funciona só para superadmin
- [ ] Feature tests do item 7.1 todos passam
- [ ] Pentest manual do item 7.2 sem achados
- [ ] Sem regressões na página `/admin/tenants` (lista) nem em `/admin/billing/tenants/:id`
