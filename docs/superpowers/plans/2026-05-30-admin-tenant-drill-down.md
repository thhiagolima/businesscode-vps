# Admin · Tenant Drill-Down Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir `/admin/tenants/:id` com 10 abas (overview, campanhas, contatos, conversas, funis, usuários, canais, relatórios, auditoria, link financeiro) — leitura completa e edição administrativa das operações de qualquer tenant, com auditoria forte e read-only em relatórios/auditoria.

**Architecture:** Novos controllers admin sob `/api/v1/admin/tenants/{tenant}/...` (middleware `superadmin`) que reusam models existentes (o trait `AppliesTenantScope` já libera superadmin do escopo global; basta filtrar `where('tenant_id', $id)` explicitamente). Frontend ganha um shell `Detail.vue` com 10 abas lazy-load, reusando tabelas extraídas das páginas tenant (`CampaignsTable.vue`, `ContactsTable.vue` etc.). Toda escrita admin grava `audit_log` com `action='admin.{resource}.{verb}'`.

**Tech Stack:** Laravel 11 (PHP 8.2), Sanctum, PHPUnit. Vue 3 + Pinia + vue-router. Bootstrap 5 + Tabler icons (`ti ti-*`). Vitest (frontend).

**Spec:** `docs/superpowers/specs/2026-05-30-admin-tenant-drill-down-design.md`

---

## File Structure

### Backend (new)

```
backend/
├── database/migrations/
│   └── 2026_05_30_140000_add_admin_fields_to_users.php
├── app/Http/Controllers/API/V1/Admin/
│   ├── TenantOverviewController.php
│   ├── TenantCampaignsController.php
│   ├── TenantContactsController.php
│   ├── TenantConversationsController.php
│   ├── TenantFunnelsController.php
│   ├── TenantUsersController.php
│   ├── TenantReportsController.php
│   └── TenantAuditController.php
├── app/Services/Admin/
│   └── AdminAuditLogger.php          # wrapper para padronizar admin.* actions
└── tests/Feature/Admin/
    ├── TenantOverviewTest.php
    ├── TenantCampaignsAdminTest.php
    ├── TenantContactsAdminTest.php
    ├── TenantConversationsAdminTest.php
    ├── TenantFunnelsAdminTest.php
    ├── TenantUsersAdminTest.php
    ├── TenantReportsAdminTest.php
    └── TenantAuditAdminTest.php

backend/tests/Pentest/
└── AdminTenantDrillDownSecurityTest.php   # IDOR, body-tenant-id, token revocation, suspended login
```

### Backend (modified)

- `backend/routes/api.php` — adicionar bloco de rotas `/admin/tenants/{tenant}/...`
- `backend/app/Models/User.php` — adicionar `force_password_reset` e `status` ao `$fillable`/`$casts`
- `backend/app/Http/Controllers/API/V1/AuthController.php` — bloquear login de `status='suspended'`; retornar `force_password_reset` no payload
- `backend/database/factories/UserFactory.php` — default `status='active'`, `force_password_reset=false`

### Frontend (new)

```
frontend/src/
├── pages/admin/tenants/
│   └── Detail.vue
├── components/admin/tenants/
│   ├── TabOverview.vue
│   ├── TabCampaigns.vue
│   ├── TabContacts.vue
│   ├── TabConversations.vue
│   ├── TabFunnels.vue
│   ├── TabUsers.vue
│   ├── TabChannels.vue
│   ├── TabReports.vue
│   ├── TabAudit.vue
│   └── modals/
│       ├── PasswordResetResultModal.vue
│       └── ConfirmDestructiveModal.vue
├── components/shared/tables/
│   ├── CampaignsTable.vue
│   ├── ContactsTable.vue
│   ├── ConversationsTable.vue
│   ├── FunnelsTable.vue
│   └── UsersTable.vue
└── stores/adminTenantDetail.ts
```

### Frontend (modified)

- `frontend/src/router/index.ts` — rota `/admin/tenants/:id`
- `frontend/src/pages/admin/Tenants.vue` — linha clicável → `/admin/tenants/${t.id}`
- `frontend/src/pages/campaigns/Index.vue` — passa a renderizar `CampaignsTable.vue`
- `frontend/src/pages/contacts/Index.vue` — passa a renderizar `ContactsTable.vue`
- `frontend/src/pages/funnels/Index.vue` — passa a renderizar `FunnelsTable.vue`
- `frontend/src/pages/conversations/Index.vue` — passa a renderizar `ConversationsTable.vue`
- `frontend/src/pages/funnels/Editor.vue` — aceitar `?admin_tenant=X` e exibir banner

---

## Conventions used in this plan

- **Branch:** `feat/admin-tenant-drill-down` (criar antes de Task 0)
- **Commit prefix:** `feat(admin):` para features, `test(admin):` para testes isolados, `refactor:` para extrações
- **Test runner backend:** `cd backend && php artisan test --filter=NomeDoTeste`
- **Test runner frontend:** `cd frontend && npm run test -- NomeDoTeste`
- **Auth para testes:** `Sanctum::actingAs($user, ['*'])` — padrão visto em `tests/Feature/Admin/AdminPricingApiTest.php`
- **Response helper:** `ApiResponse::success(...)`, `ApiResponse::error(...)`, `ApiResponse::paginated(...)` em `app/Http/Responses/ApiResponse.php`
- **Audit:** `AuditLog::record($action, $resource, $resourceId, $metadata, $userId, $tenantId)` (`app/Models/AuditLog.php:39`)
- **`useApi` no front:** `const { get, post, put, patch, del } = useApi()` (em `composables/useApi.ts`)

---

## Phase 0 — Setup

### Task 0: Criar branch de feature

**Files:** nenhum.

- [ ] **Step 1: Confirmar branch base e criar nova branch**

Run:
```bash
cd c:/xampp/htdocs/new_saas
git status
git checkout -b feat/admin-tenant-drill-down
```

Expected: branch criada limpa.

---

## Phase 1 — Foundation (migration + auth changes)

### Task 1: Migration `add_admin_fields_to_users`

**Files:**
- Create: `backend/database/migrations/2026_05_30_140000_add_admin_fields_to_users.php`

- [ ] **Step 1: Criar migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('force_password_reset')->default(false)->after('password');
            $table->string('status', 20)->default('active')->after('force_password_reset');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['force_password_reset', 'status']);
        });
    }
};
```

- [ ] **Step 2: Rodar migration e validar**

Run:
```bash
cd backend && php artisan migrate
php artisan migrate:rollback
php artisan migrate
```

Expected: migrate / rollback / migrate sem erros.

- [ ] **Step 3: Commit**

```bash
git add backend/database/migrations/2026_05_30_140000_add_admin_fields_to_users.php
git commit -m "feat(admin): add force_password_reset and status to users"
```

---

### Task 2: Atualizar `UserFactory`

**Files:**
- Modify: `backend/database/factories/UserFactory.php`

- [ ] **Step 1: Adicionar defaults**

Abrir `backend/database/factories/UserFactory.php` e no método `definition()`, garantir as duas chaves novas:

```php
return [
    // ... existentes
    'force_password_reset' => false,
    'status'               => 'active',
];
```

- [ ] **Step 2: Rodar suite atual para garantir que nada quebrou**

Run: `cd backend && php artisan test --testsuite=Feature --stop-on-failure`
Expected: PASS (todos os testes existentes continuam verdes).

- [ ] **Step 3: Commit**

```bash
git add backend/database/factories/UserFactory.php
git commit -m "test(admin): user factory defaults for new admin fields"
```

---

### Task 3: Testes de regressão para login com `status='suspended'`

**Files:**
- Create: `backend/tests/Feature/Auth/SuspendedUserLoginTest.php`

- [ ] **Step 1: Escrever test que falha**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspendedUserLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspended_user_cannot_login(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email'     => 'sus@test.com',
            'password'  => bcrypt('secret123A'),
            'status'    => 'suspended',
        ]);

        $resp = $this->postJson('/api/v1/auth/login', [
            'email'    => 'sus@test.com',
            'password' => 'secret123A',
        ]);

        $resp->assertStatus(403)
            ->assertJsonPath('message', 'Conta suspensa. Contate o suporte.');
    }

    public function test_active_user_login_returns_force_password_reset_flag(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id'            => $tenant->id,
            'email'                => 'fpr@test.com',
            'password'             => bcrypt('secret123A'),
            'force_password_reset' => true,
        ]);

        $resp = $this->postJson('/api/v1/auth/login', [
            'email'    => 'fpr@test.com',
            'password' => 'secret123A',
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.user.force_password_reset', true);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=SuspendedUserLoginTest`
Expected: FAIL — campo `status` não é checado no login; payload de login não inclui `force_password_reset`.

---

### Task 4: AuthController — bloquear suspended e expor `force_password_reset`

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/AuthController.php`

- [ ] **Step 1: Implementar bloqueio suspended e expor flag**

No método `login()`, **antes** de `$user->createToken('auth')`, adicionar (após linha 119, depois da validação de credenciais):

```php
if ($user->status === 'suspended') {
    AuditLog::record('auth.login_blocked_suspended', 'User', $user->id, null, $user->id, $user->tenant_id);
    return ApiResponse::error('Conta suspensa. Contate o suporte.', [], 403);
}
```

E no payload de retorno do `login()` e do `register()` e do `me()`, adicionar `'force_password_reset' => (bool) $user->force_password_reset` dentro do objeto `user`.

- [ ] **Step 2: Rodar testes**

Run: `cd backend && php artisan test --filter=SuspendedUserLoginTest`
Expected: PASS.

- [ ] **Step 3: Rodar suite Auth completa**

Run: `cd backend && php artisan test tests/Feature/Auth`
Expected: PASS (sem regressão).

- [ ] **Step 4: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/AuthController.php backend/tests/Feature/Auth/SuspendedUserLoginTest.php
git commit -m "feat(auth): block suspended users and surface force_password_reset"
```

---

### Task 5: Frontend — interceptar `force_password_reset` no auth store

**Files:**
- Modify: `frontend/src/stores/auth.ts`
- Modify: `frontend/src/router/index.ts`

- [ ] **Step 1: Estender o tipo `User` no store**

Em `frontend/src/stores/auth.ts`, na interface do user, adicionar:

```ts
force_password_reset?: boolean
status?: 'active' | 'suspended'
```

- [ ] **Step 2: Adicionar guard no router**

Em `frontend/src/router/index.ts`, dentro do `router.beforeEach`, **após** o bloco que checa `auth.isAuthenticated`, adicionar:

