# Email Domain Sync (Admin · Infobip) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar sync admin de domínios de email do Infobip, espelhando o padrão existente de números WhatsApp, para que domínios onboardados manualmente (ex.: `pixreals.com`) apareçam na DB local e possam ser atribuídos a tenants.

**Architecture:** Migration tornando `email_sender_domains.tenant_id` nullable + trocando unique de `(tenant_id, domain)` pra `domain`. Novo método `EmailDomainService::syncFromInfobip()` faz upsert por `domain` preservando `tenant_id` existente. Novo `Admin\InfobipEmailController` expõe `domains` / `sync` / `assign`. Frontend: `/admin/infobip-email` (página global tipo `InfobipWhatsApp.vue`).

**Tech Stack:** Laravel 11, PHP 8.2, Pest/PHPUnit, Vue 3 + Vite + TS, Bootstrap 5, Sanctum.

**Spec:** `docs/superpowers/specs/2026-05-30-email-domain-sync-design.md`

**Convenções do projeto a respeitar:**
- Route guard admin: `Route::middleware('superadmin')->prefix('admin')->group(...)`.
- Respostas wrapped em `ApiResponse::success/error` em controllers admin.
- Logs de Infobip vão pro channel `infobip` (`Log::channel('infobip')->info/warning/error`).
- `EmailSenderDomain` tem global scope `AppliesTenantScope` que filtra por `tenant_id` exceto pra `role=superadmin` — controller admin precisa usar `withoutGlobalScopes()` por defense-in-depth.
- Frontend usa `useApi` composable (`get/post/put/delete`) e `useToast`.

---

## File Map

**Created:**
- `backend/database/migrations/2026_05_30_000001_email_sender_domains_allow_admin_sync.php` — nullable tenant_id, swap unique index, nullOnDelete FK
- `backend/app/Http/Controllers/API/V1/Admin/InfobipEmailController.php` — endpoints admin
- `backend/tests/Unit/Services/EmailDomainSyncTest.php` — service unit tests
- `backend/tests/Feature/Admin/InfobipEmailSyncTest.php` — feature tests (admin endpoints)
- `backend/tests/Feature/Security/EmailDomainsAdminIdorTest.php` — pentest
- `frontend/src/pages/admin/InfobipEmailDomains.vue` — admin UI

**Modified:**
- `backend/app/Services/Messaging/EmailDomainService.php` — add `syncFromInfobip()`, expose `parseDnsRecords` para reuso (mantém método privado mas extrai a derivação de status pra helper privado também usado por sync)
- `backend/app/Models/EmailSenderDomain.php` — sem mudanças de schema (já tem `tenant` relation); nada a mudar exceto se confirmar `tenant_id` em `$fillable` (já está)
- `backend/routes/api.php` — 3 rotas novas no bloco `admin/` perto das de `infobip-whatsapp`
- `frontend/src/router/index.ts` — rota `/admin/infobip-email`
- `frontend/src/components/layout/AppSidebar.vue` — link sidebar abaixo de WhatsApp (Infobip)

---

## Task 1: Migration — nullable tenant_id + unique(domain)

**Files:**
- Create: `backend/database/migrations/2026_05_30_000001_email_sender_domains_allow_admin_sync.php`
- Test: roda `php artisan migrate:fresh` e inspeciona schema

- [ ] **Step 1: Pre-flight check de duplicatas** (read-only, não falha o build)

Run (PowerShell):

```powershell
cd c:/xampp/htdocs/new_saas/backend
php artisan tinker --execute "echo DB::table('email_sender_domains')->select('domain', DB::raw('count(*) as c'))->groupBy('domain')->having('c','>',1)->get()->toJson();"
```

Expected: `[]` (sem duplicatas). Se aparecer algum domínio, **PARE** e reporte ao usuário — a migration vai falhar em produção e exige resolução manual.

- [ ] **Step 2: Criar a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('email_sender_domains', function (Blueprint $t) {
            // Drop the FK + composite unique first so we can change column nullability.
            $t->dropForeign(['tenant_id']);
            $t->dropUnique('esd_tenant_domain_unique');
        });

        Schema::table('email_sender_domains', function (Blueprint $t) {
            // NULL = domain in the admin pool, not yet assigned to a tenant.
            $t->unsignedBigInteger('tenant_id')->nullable()->change();

            // Infobip enforces global uniqueness of domain per account; reflect that.
            $t->unique('domain', 'esd_domain_unique');

            // If a tenant is deleted, the domain goes back to the pool — don't delete it.
            $t->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_sender_domains', function (Blueprint $t) {
            $t->dropForeign(['tenant_id']);
            $t->dropUnique('esd_domain_unique');
        });

        Schema::table('email_sender_domains', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable(false)->change();
            $t->unique(['tenant_id', 'domain'], 'esd_tenant_domain_unique');
            $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }
};
```

- [ ] **Step 3: Rodar migration + rollback + migration novamente (sanity check)**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php artisan migrate
php artisan migrate:rollback --step=1
php artisan migrate
```

Expected: nenhum erro nos 3 comandos. Última saída mostra a migration aplicada.

