# Email Sender Domains · Sync Infobip (admin)

**Data**: 2026-05-30
**Status**: Design aprovado, pronto para plano de implementação
**Branch sugerida**: `feat/email-domain-sync`

---

## 1. Contexto e problema

Hoje o fluxo de onboarding de domínio de email é **só self-service** via tenant:

- Tenant chama `POST /api/v1/email-domains` → `EmailDomainsController@store` → `EmailDomainService::register()` → `POST /email/1/domains` no Infobip.
- O registro local em `email_sender_domains` é criado simultaneamente ao registro remoto.

**O que falta**: alguns domínios são onboardados **manualmente** direto no painel Infobip (ex.: `pixreals.com` do tenant do usuário pedro). Esses domínios existem no Infobip mas **não** existem na nossa tabela `email_sender_domains`, portanto:

- O tenant não consegue ver o domínio na UI.
- O guard de `from_email` em campanhas (`EmailDomainService::findActiveForEmail`) bloqueia envios porque não encontra o domínio ativo na DB.
- Não há mecanismo para o superadmin importar e atribuir esses domínios manualmente onboardados.

O padrão equivalente já existe para números WhatsApp (`Admin\InfobipWhatsAppController`: `numbers`, `sync`, `assign`). Replicar esse padrão pra domínios de email.

## 2. Objetivo

Permitir ao superadmin:

1. **Sincronizar** todos os domínios cadastrados na conta Infobip (`GET /email/1/domains`) para a tabela local `email_sender_domains`.
2. **Visualizar** o pool de domínios sincronizados (incluindo os ainda sem tenant atribuído).
3. **Atribuir** um domínio sincronizado a um tenant (ou remover atribuição).

Sem isso, o operador precisaria criar registros manualmente via SQL ou re-registrar o domínio (o que falha porque o Infobip já reconhece o domínio).

## 3. Não-objetivos (YAGNI explícito)

- ❌ Sincronizar **senders/addresses** individuais (`pedro@pixreals.com`). Apenas domínios. Qualquer endereço cujo domínio esteja `active` pode enviar.
- ❌ Sincronização automática (cron). Sync é manual, acionado pelo admin via botão.
- ❌ Migração das rotas de tenant (`POST /email-domains`) — o fluxo self-service continua intacto e coexiste com o sync.
- ❌ Reescrita do parser de DNS records. Reaproveitar `EmailDomainService::parseDnsRecords` extraindo para um helper público/protegido.
- ❌ Tracking config (`tracking_opens`/`tracking_clicks`) no fluxo sync — preservamos o que vier do Infobip; não alteramos via sync.
- ❌ Exclusão remota via admin (já existe via tenant `DELETE /email-domains/{id}`).

## 4. Mudanças no schema

Migration nova: `2026_05_30_000001_email_sender_domains_allow_admin_sync.php`

```php
Schema::table('email_sender_domains', function (Blueprint $t) {
    // tenant_id passa a ser nullable (NULL = domínio sincronizado sem atribuição)
    $t->unsignedBigInteger('tenant_id')->nullable()->change();

    // Substituir unique(tenant_id, domain) por unique(domain).
    // Infobip já enforça unicidade global do domínio na conta — refletir isso.
    $t->dropUnique('esd_tenant_domain_unique');
    $t->unique('domain', 'esd_domain_unique');

    // FK on delete: nullOnDelete em vez de cascadeOnDelete
    // (se tenant é deletado, domínio volta pro pool — não some)
    $t->dropForeign(['tenant_id']);
    $t->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
});
```

**Impacto em scopes:** `EmailSenderDomain` usa `AppliesTenantScope` global. O global scope filtra por `tenant_id = auth()->user()->tenant_id`. Para o admin pool:

- Listing admin (`InfobipEmailController@domains`) chama `EmailSenderDomain::withoutGlobalScopes()->...`
- Tenant continua vendo só os próprios.
- Tenant **não** vê domínios `tenant_id=null` (pool), apenas os atribuídos a ele.

## 5. Backend — arquivos novos / alterados

### 5.1 Novos