```ts
if (auth.user?.force_password_reset && to.path !== '/profile' && !to.path.startsWith('/auth/')) {
  return next({ path: '/profile', query: { force_password_reset: '1' } })
}
```

(A página `/profile` já tem formulário de troca de senha — basta exibir um aviso quando a query `force_password_reset=1` estiver presente. Não vamos criar tela nova nesta fase.)

- [ ] **Step 3: Banner em `pages/profile/Index.vue`**

Adicionar no topo do template (antes do conteúdo principal):

```vue
<div v-if="$route.query.force_password_reset === '1'" class="alert alert-warning d-flex align-items-center" style="border-radius:12px">
  <i class="ti ti-key me-2"></i>
  <div>
    <strong>Troca de senha obrigatória.</strong>
    Sua senha foi redefinida pelo administrador. Defina uma nova senha para continuar.
  </div>
</div>
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/stores/auth.ts frontend/src/router/index.ts frontend/src/pages/profile/Index.vue
git commit -m "feat(auth): force password reset flow in frontend"
```

---

### Task 6: Service `AdminAuditLogger`

**Files:**
- Create: `backend/app/Services/Admin/AdminAuditLogger.php`

- [ ] **Step 1: Criar wrapper**

```php
<?php

namespace App\Services\Admin;

use App\Models\AuditLog;

class AdminAuditLogger
{
    /**
     * Registra ação administrativa (superadmin operando sobre outro tenant).
     * Garante prefixo `admin.` e força os campos corretos: user_id = quem fez,
     * tenant_id = tenant impactado.
     */
    public static function log(
        string $resource,
        string $verb,
        int $targetTenantId,
        ?int $targetResourceId = null,
        ?array $extra = null,
    ): void {
        $action = "admin.{$resource}.{$verb}";
        $adminId = auth()->id();

        AuditLog::record(
            $action,
            ucfirst($resource),
            $targetResourceId,
            array_merge(['target_tenant_id' => $targetTenantId], $extra ?? []),
            $adminId,
            $targetTenantId,
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add backend/app/Services/Admin/AdminAuditLogger.php
git commit -m "feat(admin): centralized admin audit logger"
```

---

## Phase 2 — Backend per resource

### Task 7: `TenantOverviewController` (KPIs)

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantOverviewController.php`
- Create: `backend/tests/Feature/Admin/TenantOverviewTest.php`

- [ ] **Step 1: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_sees_kpis_of_any_tenant(): void
    {
        $target = Tenant::factory()->create();
        Campaign::factory()->count(3)->create(['tenant_id' => $target->id]);
        Contact::factory()->count(5)->create(['tenant_id' => $target->id]);

        $admin = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($admin, ['*']);

        $resp = $this->getJson("/api/v1/admin/tenants/{$target->id}/overview");

        $resp->assertStatus(200)
            ->assertJsonPath('data.tenant.id', $target->id)
            ->assertJsonPath('data.kpis.campaigns_total', 3)
            ->assertJsonPath('data.kpis.contacts_total', 5);
    }

    public function test_regular_user_gets_403(): void
    {
        $target = Tenant::factory()->create();
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user, ['*']);

        $this->getJson("/api/v1/admin/tenants/{$target->id}/overview")
            ->assertStatus(403);
    }

    public function test_unknown_tenant_404(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson("/api/v1/admin/tenants/9999/overview")
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Rodar — esperar fail por rota inexistente**

Run: `cd backend && php artisan test --filter=TenantOverviewTest`
Expected: FAIL — rota não definida (404).

- [ ] **Step 3: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;

class TenantOverviewController extends Controller
{
    public function index(int $tenant)
    {
        $t = Tenant::with('plan')->findOrFail($tenant);

        $kpis = [
            'campaigns_total'      => Campaign::where('tenant_id', $t->id)->count(),
            'campaigns_active'     => Campaign::where('tenant_id', $t->id)
                                              ->whereIn('status', ['scheduled', 'processing', 'running'])
                                              ->count(),
            'contacts_total'       => Contact::where('tenant_id', $t->id)->count(),
            'messages_sent_30d'    => MessageDispatch::where('tenant_id', $t->id)
                                                     ->where('created_at', '>=', now()->subDays(30))
                                                     ->count(),
            'messages_delivered_30d' => MessageDispatch::where('tenant_id', $t->id)
                                                       ->where('status', 'delivered')
                                                       ->where('created_at', '>=', now()->subDays(30))
                                                       ->count(),
            'conversations_open'   => Conversation::where('tenant_id', $t->id)
                                                  ->where('status', 'open')
                                                  ->count(),
            'users_total'          => User::where('tenant_id', $t->id)->count(),
        ];

        return ApiResponse::success([
            'tenant' => $t,
            'kpis'   => $kpis,
        ]);
    }
}
```

- [ ] **Step 4: Adicionar rota em `routes/api.php`**

Adicionar import no topo:
```php
use App\Http\Controllers\API\V1\Admin\TenantOverviewController;
```

E dentro do grupo `Route::middleware('superadmin')->prefix('admin')->group(...)` (linha ~280), **após** o bloco "Tenants" existente, adicionar **sub-grupo**:

```php
// Drill-down individual por tenant
Route::prefix('tenants/{tenant}')->group(function () {
    Route::get('overview', [TenantOverviewController::class, 'index']);
});
```

- [ ] **Step 5: Rodar testes**

Run: `cd backend && php artisan test --filter=TenantOverviewTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantOverviewController.php backend/routes/api.php backend/tests/Feature/Admin/TenantOverviewTest.php
git commit -m "feat(admin): tenant overview endpoint with KPIs"
```

---

### Task 8: `TenantCampaignsController`

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantCampaignsController.php`
- Create: `backend/tests/Feature/Admin/TenantCampaignsAdminTest.php`

- [ ] **Step 1: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantCampaignsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperadmin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_lists_campaigns_of_target_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $other  = Tenant::factory()->create();
        Campaign::factory()->count(2)->create(['tenant_id' => $tenant->id]);
        Campaign::factory()->count(5)->create(['tenant_id' => $other->id]);

        $this->asSuperadmin();

        $resp = $this->getJson("/api/v1/admin/tenants/{$tenant->id}/campaigns");
        $resp->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_cancel_campaign_audits_admin_action(): void
    {
        $tenant = Tenant::factory()->create();
        $c = Campaign::factory()->create(['tenant_id' => $tenant->id, 'status' => 'scheduled']);
        $admin = $this->asSuperadmin();

        $resp = $this->patchJson("/api/v1/admin/tenants/{$tenant->id}/campaigns/{$c->id}", [
            'action' => 'cancel',
            'reason' => 'cliente solicitou via suporte',
        ]);

        $resp->assertStatus(200);
        $this->assertSame('failed', $c->fresh()->status); // Campaign cancel transitions to failed

        $log = AuditLog::where('action', 'admin.campaign.cancel')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($tenant->id, $log->metadata['target_tenant_id']);
        $this->assertSame('cliente solicitou via suporte', $log->metadata['reason']);
    }

    public function test_body_tenant_id_is_ignored(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $c = Campaign::factory()->create(['tenant_id' => $tenant->id, 'status' => 'scheduled']);
        $this->asSuperadmin();

        $resp = $this->patchJson("/api/v1/admin/tenants/{$tenant->id}/campaigns/{$c->id}", [
            'action'    => 'cancel',
            'reason'    => 'teste',
            'tenant_id' => $other->id, // tenta sobrescrever via body
        ]);

        $resp->assertStatus(200);
        $this->assertSame($tenant->id, $c->fresh()->tenant_id); // path wins
    }

    public function test_delete_running_campaign_returns_422(): void
    {
        $tenant = Tenant::factory()->create();
        $c = Campaign::factory()->create(['tenant_id' => $tenant->id, 'status' => 'running']);
        $this->asSuperadmin();

        $this->deleteJson("/api/v1/admin/tenants/{$tenant->id}/campaigns/{$c->id}")
            ->assertStatus(422);
    }

    public function test_non_superadmin_403(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($u, ['*']);

        $this->getJson("/api/v1/admin/tenants/{$tenant->id}/campaigns")
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Rodar — esperar FAIL**

Run: `cd backend && php artisan test --filter=TenantCampaignsAdminTest`
Expected: FAIL — rotas não existem.

- [ ] **Step 3: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use App\Services\CampaignStateMachine;
use Illuminate\Http\Request;

class TenantCampaignsController extends Controller
{
    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'type'   => ['nullable', 'in:sms,voice,email,whatsapp'],
            'status' => ['nullable', 'in:draft,scheduled,processing,running,completed,failed'],
        ]);

        $q = Campaign::where('tenant_id', $tenant)->with('contactList:id,name,contact_count');

        if (!empty($validated['search'])) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $q->where('name', 'like', "%{$s}%");
        }
        if (!empty($validated['type']))   $q->where('type',   $validated['type']);
        if (!empty($validated['status'])) $q->where('status', $validated['status']);

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(20));
    }

    public function show(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $c = Campaign::where('tenant_id', $tenant)->with('contactList')->findOrFail($id);
        return ApiResponse::success($c);
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $c = Campaign::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'action' => ['required', 'in:pause,resume,cancel'],
            'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:500'],
        ]);

        $before = $c->status;

        // Reusa state machine existente — método único `transition($campaign, $newStatus)`
        $sm = app(CampaignStateMachine::class);
        match ($data['action']) {
            'pause'  => $sm->transition($c, 'paused'),
            'resume' => $sm->transition($c, 'processing'),
            'cancel' => $sm->transition($c, 'failed'),
        };

        AdminAuditLogger::log('campaign', $data['action'], $tenant, $c->id, [
            'before' => $before,
            'after'  => $c->fresh()->status,
            'reason' => $data['reason'] ?? null,
        ]);

        return ApiResponse::success($c->fresh(), "Campanha {$data['action']} ok");
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $c = Campaign::where('tenant_id', $tenant)->findOrFail($id);

        if (in_array($c->status, ['processing', 'running'])) {
            return ApiResponse::error(
                'Não é possível excluir campanha em execução. Cancele antes.',
                ['status' => $c->status],
                422,
            );
        }

        $name = $c->name;
        $c->delete();

        AdminAuditLogger::log('campaign', 'delete', $tenant, $id, ['name' => $name]);

        return ApiResponse::success([], 'Campanha removida');
    }
}
```

- [ ] **Step 4: Adicionar rotas**

Adicionar import e dentro do bloco `Route::prefix('tenants/{tenant}')->group(...)`:

```php
use App\Http\Controllers\API\V1\Admin\TenantCampaignsController;
// ...
Route::get   ('campaigns',          [TenantCampaignsController::class, 'index']);
Route::get   ('campaigns/{id}',     [TenantCampaignsController::class, 'show']);
Route::patch ('campaigns/{id}',     [TenantCampaignsController::class, 'update']);
Route::delete('campaigns/{id}',     [TenantCampaignsController::class, 'destroy']);
```

- [ ] **Step 5: Confirmar transições válidas na state machine**

Run: `grep -n "transition\|valid" backend/app/Services/CampaignStateMachine.php`

A state machine usa `transition($campaign, $newStatus)` (assinatura única). Verifique se ela aceita as transições `scheduled → paused`, `paused → processing`, `scheduled → failed`. Se alguma transição for rejeitada, adicione-a à lista permitida na state machine — em commit separado dentro desta task.

- [ ] **Step 6: Rodar testes**

Run: `cd backend && php artisan test --filter=TenantCampaignsAdminTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantCampaignsController.php backend/routes/api.php backend/tests/Feature/Admin/TenantCampaignsAdminTest.php
git commit -m "feat(admin): tenant campaigns endpoints (list/show/pause/resume/cancel/delete)"
```

---

### Task 9: `TenantContactsController`

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantContactsController.php`
- Create: `backend/tests/Feature/Admin/TenantContactsAdminTest.php`

- [ ] **Step 1: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantContactsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperadmin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_lists_contacts_of_target_tenant_only(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        Contact::factory()->count(3)->create(['tenant_id' => $a->id]);
        Contact::factory()->count(7)->create(['tenant_id' => $b->id]);

        $this->asSuperadmin();
        $resp = $this->getJson("/api/v1/admin/tenants/{$a->id}/contacts");
        $resp->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_creates_contact_with_correct_tenant_id_even_if_body_says_otherwise(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $admin = $this->asSuperadmin();

        $resp = $this->postJson("/api/v1/admin/tenants/{$a->id}/contacts", [
            'name'      => 'João',
            'phone'     => '+5511999999999',
            'tenant_id' => $b->id, // tenta envenenar
        ]);

        $resp->assertStatus(201);
        $id = $resp->json('data.id');
        $this->assertSame($a->id, Contact::find($id)->tenant_id);

        $log = AuditLog::where('action', 'admin.contact.create')->latest('id')->first();
        $this->assertSame($a->id, $log->metadata['target_tenant_id']);
    }

    public function test_update_and_destroy_audit_logged(): void
    {
        $a = Tenant::factory()->create();
        $c = Contact::factory()->create(['tenant_id' => $a->id, 'name' => 'antes']);
        $this->asSuperadmin();

        $this->putJson("/api/v1/admin/tenants/{$a->id}/contacts/{$c->id}", ['name' => 'depois'])
            ->assertStatus(200);
        $this->assertSame('depois', $c->fresh()->name);

        $this->deleteJson("/api/v1/admin/tenants/{$a->id}/contacts/{$c->id}")
            ->assertStatus(200);

        $this->assertTrue(AuditLog::where('action', 'admin.contact.update')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.contact.delete')->exists());
    }

    public function test_cross_tenant_contact_returns_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $c = Contact::factory()->create(['tenant_id' => $b->id]);
        $this->asSuperadmin();

        $this->putJson("/api/v1/admin/tenants/{$a->id}/contacts/{$c->id}", ['name' => 'x'])
            ->assertStatus(404);
    }
}
```

- [ ] **Step 2: Rodar — esperar FAIL**

Run: `cd backend && php artisan test --filter=TenantContactsAdminTest`
Expected: FAIL.

- [ ] **Step 3: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantContactsController extends Controller
{
    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $validated = $request->validate([
            'search'          => ['nullable', 'string', 'max:255'],
            'contact_list_id' => ['nullable', 'integer'],
            'status'          => ['nullable', 'in:active,opted_out,blocked'],
        ]);

        $q = Contact::where('tenant_id', $tenant);
        if (!empty($validated['search'])) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', "%{$s}%")
                   ->orWhere('email', 'like', "%{$s}%")
                   ->orWhere('phone', 'like', "%{$s}%");
            });
        }
        if (!empty($validated['contact_list_id'])) $q->where('contact_list_id', $validated['contact_list_id']);
        if (!empty($validated['status']))          $q->where('status', $validated['status']);

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(20));
    }

    public function store(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $listExists = Rule::exists('contact_lists', 'id')->where('tenant_id', $tenant);

        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'phone'           => ['nullable', 'string', 'max:32'],
            'email'           => ['nullable', 'email', 'max:255'],
            'contact_list_id' => ['nullable', 'integer', $listExists],
            'status'          => ['nullable', 'in:active,opted_out,blocked'],
        ]);
        $data['tenant_id'] = $tenant; // path wins

        $contact = Contact::create($data);

        AdminAuditLogger::log('contact', 'create', $tenant, $contact->id, ['name' => $contact->name]);

        return ApiResponse::success($contact, 'Contato criado', [], 201);
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $contact = Contact::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'phone'  => ['sometimes', 'nullable', 'string', 'max:32'],
            'email'  => ['sometimes', 'nullable', 'email', 'max:255'],
            'status' => ['sometimes', 'in:active,opted_out,blocked'],
        ]);

        $before = $contact->only(array_keys($data));
        $contact->update($data);

        AdminAuditLogger::log('contact', 'update', $tenant, $id, ['before' => $before, 'after' => $data]);

        return ApiResponse::success($contact->fresh());
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $contact = Contact::where('tenant_id', $tenant)->findOrFail($id);
        $snap = $contact->only(['name', 'email', 'phone']);
        $contact->delete();

        AdminAuditLogger::log('contact', 'delete', $tenant, $id, $snap);

        return ApiResponse::success([], 'Contato removido');
    }

    public function lists(int $tenant)
    {
        Tenant::findOrFail($tenant);
        $lists = ContactList::where('tenant_id', $tenant)
            ->select(['id', 'name', 'contact_count'])
            ->orderByDesc('id')
            ->get();
        return ApiResponse::success($lists);
    }
}
```

- [ ] **Step 4: Adicionar rotas**

```php
use App\Http\Controllers\API\V1\Admin\TenantContactsController;
// dentro do prefix tenants/{tenant}:
Route::get   ('contacts',          [TenantContactsController::class, 'index']);
Route::post  ('contacts',          [TenantContactsController::class, 'store']);
Route::put   ('contacts/{id}',     [TenantContactsController::class, 'update']);
Route::delete('contacts/{id}',     [TenantContactsController::class, 'destroy']);
Route::get   ('contact-lists',     [TenantContactsController::class, 'lists']);
```

- [ ] **Step 5: Rodar testes**

Run: `cd backend && php artisan test --filter=TenantContactsAdminTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantContactsController.php backend/routes/api.php backend/tests/Feature/Admin/TenantContactsAdminTest.php
git commit -m "feat(admin): tenant contacts CRUD with audit"
```

---

### Task 10: `TenantConversationsController`

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantConversationsController.php`
- Create: `backend/tests/Feature/Admin/TenantConversationsAdminTest.php`

- [ ] **Step 1: Inspecionar modelo `Conversation`**

Run: `grep -n "status\|fillable" backend/app/Models/Conversation.php`
Os campos confirmados são `tenant_id, contact_id, phone, channel, status`. Não há `contact_name`. O search no controller usará `phone` apenas. Valores de `status` típicos: `open`, `closed`, `archived` — verifique migration se precisar de mais.

- [ ] **Step 2: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantConversationsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_updates_status(): void
    {
        $t = Tenant::factory()->create();
        $conv = Conversation::factory()->create(['tenant_id' => $t->id, 'status' => 'open']);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);

        $this->getJson("/api/v1/admin/tenants/{$t->id}/conversations")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/admin/tenants/{$t->id}/conversations/{$conv->id}", ['status' => 'closed'])
            ->assertStatus(200);

        $this->assertSame('closed', $conv->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'admin.conversation.update')->exists());
    }

    public function test_403_for_non_superadmin(): void
    {
        $t = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
        $this->getJson("/api/v1/admin/tenants/{$t->id}/conversations")->assertStatus(403);
    }
}
```

- [ ] **Step 3: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=TenantConversationsAdminTest`
Expected: FAIL.

- [ ] **Step 4: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;

class TenantConversationsController extends Controller
{
    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $request->validate([
            'status' => ['nullable', 'in:open,closed,archived'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $q = Conversation::where('tenant_id', $tenant);
        if ($s = $request->input('status'))  $q->where('status', $s);
        if ($search = $request->input('search')) {
            $esc = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $q->where('phone', 'like', "%{$esc}%");
        }

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(20));
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $conv = Conversation::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'status'           => ['sometimes', 'in:open,closed,archived'],
            'assigned_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $before = $conv->only(array_keys($data));
        $conv->update($data);

        AdminAuditLogger::log('conversation', 'update', $tenant, $id, ['before' => $before, 'after' => $data]);

        return ApiResponse::success($conv->fresh());
    }
}
```

- [ ] **Step 5: Rotas**

```php
use App\Http\Controllers\API\V1\Admin\TenantConversationsController;
Route::get  ('conversations',      [TenantConversationsController::class, 'index']);
Route::patch('conversations/{id}', [TenantConversationsController::class, 'update']);
```

- [ ] **Step 6: Rodar testes**

Run: `cd backend && php artisan test --filter=TenantConversationsAdminTest`
Expected: PASS.
**Se** `contact_name`/`contact_phone` não existirem no modelo, simplificar o `search` para usar `id` ou remover. Ver step 1.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantConversationsController.php backend/routes/api.php backend/tests/Feature/Admin/TenantConversationsAdminTest.php
git commit -m "feat(admin): tenant conversations endpoints"
```

---

### Task 11: `TenantFunnelsController`

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantFunnelsController.php`
- Create: `backend/tests/Feature/Admin/TenantFunnelsAdminTest.php`

- [ ] **Step 1: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Funnel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantFunnelsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_list_toggle_delete(): void
    {
        $t = Tenant::factory()->create();
        $f = Funnel::factory()->create(['tenant_id' => $t->id, 'is_active' => true]);
        $this->admin();

        $this->getJson("/api/v1/admin/tenants/{$t->id}/funnels")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/admin/tenants/{$t->id}/funnels/{$f->id}", ['is_active' => false])
            ->assertStatus(200);
        $this->assertFalse((bool) $f->fresh()->is_active);

        $this->deleteJson("/api/v1/admin/tenants/{$t->id}/funnels/{$f->id}")
            ->assertStatus(200);

        $this->assertTrue(AuditLog::where('action', 'admin.funnel.update')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.funnel.delete')->exists());
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=TenantFunnelsAdminTest`
Expected: FAIL.

- [ ] **Step 3: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Funnel;
use App\Models\Tenant;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;

class TenantFunnelsController extends Controller
{
    public function index(int $tenant)
    {
        Tenant::findOrFail($tenant);
        $list = Funnel::where('tenant_id', $tenant)->orderByDesc('id')->paginate(20);
        return ApiResponse::paginated($list);
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $f = Funnel::where('tenant_id', $tenant)->findOrFail($id);

        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'name'      => ['sometimes', 'string', 'max:255'],
        ]);