- [ ] **Step 4: Verificar schema**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php artisan tinker --execute "print_r(\Illuminate\Support\Facades\Schema::getColumns('email_sender_domains'));"
```

Expected: coluna `tenant_id` com `nullable: true`. Pra unique:

```powershell
php artisan tinker --execute "print_r(\Illuminate\Support\Facades\Schema::getIndexes('email_sender_domains'));"
```

Expected: existe `esd_domain_unique` em `domain`; **não** existe `esd_tenant_domain_unique`.

- [ ] **Step 5: Commit**

```powershell
cd c:/xampp/htdocs/new_saas
git add backend/database/migrations/2026_05_30_000001_email_sender_domains_allow_admin_sync.php
git commit -m "feat(db): allow nullable tenant_id on email_sender_domains for admin sync"
```

---

## Task 2: Service — `syncFromInfobip()` (test first)

**Files:**
- Test: `backend/tests/Unit/Services/EmailDomainSyncTest.php`
- Modify: `backend/app/Services/Messaging/EmailDomainService.php`

- [ ] **Step 1: Escrever teste unitário (falha)**

Create `backend/tests/Unit/Services/EmailDomainSyncTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\EmailSenderDomain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Messaging\EmailDomainService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailDomainSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
    }

    private function infobipListPayload(array $domains): array
    {
        return [
            'results' => array_map(function (array $d) {
                return [
                    'domainId'   => $d['id'] ?? 1,
                    'domainName' => $d['name'],
                    'active'     => $d['active'] ?? false,
                    'dnsRecords' => [
                        [
                            'type' => 'TXT',
                            'name' => "selector1._domainkey.{$d['name']}",
                            'expectedValue' => 'v=DKIM1; k=rsa; p=MIIBI...',
                            'verified' => $d['dkim'] ?? false,
                        ],
                        [
                            'type' => 'TXT',
                            'name' => $d['name'],
                            'expectedValue' => 'v=spf1 include:spf.infobip.com ~all',
                            'verified' => $d['spf'] ?? false,
                        ],
                        [
                            'type' => 'CNAME',
                            'name' => "bounces.{$d['name']}",
                            'expectedValue' => 'bounces.infobip.com',
                            'verified' => $d['cname'] ?? false,
                        ],
                    ],
                ];
            }, $domains),
            'paging' => ['page' => 0, 'size' => count($domains), 'totalPages' => 1, 'totalResults' => count($domains)],
        ];
    }

    public function test_sync_creates_new_domain_with_null_tenant(): void
    {
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(
                $this->infobipListPayload([
                    ['name' => 'pixreals.com', 'id' => 42, 'active' => true, 'dkim' => true, 'spf' => true, 'cname' => true],
                ]),
                200
            ),
        ]);

        $result = app(EmailDomainService::class)->syncFromInfobip();

        $this->assertSame(1, $result['synced']);
        $this->assertDatabaseHas('email_sender_domains', [
            'domain'    => 'pixreals.com',
            'tenant_id' => null,
            'status'    => 'active',
        ]);
    }

    public function test_sync_preserves_existing_tenant_assignment(): void
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'domain'    => 'pixreals.com',
            'status'    => 'pending',
        ]);

        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(
                $this->infobipListPayload([
                    ['name' => 'pixreals.com', 'active' => true, 'dkim' => true, 'spf' => true, 'cname' => true],
                ]),
                200
            ),
        ]);

        app(EmailDomainService::class)->syncFromInfobip();

        $row = EmailSenderDomain::withoutGlobalScopes()->where('domain', 'pixreals.com')->first();
        $this->assertSame($tenant->id, $row->tenant_id, 'sync must NOT overwrite existing tenant_id');
        $this->assertSame('active', $row->status, 'sync should refresh status from Infobip');
    }

    public function test_sync_marks_status_verifying_when_partial(): void
    {
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(
                $this->infobipListPayload([
                    ['name' => 'partial.com', 'dkim' => true, 'spf' => false, 'cname' => false],
                ]),
                200
            ),
        ]);

        app(EmailDomainService::class)->syncFromInfobip();

        $this->assertDatabaseHas('email_sender_domains', [
            'domain' => 'partial.com',
            'status' => 'verifying',
        ]);
    }

    public function test_sync_handles_paginated_response(): void
    {
        $page0 = [
            'results' => [
                ['domainId' => 1, 'domainName' => 'a.com', 'active' => false, 'dnsRecords' => []],
            ],
            'paging' => ['page' => 0, 'size' => 1, 'totalPages' => 2, 'totalResults' => 2],
        ];
        $page1 = [
            'results' => [
                ['domainId' => 2, 'domainName' => 'b.com', 'active' => false, 'dnsRecords' => []],
            ],
            'paging' => ['page' => 1, 'size' => 1, 'totalPages' => 2, 'totalResults' => 2],
        ];

        Http::fakeSequence('api.infobip.com/email/1/domains*')
            ->push($page0, 200)
            ->push($page1, 200);

        $result = app(EmailDomainService::class)->syncFromInfobip();

        $this->assertSame(2, $result['synced']);
        $this->assertDatabaseHas('email_sender_domains', ['domain' => 'a.com']);
        $this->assertDatabaseHas('email_sender_domains', ['domain' => 'b.com']);
    }

    public function test_sync_throws_runtime_exception_on_http_failure(): void
    {
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        app(EmailDomainService::class)->syncFromInfobip();
    }

    public function test_sync_throws_when_infobip_not_configured(): void
    {
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', '', 'encrypted');

        $this->expectException(\App\Exceptions\InfobipNotConfiguredException::class);
        app(EmailDomainService::class)->syncFromInfobip();
    }
}
```

- [ ] **Step 2: Rodar testes — confirmar que falham**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php vendor/bin/phpunit --filter=EmailDomainSyncTest
```

Expected: 6 falhas, todas reclamando que `syncFromInfobip()` não existe.