| Arquivo | Função |
|---|---|
| `backend/database/migrations/2026_05_30_000001_email_sender_domains_allow_admin_sync.php` | Schema change descrito acima. |
| `backend/app/Http/Controllers/API/V1/Admin/InfobipEmailController.php` | 3 endpoints admin (ver 5.3). |
| `backend/tests/Feature/Admin/InfobipEmailSyncTest.php` | Cobertura feature (sync, assign, IDOR). |
| `backend/tests/Unit/Services/EmailDomainSyncTest.php` | Cobertura unitária do upsert (mock HTTP Infobip). |

### 5.2 Alterados

| Arquivo | Mudança |
|---|---|
| `backend/app/Models/EmailSenderDomain.php` | Adicionar relação `tenant()` (belongsTo) se ainda não houver; garantir `tenant_id` em `$fillable`. |
| `backend/app/Services/Messaging/EmailDomainService.php` | **Novo método `syncFromInfobip(): array`**. Move `parseDnsRecords` de `private` para `public` (chamado pelo controller via service) OU encapsula a iteração de sync inteira dentro do service (escolha: encapsular no service, controller fica fino). |
| `backend/routes/api.php` | Adicionar 3 rotas no bloco `admin/` (próximo às de `infobip-whatsapp`). |

### 5.3 Endpoints admin

Todos sob `auth:sanctum` + `role:superadmin`, prefixo `/api/v1/admin/`:

| Verb | Path | Controller@action | Resposta |
|---|---|---|---|
| GET | `infobip-email/domains` | `InfobipEmailController@domains` | `{ data: EmailSenderDomain[] with tenant:id,name }`, ordenado por `domain` |
| POST | `infobip-email/sync` | `InfobipEmailController@sync` | `{ data: { synced: int }, message: "{N} domínios sincronizados" }` |
| PUT | `infobip-email/domains/{id}/assign` | `InfobipEmailController@assign` | `{ data: EmailSenderDomain with tenant }` |

**`sync` lógica (no `EmailDomainService::syncFromInfobip`):**

1. `GET /email/1/domains?page=0&size=1000` no Infobip (paginado se preciso).
2. Resposta esperada (forma Infobip 2026): `{ results: [{ domainName, domainId, active, dnsRecords: [...], tracking: {open, clicks}, ... }], paging: {...} }`.
3. Pra cada domínio:
   - `updateOrCreate(['domain' => $domainName], [...])` — chave de unicidade é `domain` (global).
   - **Preserva `tenant_id` existente** (não sobrescreve se já atribuído).
   - Atualiza: `infobip_domain_id`, `dkim_*`, `spf_*`, `return_path_*`, `tracking_*`, `status` (deriva via mesma lógica do `verify()`).
   - `last_verified_at = now()`.
4. Retorna `['synced' => N]` + log no canal `infobip` (`email_domain.synced`).

**`assign` lógica:**

1. Valida `{ tenant_id: nullable|integer|exists:tenants,id }`.
2. Se `tenant_id` definido: garantir que nenhum **outro** domínio está atribuído a esse tenant ... **espera**: diferente do WhatsApp (1 número = 1 tenant), um tenant pode ter **múltiplos** domínios de email. Portanto **não desatribuir os outros** — só atualiza este registro.
3. Se `tenant_id` for setado (não-null), upsert `settings.email.provider = infobip` para o tenant via `SettingsService::upsert($tenantId, 'email', 'provider', 'infobip', 'string')`.
4. `$domain->update(['tenant_id' => $tenantId])`.

### 5.4 Guarda contra colisão tenant×domain pre-existente

Ao remover o unique `(tenant_id, domain)` e trocar por `unique(domain)`, há risco de migration falhar se já houver duplicatas (tenants diferentes que registraram o mesmo domínio em algum ambiente de teste). A migration **deve falhar barulhento** nesse caso (não fazer auto-merge silencioso). O usuário valida o DB antes de rodar em produção.

## 6. Frontend — admin global Infobip

**Decisão**: painel admin **global** (paralelo ao InfobipWhatsApp), não dentro de `/admin/tenants/:id`. Razão: coerência com o existente — números WhatsApp são geridos em página admin global de Infobip; domínios de email seguem o mesmo padrão. Atribuição a tenant continua sendo feita ali via select.

### 6.1 Arquivos