        $before = $f->only(array_keys($data));
        $f->update($data);

        AdminAuditLogger::log('funnel', 'update', $tenant, $id, ['before' => $before, 'after' => $data]);

        return ApiResponse::success($f->fresh());
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $f = Funnel::where('tenant_id', $tenant)->findOrFail($id);
        $name = $f->name;
        $f->delete();

        AdminAuditLogger::log('funnel', 'delete', $tenant, $id, ['name' => $name]);

        return ApiResponse::success([], 'Funil removido');
    }
}
```

- [ ] **Step 4: Rotas**

```php
use App\Http\Controllers\API\V1\Admin\TenantFunnelsController;
Route::get   ('funnels',           [TenantFunnelsController::class, 'index']);
Route::patch ('funnels/{id}',      [TenantFunnelsController::class, 'update']);
Route::delete('funnels/{id}',      [TenantFunnelsController::class, 'destroy']);
```

- [ ] **Step 5: Rodar testes + commit**

Run: `cd backend && php artisan test --filter=TenantFunnelsAdminTest`
Expected: PASS.

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantFunnelsController.php backend/routes/api.php backend/tests/Feature/Admin/TenantFunnelsAdminTest.php
git commit -m "feat(admin): tenant funnels endpoints"
```

---

### Task 12: `TenantUsersController` (com reset de senha)

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantUsersController.php`
- Create: `backend/tests/Feature/Admin/TenantUsersAdminTest.php`

- [ ] **Step 1: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantUsersAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_list_create_update_delete(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user']);
        $this->admin();

        $this->getJson("/api/v1/admin/tenants/{$t->id}/users")
            ->assertStatus(200)->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/admin/tenants/{$t->id}/users", [
            'name' => 'Novo', 'email' => 'novo@test.com', 'password' => 'Secret123', 'role' => 'admin',
        ])->assertStatus(201);

        $this->putJson("/api/v1/admin/tenants/{$t->id}/users/{$u->id}", ['status' => 'suspended'])
            ->assertStatus(200);

        $this->deleteJson("/api/v1/admin/tenants/{$t->id}/users/{$u->id}")
            ->assertStatus(200);

        $this->assertTrue(AuditLog::where('action', 'admin.user.create')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.user.update')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.user.delete')->exists());
    }

    public function test_suspending_user_revokes_tokens(): void
    {
        $t = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user']);
        $target->createToken('x');
        $this->admin();

        $this->putJson("/api/v1/admin/tenants/{$t->id}/users/{$target->id}", ['status' => 'suspended'])
            ->assertStatus(200);

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_reset_password_returns_temp_password_and_revokes_tokens(): void
    {
        $t = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user']);
        $target->createToken('x');
        $this->admin();

        $resp = $this->postJson("/api/v1/admin/tenants/{$t->id}/users/{$target->id}/reset-password");
        $resp->assertStatus(200)
             ->assertJsonStructure(['data' => ['temp_password']]);

        $temp = $resp->json('data.temp_password');
        $this->assertNotEmpty($temp);
        $this->assertGreaterThanOrEqual(16, strlen($temp));
        $this->assertTrue(Hash::check($temp, $target->fresh()->password));
        $this->assertTrue((bool) $target->fresh()->force_password_reset);
        $this->assertSame(0, $target->tokens()->count());

        $log = AuditLog::where('action', 'admin.user.password_reset')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('temp_password', $log->metadata ?? []);
    }

    public function test_cannot_suspend_superadmin(): void
    {
        $t = Tenant::factory()->create();
        $sa = User::factory()->create(['tenant_id' => $t->id, 'role' => 'superadmin']);
        $this->admin();

        $this->putJson("/api/v1/admin/tenants/{$t->id}/users/{$sa->id}", ['status' => 'suspended'])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `cd backend && php artisan test --filter=TenantUsersAdminTest`
Expected: FAIL.

- [ ] **Step 3: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantUsersController extends Controller
{
    public function index(int $tenant)
    {
        Tenant::findOrFail($tenant);
        $list = User::where('tenant_id', $tenant)
            ->select(['id', 'name', 'email', 'role', 'status', 'force_password_reset', 'created_at'])
            ->orderByDesc('id')
            ->paginate(20);
        return ApiResponse::paginated($list);
    }

    public function store(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['required', 'in:user,admin,finance'],
        ]);

        $user = (new User())->forceFill([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'tenant_id' => $tenant,
            'role'      => $data['role'],
            'status'    => 'active',
        ]);
        $user->save();

        AdminAuditLogger::log('user', 'create', $tenant, $user->id, [
            'email' => $user->email,
            'role'  => $user->role,
        ]);

        return ApiResponse::success($user->only(['id', 'name', 'email', 'role', 'status']), 'Usuário criado', [], 201);
    }

    public function update(Request $request, int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $user = User::where('tenant_id', $tenant)->findOrFail($id);

        if ($user->role === 'superadmin' && $request->has('status') && $request->input('status') !== 'active') {
            return ApiResponse::error('Superadmin não pode ser suspenso.', [], 422);
        }

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:100'],
            'email'  => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'   => ['sometimes', 'in:user,admin,finance'],
            'status' => ['sometimes', 'in:active,suspended'],
        ]);

        $before = $user->only(array_keys($data));
        // role precisa de forceFill, demais campos podem ser fillable
        if (isset($data['role'])) {
            $user->forceFill(['role' => $data['role']]);
        }
        $user->fill(array_diff_key($data, ['role' => true]));
        $user->save();

        if (($data['status'] ?? null) === 'suspended') {
            $user->tokens()->delete();
        }

        AdminAuditLogger::log('user', 'update', $tenant, $id, ['before' => $before, 'after' => $data]);

        return ApiResponse::success($user->only(['id', 'name', 'email', 'role', 'status']));
    }

    public function resetPassword(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $user = User::where('tenant_id', $tenant)->findOrFail($id);

        $temp = Str::random(16);
        $user->forceFill([
            'password'             => Hash::make($temp),
            'force_password_reset' => true,
        ])->save();

        $user->tokens()->delete();

        AdminAuditLogger::log('user', 'password_reset', $tenant, $id, []);

        return ApiResponse::success(['temp_password' => $temp], 'Senha temporária gerada — exibida uma única vez');
    }

    public function destroy(int $tenant, int $id)
    {
        Tenant::findOrFail($tenant);
        $user = User::where('tenant_id', $tenant)->findOrFail($id);

        if ($user->role === 'superadmin') {
            return ApiResponse::error('Superadmin não pode ser removido por esta rota.', [], 422);
        }

        $snap = $user->only(['email', 'role']);
        $user->tokens()->delete();
        $user->delete();

        AdminAuditLogger::log('user', 'delete', $tenant, $id, $snap);

        return ApiResponse::success([], 'Usuário removido');
    }
}
```

- [ ] **Step 4: Atualizar `User::$fillable`**

Em `backend/app/Models/User.php`, adicionar ao `$fillable`:

```php
'name', 'email', 'password',
'lgpd_consented_at', 'lgpd_consent_version', 'lgpd_consent_ip',
'status', 'force_password_reset',
```

(O comentário de segurança da linha 22 continua válido: `tenant_id` e `role` continuam fora.)

- [ ] **Step 5: Rotas**

```php
use App\Http\Controllers\API\V1\Admin\TenantUsersController;
Route::get   ('users',                       [TenantUsersController::class, 'index']);
Route::post  ('users',                       [TenantUsersController::class, 'store']);
Route::put   ('users/{id}',                  [TenantUsersController::class, 'update']);
Route::post  ('users/{id}/reset-password',   [TenantUsersController::class, 'resetPassword']);
Route::delete('users/{id}',                  [TenantUsersController::class, 'destroy']);
```

- [ ] **Step 6: Rodar testes + commit**

Run: `cd backend && php artisan test --filter=TenantUsersAdminTest`
Expected: PASS.

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantUsersController.php backend/routes/api.php backend/tests/Feature/Admin/TenantUsersAdminTest.php backend/app/Models/User.php
git commit -m "feat(admin): tenant users CRUD + password reset"
```

---

### Task 13: `TenantReportsController` (read-only)

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantReportsController.php`
- Create: `backend/tests/Feature/Admin/TenantReportsAdminTest.php`

- [ ] **Step 1: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Campaign;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantReportsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_by_channel_daily_top_campaigns(): void
    {
        $t = Tenant::factory()->create();
        $c = Campaign::factory()->create(['tenant_id' => $t->id, 'name' => 'BF']);
        MessageDispatch::factory()->count(3)->create([
            'tenant_id' => $t->id, 'campaign_id' => $c->id, 'channel' => 'sms', 'status' => 'delivered',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);
        $resp = $this->getJson("/api/v1/admin/tenants/{$t->id}/reports");

        $resp->assertStatus(200)
            ->assertJsonStructure(['data' => ['by_channel_30d', 'daily_sent_30d', 'top_campaigns_30d']]);
    }
}
```

- [ ] **Step 2: Rodar — esperar fail**

Run: `cd backend && php artisan test --filter=TenantReportsAdminTest`
Expected: FAIL.

- [ ] **Step 3: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campaign;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class TenantReportsController extends Controller
{
    public function index(int $tenant)
    {
        Tenant::findOrFail($tenant);
        $since = now()->subDays(30);

        $byChannel = ['sms', 'whatsapp', 'email', 'voice'];
        $by = collect($byChannel)->map(function ($ch) use ($tenant, $since) {
            $base = MessageDispatch::where('tenant_id', $tenant)
                ->where('channel', $ch)
                ->where('created_at', '>=', $since);
            return [
                'channel'   => $ch,
                'sent'      => (clone $base)->count(),
                'delivered' => (clone $base)->where('status', 'delivered')->count(),
                'failed'    => (clone $base)->where('status', 'failed')->count(),
            ];
        })->all();

        $daily = MessageDispatch::where('tenant_id', $tenant)
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as sent'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => ['date' => (string) $r->date, 'sent' => (int) $r->sent])
            ->all();

        $top = Campaign::where('tenant_id', $tenant)
            ->where('created_at', '>=', $since)
            ->orderByDesc('sent_count')
            ->limit(5)
            ->get(['id', 'name', 'sent_count', 'failed_count'])
            ->map(function ($c) {
                $total = max(1, (int) $c->sent_count);
                $ok    = max(0, $total - (int) $c->failed_count);
                return [
                    'id'             => $c->id,
                    'name'           => $c->name,
                    'sent'           => (int) $c->sent_count,
                    'delivered_rate' => round($ok / $total, 4),
                ];
            })->all();

        return ApiResponse::success([
            'by_channel_30d'    => $by,
            'daily_sent_30d'    => $daily,
            'top_campaigns_30d' => $top,
        ]);
    }
}
```