- [ ] **Step 3: Implementar `syncFromInfobip` no service**

Modify `backend/app/Services/Messaging/EmailDomainService.php`. Add after the `verify()` method, before `deleteRemote()`:

```php
    /**
     * Pull every email domain from Infobip and upsert locally.
     *
     * Existing rows are updated in place (tenant_id preserved). New rows are
     * created with tenant_id=NULL (pool, awaiting admin assignment).
     *
     * @return array{synced: int}
     * @throws \RuntimeException on HTTP error
     */
    public function syncFromInfobip(): array
    {
        $client = $this->infobip->makeClient();

        $synced = 0;
        $page   = 0;
        $size   = 100;

        do {
            try {
                $resp = $client->get('/email/1/domains', ['page' => $page, 'size' => $size]);
            } catch (\Illuminate\Http\Client\RequestException $e) {
                $resp = $e->response;
            }

            if (!$resp->successful()) {
                $error = $resp->json('requestError.serviceException.text') ?? $resp->body();
                $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
                \Illuminate\Support\Facades\Log::channel('infobip')->warning('email_domain.sync.failed', [
                    'status' => $resp->status(), 'error' => $error,
                ]);
                throw new \RuntimeException("Falha ao listar dominios do Infobip: {$error}");
            }

            $body    = $resp->json() ?? [];
            $results = $body['results'] ?? [];
            $paging  = $body['paging']  ?? null;

            foreach ($results as $entry) {
                $domain = mb_strtolower((string) ($entry['domainName'] ?? ''));
                if ($domain === '') {
                    continue;
                }

                $records = $this->parseDnsRecords($entry['dnsRecords'] ?? []);
                $allVerified = $records['dkim_verified'] && $records['spf_verified'] && $records['return_path_verified'];
                $remoteActive = ($entry['active'] ?? false) === true;

                if ($allVerified || $remoteActive) {
                    $status = 'active';
                } elseif ($records['dkim_verified'] || $records['spf_verified'] || $records['return_path_verified']) {
                    $status = 'verifying';
                } else {
                    $status = 'pending';
                }

                // updateOrCreate by `domain` (now globally unique). tenant_id is
                // ONLY set on create — never overwrite an existing assignment.
                $existing = EmailSenderDomain::withoutGlobalScopes()
                    ->where('domain', $domain)
                    ->first();

                $payload = [
                    'infobip_domain_id'    => isset($entry['domainId']) ? (string) $entry['domainId'] : ($existing->infobip_domain_id ?? null),
                    'status'               => $status,
                    'dkim_selector'        => $records['dkim_selector'] ?? ($existing->dkim_selector ?? null),
                    'dkim_value'           => $records['dkim_value']    ?? ($existing->dkim_value    ?? null),
                    'spf_value'            => $records['spf_value']     ?? ($existing->spf_value     ?? null),
                    'return_path_value'    => $records['return_path_value'] ?? ($existing->return_path_value ?? null),
                    'dkim_verified'        => $records['dkim_verified'],
                    'spf_verified'         => $records['spf_verified'],
                    'return_path_verified' => $records['return_path_verified'],
                    'tracking_opens'       => isset($entry['tracking']['open'])   ? (bool) $entry['tracking']['open']   : ($existing->tracking_opens  ?? false),
                    'tracking_clicks'      => isset($entry['tracking']['clicks']) ? (bool) $entry['tracking']['clicks'] : ($existing->tracking_clicks ?? false),
                    'last_verified_at'     => now(),
                ];

                if ($existing) {
                    $existing->fill($payload)->save();
                } else {
                    EmailSenderDomain::withoutGlobalScopes()->create(array_merge(
                        ['tenant_id' => null, 'domain' => $domain],
                        $payload
                    ));
                }

                $synced++;
            }

            $totalPages = (int) ($paging['totalPages'] ?? 1);
            $page++;
        } while ($paging !== null && $page < $totalPages);

        \Illuminate\Support\Facades\Log::channel('infobip')->info('email_domain.sync.ok', ['count' => $synced]);

        return ['synced' => $synced];
    }
```

> **Note:** `parseDnsRecords` permanece `private`. O sync chama-o internamente porque está na mesma classe. **Não** exponha publicamente.

- [ ] **Step 4: Rodar testes — confirmar que passam**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php vendor/bin/phpunit --filter=EmailDomainSyncTest
```

Expected: 6 passed.

- [ ] **Step 5: Commit**

```powershell
cd c:/xampp/htdocs/new_saas
git add backend/app/Services/Messaging/EmailDomainService.php backend/tests/Unit/Services/EmailDomainSyncTest.php
git commit -m "feat(email-domains): EmailDomainService::syncFromInfobip with tenant preservation"
```

---

## Task 3: Admin controller (test first)

**Files:**
- Test: `backend/tests/Feature/Admin/InfobipEmailSyncTest.php`
- Create: `backend/app/Http/Controllers/API/V1/Admin/InfobipEmailController.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Escrever feature tests (falham — endpoint não existe)**

Create `backend/tests/Feature/Admin/InfobipEmailSyncTest.php`:

```php
<?php

namespace Tests\Feature\Admin;

use App\Models\EmailSenderDomain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InfobipEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
    }

    private function asSuperadmin(): User
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($user, ['*']);
        return $user;
    }

    private function asTenantUser(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        Sanctum::actingAs($user, ['*']);
        return $user;
    }

    private function infobipListPayload(): array
    {
        return [
            'results' => [
                [
                    'domainId'   => 42,
                    'domainName' => 'pixreals.com',
                    'active'     => true,
                    'dnsRecords' => [
                        ['type' => 'TXT', 'name' => 'sel1._domainkey.pixreals.com', 'expectedValue' => 'v=DKIM1;...', 'verified' => true],
                        ['type' => 'TXT', 'name' => 'pixreals.com', 'expectedValue' => 'v=spf1 include:spf.infobip.com ~all', 'verified' => true],
                        ['type' => 'CNAME', 'name' => 'bounces.pixreals.com', 'expectedValue' => 'bounces.infobip.com', 'verified' => true],
                    ],
                ],
            ],
            'paging' => ['page' => 0, 'size' => 1, 'totalPages' => 1, 'totalResults' => 1],
        ];
    }

    public function test_non_superadmin_cannot_list_domains(): void
    {
        $this->asTenantUser();
        $this->getJson('/api/v1/admin/infobip-email/domains')->assertStatus(403);
    }

    public function test_non_superadmin_cannot_sync(): void
    {
        $this->asTenantUser();
        $this->postJson('/api/v1/admin/infobip-email/sync')->assertStatus(403);
    }

    public function test_non_superadmin_cannot_assign(): void
    {
        $this->asTenantUser();
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);
        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => null])
            ->assertStatus(403);
    }

    public function test_superadmin_lists_pool_and_assigned_domains(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'name' => 'Acme']);

        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'pool.com', 'status' => 'pending',
        ]);
        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'domain' => 'acme.com', 'status' => 'active',
        ]);

        $resp = $this->getJson('/api/v1/admin/infobip-email/domains')->assertStatus(200);
        $domains = collect($resp->json('data'));
        $this->assertCount(2, $domains);
        $this->assertContains('pool.com', $domains->pluck('domain')->all());
        $this->assertContains('acme.com', $domains->pluck('domain')->all());

        $acme = $domains->firstWhere('domain', 'acme.com');
        $this->assertSame('Acme', $acme['tenant']['name'] ?? null);
    }

    public function test_superadmin_syncs_domains(): void
    {
        $this->asSuperadmin();
        Http::fake([
            'api.infobip.com/email/1/domains*' => Http::response($this->infobipListPayload(), 200),
        ]);

        $resp = $this->postJson('/api/v1/admin/infobip-email/sync')->assertStatus(200);
        $this->assertSame(1, $resp->json('data.synced'));
        $this->assertDatabaseHas('email_sender_domains', ['domain' => 'pixreals.com', 'tenant_id' => null]);
    }

    public function test_assign_to_tenant_sets_provider_setting(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'pixreals.com', 'status' => 'active',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => $tenant->id])
            ->assertStatus(200)
            ->assertJsonPath('data.tenant_id', $tenant->id);

        $this->assertSame('infobip', app(SettingsService::class)->get($tenant->id, 'email', 'provider'));
    }

    public function test_assign_with_null_tenant_unassigns(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'domain' => 'pixreals.com', 'status' => 'active',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.tenant_id', null);
    }

    public function test_assign_does_not_unassign_other_domains_of_same_tenant(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        $a = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'domain' => 'one.com', 'status' => 'active',
        ]);
        $b = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'two.com', 'status' => 'active',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$b->id}/assign", ['tenant_id' => $tenant->id])
            ->assertStatus(200);

        $this->assertSame($tenant->id, $a->fresh()->tenant_id, 'pre-existing assignment must persist (N domains per tenant allowed)');
        $this->assertSame($tenant->id, $b->fresh()->tenant_id);
    }

    public function test_tenant_user_does_not_see_pool_domains_via_tenant_endpoint(): void
    {
        // Ensure unassigned pool entries are NOT leaked through the tenant-facing /email-domains index.
        $this->asTenantUser();
        EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'pool.com', 'status' => 'pending',
        ]);

        $resp = $this->getJson('/api/v1/email-domains')->assertStatus(200);
        $domains = collect($resp->json('data'));
        $this->assertNotContains('pool.com', $domains->pluck('domain')->all());
    }
}
```