| Arquivo | Mudança |
|---|---|
| `frontend/src/pages/admin/InfobipEmailDomains.vue` | **Novo**. Tabela de domínios + botão "Sincronizar" + modal de atribuição. |
| `frontend/src/router/index.ts` (ou similar) | Adicionar rota `/admin/infobip-email`. |
| `frontend/src/pages/admin/InfobipWhatsApp.vue` (ou layout admin) | Adicionar link/tab pra nova página. |
| `frontend/src/stores/adminInfobipEmail.ts` | Pinia store: `list`, `sync`, `assign`. |
| `frontend/src/api/admin.ts` (ou equivalente) | 3 métodos HTTP. |

### 6.2 UI

- **Tabela**: colunas `Domínio`, `Tenant atribuído` (nome ou "—"), `Status` (badge: pending/verifying/active/failed), `DKIM`, `SPF`, `Return-Path` (checks), `Última verificação`.
- **Botão "Sincronizar"** no topo direito → POST sync, exibe toast com count.
- **Linha clicável** → modal "Atribuir tenant" com select (campo `tenant_id`, opção "Remover atribuição" = null).
- Reusa componentes existentes: `ConfirmDestructiveModal` se precisar, badges de status do admin tenant detail.

## 7. Testes

Memória do projeto exige **testes unitários + pentest a cada task**.

### 7.1 Unitários (`Unit/Services/EmailDomainSyncTest.php`)

- `sync_creates_new_domain_from_infobip_response`
- `sync_updates_existing_domain_preserving_tenant_id`
- `sync_handles_paginated_response`
- `sync_throws_on_missing_api_key`
- `sync_marks_status_active_when_all_dns_verified`
- `sync_marks_status_verifying_when_partial`
- `parseDnsRecords_extracts_dkim_spf_return_path_correctly` (já implícito, regressão)

### 7.2 Feature (`Feature/Admin/InfobipEmailSyncTest.php`)

- `non_superadmin_cannot_access_admin_endpoints` (403 em GET/POST/PUT)
- `superadmin_lists_domains_including_unassigned_pool`
- `superadmin_syncs_domains_from_infobip` (Http::fake)
- `superadmin_assigns_domain_to_tenant_and_sets_provider`
- `superadmin_unassigns_domain_with_null_tenant_id`
- `tenant_cannot_see_unassigned_domains_in_pool` (via `/email-domains` index)

### 7.3 Pentest (`Feature/Security/EmailDomainsAdminIdorTest.php`)

- `assign_rejects_non_existent_tenant_id` (422)
- `assign_rejects_string_tenant_id_injection` (422)
- `assign_idempotent_no_state_leak` (mesmo PUT 2x não duplica)
- `sync_endpoint_requires_csrf_or_sanctum_token`
- `tenant_token_cannot_call_admin_sync_endpoint` (403, não 401)
- `assign_to_deleted_tenant_returns_422` (FK validation)

## 8. Plano de rollout

1. Merge backend (migration + controller + service method + tests).
2. Run migration em staging — verificar que `email_sender_domains` continua consistente.
3. Merge frontend.
4. Admin acessa `/admin/infobip-email` → clica "Sincronizar" → `pixreals.com` aparece com `tenant_id=null`.
5. Admin atribui ao tenant do pedro via modal.
6. Pedro vê o domínio na UI de tenant e consegue criar campanhas com `from_email: pedro@pixreals.com`.

## 9. Riscos e mitigações

| Risco | Mitigação |
|---|---|
| Migration falha por duplicata `(tenant_id, domain)` em prod | Rodar query de auditoria antes (`SELECT domain, COUNT(*) FROM email_sender_domains GROUP BY domain HAVING COUNT > 1`). |
| Sync sobrescreve `tenant_id` de domínio já atribuído | Lógica explícita: `updateOrCreate` exclui `tenant_id` do array de update; só insere `tenant_id=null` no create. Coberto por teste unitário. |
| Infobip muda formato da resposta `/email/1/domains` | Parser defensivo em `parseDnsRecords` (já é). Adicionar teste com fixture do payload real. |
| Tenant excluído enquanto domínio atribuído | FK `nullOnDelete` (não cascade) — domínio volta pro pool. |
| Sync demorado / timeout HTTP | `timeout(30)` no client; paginação com `page`/`size` controlada. |

## 10. Métricas de sucesso

- Após sync inicial em prod, todos os domínios manualmente onboardados aparecem em `email_sender_domains` (auditoria SQL).
- Tenant do pedro consegue enviar via `pedro@pixreals.com` sem erro 422 de "domínio não cadastrado".
- 0 ocorrências de IDOR / privilege escalation nos testes pentest.