- [ ] **Step 4: Rota**

```php
use App\Http\Controllers\API\V1\Admin\TenantReportsController;
Route::get('reports', [TenantReportsController::class, 'index']);
```

- [ ] **Step 5: Rodar testes + commit**

Run: `cd backend && php artisan test --filter=TenantReportsAdminTest`
Expected: PASS.

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantReportsController.php backend/routes/api.php backend/tests/Feature/Admin/TenantReportsAdminTest.php
git commit -m "feat(admin): tenant reports endpoint (read-only)"
```

---

### Task 14: `TenantAuditController` (read-only)

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantAuditController.php`
- Create: `backend/tests/Feature/Admin/TenantAuditAdminTest.php`

- [ ] **Step 1: Test que falha**

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantAuditAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_audit_filtered_by_tenant_with_filters(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        AuditLog::record('campaign.created', 'Campaign', 1, [], null, $a->id);
        AuditLog::record('contact.created',  'Contact',  2, [], null, $a->id);
        AuditLog::record('campaign.created', 'Campaign', 3, [], null, $b->id);

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);

        $resp = $this->getJson("/api/v1/admin/tenants/{$a->id}/audit");
        $resp->assertStatus(200)->assertJsonCount(2, 'data');

        $respFiltered = $this->getJson("/api/v1/admin/tenants/{$a->id}/audit?action=campaign.created");
        $respFiltered->assertStatus(200)->assertJsonCount(1, 'data');
    }
}
```

- [ ] **Step 2: Rodar — esperar fail**

Run: `cd backend && php artisan test --filter=TenantAuditAdminTest`
Expected: FAIL.

- [ ] **Step 3: Implementar controller**

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantAuditController extends Controller
{
    public function index(Request $request, int $tenant)
    {
        Tenant::findOrFail($tenant);
        $request->validate([
            'action'  => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date'],
        ]);

        $q = AuditLog::where('tenant_id', $tenant);
        if ($a = $request->input('action'))   $q->where('action', $a);
        if ($u = $request->input('user_id'))  $q->where('user_id', $u);
        if ($f = $request->input('from'))     $q->where('created_at', '>=', $f);
        if ($t = $request->input('to'))       $q->where('created_at', '<=', $t);

        return ApiResponse::paginated($q->orderByDesc('id')->paginate(50));
    }
}
```

- [ ] **Step 4: Rota**

```php
use App\Http\Controllers\API\V1\Admin\TenantAuditController;
Route::get('audit', [TenantAuditController::class, 'index']);
```

- [ ] **Step 5: Rodar testes + commit**

Run: `cd backend && php artisan test --filter=TenantAuditAdminTest`
Expected: PASS.

```bash
git add backend/app/Http/Controllers/API/V1/Admin/TenantAuditController.php backend/routes/api.php backend/tests/Feature/Admin/TenantAuditAdminTest.php
git commit -m "feat(admin): tenant audit log endpoint (read-only)"
```

---

### Task 15: Pentest tests

**Files:**
- Create: `backend/tests/Pentest/AdminTenantDrillDownSecurityTest.php`

- [ ] **Step 1: Test**

```php
<?php

namespace Tests\Pentest;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTenantDrillDownSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_cannot_access_any_admin_tenant_route(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'admin']);
        Sanctum::actingAs($u, ['*']);

        foreach (['overview','campaigns','contacts','conversations','funnels','users','reports','audit'] as $r) {
            $this->getJson("/api/v1/admin/tenants/{$t->id}/{$r}")->assertStatus(403);
        }
    }

    public function test_body_tenant_id_cannot_override_path(): void
    {
        $t = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);

        $resp = $this->postJson("/api/v1/admin/tenants/{$t->id}/contacts", [
            'name'      => 'X',
            'tenant_id' => $other->id,
        ]);
        $resp->assertStatus(201);
        $this->assertSame($t->id, Contact::find($resp->json('data.id'))->tenant_id);
    }

    public function test_token_revoked_after_password_reset(): void
    {
        $t = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user']);
        $token = $target->createToken('x')->plainTextToken;

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);
        $this->postJson("/api/v1/admin/tenants/{$t->id}/users/{$target->id}/reset-password")
            ->assertStatus(200);

        // Re-authenticate as the target with the old token
        $resp = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/auth/me');
        $resp->assertStatus(401);
    }

    public function test_suspended_user_token_cannot_be_used(): void
    {
        $t = Tenant::factory()->create();
        $target = User::factory()->create(['tenant_id' => $t->id, 'role' => 'user', 'status' => 'active']);
        $token = $target->createToken('x')->plainTextToken;

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);
        $this->putJson("/api/v1/admin/tenants/{$t->id}/users/{$target->id}", ['status' => 'suspended'])
            ->assertStatus(200);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
```

- [ ] **Step 2: Rodar testes**

Run: `cd backend && php artisan test tests/Pentest/AdminTenantDrillDownSecurityTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Pentest/AdminTenantDrillDownSecurityTest.php
git commit -m "test(admin): pentest for admin tenant drill-down (IDOR, body override, token revocation)"
```

---

## Phase 3 — Frontend foundation

### Task 16: Extract `CampaignsTable.vue` (refactor sem mudar UX)

**Files:**
- Create: `frontend/src/components/shared/tables/CampaignsTable.vue`
- Modify: `frontend/src/pages/campaigns/Index.vue`

- [ ] **Step 1: Identificar a tabela em `pages/campaigns/Index.vue`**

Run: `grep -n "<table" frontend/src/pages/campaigns/Index.vue`

- [ ] **Step 2: Criar componente shared**

Conteúdo de `CampaignsTable.vue` — extrair o bloco `<table>` + `<tbody>` + handlers de ação atuais e expor props/events:

```vue
<template>
  <div>
    <TableSkeleton v-if="loading && !items.length" :rows="5" :cols="6" />
    <EmptyState
      v-else-if="!items.length"
      icon="ti-bullhorn"
      title="Nenhuma campanha"
      :description="emptyHint"
    />
    <div v-else class="table-responsive">
      <table class="table table-vcenter table-hover card-table">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Tipo</th>
            <th>Status</th>
            <th class="text-end">Enviadas</th>
            <th>Criada</th>
            <th class="w-1"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in items" :key="c.id">
            <td class="fw-medium">{{ c.name }}</td>
            <td><span class="badge bg-secondary-lt">{{ c.type }}</span></td>
            <td><StatusBadge :label="c.status" :status="c.status" dot /></td>
            <td class="text-end" style="font-family:'JetBrains Mono',monospace">{{ c.sent_count ?? 0 }}</td>
            <td class="text-muted">{{ formatDate(c.created_at) }}</td>
            <td class="text-end">
              <div class="d-flex gap-1 justify-content-end">
                <button v-if="canPause(c)" class="btn btn-sm btn-icon btn-ghost-warning" @click="$emit('action', { type: 'pause', item: c })" title="Pausar"><i class="ti ti-player-pause"></i></button>
                <button v-if="canResume(c)" class="btn btn-sm btn-icon btn-ghost-success" @click="$emit('action', { type: 'resume', item: c })" title="Retomar"><i class="ti ti-player-play"></i></button>
                <button v-if="canCancel(c)" class="btn btn-sm btn-icon btn-ghost-danger" @click="$emit('action', { type: 'cancel', item: c })" title="Cancelar"><i class="ti ti-square"></i></button>
                <button class="btn btn-sm btn-icon btn-ghost-secondary" @click="$emit('action', { type: 'view', item: c })" title="Ver"><i class="ti ti-eye"></i></button>
                <button v-if="allowDelete" class="btn btn-sm btn-icon btn-ghost-danger" @click="$emit('action', { type: 'delete', item: c })" title="Excluir"><i class="ti ti-trash"></i></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'

defineProps<{
  items: any[]
  loading?: boolean
  allowDelete?: boolean
  emptyHint?: string
}>()
defineEmits<{ (e: 'action', payload: { type: string; item: any }): void }>()

function canPause(c: any) { return ['scheduled', 'processing', 'running'].includes(c.status) }
function canResume(c: any) { return c.status === 'paused' }
function canCancel(c: any) { return !['completed', 'failed', 'draft'].includes(c.status) }

function formatDate(d?: string) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>
```

- [ ] **Step 3: Refatorar `pages/campaigns/Index.vue` para usar o componente**

Substituir o bloco original da tabela por `<CampaignsTable :items="items" :loading="isLoading" @action="onAction" />` e adicionar handler `onAction` que faz o mesmo que os botões originais (chamar mesmos endpoints / mostrar mesmos modais).

- [ ] **Step 4: Smoke test manual**