- [ ] **Step 2: Rodar testes — confirmar que falham (rotas não existem)**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php vendor/bin/phpunit --filter=InfobipEmailSyncTest
```

Expected: 9 falhas (404 nas rotas, ou tests/asserts não cumpridos).

- [ ] **Step 3: Criar o controller**

Create `backend/app/Http/Controllers/API/V1/Admin/InfobipEmailController.php`:

```php
<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EmailSenderDomain;
use App\Services\Messaging\EmailDomainService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InfobipEmailController extends Controller
{
    public function __construct(
        private EmailDomainService $service,
        private SettingsService $settings,
    ) {}

    /**
     * GET /admin/infobip-email/domains
     * Lists every domain (including unassigned pool), with tenant info eager-loaded.
     */
    public function domains()
    {
        $domains = EmailSenderDomain::withoutGlobalScopes()
            ->with('tenant:id,name')
            ->orderBy('domain')
            ->get();

        return ApiResponse::success($domains);
    }

    /**
     * POST /admin/infobip-email/sync
     * Pulls all domains from Infobip and upserts locally.
     */
    public function sync()
    {
        try {
            $result = $this->service->syncFromInfobip();
        } catch (\App\Exceptions\InfobipNotConfiguredException $e) {
            return ApiResponse::error('Infobip API key não configurada.', [], 422);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 502);
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('email_domain.sync.exception', ['error' => $e->getMessage()]);
            return ApiResponse::error('Erro ao sincronizar: ' . $e->getMessage(), [], 500);
        }

        return ApiResponse::success($result, "{$result['synced']} domínios sincronizados");
    }

    /**
     * PUT /admin/infobip-email/domains/{id}/assign
     * Body: { tenant_id: int|null }
     *
     * Unlike WhatsApp numbers (1 number ↔ 1 tenant), email domains are N ↔ 1:
     * we do NOT unassign other domains belonging to the same tenant.
     */
    public function assign(int $id, Request $request)
    {
        $domain = EmailSenderDomain::withoutGlobalScopes()->findOrFail($id);

        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        $tenantId = $data['tenant_id'];

        if ($tenantId !== null) {
            $this->settings->upsert($tenantId, 'email', 'provider', 'infobip', 'string');
        }

        $domain->update(['tenant_id' => $tenantId]);

        return ApiResponse::success($domain->fresh()->load('tenant:id,name'), 'Domínio atualizado');
    }
}
```

- [ ] **Step 4: Adicionar rotas**

Modify `backend/routes/api.php` — adicionar **logo após** as 3 linhas de `infobip-whatsapp` (perto da linha 311):

```php
            // Infobip Email Domains
            Route::get('infobip-email/domains',               [\App\Http\Controllers\API\V1\Admin\InfobipEmailController::class, 'domains']);
            Route::post('infobip-email/sync',                 [\App\Http\Controllers\API\V1\Admin\InfobipEmailController::class, 'sync']);
            Route::put('infobip-email/domains/{id}/assign',   [\App\Http\Controllers\API\V1\Admin\InfobipEmailController::class, 'assign']);
```

> Use FQN ou adicione `use App\Http\Controllers\API\V1\Admin\InfobipEmailController;` no topo do arquivo, seguindo o padrão dos imports existentes (`use App\Http\Controllers\API\V1\Admin\InfobipWhatsAppController;`).

- [ ] **Step 5: Rodar testes — confirmar que passam**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php vendor/bin/phpunit --filter=InfobipEmailSyncTest
```

Expected: 9 passed.

- [ ] **Step 6: Sanity check do EmailDomainsApiTest existente (regressão)**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php vendor/bin/phpunit --filter=EmailDomainsApiTest
```

Expected: todos os testes anteriores continuam verdes (a migration não pode ter quebrado nada).

- [ ] **Step 7: Commit**

```powershell
cd c:/xampp/htdocs/new_saas
git add backend/app/Http/Controllers/API/V1/Admin/InfobipEmailController.php backend/routes/api.php backend/tests/Feature/Admin/InfobipEmailSyncTest.php
git commit -m "feat(admin): InfobipEmail endpoints (list, sync, assign)"
```

---

## Task 4: Pentest — IDOR + injection coverage

**Files:**
- Test: `backend/tests/Feature/Security/EmailDomainsAdminIdorTest.php`

- [ ] **Step 1: Escrever pentest**

Create `backend/tests/Feature/Security/EmailDomainsAdminIdorTest.php`:

```php
<?php

namespace Tests\Feature\Security;

use App\Models\EmailSenderDomain;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailDomainsAdminIdorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
    }

    private function asSuperadmin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_assign_rejects_nonexistent_tenant_id(): void
    {
        $this->asSuperadmin();
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => 999999])
            ->assertStatus(422);
    }

    public function test_assign_rejects_string_tenant_id_injection(): void
    {
        $this->asSuperadmin();
        $row = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => "1' OR '1'='1"])
            ->assertStatus(422);
    }

    public function test_assign_to_nonexistent_domain_returns_404(): void
    {
        $this->asSuperadmin();
        $this->putJson('/api/v1/admin/infobip-email/domains/9999/assign', ['tenant_id' => null])
            ->assertStatus(404);
    }

    public function test_assign_is_idempotent_no_duplicate_settings(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row    = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => $tenant->id])->assertStatus(200);
        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", ['tenant_id' => $tenant->id])->assertStatus(200);

        $settingsRows = \DB::table('settings')
            ->where('tenant_id', $tenant->id)
            ->where('group', 'email')
            ->where('key', 'provider')
            ->count();

        $this->assertSame(1, $settingsRows, 'repeated assign must not duplicate settings rows');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/infobip-email/domains')->assertStatus(401);
        $this->postJson('/api/v1/admin/infobip-email/sync')->assertStatus(401);
        $this->putJson('/api/v1/admin/infobip-email/domains/1/assign', ['tenant_id' => null])->assertStatus(401);
    }

    public function test_assign_strips_unexpected_fields_and_only_updates_tenant_id(): void
    {
        $this->asSuperadmin();
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $row    = EmailSenderDomain::withoutGlobalScopes()->create([
            'tenant_id' => null, 'domain' => 'x.com', 'status' => 'pending',
        ]);

        $this->putJson("/api/v1/admin/infobip-email/domains/{$row->id}/assign", [
            'tenant_id' => $tenant->id,
            'domain'    => 'hijacked.com',
            'status'    => 'active',
        ])->assertStatus(200);

        $fresh = $row->fresh();
        $this->assertSame('x.com', $fresh->domain, 'domain must not be mass-assigned via assign endpoint');
        $this->assertSame('pending', $fresh->status, 'status must not be mass-assigned via assign endpoint');
        $this->assertSame($tenant->id, $fresh->tenant_id);
    }
}
```

- [ ] **Step 2: Rodar testes**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php vendor/bin/phpunit --filter=EmailDomainsAdminIdorTest
```