Run:
```bash
cd frontend && npm run build
```
Expected: build sem erros. Abrir `/campaigns` no browser e validar que as ações continuam funcionando (pausar/cancelar/ver/excluir).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/shared/tables/CampaignsTable.vue frontend/src/pages/campaigns/Index.vue
git commit -m "refactor(ui): extract CampaignsTable component for reuse"
```

---

### Task 17: Extract `ContactsTable.vue`, `ConversationsTable.vue`, `FunnelsTable.vue`, `UsersTable.vue`

**Files:**
- Create: `frontend/src/components/shared/tables/ContactsTable.vue`
- Create: `frontend/src/components/shared/tables/ConversationsTable.vue`
- Create: `frontend/src/components/shared/tables/FunnelsTable.vue`
- Create: `frontend/src/components/shared/tables/UsersTable.vue`
- Modify: `frontend/src/pages/contacts/Index.vue`
- Modify: `frontend/src/pages/conversations/Index.vue`
- Modify: `frontend/src/pages/funnels/Index.vue`

- [ ] **Step 1: Especificação de colunas e ações por tabela**

Cada `*Table.vue` segue o **template estrutural da Task 16** (`TableSkeleton` para loading, `EmptyState` quando vazio, `<table class="table table-vcenter table-hover card-table">`, props `items, loading, allowDelete, emptyHint`, event `@action({type,item})`). As variações são apenas colunas e botões. Use esta tabela como contrato:

**`ContactsTable.vue`** — extrair de `pages/contacts/Index.vue`
- Colunas: Nome, Telefone (`phone`), Email, Lista (`contactList?.name`), Status (`StatusBadge`), Criado em
- Botões: editar (pencil), excluir (trash, se `allowDelete`)
- Empty icon: `ti-address-book`

**`ConversationsTable.vue`** — extrair de `pages/conversations/Index.vue`
- Colunas: Telefone (`phone`), Canal (badge), Status (`StatusBadge`), Última mensagem (`last_message_at` formatado), Mensagens (`messages_count`)
- Botões: ver (eye), fechar (square se status=open), arquivar (archive)
- Empty icon: `ti-messages`

**`FunnelsTable.vue`** — extrair de `pages/funnels/Index.vue`
- Colunas: Nome, Trigger (`trigger_type`), Ativo (`is_active` boolean badge), Execuções (`executions_count`), Criado em
- Botões: editar (pencil, emite `view` para abrir editor), toggle ativo (switch), excluir (trash)
- Empty icon: `ti-route`

**`UsersTable.vue`** — criar do zero (nenhuma página existente)
- Colunas: Nome, Email, Role (`badge`), Status (`active`/`suspended` badge), Último login (`last_login_at` ou `—`), Criado em
- Botões: editar (pencil) — emite `edit`; reset senha (key) — emite `reset`; suspender/ativar (lock/lock-open) — emite `suspend`/`activate`; excluir (trash) — emite `delete`
- Empty icon: `ti-users`

- [ ] **Step 2: Implementar cada componente seguindo a Task 16 + a especificação acima**

Para cada um, refatorar a página de tenant existente (quando houver) para usar o novo componente, mantendo o comportamento atual.

- [ ] **Step 3: Build e commit por componente**

Para cada tabela:
```bash
cd frontend && npm run build
git add frontend/src/components/shared/tables/<Name>Table.vue frontend/src/pages/<page>/Index.vue
git commit -m "refactor(ui): extract <Name>Table component"
```

`UsersTable.vue` sozinha vai em um commit isolado:
```bash
git add frontend/src/components/shared/tables/UsersTable.vue
git commit -m "feat(ui): UsersTable shared component"
```

---

### Task 18: Store `adminTenantDetail.ts`

**Files:**
- Create: `frontend/src/stores/adminTenantDetail.ts`

- [ ] **Step 1: Criar store**

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

interface TenantSummary {
  id: number
  name: string
  slug: string
  status: string
  plan?: { id: number; name: string } | null
  balance_cents: number
  credit_limit_cents?: number
}

interface Kpis {
  campaigns_total: number
  campaigns_active: number
  contacts_total: number
  messages_sent_30d: number
  messages_delivered_30d: number
  conversations_open: number
  users_total: number
}

export const useAdminTenantDetailStore = defineStore('adminTenantDetail', () => {
  const { get } = useApi()

  const tenant = ref<TenantSummary | null>(null)
  const kpis = ref<Kpis | null>(null)
  const loading = ref(false)

  async function loadOverview(tenantId: number) {
    loading.value = true
    try {
      const resp = await get<any>(`/admin/tenants/${tenantId}/overview`)
      tenant.value = resp.data?.tenant ?? resp.tenant
      kpis.value = resp.data?.kpis ?? resp.kpis
    } finally {
      loading.value = false
    }
  }

  function reset() {
    tenant.value = null
    kpis.value = null
  }

  return { tenant, kpis, loading, loadOverview, reset }
})
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/stores/adminTenantDetail.ts
git commit -m "feat(admin): adminTenantDetail Pinia store"
```

---

### Task 19: Rota `/admin/tenants/:id` + `Detail.vue` shell

**Files:**
- Modify: `frontend/src/router/index.ts`
- Create: `frontend/src/pages/admin/tenants/Detail.vue`

- [ ] **Step 1: Adicionar rota**

Em `frontend/src/router/index.ts`, depois do bloco `/admin/tenants`:

```ts
{
  path: '/admin/tenants/:id',
  component: () => import('@/pages/admin/tenants/Detail.vue'),
  meta: { superadmin: true, title: 'Admin • Tenant' },
},
```

- [ ] **Step 2: Criar shell com tabs**

```vue
<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-3">
      <div>
        <button class="btn btn-ghost-secondary btn-sm mb-2" @click="$router.push('/admin/tenants')">
          <i class="ti ti-chevron-left me-1"></i>Voltar
        </button>
        <h2 style="font-size:1.4rem;font-weight:700;margin:0">
          {{ tenant?.name || 'Tenant' }}
          <span v-if="tenant" class="badge bg-secondary-lt" style="font-size:0.7rem;vertical-align:middle">{{ tenant.status }}</span>
        </h2>
        <span v-if="tenant" style="font-size:0.78rem;color:var(--bc-text-muted);font-family:'JetBrains Mono',monospace">
          #{{ tenant.id }} · {{ tenant.slug }}
        </span>
      </div>
      <button class="btn btn-ghost-secondary" :disabled="loading" @click="reload">
        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>
        <i v-else class="ti ti-refresh me-1"></i>Atualizar
      </button>
    </div>

    <ul class="nav nav-tabs mb-3" role="tablist">
      <li v-for="t in tabs" :key="t.id" class="nav-item">
        <a href="#" class="nav-link" :class="{ active: active === t.id }" @click.prevent="go(t.id)">
          <i :class="`ti ${t.icon} me-1`"></i>{{ t.label }}
        </a>
      </li>
    </ul>

    <TabOverview        v-if="active === 'overview'"        :tenant-id="tenantId" />
    <TabCampaigns       v-else-if="active === 'campaigns'"  :tenant-id="tenantId" />
    <TabContacts        v-else-if="active === 'contacts'"   :tenant-id="tenantId" />
    <TabConversations   v-else-if="active === 'conversations'" :tenant-id="tenantId" />
    <TabFunnels         v-else-if="active === 'funnels'"    :tenant-id="tenantId" />
    <TabUsers           v-else-if="active === 'users'"      :tenant-id="tenantId" />
    <TabChannels        v-else-if="active === 'channels'"   :tenant-id="tenantId" />
    <TabReports         v-else-if="active === 'reports'"    :tenant-id="tenantId" />
    <TabAudit           v-else-if="active === 'audit'"      :tenant-id="tenantId" />
    <div v-else-if="active === 'billing'" class="card" style="border-radius:14px">
      <div class="card-body text-center py-5">
        <i class="ti ti-cash" style="font-size:2.5rem;color:var(--bc-text-muted);opacity:0.3"></i>
        <h3 style="font-size:1rem;margin-top:1rem">Financeiro</h3>
        <p style="font-size:0.85rem;color:var(--bc-text-muted)">A área financeira tem sua própria página.</p>
        <button class="btn btn-primary" @click="$router.push(`/admin/billing/tenants/${tenantId}`)">
          <i class="ti ti-external-link me-1"></i>Abrir financeiro
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { useAdminTenantDetailStore } from '@/stores/adminTenantDetail'
import TabOverview from '@/components/admin/tenants/TabOverview.vue'
import TabCampaigns from '@/components/admin/tenants/TabCampaigns.vue'
import TabContacts from '@/components/admin/tenants/TabContacts.vue'
import TabConversations from '@/components/admin/tenants/TabConversations.vue'
import TabFunnels from '@/components/admin/tenants/TabFunnels.vue'
import TabUsers from '@/components/admin/tenants/TabUsers.vue'
import TabChannels from '@/components/admin/tenants/TabChannels.vue'
import TabReports from '@/components/admin/tenants/TabReports.vue'
import TabAudit from '@/components/admin/tenants/TabAudit.vue'

const route = useRoute()
const router = useRouter()
const store = useAdminTenantDetailStore()
const { tenant, loading } = storeToRefs(store)

const tenantId = computed(() => Number(route.params.id))
const active = computed(() => (route.query.tab as string) || 'overview')

const tabs = [
  { id: 'overview',       label: 'Visão geral',   icon: 'ti-layout-dashboard' },
  { id: 'campaigns',      label: 'Campanhas',     icon: 'ti-bullhorn' },
  { id: 'contacts',       label: 'Contatos',      icon: 'ti-address-book' },
  { id: 'conversations',  label: 'Conversas',     icon: 'ti-messages' },
  { id: 'funnels',        label: 'Funis',         icon: 'ti-route' },
  { id: 'users',          label: 'Usuários',      icon: 'ti-users' },
  { id: 'channels',       label: 'Canais',        icon: 'ti-broadcast' },
  { id: 'reports',        label: 'Relatórios',    icon: 'ti-chart-bar' },
  { id: 'audit',          label: 'Auditoria',     icon: 'ti-history' },
  { id: 'billing',        label: 'Financeiro',    icon: 'ti-cash' },
]

function go(tab: string) {
  router.replace({ query: { ...route.query, tab } })
}

async function reload() {
  await store.loadOverview(tenantId.value)
}

onMounted(reload)
watch(tenantId, reload)
</script>
```

- [ ] **Step 3: Build sem componentes de aba (vão dar warning)**

Comentar os imports/usos das abas que ainda não existem ou criar stubs vazios. Recomendação: criar stubs vazios temporários para destravar build.

```bash
cd frontend
# criar 9 arquivos stub minimal:
for n in TabOverview TabCampaigns TabContacts TabConversations TabFunnels TabUsers TabChannels TabReports TabAudit; do
  printf '<template><div class="card" style="border-radius:14px"><div class="card-body text-muted text-center py-4">Em construção (%s)</div></div></template>\n<script setup lang="ts">defineProps<{ tenantId: number }>()</script>\n' "$n" > "src/components/admin/tenants/$n.vue"
done
npm run build
```

Expected: build sem erros.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/router/index.ts frontend/src/pages/admin/tenants/Detail.vue frontend/src/components/admin/tenants/
git commit -m "feat(admin): /admin/tenants/:id shell with tab stubs"
```

---

### Task 20: Linha clicável em `/admin/tenants`

**Files:**
- Modify: `frontend/src/pages/admin/Tenants.vue`

- [ ] **Step 1: Tornar `<tr>` clicável**

Localizar o `<tr v-for="t in items" :key="t.id">` na tabela (linha ~58) e:
1. Adicionar `@click="$router.push(`/admin/tenants/${t.id}`)"`
2. Adicionar `style="cursor:pointer"`
3. Em **cada `<button>` de ação** dentro daquela `<tr>`, adicionar `@click.stop` para evitar bubbling.

- [ ] **Step 2: Build + smoke test**

Run: `cd frontend && npm run build`
Expected: build sem erros. Em browser, clicar na linha leva para `/admin/tenants/:id`; clicar nos ícones de ação continua abrindo modais sem navegar.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/admin/Tenants.vue
git commit -m "feat(admin): row click navigates to tenant drill-down"
```

---

## Phase 4 — Frontend tabs (uma por uma)

### Task 21: `TabOverview.vue`

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabOverview.vue`

- [ ] **Step 1: Implementar**

```vue
<template>
  <div>
    <div class="row g-3 mb-3">
      <div class="col-md-3" v-for="card in cards" :key="card.label">
        <div class="card" style="border-radius:14px">
          <div class="card-body">
            <div style="font-size:0.78rem;color:var(--bc-text-muted);text-transform:uppercase;letter-spacing:0.05em">
              {{ card.label }}
            </div>
            <div style="font-size:1.5rem;font-weight:700;font-family:'JetBrains Mono',monospace">
              {{ card.value }}
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="tenant" class="card" style="border-radius:14px">
      <div class="card-header"><h3 class="card-title">Editar tenant</h3></div>
      <form @submit.prevent="save" class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Nome</label>
            <input v-model="form.name" class="form-control" style="border-radius:10px" />
          </div>
          <div class="col-md-6">
            <label class="form-label">Slug</label>
            <input v-model="form.slug" class="form-control" style="border-radius:10px" />
          </div>
          <div class="col-md-6">
            <label class="form-label">Status</label>
            <select v-model="form.status" class="form-select" style="border-radius:10px">
              <option value="trial">Trial</option>
              <option value="active">Ativo</option>
              <option value="suspended">Suspenso</option>
            </select>
          </div>
        </div>
        <div class="mt-3">
          <button type="submit" class="btn btn-primary" :disabled="saving">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useAdminTenantDetailStore } from '@/stores/adminTenantDetail'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ tenantId: number }>()
const store = useAdminTenantDetailStore()
const { tenant, kpis } = storeToRefs(store)
const { put } = useApi()
const toast = useToast()

const form = reactive({ name: '', slug: '', status: 'active' })
const saving = ref(false)

const cards = computed(() => [
  { label: 'Campanhas',       value: kpis.value?.campaigns_total ?? '—' },
  { label: 'Ativas',          value: kpis.value?.campaigns_active ?? '—' },
  { label: 'Contatos',        value: kpis.value?.contacts_total ?? '—' },
  { label: 'Msgs 30d',        value: kpis.value?.messages_sent_30d ?? '—' },
  { label: 'Entregues 30d',   value: kpis.value?.messages_delivered_30d ?? '—' },
  { label: 'Conversas abertas', value: kpis.value?.conversations_open ?? '—' },
  { label: 'Usuários',        value: kpis.value?.users_total ?? '—' },
])

async function save() {
  saving.value = true
  try {
    await put(`/admin/tenants/${props.tenantId}`, {
      name: form.name, slug: form.slug, status: form.status,
    })
    toast.success('Tenant atualizado')
    await store.loadOverview(props.tenantId)
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  if (!tenant.value) store.loadOverview(props.tenantId).then(syncForm)
  else syncForm()
})

function syncForm() {
  if (tenant.value) {
    form.name = tenant.value.name
    form.slug = tenant.value.slug
    form.status = tenant.value.status
  }
}
</script>
```

- [ ] **Step 2: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabOverview.vue
git commit -m "feat(admin): TabOverview with KPIs and edit form"
```

---

### Task 22: `TabCampaigns.vue`

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabCampaigns.vue`
- Create: `frontend/src/components/admin/tenants/modals/ConfirmDestructiveModal.vue`

- [ ] **Step 1: ConfirmDestructiveModal**

```vue
<template>
  <div class="modal modal-blur fade show d-block" style="background:rgba(0,0,0,0.4)" v-if="open">
    <div class="modal-dialog modal-sm">
      <div class="modal-content" style="border-radius:14px">
        <div class="modal-header">
          <h5 class="modal-title">Confirmar ação destrutiva</h5>
          <button type="button" class="btn-close" @click="$emit('cancel')"></button>
        </div>
        <div class="modal-body">
          <p>{{ message }}</p>
          <p style="font-size:0.85rem;color:var(--bc-text-muted)">
            Para confirmar, digite <code>{{ keyword }}</code> abaixo:
          </p>
          <input v-model="typed" class="form-control" style="border-radius:10px" />
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost-secondary" @click="$emit('cancel')">Cancelar</button>
          <button class="btn btn-danger" :disabled="typed !== keyword" @click="$emit('confirm')">
            Confirmar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
const props = defineProps<{ open: boolean; keyword: string; message: string }>()
defineEmits<{ (e: 'confirm'): void; (e: 'cancel'): void }>()
const typed = ref('')
watch(() => props.open, (v) => { if (v) typed.value = '' })
</script>
```

- [ ] **Step 2: TabCampaigns**

```vue
<template>
  <div>
    <CampaignsTable
      :items="items"
      :loading="loading"
      allow-delete
      empty-hint="Este tenant ainda não tem campanhas."
      @action="onAction"
    />

    <ConfirmDestructiveModal
      :open="!!pending"
      :keyword="pending?.item?.name ?? ''"
      :message="`Excluir a campanha '${pending?.item?.name}'? Esta ação não pode ser desfeita.`"
      @cancel="pending = null"
      @confirm="confirmDelete"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import CampaignsTable from '@/components/shared/tables/CampaignsTable.vue'
import ConfirmDestructiveModal from './modals/ConfirmDestructiveModal.vue'

const props = defineProps<{ tenantId: number }>()
const { get, patch, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)
const pending = ref<{ item: any } | null>(null)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/campaigns`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  if (['pause', 'resume', 'cancel'].includes(type)) {
    const reason = type === 'cancel' ? (prompt('Motivo do cancelamento?') || 'sem motivo') : null
    try {
      await patch(`/admin/tenants/${props.tenantId}/campaigns/${item.id}`, { action: type, reason })
      toast.success(`Campanha ${type} ok`)
      await load()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro')
    }
  } else if (type === 'view') {
    // abrir detalhe (rota tenant existente)
    window.open(`/campaigns/${item.id}`, '_blank')
  } else if (type === 'delete') {
    pending.value = { item }
  }
}

async function confirmDelete() {
  if (!pending.value) return
  try {
    await del(`/admin/tenants/${props.tenantId}/campaigns/${pending.value.item.id}`)
    toast.success('Campanha excluída')
    pending.value = null
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

onMounted(load)
</script>
```

- [ ] **Step 3: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabCampaigns.vue frontend/src/components/admin/tenants/modals/ConfirmDestructiveModal.vue
git commit -m "feat(admin): TabCampaigns + ConfirmDestructiveModal"
```

---

### Task 23: `TabContacts.vue`

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabContacts.vue`

- [ ] **Step 1: Implementar**

```vue
<template>
  <div>
    <ContactsTable
      :items="items"
      :loading="loading"
      allow-delete
      empty-hint="Sem contatos para este tenant."
      @action="onAction"
    />
    <!-- TODO modal de edição em fase 2; criação/edição fica com tenant por ora -->
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import ContactsTable from '@/components/shared/tables/ContactsTable.vue'

const props = defineProps<{ tenantId: number }>()
const { get, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/contacts`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  if (type === 'delete') {
    if (!confirm(`Excluir contato '${item.name}'?`)) return
    try {
      await del(`/admin/tenants/${props.tenantId}/contacts/${item.id}`)
      toast.success('Removido')
      await load()
    } catch (e: any) {
      toast.error(e?.response?.data?.message ?? 'Erro')
    }
  }
}

onMounted(load)
</script>
```

- [ ] **Step 2: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabContacts.vue
git commit -m "feat(admin): TabContacts with delete action"
```

---

### Task 24: `TabConversations.vue` e `TabFunnels.vue`

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabConversations.vue`
- Modify: `frontend/src/components/admin/tenants/TabFunnels.vue`
- Modify: `frontend/src/pages/funnels/Editor.vue`

- [ ] **Step 1: TabConversations**

Padrão idêntico à TabContacts: importar `ConversationsTable`, fetchar `/admin/tenants/{id}/conversations`, action `close`/`archive` → `PATCH ...{ status }`.

- [ ] **Step 2: TabFunnels**

Mesmo padrão; action `view` → `window.open` em `/funnels/${id}/edit?admin_tenant=${tenantId}`. Action `toggle` → `PATCH { is_active: !item.is_active }`.

- [ ] **Step 3: Banner admin no editor de funil**

Em `frontend/src/pages/funnels/Editor.vue`, no topo do template, adicionar:

```vue
<div v-if="$route.query.admin_tenant" class="alert alert-warning d-flex align-items-center mb-3" style="border-radius:12px">
  <i class="ti ti-shield-lock me-2"></i>
  <div>
    <strong>Modo administrador.</strong>
    Editando funil do tenant #{{ $route.query.admin_tenant }} como superadmin. Toda alteração será auditada.
  </div>
</div>
```

O guard de `meta.superadmin` na rota já impede usuários comuns de explorar essa query.

- [ ] **Step 4: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabConversations.vue frontend/src/components/admin/tenants/TabFunnels.vue frontend/src/pages/funnels/Editor.vue
git commit -m "feat(admin): TabConversations, TabFunnels and admin banner in funnel editor"
```

---