Expected: 6 passed. Se `test_assign_strips_unexpected_fields_and_only_updates_tenant_id` falhar, é porque o controller usa `$request->all()` em vez de `$request->validate()` retornando array filtrado — corrija o controller pra usar **apenas** `$data['tenant_id']` (já está assim no código de Task 3).

- [ ] **Step 3: Commit**

```powershell
cd c:/xampp/htdocs/new_saas
git add backend/tests/Feature/Security/EmailDomainsAdminIdorTest.php
git commit -m "test(security): IDOR + injection coverage for InfobipEmail admin endpoints"
```

---

## Task 5: Frontend — admin page `/admin/infobip-email`

**Files:**
- Create: `frontend/src/pages/admin/InfobipEmailDomains.vue`
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/components/layout/AppSidebar.vue`

- [ ] **Step 1: Criar a página Vue**

Create `frontend/src/pages/admin/InfobipEmailDomains.vue`:

```vue
<template>
  <div class="page-header d-print-none">
    <div class="container-xl">
      <div class="d-flex align-items-center justify-content-between">
        <h2 class="page-title">Domínios de Email via Infobip</h2>
        <button class="btn btn-primary" @click="syncDomains" :disabled="isSyncing">
          <span v-if="isSyncing" class="spinner-border spinner-border-sm me-1"></span>
          <i v-else class="ti ti-refresh me-1"></i>
          Sincronizar domínios
        </button>
      </div>
    </div>
  </div>
  <div class="page-body">
    <div class="container-xl">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Domínios disponíveis</h3>
          <div class="card-actions">
            <span class="text-muted small">{{ domains.length }} domínios</span>
          </div>
        </div>
        <div v-if="isLoading" class="card-body">
          <div class="placeholder-glow">
            <div v-for="n in 3" :key="n" class="mb-2"><span class="placeholder col-8"></span></div>
          </div>
        </div>
        <div v-else-if="!domains.length" class="card-body text-center py-5">
          <i class="ti ti-mail" style="font-size:3rem;color:#ccc"></i>
          <p class="text-muted mt-2">Nenhum domínio encontrado. Clique "Sincronizar" para buscar da API Infobip.</p>
        </div>
        <div v-else class="table-responsive">
          <table class="table table-vcenter card-table">
            <thead>
              <tr>
                <th>Domínio</th>
                <th>Status</th>
                <th>DKIM</th>
                <th>SPF</th>
                <th>Return-Path</th>
                <th>Tenant atribuído</th>
                <th style="width:180px">Ação</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="d in domains" :key="d.id">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <i class="ti ti-at text-blue"></i>
                    <strong>{{ d.domain }}</strong>
                  </div>
                  <div v-if="d.infobip_domain_id" class="text-muted small">Infobip ID: {{ d.infobip_domain_id }}</div>
                </td>
                <td><StatusBadge :label="statusLabel(d.status)" :status="statusToKey(d.status)" dot /></td>
                <td><i :class="d.dkim_verified ? 'ti ti-check text-green' : 'ti ti-x text-muted'"></i></td>
                <td><i :class="d.spf_verified ? 'ti ti-check text-green' : 'ti ti-x text-muted'"></i></td>
                <td><i :class="d.return_path_verified ? 'ti ti-check text-green' : 'ti ti-x text-muted'"></i></td>
                <td>
                  <RouterLink
                    v-if="d.tenant"
                    :to="`/admin/billing/tenants/${d.tenant.id}`"
                    class="bc-tenant-link"
                  >
                    <i class="ti ti-external-link me-1"></i>{{ d.tenant.name }}
                  </RouterLink>
                  <span v-else style="color:var(--bc-text-muted,#6c7293);font-size:0.82rem">Disponível</span>
                </td>
                <td>
                  <button
                    v-if="d.tenant"
                    class="btn btn-sm btn-outline-danger"
                    @click="unassign(d)"
                    :disabled="busyId === d.id"
                  >
                    <span v-if="busyId === d.id" class="spinner-border spinner-border-sm me-1"></span>
                    <i v-else class="ti ti-link-off me-1"></i>
                    Desassociar
                  </button>
                  <button
                    v-else
                    class="btn btn-sm btn-outline-primary"
                    @click="openAssignModal(d)"
                    :disabled="busyId === d.id"
                  >
                    <i class="ti ti-link me-1"></i>
                    Associar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="domains.length" class="card-footer text-muted small">
          <i class="ti ti-info-circle me-1"></i>
          Ao atribuir um domínio a um tenant, o provider de email dele será automaticamente configurado para Infobip.
        </div>
      </div>
    </div>
  </div>

  <!-- Assign modal -->
  <div ref="modalEl" class="modal modal-blur fade" tabindex="-1" aria-labelledby="assignEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="assignEmailModalTitle">
            Associar domínio
            <span v-if="selectedDomain" class="text-muted ms-1" style="font-weight:400">{{ selectedDomain.domain }}</span>
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <input
              v-model="search"
              @input="onSearch"
              type="search"
              class="form-control"
              placeholder="Buscar por nome ou slug (mín. 2 caracteres)..."
              autocomplete="off"
            />
          </div>
          <div v-if="searchLoading" class="text-center py-4">
            <span class="spinner-border spinner-border-sm"></span>
          </div>
          <div v-else-if="!searchResults.length" class="text-muted text-center py-4">
            <i class="ti ti-mood-empty d-block mb-2" style="font-size:1.5rem"></i>
            Nenhum tenant encontrado
          </div>
          <ul v-else class="list-unstyled mb-0 bc-tenant-list">
            <li
              v-for="t in searchResults"
              :key="t.id"
              class="bc-tenant-row"
              @click="confirmAssign(t)"
            >
              <div class="flex-fill" style="min-width:0">
                <div class="text-truncate" style="font-weight:600">{{ t.name }}</div>
                <div class="text-muted small text-truncate">{{ t.slug }}</div>
              </div>
              <i class="ti ti-chevron-right text-muted ms-2"></i>
            </li>
          </ul>
          <div v-if="!searchLoading && searchResults.length && !search" class="text-muted small mt-2 text-center">
            Mostrando os {{ searchResults.length }} primeiros tenants. Digite para refinar a busca.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancelar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'