### Task 25: `TabUsers.vue` + `PasswordResetResultModal.vue`

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabUsers.vue`
- Create: `frontend/src/components/admin/tenants/modals/PasswordResetResultModal.vue`

- [ ] **Step 1: PasswordResetResultModal**

```vue
<template>
  <div class="modal modal-blur fade show d-block" style="background:rgba(0,0,0,0.4)" v-if="open">
    <div class="modal-dialog modal-md">
      <div class="modal-content" style="border-radius:14px">
        <div class="modal-header">
          <h5 class="modal-title"><i class="ti ti-key me-2"></i>Senha temporária gerada</h5>
        </div>
        <div class="modal-body">
          <div class="alert alert-warning" style="border-radius:12px">
            <i class="ti ti-alert-triangle me-1"></i>
            <strong>Esta senha será exibida apenas uma vez.</strong> Anote ou copie agora.
          </div>
          <div class="d-flex align-items-center gap-2 my-3">
            <input :value="password" readonly class="form-control" style="font-family:'JetBrains Mono',monospace" />
            <button class="btn btn-primary" @click="copy">
              <i class="ti ti-copy me-1"></i>Copiar
            </button>
          </div>
          <p style="font-size:0.85rem;color:var(--bc-text-muted)">
            O usuário será obrigado a trocar a senha no próximo login. Todos os tokens ativos foram revogados.
          </p>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" :disabled="!copied" @click="$emit('close')">
            {{ copied ? 'Entendi' : 'Copie a senha primeiro' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
const props = defineProps<{ open: boolean; password: string }>()
defineEmits<{ (e: 'close'): void }>()
const copied = ref(false)
watch(() => props.open, v => { if (v) copied.value = false })
function copy() {
  navigator.clipboard.writeText(props.password)
  copied.value = true
}
</script>
```

- [ ] **Step 2: TabUsers**

```vue
<template>
  <div>
    <UsersTable :items="items" :loading="loading" allow-delete @action="onAction" />

    <PasswordResetResultModal
      :open="!!tempPassword"
      :password="tempPassword ?? ''"
      @close="tempPassword = null"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import UsersTable from '@/components/shared/tables/UsersTable.vue'
import PasswordResetResultModal from './modals/PasswordResetResultModal.vue'

const props = defineProps<{ tenantId: number }>()
const { get, post, put, del } = useApi()
const toast = useToast()

const items = ref<any[]>([])
const loading = ref(false)
const tempPassword = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const resp = await get<any>(`/admin/tenants/${props.tenantId}/users`)
    items.value = resp.data ?? []
  } finally {
    loading.value = false
  }
}

async function onAction({ type, item }: { type: string; item: any }) {
  try {
    if (type === 'suspend') {
      await put(`/admin/tenants/${props.tenantId}/users/${item.id}`, { status: 'suspended' })
      toast.success('Suspenso')
    } else if (type === 'activate') {
      await put(`/admin/tenants/${props.tenantId}/users/${item.id}`, { status: 'active' })
      toast.success('Ativado')
    } else if (type === 'reset') {
      const resp = await post<any>(`/admin/tenants/${props.tenantId}/users/${item.id}/reset-password`, {})
      tempPassword.value = resp.data?.temp_password
    } else if (type === 'delete') {
      if (!confirm(`Excluir ${item.email}?`)) return
      await del(`/admin/tenants/${props.tenantId}/users/${item.id}`)
      toast.success('Removido')
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro')
  }
}

onMounted(load)
</script>
```

- [ ] **Step 3: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabUsers.vue frontend/src/components/admin/tenants/modals/PasswordResetResultModal.vue
git commit -m "feat(admin): TabUsers with password reset flow"
```

---

### Task 26: `TabChannels.vue` (migra modal existente)

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabChannels.vue`
- Modify: `frontend/src/pages/admin/Tenants.vue` — remover modal de canais e seu botão

- [ ] **Step 1: Mover lógica do modal**

Copiar o conteúdo de `Tenants.vue:222-275` (modal de canais) e seu script (`channelsDefs`, `openChannels`, `updateChannel`, `updateWhatsappProvider`) para `TabChannels.vue`, adaptando para receber `tenantId` por prop e carregar via `GET /admin/tenants/{id}/channels` (rota já existente em `routes/api.php:326`).

- [ ] **Step 2: Remover do `Tenants.vue`**

Remover modal e botão `<i class="ti ti-broadcast">` da tabela; a tab nova cobre o caso. Manter as outras ações (`creditsModal`, `tenantModal`).

- [ ] **Step 3: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabChannels.vue frontend/src/pages/admin/Tenants.vue
git commit -m "feat(admin): TabChannels (migrated from inline modal)"
```

---

### Task 27: `TabReports.vue` (read-only)

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabReports.vue`

- [ ] **Step 1: Implementar**

```vue
<template>
  <div>
    <div class="row g-3 mb-3" v-if="data">
      <div v-for="row in data.by_channel_30d" :key="row.channel" class="col-md-3">
        <div class="card" style="border-radius:14px">
          <div class="card-body">
            <div style="font-size:0.78rem;color:var(--bc-text-muted);text-transform:uppercase">{{ row.channel }}</div>
            <div style="font-size:1.4rem;font-weight:700;font-family:'JetBrains Mono',monospace">{{ row.sent }}</div>
            <div style="font-size:0.7rem;color:var(--bc-text-muted)">
              {{ row.delivered }} entregues · {{ row.failed }} falhas
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="card" style="border-radius:14px" v-if="data">
      <div class="card-header"><h3 class="card-title">Top 5 campanhas (30d)</h3></div>
      <div class="table-responsive">
        <table class="table card-table">
          <thead><tr><th>Campanha</th><th class="text-end">Enviadas</th><th class="text-end">Taxa entrega</th></tr></thead>
          <tbody>
            <tr v-for="c in data.top_campaigns_30d" :key="c.id">
              <td>{{ c.name }}</td>
              <td class="text-end" style="font-family:'JetBrains Mono',monospace">{{ c.sent }}</td>
              <td class="text-end">{{ (c.delivered_rate * 100).toFixed(1) }}%</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useApi } from '@/composables/useApi'

const props = defineProps<{ tenantId: number }>()
const { get } = useApi()
const data = ref<any | null>(null)

onMounted(async () => {
  const resp = await get<any>(`/admin/tenants/${props.tenantId}/reports`)
  data.value = resp.data ?? resp
})
</script>
```

- [ ] **Step 2: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabReports.vue
git commit -m "feat(admin): TabReports (read-only)"
```

---

### Task 28: `TabAudit.vue` (read-only)

**Files:**
- Modify: `frontend/src/components/admin/tenants/TabAudit.vue`

- [ ] **Step 1: Implementar**

```vue
<template>
  <div class="card" style="border-radius:14px">
    <div class="card-header">
      <h3 class="card-title">Auditoria</h3>
      <div class="ms-auto d-flex gap-2">
        <input v-model="filters.action" class="form-control form-control-sm" placeholder="action..." style="max-width:200px" @keyup.enter="load" />
        <button class="btn btn-sm btn-ghost-primary" @click="load"><i class="ti ti-search"></i></button>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table card-table table-vcenter">
        <thead>
          <tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Recurso</th><th>IP</th></tr>
        </thead>
        <tbody>
          <tr v-for="row in items" :key="row.id">
            <td style="font-size:0.78rem">{{ formatDate(row.created_at) }}</td>
            <td style="font-family:'JetBrains Mono',monospace">{{ row.user_id ?? '—' }}</td>
            <td><code>{{ row.action }}</code></td>
            <td>{{ row.resource ?? '—' }} <span v-if="row.resource_id" class="text-muted">#{{ row.resource_id }}</span></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:0.78rem">{{ row.ip_address ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useApi } from '@/composables/useApi'

const props = defineProps<{ tenantId: number }>()
const { get } = useApi()
const items = ref<any[]>([])
const filters = reactive({ action: '' })

async function load() {
  const params = new URLSearchParams()
  if (filters.action) params.set('action', filters.action)
  const resp = await get<any>(`/admin/tenants/${props.tenantId}/audit?${params}`)
  items.value = resp.data ?? []
}

function formatDate(s?: string) {
  if (!s) return '—'
  return new Date(s).toLocaleString('pt-BR')
}

onMounted(load)
</script>
```

- [ ] **Step 2: Build + commit**

```bash
cd frontend && npm run build
git add frontend/src/components/admin/tenants/TabAudit.vue
git commit -m "feat(admin): TabAudit (read-only with filters)"
```

---

## Phase 5 — Validação final

### Task 29: Suite completa + smoke manual

- [ ] **Step 1: Backend completo**

Run: `cd backend && php artisan test`
Expected: PASS (sem regressão).

- [ ] **Step 2: Frontend build**

Run: `cd frontend && npm run build`
Expected: build sem erros.

- [ ] **Step 3: Smoke manual (em browser, logado como superadmin)**

Checklist:
- [ ] Clicar numa linha de `/admin/tenants` abre `/admin/tenants/:id`
- [ ] Aba "Visão geral" mostra KPIs
- [ ] Editar nome do tenant e salvar funciona; voltar pra lista mostra nome novo
- [ ] Aba "Campanhas" lista; ações cancelar/excluir funcionam
- [ ] Aba "Usuários" — criar, suspender, reset senha (modal mostra temp_password), excluir
- [ ] Aba "Canais" — habilitar/desabilitar WhatsApp e mudar provider funciona
- [ ] Aba "Relatórios" carrega gráfico/cards
- [ ] Aba "Auditoria" filtra por action
- [ ] Aba "Financeiro" navega para `/admin/billing/tenants/:id`
- [ ] Funil editor com `?admin_tenant=X` mostra banner amarelo
- [ ] Logar como user comum (`role=admin`) e abrir `/admin/tenants/1` → redirect para `/dashboard`
- [ ] Suspender um user, copiar token dele antes, tentar usar token → 401

- [ ] **Step 4: Commit final (se houver ajustes)**

```bash
git status
# se houver mudanças menores de smoke:
git add -A
git commit -m "fix(admin): smoke fixes from manual QA"
```

---

### Task 30: PR

- [ ] **Step 1: Push e abrir PR**

```bash
cd c:/xampp/htdocs/new_saas
git push -u origin feat/admin-tenant-drill-down
gh pr create --title "feat(admin): tenant drill-down (10 tabs)" --body "$(cat <<'EOF'
## Summary
- Novo `/admin/tenants/:id` com 10 abas (overview, campanhas, contatos, conversas, funis, usuários, canais, relatórios, auditoria, link financeiro)
- Read-only em relatórios e auditoria; edição nas demais abas
- Migration `users.force_password_reset` + `users.status`
- Centralizado `AdminAuditLogger` para todas as ações `admin.*`
- Refactor: tabelas compartilhadas (`CampaignsTable`, `ContactsTable`, etc.) reusadas entre páginas tenant e abas admin

## Test plan
- [ ] `php artisan test` — todos verdes
- [ ] `npm run build` no frontend
- [ ] Smoke manual da Task 29 (10 itens)
- [ ] Pentest tests (`tests/Pentest/AdminTenantDrillDownSecurityTest.php`)

Spec: `docs/superpowers/specs/2026-05-30-admin-tenant-drill-down-design.md`
Plan: `docs/superpowers/plans/2026-05-30-admin-tenant-drill-down.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```