import { RouterLink } from 'vue-router'
import { Modal } from 'bootstrap'
import { useApi } from '@/composables/useApi'
import { useToast } from '@/composables/useToast'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const { get, put, post } = useApi()
const toast = useToast()

const domains = ref<any[]>([])
const isLoading = ref(false)
const isSyncing = ref(false)
const busyId = ref<number | null>(null)

const modalEl = ref<HTMLElement | null>(null)
let bsModal: Modal | null = null
const selectedDomain = ref<any | null>(null)

const search = ref('')
const searchResults = ref<any[]>([])
const searchLoading = ref(false)
let searchTimer: any = null

function statusLabel(status: string): string {
  const map: Record<string, string> = {
    pending: 'Pendente', verifying: 'Verificando', active: 'Ativo', failed: 'Falhou',
  }
  return map[status?.toLowerCase()] ?? status
}

function statusToKey(status: string): string {
  const s = status?.toLowerCase()
  if (s === 'active') return 'active'
  if (s === 'failed') return 'blocked'
  if (s === 'pending' || s === 'verifying') return 'trial'
  return 'inactive'
}

async function loadDomains() {
  isLoading.value = true
  try {
    const res = await get<any>('/admin/infobip-email/domains')
    domains.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch { domains.value = [] }
  finally { isLoading.value = false }
}

async function syncDomains() {
  isSyncing.value = true
  try {
    const res = await post<any>('/admin/infobip-email/sync')
    toast.success(res?.message ?? 'Sincronizado')
    await loadDomains()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao sincronizar')
  } finally {
    isSyncing.value = false
  }
}

function getModal(): Modal | null {
  if (!bsModal && modalEl.value) {
    bsModal = new Modal(modalEl.value)
  }
  return bsModal
}

async function loadSearch(query = '') {
  searchLoading.value = true
  try {
    const params = query.length >= 2 ? `?search=${encodeURIComponent(query)}` : ''
    const res = await get<any>(`/admin/tenants${params}`)
    searchResults.value = Array.isArray(res) ? res : (res?.data ?? [])
  } catch {
    searchResults.value = []
  } finally {
    searchLoading.value = false
  }
}

function onSearch() {
  clearTimeout(searchTimer)
  const q = search.value.trim()
  if (q.length === 1) return
  searchTimer = setTimeout(() => loadSearch(q), 300)
}

function openAssignModal(d: any) {
  selectedDomain.value = d
  search.value = ''
  searchResults.value = []
  loadSearch('')
  nextTick(() => getModal()?.show())
}

async function confirmAssign(tenant: any) {
  if (!selectedDomain.value) return
  const domainId = selectedDomain.value.id
  busyId.value = domainId
  try {
    await put(`/admin/infobip-email/domains/${domainId}/assign`, { tenant_id: tenant.id })
    toast.success(`Domínio associado a ${tenant.name}`)
    getModal()?.hide()
    await loadDomains()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao associar')
  } finally {
    busyId.value = null
  }
}

async function unassign(d: any) {
  const tenantName = d.tenant?.name ?? 'este tenant'
  if (!confirm(`Desassociar o domínio ${d.domain} de "${tenantName}"?`)) return
  busyId.value = d.id
  try {
    await put(`/admin/infobip-email/domains/${d.id}/assign`, { tenant_id: null })
    toast.success('Domínio desassociado')
    await loadDomains()
  } catch (e: any) {
    toast.error(e?.response?.data?.message ?? 'Erro ao desassociar')
  } finally {
    busyId.value = null
  }
}

onMounted(() => {
  loadDomains()
})
</script>

<style scoped>
.bc-tenant-link {
  display: inline-flex;
  align-items: center;
  padding: 0.25rem 0.6rem;
  font-size: 0.78rem;
  font-weight: 600;
  background: rgba(0, 100, 255, 0.1);
  color: #0064ff;
  border-radius: 999px;
  text-decoration: none;
  transition: background 0.15s ease;
}
.bc-tenant-link:hover {
  background: rgba(0, 100, 255, 0.18);
  color: #0064ff;
}
.bc-tenant-list { max-height: 360px; overflow-y: auto; }
.bc-tenant-row {
  display: flex; align-items: center;
  padding: 0.65rem 0.75rem; border-radius: 8px;
  cursor: pointer; border: 1px solid transparent;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.bc-tenant-row:hover { background: rgba(0, 100, 255, 0.06); border-color: rgba(0, 100, 255, 0.18); }
.bc-tenant-row + .bc-tenant-row { margin-top: 2px; }
</style>
```

- [ ] **Step 2: Adicionar rota**

Modify `frontend/src/router/index.ts` — adicionar **logo após** o objeto da rota `/admin/infobip-whatsapp`:

```typescript
  {
    path: '/admin/infobip-email',
    component: () => import('@/pages/admin/InfobipEmailDomains.vue'),
    meta: { superadmin: true, title: 'Admin • Domínios de Email Infobip' },
  },
```

- [ ] **Step 3: Adicionar link no sidebar**

Modify `frontend/src/components/layout/AppSidebar.vue`:

Localize a linha:

```html
<a class="nav-link" :class="{ active: isActive('/admin/infobip-whatsapp') }" href="#" @click.prevent="go('/admin/infobip-whatsapp')">WhatsApp (Infobip)</a>
```

Adicione **logo abaixo** (dentro do mesmo `<ul>`):

```html
              <li class="nav-item">
                <a class="nav-link" :class="{ active: isActive('/admin/infobip-email') }" href="#" @click.prevent="go('/admin/infobip-email')">Email (Infobip)</a>
              </li>
```

E adicione `/admin/infobip-email` ao array de rotas superadmin perto da linha 264 (lista similar onde aparece `/admin/infobip-whatsapp`):

```typescript
  '/admin/infobip-whatsapp',
  '/admin/infobip-email',
```

- [ ] **Step 4: Smoke test visual**

Run (em duas janelas separadas):

```powershell
# Janela 1 - backend
cd c:/xampp/htdocs/new_saas/backend
php artisan serve
```

```powershell
# Janela 2 - frontend
cd c:/xampp/htdocs/new_saas/frontend
npm run dev
```

Then no browser:

1. Login como superadmin.
2. Acesse `/admin/infobip-email` — deve carregar (lista vazia ou já populada se sync rodou).
3. Clique "Sincronizar domínios" — confirma toast de sucesso + linhas aparecem.
4. Localize `pixreals.com`, clique "Associar" → busca o tenant do pedro → confirma.
5. Linha de `pixreals.com` agora mostra o tenant no badge azul.
6. Clique "Desassociar" → tenant volta pra "Disponível".

Expected: cada passo funciona sem console errors. Network tab mostra 200 em GET/POST/PUT.

- [ ] **Step 5: Commit**

```powershell
cd c:/xampp/htdocs/new_saas
git add frontend/src/pages/admin/InfobipEmailDomains.vue frontend/src/router/index.ts frontend/src/components/layout/AppSidebar.vue
git commit -m "feat(admin-ui): InfobipEmailDomains page with sync and assign"
```

---

## Task 6: Final regression sweep

- [ ] **Step 1: Rodar suite backend completa**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/backend
php vendor/bin/phpunit
```

Expected: 0 falhas. Particularmente atento a:
- `EmailDomainsApiTest` (fluxo tenant não regrediu pela migration)
- `CampaignFromEmailGuardTest` (guard `findActiveForEmail` ainda funciona)
- `EmailDomainsIdorTest` (IDOR tenant antigo intacto)

- [ ] **Step 2: Build frontend**

Run:

```powershell
cd c:/xampp/htdocs/new_saas/frontend
npm run build
```

Expected: build success, sem TS errors.

- [ ] **Step 3: Verificação manual final do fluxo pixreals.com**

(Requer ambiente com Infobip real, ou pode ser deferido pra staging)

1. Acesse `/admin/infobip-email` em ambiente conectado ao Infobip real.
2. Clique "Sincronizar".
3. Verifique que `pixreals.com` aparece no pool.
4. Atribua ao tenant correto.
5. Login como pedro → criar campanha de email com `from_email: pedro@pixreals.com` → deve passar pelo guard.

Expected: campanha cria sem erro 422 "domínio não cadastrado".

- [ ] **Step 4: Commit final (se houver fixups)**

Se nada precisar de fixup, pular este passo. Se houve fixups menores, commit:

```powershell
cd c:/xampp/htdocs/new_saas
git add -A
git commit -m "chore(email-domain-sync): post-regression fixups"
```

---

## Spec Coverage Check

| Spec § | Requisito | Task |
|---|---|---|
| 4 | `tenant_id` nullable + `unique(domain)` + `nullOnDelete` | Task 1 |
| 5.1 | Migration nova | Task 1 |
| 5.1 | `InfobipEmailController` novo | Task 3 |
| 5.1 | `EmailDomainSyncTest` (unit) | Task 2 |
| 5.1 | `InfobipEmailSyncTest` (feature) | Task 3 |
| 5.2 | `EmailDomainService::syncFromInfobip` | Task 2 |
| 5.2 | Rotas admin em `routes/api.php` | Task 3 |
| 5.3 | GET / POST sync / PUT assign | Task 3 |
| 5.3 | Preservar `tenant_id` no upsert | Task 2 |
| 5.3 | Não desatribuir outros domínios do tenant | Task 3 (`test_assign_does_not_unassign_other_domains_of_same_tenant`) |
| 5.3 | `settings.email.provider = infobip` em assign | Task 3 |
| 5.4 | Migration falha barulhento em duplicatas | Task 1 Step 1 (pre-flight check) |
| 6 | Vue page `/admin/infobip-email` | Task 5 |
| 6 | Sidebar link | Task 5 |
| 7.1 | Cobertura unit | Task 2 |
| 7.2 | Cobertura feature | Task 3 |
| 7.3 | Cobertura pentest | Task 4 |
| 8 | Plano de rollout | Task 6 Step 3 |
| 9 | Risco: sync sobrescreve tenant_id | Task 2 (`test_sync_preserves_existing_tenant_assignment`) |
| 9 | Risco: tenant deletado → domínio volta pro pool | Task 1 (FK `nullOnDelete`) |
