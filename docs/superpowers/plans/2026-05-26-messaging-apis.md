# Messaging APIs (SMS / Voice / Email) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build authenticated direct-send APIs for SMS, voice, and email with auditable billing, blocking opt-out, per-tenant rate limiting, idempotency, and quiet hours — without regressing the existing campaign flow. Validated end-to-end by Dev Senior + QA + Red Team + Code Reviewer, gated by PM at each phase.

**Architecture:** Laravel 12 backend, all routes under `auth:sanctum` + new `EnsureTokenAbility` middleware checking `tokenCan()` scopes. `MessagingService` facade orchestrates idempotency → opt-out → quiet hours → credit reserve (DB::transaction) → enqueue `SendMessageJob`. Job calls `InfobipService` (already tested) or `Mail::send` for transactional email; webhook updates dispatch status. New `message_dispatches` table separate from `campaign_dispatches`.

**Tech Stack:** Laravel 12, PHP 8.2+, MySQL/MariaDB, Laravel Sanctum (tokens with abilities), Laravel Mail, Infobip HTTP API, HTMLPurifier, PHPUnit.

**Spec:** [docs/superpowers/specs/2026-05-26-messaging-apis-design.md](../specs/2026-05-26-messaging-apis-design.md)

---

## Phase Roadmap

| Phase | Owner | Output | Gate |
|-------|-------|--------|------|
| 0 | PM | Worktree + plan approved | conversation |
| 1 | Dev Senior | Route audit + webhook lookup extension | PM diff review |
| 2 | Dev Senior | Migrations, models, services, jobs, middleware, controllers, routes | PM diff review |
| 3 | QA Engineer | Unit + Feature tests, coverage ≥80% | PM test output review |
| 4 | Red Team | Pentest tests + fix report | PM relatório review |
| 5 | Code Reviewer | Final review vs spec | PM relatório review |
| 6 | PM | Merge + smoke E2E real | done |

---

## File Map

### New Files — Backend
- `backend/database/migrations/2026_05_26_000001_create_message_dispatches_table.php`
- `backend/database/migrations/2026_05_26_000002_create_message_opt_outs_table.php`
- `backend/database/migrations/2026_05_26_000003_add_messaging_settings_to_plans.php`
- `backend/app/Models/MessageDispatch.php`
- `backend/app/Models/MessageOptOut.php`
- `backend/app/Services/Messaging/MessagingService.php`
- `backend/app/Services/Messaging/OptOutService.php`
- `backend/app/Services/Messaging/QuietHoursService.php`
- `backend/app/Services/Messaging/IdempotencyService.php`
- `backend/app/Services/Messaging/PricingService.php`
- `backend/app/Services/Messaging/PhoneNormalizer.php`
- `backend/app/Exceptions/Messaging/InsufficientCreditsException.php`
- `backend/app/Exceptions/Messaging/RecipientOptedOutException.php`
- `backend/app/Exceptions/Messaging/QuietHoursException.php`
- `backend/app/Exceptions/Messaging/IdempotencyKeyReuseException.php`
- `backend/app/Jobs/SendMessageJob.php`
- `backend/app/Mail/TransactionalMailer.php`
- `backend/app/Http/Middleware/EnsureTokenAbility.php`
- `backend/app/Http/Controllers/API/V1/Messaging/SmsController.php`
- `backend/app/Http/Controllers/API/V1/Messaging/VoiceController.php`
- `backend/app/Http/Controllers/API/V1/Messaging/EmailController.php`
- `backend/app/Http/Controllers/API/V1/Messaging/DispatchesController.php`
- `backend/app/Http/Controllers/API/V1/Messaging/OptOutsController.php`
- `backend/app/Http/Controllers/API/V1/Messaging/UnsubscribeController.php`
- `backend/app/Http/Controllers/API/V1/ApiTokensController.php`
- `backend/app/Http/Controllers/API/V1/InboundWebhookController.php`
- `backend/app/Http/Controllers/API/V1/Admin/MessagingAdminController.php`
- `backend/app/Http/Requests/Messaging/SendSmsRequest.php`
- `backend/app/Http/Requests/Messaging/SendVoiceRequest.php`
- `backend/app/Http/Requests/Messaging/SendEmailRequest.php`
- `backend/app/Http/Requests/Messaging/CreateOptOutRequest.php`
- `backend/app/Http/Requests/Messaging/CreateApiTokenRequest.php`
- `backend/app/Policies/MessageDispatchPolicy.php`
- `backend/config/messaging.php`
- `backend/tests/Unit/Messaging/MessagingServiceTest.php`
- `backend/tests/Unit/Messaging/OptOutServiceTest.php`
- `backend/tests/Unit/Messaging/QuietHoursServiceTest.php`
- `backend/tests/Unit/Messaging/IdempotencyServiceTest.php`
- `backend/tests/Unit/Messaging/PricingServiceTest.php`
- `backend/tests/Unit/Messaging/PhoneNormalizerTest.php`
- `backend/tests/Unit/Jobs/SendMessageJobTest.php`
- `backend/tests/Unit/Middleware/EnsureTokenAbilityMiddlewareTest.php`
- `backend/tests/Unit/Policies/MessageDispatchPolicyTest.php`
- `backend/tests/Feature/Messaging/SendSmsApiTest.php`
- `backend/tests/Feature/Messaging/SendVoiceApiTest.php`
- `backend/tests/Feature/Messaging/SendEmailApiTest.php`
- `backend/tests/Feature/Messaging/GetDispatchStatusTest.php`
- `backend/tests/Feature/Messaging/OptOutsApiTest.php`
- `backend/tests/Feature/Messaging/UnsubscribeTest.php`
- `backend/tests/Feature/Messaging/ApiTokenCrudTest.php`
- `backend/tests/Feature/Messaging/TransactionalEmailRegistersDispatchTest.php`
- `backend/tests/Feature/Messaging/BillingFlowTest.php`
- `backend/tests/Feature/Messaging/InboundWebhookSmsOptOutTest.php`
- `backend/tests/Feature/Messaging/WebhookDeliveryRoutingTest.php`
- `backend/tests/Feature/Messaging/RateLimiterMessagingTest.php`
- `backend/tests/Feature/Admin/MessagingAdminTest.php`
- `backend/tests/Pentest/MessagingSecurityTest.php`

### Modified Files — Backend
- `backend/.env.example` — add `MESSAGING_*` and `INFOBIP_INBOUND_WEBHOOK_SECRET`
- `backend/config/services.php` — add messaging config section
- `backend/bootstrap/app.php` — register `token.ability` middleware alias
- `backend/app/Providers/AppServiceProvider.php` — register 3 RateLimiter::for() for `messaging-sms|voice|email`
- `backend/app/Models/Plan.php` — add fillable/casts for new fields
- `backend/app/Http/Controllers/API/V1/WebhookController.php` — extend `infobipDelivery()` lookup to `message_dispatches` first
- `backend/routes/api.php` — add 15 new routes

---

## Phase 0 — Setup

### Task 0.1: Create worktree and approve plan

**Files:** none (worktree operation)

- [ ] **Step 1: Create isolated worktree**

```powershell
cd c:\xampp\htdocs\new_saas
git worktree add ..\new_saas-messaging feat/messaging-apis
```

Expected: new directory `c:\xampp\htdocs\new_saas-messaging` with branch `feat/messaging-apis` checked out.

- [ ] **Step 2: Verify worktree clean**

```powershell
cd c:\xampp\htdocs\new_saas-messaging
git status
```

Expected: `nothing to commit, working tree clean`.

- [ ] **Step 3: PM gate**

PM confirms with user: "Worktree ready at `..\new_saas-messaging` on branch `feat/messaging-apis`. Plan approved. Proceeding to Phase 1."

---

## Phase 1 — Audit and Webhook Lookup Extension (Dev Senior)

### Task 1.1: Audit all routes for auth coverage

**Files:**
- Read: `backend/routes/api.php`
- Create: `backend/docs/route-audit-2026-05-26.md` (one-time artifact, can delete after PR)

- [ ] **Step 1: Generate route list with middleware**

```powershell
cd c:\xampp\htdocs\new_saas-messaging\backend
php artisan route:list --json > ..\..\new_saas\docs\route-list.json
```

- [ ] **Step 2: Identify routes WITHOUT `auth:sanctum`**

Manual inspection (PowerShell): every route in `api.php` outside the `Route::middleware('auth:sanctum')->group(...)` block must be explicitly whitelisted:

```
PUBLIC WHITELIST (must remain unauthenticated):
- POST   auth/login
- POST   auth/register
- POST   auth/forgot-password
- POST   auth/reset-password
- GET    auth/verify-email/{id}/{hash}
- GET    auth/config
- POST   webhooks/infobip/delivery
- GET    webhooks/whatsapp
- POST   webhooks/whatsapp
- POST   webhooks/infobip/whatsapp
- POST   checkout/validate-coupon
- GET    checkout/config
- GET    plans
- GET    public/identity
- GET    public/stats
- POST   webhooks/mercadopago
```

Anything outside this whitelist that lacks `auth:sanctum` is a finding.

- [ ] **Step 3: Write a route-audit test**

`backend/tests/Feature/Security/PublicRoutesWhitelistTest.php`:

```php
<?php
namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicRoutesWhitelistTest extends TestCase
{
    public function test_only_whitelisted_routes_are_public(): void
    {
        $whitelist = [
            'POST api/v1/auth/login',
            'POST api/v1/auth/register',
            'POST api/v1/auth/forgot-password',
            'POST api/v1/auth/reset-password',
            'GET api/v1/auth/verify-email/{id}/{hash}',
            'GET api/v1/auth/config',
            'POST api/v1/webhooks/infobip/delivery',
            'GET api/v1/webhooks/whatsapp',
            'POST api/v1/webhooks/whatsapp',
            'POST api/v1/webhooks/infobip/whatsapp',
            'POST api/v1/webhooks/infobip/inbound',
            'POST api/v1/checkout/validate-coupon',
            'GET api/v1/checkout/config',
            'GET api/v1/plans',
            'GET api/v1/public/identity',
            'GET api/v1/public/stats',
            'POST api/v1/webhooks/mercadopago',
            'GET api/v1/messaging/unsubscribe/{token}',
        ];

        $publicRoutes = collect(Route::getRoutes())
            ->filter(fn($r) => str_starts_with($r->uri(), 'api/v1/'))
            ->filter(fn($r) => ! in_array('auth:sanctum', $r->gatherMiddleware()))
            ->map(fn($r) => implode('|', $r->methods()).' '.$r->uri())
            ->map(fn($s) => str_replace(['POST|HEAD','GET|HEAD','PUT|HEAD','DELETE|HEAD','PATCH|HEAD'], ['POST','GET','PUT','DELETE','PATCH'], $s))
            ->toArray();

        foreach ($publicRoutes as $route) {
            $this->assertContains($route, $whitelist, "Route {$route} is public but not in whitelist");
        }
    }
}
```

- [ ] **Step 4: Run audit test**

```powershell
php artisan test --filter=PublicRoutesWhitelistTest
```

Expected: PASS (current state is OK). If any route fails, add `auth:sanctum` middleware or justify and add to whitelist.

- [ ] **Step 5: Commit**

```powershell
cd ..\..\new_saas-messaging
git add backend/tests/Feature/Security/PublicRoutesWhitelistTest.php
git commit -m "test(security): freeze public route whitelist to prevent accidental exposure

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.2: Extend webhook delivery lookup to message_dispatches

**Files:**
- Modify: `backend/app/Http/Controllers/API/V1/WebhookController.php` (lines 79-92)
- Test: `backend/tests/Feature/Messaging/WebhookDeliveryRoutingTest.php`

> Note: this task creates the test referencing `MessageDispatch` which doesn't exist yet. The implementation in `WebhookController` uses `class_exists` to soft-degrade until Phase 2 creates the model. After Phase 2 the test fully passes.

- [ ] **Step 1: Write failing test (lookup falls back to campaign_dispatches)**

```php
<?php
namespace Tests\Feature\Messaging;

use App\Models\CampaignDispatch;
use App\Models\Tenant;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookDeliveryRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'webhook_secret', 'test-secret', 'string');
    }

    public function test_webhook_updates_campaign_dispatch_when_no_message_dispatch_match(): void
    {
        $tenant = Tenant::factory()->create();
        $dispatch = CampaignDispatch::factory()->create([
            'tenant_id' => $tenant->id,
            'external_message_id' => 'msg-camp-1',
            'status' => 'sent',
        ]);

        $resp = $this->withHeaders(['Authorization' => 'Bearer test-secret'])
            ->postJson('/api/v1/webhooks/infobip/delivery', [
                'results' => [[
                    'messageId' => 'msg-camp-1',
                    'status' => ['groupId' => 3, 'groupName' => 'DELIVERED'],
                ]],
            ]);

        $resp->assertOk()->assertJson(['ok' => true, 'processed' => 1]);
        $this->assertEquals('delivered', $dispatch->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test, verify it passes (current behavior)**

```powershell
php artisan test --filter=WebhookDeliveryRoutingTest::test_webhook_updates_campaign_dispatch_when_no_message_dispatch_match
```

Expected: PASS.

- [ ] **Step 3: Modify WebhookController to attempt message_dispatches first**

Replace [WebhookController.php lines 79-97](backend/app/Http/Controllers/API/V1/WebhookController.php#L79-L97) with:

```php
        foreach ($results as $result) {
            $messageId = $result['messageId'] ?? null;
            if (! $messageId) {
                continue;
            }

            // Try transactional dispatches first
            $dispatch = null;
            if (class_exists(\App\Models\MessageDispatch::class)) {
                $dispatch = \App\Models\MessageDispatch::withoutGlobalScopes()
                    ->where('external_message_id', $messageId)
                    ->first();
            }

            // Fallback to campaign dispatches
            if (! $dispatch) {
                $dispatch = CampaignDispatch::withoutGlobalScopes()
                    ->where('external_message_id', $messageId)
                    ->first();
            }

            if (! $dispatch) {
                Log::channel('infobip')->debug('webhook.dispatch_not_found', ['message_id' => $messageId]);
                continue;
            }

            $newStatus = $this->resolveStatus($result);
            $this->applyStatus($dispatch, $newStatus, $result);
            $processed++;
        }
```

Update the `applyStatus()` method signature from `CampaignDispatch $dispatch` to `\Illuminate\Database\Eloquent\Model $dispatch` (so it accepts both models).

- [ ] **Step 4: Re-run test**

```powershell
php artisan test --filter=WebhookDeliveryRoutingTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/WebhookController.php backend/tests/Feature/Messaging/WebhookDeliveryRoutingTest.php
git commit -m "feat(messaging): webhook lookup tries message_dispatches before campaign_dispatches

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.3: PM Gate Phase 1

- [ ] **Step 1: Show diff to PM**

```powershell
git log --oneline master..HEAD
git diff master..HEAD --stat
```

- [ ] **Step 2: PM confirms with user**: "Phase 1 complete (route whitelist test + webhook lookup extension). 2 commits, ready to proceed to Phase 2?"

---

## Phase 2 — Implementation (Dev Senior)

### Task 2.1: Config and env

**Files:**
- Create: `backend/config/messaging.php`
- Modify: `backend/.env.example`

- [ ] **Step 1: Create config file**

```php
<?php
// backend/config/messaging.php
return [
    'idempotency_ttl_hours' => (int) env('MESSAGING_IDEMPOTENCY_TTL_HOURS', 24),

    'transactional_email_credits' => (int) env('MESSAGING_TRANSACTIONAL_EMAIL_CREDITS', 0),

    'refund_on_undeliverable' => (bool) env('MESSAGING_REFUND_ON_UNDELIVERABLE', false),

    'audio_url_allowlist' => array_filter(
        explode(',', env('MESSAGING_AUDIO_URL_ALLOWLIST', 's3.amazonaws.com,storage.googleapis.com'))
    ),

    'quiet_hours' => [
        'default_timezone' => env('MESSAGING_QUIET_HOURS_DEFAULT_TZ', 'America/Sao_Paulo'),
        'default_start'    => env('MESSAGING_QUIET_HOURS_DEFAULT_START', '22:00'),
        'default_end'      => env('MESSAGING_QUIET_HOURS_DEFAULT_END', '08:00'),
    ],

    'rate_limits' => [
        'sms'   => (int) env('MESSAGING_RATE_LIMIT_SMS',   60),
        'voice' => (int) env('MESSAGING_RATE_LIMIT_VOICE', 10),
        'email' => (int) env('MESSAGING_RATE_LIMIT_EMAIL', 120),
    ],

    'inbound_webhook_secret' => env('INFOBIP_INBOUND_WEBHOOK_SECRET', ''),

    'opt_out_keywords_in'  => ['SAIR', 'STOP', 'CANCELAR', 'PARAR', 'REMOVER'],
    'opt_out_keywords_out' => ['ENTRAR', 'START'],
];
```

- [ ] **Step 2: Append to `.env.example`**

```bash
# Messaging APIs
MESSAGING_IDEMPOTENCY_TTL_HOURS=24
MESSAGING_TRANSACTIONAL_EMAIL_CREDITS=0
MESSAGING_REFUND_ON_UNDELIVERABLE=false
MESSAGING_AUDIO_URL_ALLOWLIST=s3.amazonaws.com,storage.googleapis.com
MESSAGING_QUIET_HOURS_DEFAULT_TZ=America/Sao_Paulo
MESSAGING_QUIET_HOURS_DEFAULT_START=22:00
MESSAGING_QUIET_HOURS_DEFAULT_END=08:00
MESSAGING_RATE_LIMIT_SMS=60
MESSAGING_RATE_LIMIT_VOICE=10
MESSAGING_RATE_LIMIT_EMAIL=120
INFOBIP_INBOUND_WEBHOOK_SECRET=
```

- [ ] **Step 3: Commit**

```powershell
git add backend/config/messaging.php backend/.env.example
git commit -m "feat(messaging): add config/messaging.php and env defaults

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.2: Migration — message_dispatches

**Files:**
- Create: `backend/database/migrations/2026_05_26_000001_create_message_dispatches_table.php`

- [ ] **Step 1: Create migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_dispatches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->enum('channel', ['sms', 'voice', 'email']);
            $t->enum('source', ['api', 'transactional', 'internal'])->default('api');
            $t->string('to', 255);
            $t->string('from', 255)->nullable();
            $t->string('subject', 255)->nullable();
            $t->text('content');
            $t->string('audio_url', 500)->nullable();
            $t->string('provider', 50);
            $t->string('external_message_id', 120)->nullable();
            $t->enum('status', [
                'queued', 'sending', 'sent', 'delivered',
                'failed', 'rejected_opt_out', 'rejected_quiet_hours',
            ])->default('queued');
            $t->unsignedInteger('credits_unit')->default(0);
            $t->unsignedInteger('credits_charged')->default(0);
            $t->string('idempotency_key', 64)->nullable();
            $t->char('idempotency_payload_hash', 64)->nullable();
            $t->string('unsubscribe_token', 64)->nullable();
            $t->timestamp('unsubscribe_consumed_at')->nullable();
            $t->string('error_code', 64)->nullable();
            $t->string('error_message', 500)->nullable();
            $t->timestamp('scheduled_for')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('failed_at')->nullable();
            $t->json('meta')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'idempotency_key'], 'msg_disp_tenant_idem_unique');
            $t->unique('unsubscribe_token', 'msg_disp_unsub_unique');
            $t->index(['tenant_id', 'channel', 'created_at'], 'msg_disp_tenant_channel_idx');
            $t->index('external_message_id', 'msg_disp_external_idx');
            $t->index(['status', 'scheduled_for'], 'msg_disp_status_sched_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_dispatches');
    }
};
```

- [ ] **Step 2: Run migration**

```powershell
php artisan migrate
```

Expected: `Migrating: 2026_05_26_000001_create_message_dispatches_table` then `Migrated`.

- [ ] **Step 3: Commit**

```powershell
git add backend/database/migrations/2026_05_26_000001_create_message_dispatches_table.php
git commit -m "feat(messaging): create message_dispatches table

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.3: Migration — message_opt_outs

**Files:**
- Create: `backend/database/migrations/2026_05_26_000002_create_message_opt_outs_table.php`

- [ ] **Step 1: Create migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('message_opt_outs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->enum('channel', ['sms', 'voice', 'email', 'all']);
            $t->string('identifier', 255);
            $t->char('identifier_hash', 64);
            $t->string('reason', 50);
            $t->foreignId('source_dispatch_id')->nullable()->constrained('message_dispatches')->nullOnDelete();
            $t->timestamps();

            $t->unique(['tenant_id', 'channel', 'identifier_hash'], 'msg_opt_tenant_chan_id_unique');
            $t->index(['tenant_id', 'identifier_hash'], 'msg_opt_tenant_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_opt_outs');
    }
};
```

- [ ] **Step 2: Run migration**

```powershell
php artisan migrate
```

- [ ] **Step 3: Commit**

```powershell
git add backend/database/migrations/2026_05_26_000002_create_message_opt_outs_table.php
git commit -m "feat(messaging): create message_opt_outs table

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.4: Migration — add messaging fields to plans

**Files:**
- Create: `backend/database/migrations/2026_05_26_000003_add_messaging_settings_to_plans.php`
- Modify: `backend/app/Models/Plan.php`

- [ ] **Step 1: Create migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('rate_limit_sms_per_min')->nullable()->after('overage_rate_ai');
            $t->unsignedInteger('rate_limit_voice_per_min')->nullable()->after('rate_limit_sms_per_min');
            $t->unsignedInteger('rate_limit_email_per_min')->nullable()->after('rate_limit_voice_per_min');
            $t->unsignedInteger('credits_per_sms_override')->nullable()->after('rate_limit_email_per_min');
            $t->unsignedInteger('credits_per_voice_override')->nullable()->after('credits_per_sms_override');
            $t->unsignedInteger('credits_per_email_override')->nullable()->after('credits_per_voice_override');
            $t->boolean('quiet_hours_enabled')->default(true)->after('credits_per_email_override');
            $t->time('quiet_hours_start')->default('22:00:00')->after('quiet_hours_enabled');
            $t->time('quiet_hours_end')->default('08:00:00')->after('quiet_hours_start');
            $t->string('quiet_hours_timezone', 50)->default('America/Sao_Paulo')->after('quiet_hours_end');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn([
                'rate_limit_sms_per_min','rate_limit_voice_per_min','rate_limit_email_per_min',
                'credits_per_sms_override','credits_per_voice_override','credits_per_email_override',
                'quiet_hours_enabled','quiet_hours_start','quiet_hours_end','quiet_hours_timezone',
            ]);
        });
    }
};
```

- [ ] **Step 2: Update Plan model fillable + casts**

Append to `backend/app/Models/Plan.php` `$fillable`:
```php
'rate_limit_sms_per_min', 'rate_limit_voice_per_min', 'rate_limit_email_per_min',
'credits_per_sms_override', 'credits_per_voice_override', 'credits_per_email_override',
'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'quiet_hours_timezone',
```

Append to `$casts`:
```php
'rate_limit_sms_per_min'      => 'integer',
'rate_limit_voice_per_min'    => 'integer',
'rate_limit_email_per_min'    => 'integer',
'credits_per_sms_override'    => 'integer',
'credits_per_voice_override'  => 'integer',
'credits_per_email_override'  => 'integer',
'quiet_hours_enabled'         => 'boolean',
```

- [ ] **Step 3: Run migration**

```powershell
php artisan migrate
```

- [ ] **Step 4: Commit**

```powershell
git add backend/database/migrations/2026_05_26_000003_add_messaging_settings_to_plans.php backend/app/Models/Plan.php
git commit -m "feat(messaging): add per-plan rate-limit, credits override, and quiet hours

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.5: Models — MessageDispatch and MessageOptOut

**Files:**
- Create: `backend/app/Models/MessageDispatch.php`
- Create: `backend/app/Models/MessageOptOut.php`

- [ ] **Step 1: Create MessageDispatch model**

```php
<?php
namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageDispatch extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'channel', 'source', 'to', 'from', 'subject',
        'content', 'audio_url', 'provider', 'external_message_id', 'status',
        'credits_unit', 'credits_charged', 'idempotency_key', 'idempotency_payload_hash',
        'unsubscribe_token', 'unsubscribe_consumed_at', 'error_code', 'error_message',
        'scheduled_for', 'sent_at', 'delivered_at', 'failed_at', 'meta',
    ];

    protected $casts = [
        'meta'                    => 'array',
        'scheduled_for'           => 'datetime',
        'sent_at'                 => 'datetime',
        'delivered_at'            => 'datetime',
        'failed_at'               => 'datetime',
        'unsubscribe_consumed_at' => 'datetime',
        'credits_unit'            => 'integer',
        'credits_charged'         => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 2: Verify `BelongsToTenant` trait exists**

```powershell
ls backend\app\Models\Traits\
```

If `BelongsToTenant.php` missing, drop the trait usage and rely on global scope from `TenantContext`. Check `backend/app/Models/Campaign.php` for the convention used in the project.

- [ ] **Step 3: Create MessageOptOut model**

```php
<?php
namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageOptOut extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'channel', 'identifier', 'identifier_hash', 'reason', 'source_dispatch_id',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function sourceDispatch(): BelongsTo
    {
        return $this->belongsTo(MessageDispatch::class, 'source_dispatch_id');
    }

    public static function hashFor(string $identifier): string
    {
        return hash('sha256', mb_strtolower(trim($identifier)));
    }
}
```

- [ ] **Step 4: Commit**

```powershell
git add backend/app/Models/MessageDispatch.php backend/app/Models/MessageOptOut.php
git commit -m "feat(messaging): MessageDispatch and MessageOptOut models

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.6: PhoneNormalizer helper (TDD)

**Files:**
- Create: `backend/app/Services/Messaging/PhoneNormalizer.php`
- Test: `backend/tests/Unit/Messaging/PhoneNormalizerTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Unit\Messaging;

use App\Services\Messaging\PhoneNormalizer;
use Tests\TestCase;

class PhoneNormalizerTest extends TestCase
{
    public function test_strips_non_digits_and_adds_country_code(): void
    {
        $this->assertEquals('+5521999998888', PhoneNormalizer::e164('(21) 99999-8888', '55'));
    }

    public function test_keeps_already_normalized(): void
    {
        $this->assertEquals('+5521999998888', PhoneNormalizer::e164('+5521999998888'));
    }

    public function test_rejects_invalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhoneNormalizer::e164('abc', '55');
    }

    public function test_email_passthrough(): void
    {
        $this->assertEquals('joao@example.com', PhoneNormalizer::emailLower('  JOAO@Example.COM '));
    }
}
```

- [ ] **Step 2: Run test, verify fails**

```powershell
php artisan test --filter=PhoneNormalizerTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
namespace App\Services\Messaging;

class PhoneNormalizer
{
    public static function e164(string $raw, string $defaultCountryCode = '55'): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('Empty phone');
        }

        // Already starts with +
        if (str_starts_with($raw, '+')) {
            $digits = preg_replace('/\D/', '', $raw);
            $candidate = '+' . $digits;
        } else {
            $digits = preg_replace('/\D/', '', $raw);
            if ($digits === '') {
                throw new \InvalidArgumentException("Invalid phone: {$raw}");
            }
            $candidate = '+' . $defaultCountryCode . $digits;
        }

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $candidate)) {
            throw new \InvalidArgumentException("Invalid E.164: {$raw}");
        }

        return $candidate;
    }

    public static function emailLower(string $raw): string
    {
        $clean = mb_strtolower(trim($raw));
        if (! filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email: {$raw}");
        }
        return $clean;
    }
}
```

- [ ] **Step 4: Re-run test**

```powershell
php artisan test --filter=PhoneNormalizerTest
```

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Services/Messaging/PhoneNormalizer.php backend/tests/Unit/Messaging/PhoneNormalizerTest.php
git commit -m "feat(messaging): PhoneNormalizer for E.164 and email canonicalization

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.7: OptOutService (TDD)

**Files:**
- Create: `backend/app/Services/Messaging/OptOutService.php`
- Test: `backend/tests/Unit/Messaging/OptOutServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Messaging;

use App\Models\MessageOptOut;
use App\Models\Tenant;
use App\Services\Messaging\OptOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OptOutServiceTest extends TestCase
{
    use RefreshDatabase;

    private OptOutService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OptOutService::class);
        $this->tenant  = Tenant::factory()->create();
    }

    public function test_add_and_detect_opt_out(): void
    {
        $this->service->add($this->tenant->id, 'sms', '+5521999998888', 'user_request');
        $this->assertTrue($this->service->isOptedOut($this->tenant->id, 'sms', '+5521999998888'));
    }

    public function test_channel_all_blocks_all_channels(): void
    {
        $this->service->add($this->tenant->id, 'all', '+5521999998888', 'user_request');
        $this->assertTrue($this->service->isOptedOut($this->tenant->id, 'sms',   '+5521999998888'));
        $this->assertTrue($this->service->isOptedOut($this->tenant->id, 'voice', '+5521999998888'));
        $this->assertTrue($this->service->isOptedOut($this->tenant->id, 'email', '+5521999998888'));
    }

    public function test_normalization_prevents_bypass_by_case(): void
    {
        $this->service->add($this->tenant->id, 'email', 'JoAo@Example.COM', 'user_request');
        $this->assertTrue($this->service->isOptedOut($this->tenant->id, 'email', 'joao@example.com'));
    }

    public function test_idempotent_add(): void
    {
        $this->service->add($this->tenant->id, 'sms', '+5521999998888', 'sms_stop');
        $this->service->add($this->tenant->id, 'sms', '+5521999998888', 'sms_stop');
        $this->assertEquals(1, MessageOptOut::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_remove(): void
    {
        $this->service->add($this->tenant->id, 'sms', '+5521999998888', 'sms_stop');
        $this->service->remove($this->tenant->id, 'sms', '+5521999998888');
        $this->assertFalse($this->service->isOptedOut($this->tenant->id, 'sms', '+5521999998888'));
    }

    public function test_cross_tenant_isolation(): void
    {
        $other = Tenant::factory()->create();
        $this->service->add($other->id, 'sms', '+5521999998888', 'user_request');
        $this->assertFalse($this->service->isOptedOut($this->tenant->id, 'sms', '+5521999998888'));
    }
}
```

- [ ] **Step 2: Run, verify fails**

```powershell
php artisan test --filter=OptOutServiceTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
namespace App\Services\Messaging;

use App\Models\MessageOptOut;
use Illuminate\Support\Facades\DB;

class OptOutService
{
    public function add(int $tenantId, string $channel, string $identifier, string $reason, ?int $sourceDispatchId = null): MessageOptOut
    {
        $hash = MessageOptOut::hashFor($identifier);

        return MessageOptOut::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id'       => $tenantId,
                'channel'         => $channel,
                'identifier_hash' => $hash,
            ],
            [
                'identifier'         => mb_strtolower(trim($identifier)),
                'reason'             => $reason,
                'source_dispatch_id' => $sourceDispatchId,
            ]
        );
    }

    public function remove(int $tenantId, string $channel, string $identifier): void
    {
        $hash = MessageOptOut::hashFor($identifier);
        MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('channel', [$channel, 'all'])
            ->where('identifier_hash', $hash)
            ->delete();
    }

    public function isOptedOut(int $tenantId, string $channel, string $identifier): bool
    {
        $hash = MessageOptOut::hashFor($identifier);
        return MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('channel', [$channel, 'all'])
            ->where('identifier_hash', $hash)
            ->exists();
    }
}
```

- [ ] **Step 4: Re-run test**

```powershell
php artisan test --filter=OptOutServiceTest
```

Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Services/Messaging/OptOutService.php backend/tests/Unit/Messaging/OptOutServiceTest.php
git commit -m "feat(messaging): OptOutService with per-tenant, per-channel blocking

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.8: QuietHoursService (TDD)

**Files:**
- Create: `backend/app/Services/Messaging/QuietHoursService.php`
- Test: `backend/tests/Unit/Messaging/QuietHoursServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Messaging;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Messaging\QuietHoursService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuietHoursServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inside_quiet_window_returns_true(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-26 23:30:00', 'America/Sao_Paulo'));

        $tenant = Tenant::factory()->create();
        $service = app(QuietHoursService::class);

        $this->assertTrue($service->isQuietHour($tenant));
    }

    public function test_outside_quiet_window_returns_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-26 14:00:00', 'America/Sao_Paulo'));

        $tenant = Tenant::factory()->create();
        $service = app(QuietHoursService::class);

        $this->assertFalse($service->isQuietHour($tenant));
    }

    public function test_disabled_when_plan_sets_false(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-26 23:30:00', 'America/Sao_Paulo'));

        $plan   = Plan::factory()->create(['quiet_hours_enabled' => false]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $service = app(QuietHoursService::class);

        $this->assertFalse($service->isQuietHour($tenant));
    }

    public function test_next_valid_time_after_quiet_returns_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-26 23:30:00', 'America/Sao_Paulo'));

        $tenant = Tenant::factory()->create();
        $service = app(QuietHoursService::class);

        $next = $service->nextValidTime($tenant);
        $this->assertEquals('08:00', $next->format('H:i'));
        $this->assertEquals('2026-05-27', $next->format('Y-m-d'));
    }
}
```

- [ ] **Step 2: Run, verify fails**

```powershell
php artisan test --filter=QuietHoursServiceTest
```

- [ ] **Step 3: Implement**

```php
<?php
namespace App\Services\Messaging;

use App\Models\Tenant;
use Carbon\Carbon;

class QuietHoursService
{
    public function isQuietHour(Tenant $tenant): bool
    {
        $plan = $tenant->plan ?? null;

        if ($plan && ! $plan->quiet_hours_enabled) {
            return false;
        }

        [$start, $end, $tz] = $this->windowFor($tenant);

        $now = Carbon::now($tz);
        $startToday = $now->copy()->setTimeFromTimeString($start);
        $endToday   = $now->copy()->setTimeFromTimeString($end);

        // Cross-midnight window (22:00–08:00)
        if ($startToday->gt($endToday)) {
            return $now->gte($startToday) || $now->lt($endToday);
        }
        return $now->gte($startToday) && $now->lt($endToday);
    }

    public function nextValidTime(Tenant $tenant): Carbon
    {
        [$start, $end, $tz] = $this->windowFor($tenant);

        $now = Carbon::now($tz);
        $endToday = $now->copy()->setTimeFromTimeString($end);

        if ($now->lt($endToday) && $this->isQuietHour($tenant)) {
            return $endToday;
        }
        // Next day's end-of-quiet
        return $endToday->addDay();
    }

    private function windowFor(Tenant $tenant): array
    {
        $plan = $tenant->plan ?? null;
        $start = $plan->quiet_hours_start ?? config('messaging.quiet_hours.default_start');
        $end   = $plan->quiet_hours_end   ?? config('messaging.quiet_hours.default_end');
        $tz    = $plan->quiet_hours_timezone ?? config('messaging.quiet_hours.default_timezone');
        return [(string) $start, (string) $end, (string) $tz];
    }
}
```

- [ ] **Step 4: Re-run test**

```powershell
php artisan test --filter=QuietHoursServiceTest
```

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Services/Messaging/QuietHoursService.php backend/tests/Unit/Messaging/QuietHoursServiceTest.php
git commit -m "feat(messaging): QuietHoursService with per-plan window and timezone

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.9: PricingService (TDD)

**Files:**
- Create: `backend/app/Services/Messaging/PricingService.php`
- Test: `backend/tests/Unit/Messaging/PricingServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Unit\Messaging;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Messaging\PricingService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_global_default_when_plan_has_no_override(): void
    {
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_sms', '1', 'string');

        $tenant = Tenant::factory()->create();
        $this->assertEquals(1, app(PricingService::class)->unitFor($tenant, 'sms'));
    }

    public function test_plan_override_wins(): void
    {
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_sms', '1', 'string');
        $plan   = Plan::factory()->create(['credits_per_sms_override' => 3]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        $this->assertEquals(3, app(PricingService::class)->unitFor($tenant, 'sms'));
    }
}
```

- [ ] **Step 2: Implement**

```php
<?php
namespace App\Services\Messaging;

use App\Models\Tenant;
use App\Services\SettingsService;

class PricingService
{
    private array $defaults = ['sms' => 1, 'voice' => 5, 'email' => 2];

    public function __construct(private SettingsService $settings) {}

    public function unitFor(Tenant $tenant, string $channel): int
    {
        if (! in_array($channel, ['sms', 'voice', 'email'], true)) {
            throw new \InvalidArgumentException("Invalid channel: {$channel}");
        }

        $plan = $tenant->plan ?? null;
        $overrideField = "credits_per_{$channel}_override";
        if ($plan && $plan->{$overrideField} !== null) {
            return (int) $plan->{$overrideField};
        }

        $global = $this->settings->getGlobal('billing', "credits_per_{$channel}", (string) $this->defaults[$channel]);
        return (int) $global;
    }
}
```

- [ ] **Step 3: Run test**

```powershell
php artisan test --filter=PricingServiceTest
```

Expected: PASS.

- [ ] **Step 4: Commit**

```powershell
git add backend/app/Services/Messaging/PricingService.php backend/tests/Unit/Messaging/PricingServiceTest.php
git commit -m "feat(messaging): PricingService with per-plan override

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.10: IdempotencyService (TDD)

**Files:**
- Create: `backend/app/Services/Messaging/IdempotencyService.php`
- Test: `backend/tests/Unit/Messaging/IdempotencyServiceTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Unit\Messaging;

use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Services\Messaging\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_returns_null_when_no_key(): void
    {
        $tenant = Tenant::factory()->create();
        $result = app(IdempotencyService::class)->lookup($tenant->id, null, ['to' => 'x']);
        $this->assertNull($result);
    }

    public function test_lookup_hit_returns_existing_dispatch(): void
    {
        $tenant = Tenant::factory()->create();
        $payload = ['to' => '+5521999998888', 'content' => 'hi'];
        $hash = hash('sha256', json_encode($payload));

        $existing = MessageDispatch::create([
            'tenant_id' => $tenant->id,
            'channel' => 'sms', 'source' => 'api', 'to' => '+5521999998888',
            'content' => 'hi', 'provider' => 'infobip', 'status' => 'sent',
            'credits_unit' => 1, 'credits_charged' => 1,
            'idempotency_key' => 'abc-123',
            'idempotency_payload_hash' => $hash,
            'created_at' => now(),
        ]);

        $hit = app(IdempotencyService::class)->lookup($tenant->id, 'abc-123', $payload);
        $this->assertEquals($existing->id, $hit->id);
    }

    public function test_lookup_with_diverging_payload_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $payload = ['to' => '+5521999998888', 'content' => 'original'];

        MessageDispatch::create([
            'tenant_id' => $tenant->id,
            'channel' => 'sms', 'source' => 'api', 'to' => '+5521999998888',
            'content' => 'original', 'provider' => 'infobip', 'status' => 'sent',
            'credits_unit' => 1, 'credits_charged' => 1,
            'idempotency_key' => 'abc-123',
            'idempotency_payload_hash' => hash('sha256', json_encode($payload)),
            'created_at' => now(),
        ]);

        $this->expectException(\App\Exceptions\Messaging\IdempotencyKeyReuseException::class);
        app(IdempotencyService::class)->lookup($tenant->id, 'abc-123', ['to' => 'x', 'content' => 'different']);
    }

    public function test_expired_idempotency_returns_null(): void
    {
        $tenant = Tenant::factory()->create();
        MessageDispatch::create([
            'tenant_id' => $tenant->id,
            'channel' => 'sms', 'source' => 'api', 'to' => '+5521999998888',
            'content' => 'hi', 'provider' => 'infobip', 'status' => 'sent',
            'credits_unit' => 1, 'credits_charged' => 1,
            'idempotency_key' => 'old-key',
            'idempotency_payload_hash' => hash('sha256', '{}'),
            'created_at' => now()->subHours(25),
        ]);

        $result = app(IdempotencyService::class)->lookup($tenant->id, 'old-key', []);
        $this->assertNull($result);
    }

    public function test_hash_is_stable(): void
    {
        $service = app(IdempotencyService::class);
        $a = $service->hashPayload(['c' => 3, 'a' => 1, 'b' => 2]);
        $b = $service->hashPayload(['a' => 1, 'b' => 2, 'c' => 3]);
        $this->assertEquals($a, $b);
    }
}
```

- [ ] **Step 2: Implement exception**

`backend/app/Exceptions/Messaging/IdempotencyKeyReuseException.php`:
```php
<?php
namespace App\Exceptions\Messaging;

class IdempotencyKeyReuseException extends \RuntimeException {}
```

- [ ] **Step 3: Implement service**

```php
<?php
namespace App\Services\Messaging;

use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Models\MessageDispatch;

class IdempotencyService
{
    public function hashPayload(array $payload): string
    {
        $sorted = $this->ksortRecursive($payload);
        return hash('sha256', json_encode($sorted));
    }

    public function lookup(int $tenantId, ?string $key, array $payload): ?MessageDispatch
    {
        if (! $key) {
            return null;
        }

        $ttlHours = (int) config('messaging.idempotency_ttl_hours', 24);
        $hash = $this->hashPayload($payload);

        $existing = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->where('created_at', '>=', now()->subHours($ttlHours))
            ->first();

        if (! $existing) {
            return null;
        }

        if ($existing->idempotency_payload_hash !== $hash) {
            throw new IdempotencyKeyReuseException("Idempotency key reused with different payload");
        }

        return $existing;
    }

    private function ksortRecursive(array $arr): array
    {
        ksort($arr);
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $this->ksortRecursive($v);
            }
        }
        return $arr;
    }
}
```

- [ ] **Step 4: Run test**

```powershell
php artisan test --filter=IdempotencyServiceTest
```

Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Services/Messaging/IdempotencyService.php backend/app/Exceptions/Messaging/IdempotencyKeyReuseException.php backend/tests/Unit/Messaging/IdempotencyServiceTest.php
git commit -m "feat(messaging): IdempotencyService with strict payload hash matching

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.11: Other exceptions

**Files:**
- Create: `backend/app/Exceptions/Messaging/InsufficientCreditsException.php`
- Create: `backend/app/Exceptions/Messaging/RecipientOptedOutException.php`
- Create: `backend/app/Exceptions/Messaging/QuietHoursException.php`

- [ ] **Step 1: Create all three**

```php
<?php
// InsufficientCreditsException.php
namespace App\Exceptions\Messaging;

class InsufficientCreditsException extends \RuntimeException {
    public function __construct(public readonly int $required, public readonly int $balance) {
        parent::__construct("Insufficient credits: required {$required}, balance {$balance}");
    }
}
```

```php
<?php
// RecipientOptedOutException.php
namespace App\Exceptions\Messaging;

class RecipientOptedOutException extends \RuntimeException {}
```

```php
<?php
// QuietHoursException.php
namespace App\Exceptions\Messaging;

class QuietHoursException extends \RuntimeException {}
```

- [ ] **Step 2: Commit**

```powershell
git add backend/app/Exceptions/Messaging/
git commit -m "feat(messaging): domain exceptions for messaging flow

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.12: MessagingService — orchestration (TDD)

**Files:**
- Create: `backend/app/Services/Messaging/MessagingService.php`
- Test: `backend/tests/Unit/Messaging/MessagingServiceTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Messaging;

use App\Exceptions\Messaging\InsufficientCreditsException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OptOutService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_sms', '1', 'string');
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_voice', '5', 'string');
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_email', '2', 'string');
    }

    public function test_dispatch_creates_queued_record_and_reserves_credits(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $dispatch = app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521999998888', 'content' => 'hi'],
            idempotencyKey: null,
            quietHoursStrategy: 'reject'
        );

        $this->assertEquals('queued', $dispatch->status);
        $this->assertEquals(1, $dispatch->credits_unit);
        $this->assertEquals(0, $dispatch->credits_charged);
        $this->assertEquals(9, $tenant->fresh()->credits_balance);
    }

    public function test_dispatch_rejects_opt_out_without_debit(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        app(OptOutService::class)->add($tenant->id, 'sms', '+5521999998888', 'user_request');

        try {
            app(MessagingService::class)->dispatch(
                $tenant, $user, 'sms',
                ['to' => '+5521999998888', 'content' => 'hi'],
                null, 'reject'
            );
            $this->fail('Expected RecipientOptedOutException');
        } catch (RecipientOptedOutException $e) {}

        $this->assertEquals(10, $tenant->fresh()->credits_balance);
        $this->assertDatabaseHas('message_dispatches', [
            'tenant_id' => $tenant->id,
            'status'    => 'rejected_opt_out',
            'credits_charged' => 0,
        ]);
    }

    public function test_dispatch_throws_when_insufficient(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 0]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->expectException(InsufficientCreditsException::class);
        app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521999998888', 'content' => 'hi'],
            null, 'reject'
        );
    }

    public function test_idempotency_replay_returns_same_dispatch_no_debit(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);

        $first = app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521999998888', 'content' => 'hi'],
            'idem-1', 'reject'
        );

        $second = app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521999998888', 'content' => 'hi'],
            'idem-1', 'reject'
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(9, $tenant->fresh()->credits_balance);
    }
}
```

- [ ] **Step 2: Run, verify fails**

- [ ] **Step 3: Implement MessagingService**

```php
<?php
namespace App\Services\Messaging;

use App\Exceptions\Messaging\InsufficientCreditsException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MessagingService
{
    public function __construct(
        private OptOutService     $optOuts,
        private QuietHoursService $quiet,
        private PricingService    $pricing,
        private IdempotencyService $idempotency,
        private CreditService     $credits,
    ) {}

    public function dispatch(
        Tenant $tenant,
        ?User $user,
        string $channel,
        array $payload,
        ?string $idempotencyKey,
        string $quietHoursStrategy = 'reject'
    ): MessageDispatch {
        // Normalize recipient
        $to = $channel === 'email'
            ? PhoneNormalizer::emailLower($payload['to'])
            : PhoneNormalizer::e164($payload['to']);
        $payload['to'] = $to;

        // Idempotency check
        if ($hit = $this->idempotency->lookup($tenant->id, $idempotencyKey, $payload)) {
            return $hit;
        }

        // Opt-out check
        if ($this->optOuts->isOptedOut($tenant->id, $channel, $to)) {
            $rejected = MessageDispatch::create([
                'tenant_id' => $tenant->id,
                'user_id'   => $user?->id,
                'channel'   => $channel,
                'source'    => 'api',
                'to'        => $to,
                'content'   => $payload['content'] ?? '',
                'subject'   => $payload['subject'] ?? null,
                'from'      => $payload['from'] ?? null,
                'provider'  => $channel === 'email' && ($payload['source'] ?? null) === 'transactional' ? 'laravel_mail' : 'infobip',
                'status'    => 'rejected_opt_out',
                'credits_unit' => 0,
                'credits_charged' => 0,
                'idempotency_key' => $idempotencyKey,
                'idempotency_payload_hash' => $idempotencyKey ? $this->idempotency->hashPayload($payload) : null,
            ]);
            throw new RecipientOptedOutException();
        }

        // Quiet hours
        $scheduledFor = null;
        if ($this->quiet->isQuietHour($tenant)) {
            if ($quietHoursStrategy === 'reject') {
                MessageDispatch::create([
                    'tenant_id' => $tenant->id,
                    'user_id'   => $user?->id,
                    'channel'   => $channel,
                    'source'    => 'api',
                    'to'        => $to,
                    'content'   => $payload['content'] ?? '',
                    'subject'   => $payload['subject'] ?? null,
                    'from'      => $payload['from'] ?? null,
                    'provider'  => 'infobip',
                    'status'    => 'rejected_quiet_hours',
                    'credits_unit' => 0,
                    'credits_charged' => 0,
                ]);
                throw new QuietHoursException();
            }
            // defer
            $scheduledFor = $this->quiet->nextValidTime($tenant);
        }

        $unit = $this->pricing->unitFor($tenant, $channel);
        $provider = ($channel === 'email' && ($payload['source'] ?? null) === 'transactional') ? 'laravel_mail' : 'infobip';

        return DB::transaction(function () use ($tenant, $user, $channel, $payload, $idempotencyKey, $unit, $provider, $scheduledFor) {
            $dispatch = MessageDispatch::create([
                'tenant_id' => $tenant->id,
                'user_id'   => $user?->id,
                'channel'   => $channel,
                'source'    => $payload['source'] ?? 'api',
                'to'        => $payload['to'],
                'from'      => $payload['from'] ?? null,
                'subject'   => $payload['subject'] ?? null,
                'content'   => $payload['content'] ?? '',
                'audio_url' => $payload['audio_url'] ?? null,
                'provider'  => $provider,
                'status'    => 'queued',
                'credits_unit' => $unit,
                'credits_charged' => 0,
                'idempotency_key' => $idempotencyKey,
                'idempotency_payload_hash' => $idempotencyKey ? $this->idempotency->hashPayload($payload) : null,
                'unsubscribe_token' => $channel === 'email' ? Str::random(64) : null,
                'scheduled_for' => $scheduledFor,
                'meta' => $payload['meta'] ?? null,
            ]);

            $ok = $this->credits->reserve($tenant->id, $unit, 'message_dispatch', $dispatch->id);
            if (! $ok) {
                throw new InsufficientCreditsException($unit, (int) $tenant->fresh()->credits_balance);
            }

            SendMessageJob::dispatch($dispatch->id)->onQueue('messaging');

            return $dispatch;
        });
    }
}
```

- [ ] **Step 4: Re-run test**

```powershell
php artisan test --filter=MessagingServiceTest
```

Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Services/Messaging/MessagingService.php backend/tests/Unit/Messaging/MessagingServiceTest.php
git commit -m "feat(messaging): MessagingService orchestrator with idempotency, opt-out, quiet hours, billing

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.13: SendMessageJob (TDD)

**Files:**
- Create: `backend/app/Jobs/SendMessageJob.php`
- Test: `backend/tests/Unit/Jobs/SendMessageJobTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Jobs;

use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Services\Infobip\InfobipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_success_marks_sent_and_charges(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $dispatch = MessageDispatch::create([
            'tenant_id' => $tenant->id, 'channel' => 'sms', 'source' => 'api',
            'to' => '+5521999998888', 'content' => 'hi', 'provider' => 'infobip',
            'status' => 'queued', 'credits_unit' => 1, 'credits_charged' => 0,
        ]);

        app(\App\Services\SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(\App\Services\SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');

        Http::fake([
            'api.infobip.com/sms/2/text/advanced' => Http::response([
                'messages' => [['messageId' => 'mid-1']],
            ], 200),
        ]);

        (new SendMessageJob($dispatch->id))->handle(app(InfobipService::class));

        $dispatch->refresh();
        $this->assertEquals('sent', $dispatch->status);
        $this->assertEquals('mid-1', $dispatch->external_message_id);
        $this->assertEquals(1, $dispatch->credits_charged);
    }

    public function test_sms_definitive_failure_releases_credits(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 9]); // 1 already reserved
        $dispatch = MessageDispatch::create([
            'tenant_id' => $tenant->id, 'channel' => 'sms', 'source' => 'api',
            'to' => '+5521999998888', 'content' => 'hi', 'provider' => 'infobip',
            'status' => 'queued', 'credits_unit' => 1, 'credits_charged' => 0,
        ]);

        app(\App\Services\SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(\App\Services\SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');

        Http::fake([
            'api.infobip.com/sms/2/text/advanced' => Http::response([
                'requestError' => ['serviceException' => ['text' => 'invalid number']],
            ], 400),
        ]);

        (new SendMessageJob($dispatch->id))->handle(app(InfobipService::class));

        $dispatch->refresh();
        $this->assertEquals('failed', $dispatch->status);
        $this->assertEquals(0, $dispatch->credits_charged);
        $this->assertEquals(10, $tenant->fresh()->credits_balance);
    }

    public function test_already_processed_dispatch_is_skipped(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $dispatch = MessageDispatch::create([
            'tenant_id' => $tenant->id, 'channel' => 'sms', 'source' => 'api',
            'to' => '+5521999998888', 'content' => 'hi', 'provider' => 'infobip',
            'status' => 'sent', 'credits_unit' => 1, 'credits_charged' => 1,
        ]);

        Http::fake();
        (new SendMessageJob($dispatch->id))->handle(app(InfobipService::class));
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Implement job**

```php
<?php
namespace App\Jobs;

use App\Mail\TransactionalMailer;
use App\Models\MessageDispatch;
use App\Services\Billing\CreditService;
use App\Services\Infobip\InfobipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 600];

    public function __construct(public int $dispatchId)
    {
        $this->onQueue('messaging');
    }

    public function handle(InfobipService $infobip): void
    {
        $dispatch = DB::transaction(function () {
            $d = MessageDispatch::withoutGlobalScopes()->lockForUpdate()->find($this->dispatchId);
            if (! $d || $d->status !== 'queued') {
                return null;
            }
            $d->update(['status' => 'sending']);
            return $d;
        });

        if (! $dispatch) {
            return;
        }

        try {
            $result = match ($dispatch->channel) {
                'sms'   => $infobip->sendSms($dispatch->to, $dispatch->content, $dispatch->from ?? 'InfoSMS'),
                'voice' => $infobip->sendVoice($dispatch->to, $dispatch->content, $dispatch->from ?? 'InfoVoice', $dispatch->audio_url),
                'email' => $dispatch->provider === 'laravel_mail'
                    ? app(TransactionalMailer::class)->send($dispatch)
                    : $infobip->sendEmail($dispatch->to, $dispatch->subject ?? '(sem assunto)', $dispatch->content, $dispatch->from ?? '', ''),
            };
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('send_message.exception', [
                'dispatch_id' => $dispatch->id, 'error' => $e->getMessage(),
            ]);
            // Allow retry up to $tries; release after final attempt
            if ($this->attempts() >= $this->tries) {
                $this->releaseCredits($dispatch, $e->getMessage(), 'EXCEPTION');
            }
            throw $e;
        }

        if ($result['ok']) {
            $dispatch->update([
                'status' => 'sent',
                'external_message_id' => $result['message_id'],
                'credits_charged' => $dispatch->credits_unit,
                'sent_at' => now(),
            ]);
        } else {
            $this->releaseCredits($dispatch, $result['error'] ?? 'unknown', 'PROVIDER_ERROR');
        }
    }

    private function releaseCredits(MessageDispatch $dispatch, string $errorMessage, string $errorCode): void
    {
        app(CreditService::class)->release(
            $dispatch->tenant_id,
            $dispatch->credits_unit,
            'message_dispatch',
            $dispatch->id
        );
        $dispatch->update([
            'status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => mb_substr($errorMessage, 0, 500),
            'failed_at' => now(),
            'credits_charged' => 0,
        ]);
    }
}
```

- [ ] **Step 3: Run test**

```powershell
php artisan test --filter=SendMessageJobTest
```

Expected: PASS (3 tests).

- [ ] **Step 4: Commit**

```powershell
git add backend/app/Jobs/SendMessageJob.php backend/tests/Unit/Jobs/SendMessageJobTest.php
git commit -m "feat(messaging): SendMessageJob with retry, credit confirm/release

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.14: TransactionalMailer

**Files:**
- Create: `backend/app/Mail/TransactionalMailer.php`

- [ ] **Step 1: Implement**

```php
<?php
namespace App\Mail;

use App\Models\MessageDispatch;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class TransactionalMailer
{
    /**
     * Sends a Mailable through Laravel Mail and links it to a MessageDispatch.
     * Returns array compatible with InfobipService return format.
     */
    public function send(MessageDispatch $dispatch, ?Mailable $mailable = null): array
    {
        try {
            if ($mailable) {
                Mail::to($dispatch->to)->send($mailable);
            } else {
                // Bare content fallback
                Mail::raw($dispatch->content, function ($m) use ($dispatch) {
                    $m->to($dispatch->to)->subject($dispatch->subject ?? '(sem assunto)');
                });
            }
            return ['ok' => true, 'message_id' => 'mail-'.$dispatch->id, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message_id' => null, 'error' => $e->getMessage()];
        }
    }
}
```

- [ ] **Step 2: Commit**

```powershell
git add backend/app/Mail/TransactionalMailer.php
git commit -m "feat(messaging): TransactionalMailer wrapper for Laravel Mail

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.15: EnsureTokenAbility middleware (TDD)

**Files:**
- Create: `backend/app/Http/Middleware/EnsureTokenAbility.php`
- Modify: `backend/bootstrap/app.php` (register alias)
- Test: `backend/tests/Unit/Middleware/EnsureTokenAbilityMiddlewareTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureTokenAbility;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnsureTokenAbilityMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_passes_when_token_has_ability(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['messaging:sms']);

        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn() => auth()->user());

        $result = (new EnsureTokenAbility)->handle($request, fn() => response('ok'), 'messaging:sms');
        $this->assertEquals('ok', $result->getContent());
    }

    public function test_blocks_when_token_lacks_ability(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['messaging:email']);

        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn() => auth()->user());

        $result = (new EnsureTokenAbility)->handle($request, fn() => response('ok'), 'messaging:sms');
        $this->assertEquals(403, $result->getStatusCode());
    }
}
```

- [ ] **Step 2: Implement middleware**

```php
<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTokenAbility
{
    public function handle(Request $request, Closure $next, string $ability)
    {
        $user = $request->user();
        if (! $user || ! $user->tokenCan($ability)) {
            return response()->json([
                'error'    => 'INSUFFICIENT_TOKEN_ABILITY',
                'required' => $ability,
            ], 403);
        }
        return $next($request);
    }
}
```

- [ ] **Step 3: Register alias in `bootstrap/app.php`**

Inside `withMiddleware(function (Middleware $middleware) { ... })`, add:

```php
$middleware->alias([
    'token.ability' => \App\Http\Middleware\EnsureTokenAbility::class,
    // ...existing aliases preserved
]);
```

- [ ] **Step 4: Run test**

```powershell
php artisan test --filter=EnsureTokenAbilityMiddlewareTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Http/Middleware/EnsureTokenAbility.php backend/bootstrap/app.php backend/tests/Unit/Middleware/EnsureTokenAbilityMiddlewareTest.php
git commit -m "feat(messaging): EnsureTokenAbility middleware + alias

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.16: Rate limiters

**Files:**
- Modify: `backend/app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Append to `configureRateLimiting()`**

```php
foreach (['sms', 'voice', 'email'] as $ch) {
    RateLimiter::for("messaging-{$ch}", function (Request $request) use ($ch) {
        $tenantId = $request->user()?->tenant_id;
        if (! $tenantId) {
            return Limit::perMinute(5)->by($request->ip());
        }
        $plan = $request->user()->tenant->plan ?? null;
        $field = "rate_limit_{$ch}_per_min";
        $limit = $plan?->{$field} ?? (int) config("messaging.rate_limits.{$ch}");
        return Limit::perMinute($limit)
            ->by("tenant:{$tenantId}:msg:{$ch}")
            ->response(fn() => response()->json([
                'error'   => 'RATE_LIMIT_EXCEEDED',
                'channel' => $ch,
            ], 429));
    });
}
```

- [ ] **Step 2: Commit**

```powershell
git add backend/app/Providers/AppServiceProvider.php
git commit -m "feat(messaging): per-tenant rate limiters for sms, voice, email

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.17: FormRequests

**Files:**
- Create: `backend/app/Http/Requests/Messaging/SendSmsRequest.php`
- Create: `backend/app/Http/Requests/Messaging/SendVoiceRequest.php`
- Create: `backend/app/Http/Requests/Messaging/SendEmailRequest.php`

- [ ] **Step 1: SendSmsRequest**

```php
<?php
namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class SendSmsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'to'            => ['required', 'string', 'max:20'],
            'content'       => ['required', 'string', 'max:1600'],
            'from'          => ['nullable', 'string', 'max:11', 'regex:/^[A-Za-z0-9]+$/'],
            'scheduled_for' => ['nullable', 'date', 'after:now', 'before:+30 days'],
            'meta'          => ['nullable', 'array'],
        ];
    }
}
```

- [ ] **Step 2: SendVoiceRequest**

```php
<?php
namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class SendVoiceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'to'        => ['required', 'string', 'max:20'],
            'content'   => ['required_without:audio_url', 'nullable', 'string', 'max:600'],
            'audio_url' => ['nullable', 'url', new \App\Rules\AudioUrlAllowlist],
            'from'      => ['nullable', 'string', 'max:11'],
            'meta'      => ['nullable', 'array'],
        ];
    }
}
```

- [ ] **Step 3: AudioUrlAllowlist rule**

`backend/app/Rules/AudioUrlAllowlist.php`:
```php
<?php
namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AudioUrlAllowlist implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value) return;
        $parts = parse_url($value);
        if (! isset($parts['scheme']) || $parts['scheme'] !== 'https') {
            $fail('audio_url must be https');
            return;
        }
        $allow = config('messaging.audio_url_allowlist', []);
        $host  = $parts['host'] ?? '';
        $ok = false;
        foreach ($allow as $a) {
            if ($host === $a || str_ends_with($host, '.' . $a)) {
                $ok = true; break;
            }
        }
        if (! $ok) {
            $fail("audio_url host {$host} not in allowlist");
        }
    }
}
```

- [ ] **Step 4: SendEmailRequest**

```php
<?php
namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class SendEmailRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'to'      => ['required', 'email:rfc'],
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:65000'],
            'from'    => ['nullable', 'email:rfc'],
            'meta'    => ['nullable', 'array'],
        ];
    }

    protected function passedValidation(): void
    {
        // Strip header-injection chars from subject/from
        $this->merge([
            'subject' => str_replace(["\r", "\n"], '', $this->input('subject', '')),
            'from'    => str_replace(["\r", "\n"], '', (string) $this->input('from', '')) ?: null,
        ]);
    }
}
```

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Http/Requests/Messaging/ backend/app/Rules/AudioUrlAllowlist.php
git commit -m "feat(messaging): FormRequests for sms/voice/email with security validations

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.18: Controllers — SmsController, VoiceController, EmailController

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Messaging/SmsController.php`
- Create: `backend/app/Http/Controllers/API/V1/Messaging/VoiceController.php`
- Create: `backend/app/Http/Controllers/API/V1/Messaging/EmailController.php`

- [ ] **Step 1: SmsController**

```php
<?php
namespace App\Http\Controllers\API\V1\Messaging;

use App\Exceptions\Messaging\InsufficientCreditsException;
use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendSmsRequest;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;

class SmsController extends Controller
{
    public function __construct(private MessagingService $messaging) {}

    public function store(SendSmsRequest $request): JsonResponse
    {
        $user   = $request->user();
        $tenant = $user->tenant;

        try {
            $dispatch = $this->messaging->dispatch(
                $tenant, $user, 'sms',
                $request->validated(),
                $request->header('Idempotency-Key'),
                $request->header('X-Quiet-Hours-Strategy', 'reject')
            );
        } catch (RecipientOptedOutException) {
            return response()->json(['error' => 'RECIPIENT_OPTED_OUT'], 422);
        } catch (QuietHoursException) {
            return response()->json(['error' => 'QUIET_HOURS'], 422);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'error' => 'INSUFFICIENT_CREDITS',
                'required' => $e->required,
                'balance'  => $e->balance,
            ], 402);
        } catch (IdempotencyKeyReuseException) {
            return response()->json(['error' => 'IDEMPOTENCY_KEY_REUSE'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'INVALID_INPUT', 'detail' => $e->getMessage()], 422);
        }

        return response()->json([
            'dispatch_id'      => $dispatch->id,
            'status'           => $dispatch->status,
            'credits_reserved' => $dispatch->credits_unit,
            '_links'           => ['status' => url("/api/v1/messaging/dispatches/{$dispatch->id}")],
        ], 202);
    }
}
```

- [ ] **Step 2: VoiceController (identical structure with channel='voice' and SendVoiceRequest)**

```php
<?php
namespace App\Http\Controllers\API\V1\Messaging;

use App\Exceptions\Messaging\InsufficientCreditsException;
use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendVoiceRequest;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;

class VoiceController extends Controller
{
    public function __construct(private MessagingService $messaging) {}

    public function store(SendVoiceRequest $request): JsonResponse
    {
        try {
            $dispatch = $this->messaging->dispatch(
                $request->user()->tenant, $request->user(), 'voice',
                $request->validated(),
                $request->header('Idempotency-Key'),
                $request->header('X-Quiet-Hours-Strategy', 'reject')
            );
        } catch (RecipientOptedOutException) {
            return response()->json(['error' => 'RECIPIENT_OPTED_OUT'], 422);
        } catch (QuietHoursException) {
            return response()->json(['error' => 'QUIET_HOURS'], 422);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'error' => 'INSUFFICIENT_CREDITS',
                'required' => $e->required, 'balance' => $e->balance,
            ], 402);
        } catch (IdempotencyKeyReuseException) {
            return response()->json(['error' => 'IDEMPOTENCY_KEY_REUSE'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'INVALID_INPUT', 'detail' => $e->getMessage()], 422);
        }

        return response()->json([
            'dispatch_id' => $dispatch->id, 'status' => $dispatch->status,
            'credits_reserved' => $dispatch->credits_unit,
            '_links' => ['status' => url("/api/v1/messaging/dispatches/{$dispatch->id}")],
        ], 202);
    }
}
```

- [ ] **Step 3: EmailController (purifies HTML before passing through)**

```php
<?php
namespace App\Http\Controllers\API\V1\Messaging;

use App\Exceptions\Messaging\InsufficientCreditsException;
use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\SendEmailRequest;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\JsonResponse;

class EmailController extends Controller
{
    public function __construct(private MessagingService $messaging) {}

    public function store(SendEmailRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['content'] = $this->sanitizeHtml($payload['content']);

        try {
            $dispatch = $this->messaging->dispatch(
                $request->user()->tenant, $request->user(), 'email',
                $payload,
                $request->header('Idempotency-Key'),
                $request->header('X-Quiet-Hours-Strategy', 'reject')
            );
        } catch (RecipientOptedOutException) {
            return response()->json(['error' => 'RECIPIENT_OPTED_OUT'], 422);
        } catch (QuietHoursException) {
            return response()->json(['error' => 'QUIET_HOURS'], 422);
        } catch (InsufficientCreditsException $e) {
            return response()->json([
                'error' => 'INSUFFICIENT_CREDITS',
                'required' => $e->required, 'balance' => $e->balance,
            ], 402);
        } catch (IdempotencyKeyReuseException) {
            return response()->json(['error' => 'IDEMPOTENCY_KEY_REUSE'], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'INVALID_INPUT', 'detail' => $e->getMessage()], 422);
        }

        return response()->json([
            'dispatch_id' => $dispatch->id, 'status' => $dispatch->status,
            'credits_reserved' => $dispatch->credits_unit,
            '_links' => ['status' => url("/api/v1/messaging/dispatches/{$dispatch->id}")],
        ], 202);
    }

    private function sanitizeHtml(string $html): string
    {
        // Minimal allow-list; for production use HTMLPurifier package.
        // Strip <script>, <iframe>, <object>, <embed>, on* handlers, javascript: URIs.
        $clean = preg_replace('#<(script|iframe|object|embed|style)[^>]*>.*?</\1>#is', '', $html);
        $clean = preg_replace('#<(script|iframe|object|embed|style|link|meta)[^>]*/?>#i', '', $clean);
        $clean = preg_replace('#\son\w+\s*=\s*"[^"]*"#i', '', $clean);
        $clean = preg_replace('#\son\w+\s*=\s*\'[^\']*\'#i', '', $clean);
        $clean = preg_replace('#javascript:#i', '', $clean);
        return $clean;
    }
}
```

> **NOTE for Dev Senior:** consider installing `ezyang/htmlpurifier` for robust sanitization. The inline regex above is a defense-in-depth baseline; production should add Purifier.

- [ ] **Step 4: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/Messaging/SmsController.php backend/app/Http/Controllers/API/V1/Messaging/VoiceController.php backend/app/Http/Controllers/API/V1/Messaging/EmailController.php
git commit -m "feat(messaging): SMS/Voice/Email controllers with structured error responses

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.19: DispatchesController + Policy

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Messaging/DispatchesController.php`
- Create: `backend/app/Policies/MessageDispatchPolicy.php`

- [ ] **Step 1: Policy**

```php
<?php
namespace App\Policies;

use App\Models\MessageDispatch;
use App\Models\User;

class MessageDispatchPolicy
{
    public function view(User $user, MessageDispatch $dispatch): bool
    {
        return $dispatch->tenant_id === $user->tenant_id;
    }

    public function viewAny(User $user): bool
    {
        return $user->tenant_id !== null;
    }
}
```

- [ ] **Step 2: Controller**

```php
<?php
namespace App\Http\Controllers\API\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageDispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DispatchesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MessageDispatch::class);

        $q = MessageDispatch::query()->where('tenant_id', $request->user()->tenant_id);

        if ($channel = $request->query('channel')) {
            $q->where('channel', $channel);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        $items = $q->orderByDesc('id')->limit(100)->get();
        return response()->json(['data' => $items]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $dispatch = MessageDispatch::findOrFail($id);
        $this->authorize('view', $dispatch);

        // Mask content for users who didn't dispatch it (except superadmin)
        if ($request->user()->id !== $dispatch->user_id && ! $request->user()->is_superadmin) {
            $dispatch->content = mb_substr($dispatch->content, 0, 20) . '…';
        }

        return response()->json(['data' => $dispatch]);
    }
}
```

- [ ] **Step 3: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/Messaging/DispatchesController.php backend/app/Policies/MessageDispatchPolicy.php
git commit -m "feat(messaging): DispatchesController with policy + content masking

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.20: OptOutsController + CreateOptOutRequest

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Messaging/OptOutsController.php`
- Create: `backend/app/Http/Requests/Messaging/CreateOptOutRequest.php`

- [ ] **Step 1: Request**

```php
<?php
namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class CreateOptOutRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'channel'    => ['required', 'in:sms,voice,email,all'],
            'identifier' => ['required', 'string', 'max:255'],
            'reason'     => ['required', 'string', 'max:50'],
        ];
    }
}
```

- [ ] **Step 2: Controller**

```php
<?php
namespace App\Http\Controllers\API\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\CreateOptOutRequest;
use App\Models\MessageOptOut;
use App\Services\Messaging\OptOutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OptOutsController extends Controller
{
    public function __construct(private OptOutService $optOuts) {}

    public function index(Request $request): JsonResponse
    {
        $list = MessageOptOut::where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('id')->limit(200)->get();
        return response()->json(['data' => $list]);
    }

    public function store(CreateOptOutRequest $request): JsonResponse
    {
        $entry = $this->optOuts->add(
            $request->user()->tenant_id,
            $request->validated('channel'),
            $request->validated('identifier'),
            $request->validated('reason')
        );
        return response()->json(['data' => $entry], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $entry = MessageOptOut::where('tenant_id', $request->user()->tenant_id)
            ->findOrFail($id);
        $this->optOuts->remove($entry->tenant_id, $entry->channel, $entry->identifier);
        return response()->json(null, 204);
    }
}
```

- [ ] **Step 3: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/Messaging/OptOutsController.php backend/app/Http/Requests/Messaging/CreateOptOutRequest.php
git commit -m "feat(messaging): OptOuts CRUD per tenant

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.21: UnsubscribeController (public, token-validated)

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Messaging/UnsubscribeController.php`

- [ ] **Step 1: Implement**

```php
<?php
namespace App\Http\Controllers\API\V1\Messaging;

use App\Http\Controllers\Controller;
use App\Models\MessageDispatch;
use App\Services\Messaging\OptOutService;
use Illuminate\Http\Request;

class UnsubscribeController extends Controller
{
    public function __construct(private OptOutService $optOuts) {}

    public function __invoke(Request $request, string $token)
    {
        $dispatch = MessageDispatch::withoutGlobalScopes()
            ->where('unsubscribe_token', $token)
            ->first();

        if (! $dispatch || $dispatch->channel !== 'email') {
            abort(404);
        }

        if (! $dispatch->unsubscribe_consumed_at) {
            $this->optOuts->add(
                $dispatch->tenant_id, 'email', $dispatch->to, 'email_link', $dispatch->id
            );
            $dispatch->update(['unsubscribe_consumed_at' => now()]);
        }

        return response('<h1>Você foi descadastrado</h1><p>Não receberá mais emails desta lista.</p>')
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
```

- [ ] **Step 2: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/Messaging/UnsubscribeController.php
git commit -m "feat(messaging): unsubscribe endpoint with single-use token

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.22: ApiTokensController

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/ApiTokensController.php`
- Create: `backend/app/Http/Requests/Messaging/CreateApiTokenRequest.php`

- [ ] **Step 1: Request**

```php
<?php
namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;

class CreateApiTokenRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:100'],
            'abilities'   => ['required', 'array', 'min:1'],
            'abilities.*' => ['in:messaging:sms,messaging:voice,messaging:email,messaging:read,messaging:*'],
            'expires_at'  => ['nullable', 'date', 'after:now'],
        ];
    }
}
```

- [ ] **Step 2: Controller**

```php
<?php
namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\CreateApiTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokensController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->get(['id', 'name', 'abilities', 'expires_at', 'created_at', 'last_used_at']);
        return response()->json(['data' => $tokens]);
    }

    public function store(CreateApiTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->createToken(
            $request->validated('name'),
            $request->validated('abilities'),
            $request->validated('expires_at') ? \Carbon\Carbon::parse($request->validated('expires_at')) : null
        );
        return response()->json([
            'id'         => $token->accessToken->id,
            'token'      => $token->plainTextToken,
            'abilities'  => $token->accessToken->abilities,
            'expires_at' => $token->accessToken->expires_at,
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->tokens()->where('id', $id)->delete();
        return response()->json(null, 204);
    }
}
```

- [ ] **Step 3: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/ApiTokensController.php backend/app/Http/Requests/Messaging/CreateApiTokenRequest.php
git commit -m "feat(messaging): ApiTokensController for personal access tokens with abilities

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.23: InboundWebhookController (SMS opt-out)

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/InboundWebhookController.php`

- [ ] **Step 1: Implement**

```php
<?php
namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantChannel;
use App\Services\Messaging\OptOutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InboundWebhookController extends Controller
{
    public function __construct(private OptOutService $optOuts) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('messaging.inbound_webhook_secret');
        $provided = $request->header('Authorization', '');
        $provided = str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : $provided;

        if (! $secret || ! hash_equals($secret, $provided)) {
            Log::channel('infobip')->warning('inbound_webhook.unauthorized', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $processed = 0;
        $inKeywords  = array_map('mb_strtoupper', config('messaging.opt_out_keywords_in', []));
        $outKeywords = array_map('mb_strtoupper', config('messaging.opt_out_keywords_out', []));

        foreach ($request->input('results', []) as $msg) {
            $from = $msg['from'] ?? null;
            $to   = $msg['to']   ?? null;
            $text = mb_strtoupper(trim($msg['text'] ?? ''));
            if (! $from || ! $to) continue;

            $tenantId = $this->resolveTenantByNumber($to);
            if (! $tenantId) {
                Log::channel('infobip')->warning('inbound_webhook.tenant_not_found', ['to' => $to]);
                continue;
            }

            if (in_array($text, $inKeywords, true)) {
                $this->optOuts->add($tenantId, 'sms', '+' . preg_replace('/\D/', '', $from), 'sms_stop');
                $processed++;
            } elseif (in_array($text, $outKeywords, true)) {
                $this->optOuts->remove($tenantId, 'sms', '+' . preg_replace('/\D/', '', $from));
                $processed++;
            }
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    private function resolveTenantByNumber(string $to): ?int
    {
        $row = TenantChannel::where('channel', 'sms')
            ->where('identifier', $to)
            ->first();
        return $row?->tenant_id;
    }
}
```

- [ ] **Step 2: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/InboundWebhookController.php
git commit -m "feat(messaging): inbound webhook for SMS opt-out keywords

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.24: Admin pricing controller

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/MessagingAdminController.php`

- [ ] **Step 1: Implement**

```php
<?php
namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageDispatch;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessagingAdminController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function getPricing(): JsonResponse
    {
        return response()->json([
            'credits_per_sms'   => (int) $this->settings->getGlobal('billing', 'credits_per_sms', '1'),
            'credits_per_voice' => (int) $this->settings->getGlobal('billing', 'credits_per_voice', '5'),
            'credits_per_email' => (int) $this->settings->getGlobal('billing', 'credits_per_email', '2'),
        ]);
    }

    public function updatePricing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'credits_per_sms'   => ['nullable', 'integer', 'min:0', 'max:1000'],
            'credits_per_voice' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'credits_per_email' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        foreach ($data as $k => $v) {
            if ($v !== null) {
                $this->settings->upsertGlobal('billing', $k, (string) $v, 'string');
            }
        }
        return response()->json(['ok' => true]);
    }

    public function stats(): JsonResponse
    {
        $byChannelStatus = DB::table('message_dispatches')
            ->select('tenant_id', 'channel', 'status', DB::raw('COUNT(*) as n'), DB::raw('SUM(credits_charged) as credits'))
            ->groupBy('tenant_id', 'channel', 'status')
            ->orderBy('tenant_id')->orderBy('channel')->orderBy('status')
            ->limit(500)
            ->get();
        return response()->json(['data' => $byChannelStatus]);
    }
}
```

- [ ] **Step 2: Commit**

```powershell
git add backend/app/Http/Controllers/API/V1/Admin/MessagingAdminController.php
git commit -m "feat(messaging): admin endpoints for pricing and aggregated stats

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.25: Routes wiring

**Files:**
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Add use statements at top**

```php
use App\Http\Controllers\API\V1\Messaging\SmsController;
use App\Http\Controllers\API\V1\Messaging\VoiceController;
use App\Http\Controllers\API\V1\Messaging\EmailController;
use App\Http\Controllers\API\V1\Messaging\DispatchesController as MessagingDispatchesController;
use App\Http\Controllers\API\V1\Messaging\OptOutsController as MessagingOptOutsController;
use App\Http\Controllers\API\V1\Messaging\UnsubscribeController;
use App\Http\Controllers\API\V1\ApiTokensController;
use App\Http\Controllers\API\V1\InboundWebhookController;
use App\Http\Controllers\API\V1\Admin\MessagingAdminController;
```

- [ ] **Step 2: Inside `Route::prefix('v1')->group(function () {`, BEFORE the `auth:sanctum` group, add public endpoints**

```php
// Inbound webhook (validated by secret)
Route::post('webhooks/infobip/inbound', [InboundWebhookController::class, 'handle'])
    ->withoutMiddleware(['auth:sanctum'])
    ->middleware('throttle:webhook');

// Public unsubscribe (token-validated)
Route::get('messaging/unsubscribe/{token}', UnsubscribeController::class)
    ->middleware('throttle:60,1');
```

- [ ] **Step 3: Inside `Route::middleware('auth:sanctum')->group(...)`, add authenticated endpoints**

```php
// Messaging — direct send (token abilities + per-channel throttle)
Route::post('messaging/sms',   [SmsController::class,   'store'])
    ->middleware(['token.ability:messaging:sms',   'throttle:messaging-sms']);
Route::post('messaging/voice', [VoiceController::class, 'store'])
    ->middleware(['token.ability:messaging:voice', 'throttle:messaging-voice']);
Route::post('messaging/email', [EmailController::class, 'store'])
    ->middleware(['token.ability:messaging:email', 'throttle:messaging-email']);

// Dispatches read
Route::get('messaging/dispatches',       [MessagingDispatchesController::class, 'index'])
    ->middleware('token.ability:messaging:read');
Route::get('messaging/dispatches/{id}',  [MessagingDispatchesController::class, 'show'])
    ->middleware('token.ability:messaging:read');

// Opt-outs (read = messaging:read; write = messaging:*)
Route::get('messaging/opt-outs',          [MessagingOptOutsController::class, 'index'])
    ->middleware('token.ability:messaging:read');
Route::post('messaging/opt-outs',         [MessagingOptOutsController::class, 'store'])
    ->middleware('token.ability:messaging:*');
Route::delete('messaging/opt-outs/{id}',  [MessagingOptOutsController::class, 'destroy'])
    ->middleware('token.ability:messaging:*');

// API tokens
Route::get('auth/api-tokens',         [ApiTokensController::class, 'index']);
Route::post('auth/api-tokens',        [ApiTokensController::class, 'store']);
Route::delete('auth/api-tokens/{id}', [ApiTokensController::class, 'destroy']);
```

- [ ] **Step 4: Inside `Route::middleware('superadmin')->prefix('admin')->group(...)`, add admin endpoints**

```php
// Messaging admin
Route::get('messaging/pricing',  [MessagingAdminController::class, 'getPricing']);
Route::put('messaging/pricing',  [MessagingAdminController::class, 'updatePricing']);
Route::get('messaging/stats',    [MessagingAdminController::class, 'stats']);
```

- [ ] **Step 5: Re-run the route whitelist test (Task 1.1, Step 3)**

```powershell
php artisan test --filter=PublicRoutesWhitelistTest
```

Expected: PASS (whitelist already includes the new inbound webhook and unsubscribe).

- [ ] **Step 6: Sanity check route registration**

```powershell
php artisan route:list | findstr messaging
```

Expected: list of 12 messaging routes including admin.

- [ ] **Step 7: Commit**

```powershell
git add backend/routes/api.php
git commit -m "feat(messaging): wire 15 new routes (sms/voice/email/dispatches/opt-outs/tokens/admin)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.26: Sanctum user-trait sanity

**Files:** none (verification only)

- [ ] **Step 1: Confirm `User` model uses `HasApiTokens`**

```powershell
findstr "HasApiTokens" backend\app\Models\User.php
```

Expected: `use Laravel\Sanctum\HasApiTokens;` and `use HasApiTokens;` in trait list.

If missing, add both lines (this is required for `$user->createToken()` to work).

### Task 2.27: PM Gate Phase 2

- [ ] **Step 1: Show stats to PM**

```powershell
git log --oneline master..HEAD | findstr /v "test(security)"
git diff master..HEAD --stat
php artisan test
```

- [ ] **Step 2: PM confirms with user**: "Phase 2 implementation complete. N commits, M files changed, all existing tests still pass. Proceed to Phase 3 (QA)?"

---

## Phase 3 — QA (Feature & Integration Tests)

> Owner: QA Engineer (subagent). Each task creates one test file and runs it to confirm green. After all tests pass, generate a coverage report.

### Task 3.1: SendSmsApiTest

**Files:**
- Create: `backend/tests/Feature/Messaging/SendSmsApiTest.php`

- [ ] **Step 1: Write feature test (full happy + error matrix)**

```php
<?php
namespace Tests\Feature\Messaging;

use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\OptOutService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SendSmsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_sms', '1', 'string');
        Http::fake([
            'api.infobip.com/sms/2/text/advanced' => Http::response(['messages' => [['messageId' => 'mid-1']]], 200),
        ]);
    }

    private function actAsUserWithAbility(array $abilities = ['messaging:sms']): User
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 100]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, $abilities);
        return $user;
    }

    public function test_happy_path_returns_202(): void
    {
        $this->actAsUserWithAbility();
        $resp = $this->postJson('/api/v1/messaging/sms', [
            'to' => '+5521999998888', 'content' => 'hi',
        ]);
        $resp->assertStatus(202)
            ->assertJsonStructure(['dispatch_id', 'status', 'credits_reserved', '_links']);
    }

    public function test_requires_auth(): void
    {
        $resp = $this->postJson('/api/v1/messaging/sms', ['to' => '+5521999998888', 'content' => 'hi']);
        $resp->assertStatus(401);
    }

    public function test_requires_correct_ability(): void
    {
        $this->actAsUserWithAbility(['messaging:email']);
        $resp = $this->postJson('/api/v1/messaging/sms', ['to' => '+5521999998888', 'content' => 'hi']);
        $resp->assertStatus(403);
    }

    public function test_validates_payload(): void
    {
        $this->actAsUserWithAbility();
        $resp = $this->postJson('/api/v1/messaging/sms', ['to' => '', 'content' => '']);
        $resp->assertStatus(422);
    }

    public function test_rejects_opt_out(): void
    {
        $user = $this->actAsUserWithAbility();
        app(OptOutService::class)->add($user->tenant_id, 'sms', '+5521999998888', 'user_request');
        $resp = $this->postJson('/api/v1/messaging/sms', ['to' => '+5521999998888', 'content' => 'hi']);
        $resp->assertStatus(422)->assertJson(['error' => 'RECIPIENT_OPTED_OUT']);
    }

    public function test_insufficient_credits(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 0]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/sms', ['to' => '+5521999998888', 'content' => 'hi']);
        $resp->assertStatus(402)->assertJsonStructure(['error', 'required', 'balance']);
    }

    public function test_idempotency_replay(): void
    {
        $this->actAsUserWithAbility();
        $payload = ['to' => '+5521999998888', 'content' => 'hi'];
        $headers = ['Idempotency-Key' => 'k1'];

        $first  = $this->withHeaders($headers)->postJson('/api/v1/messaging/sms', $payload);
        $second = $this->withHeaders($headers)->postJson('/api/v1/messaging/sms', $payload);

        $this->assertEquals($first->json('dispatch_id'), $second->json('dispatch_id'));
        $this->assertEquals(1, MessageDispatch::count());
    }

    public function test_idempotency_key_reuse_different_payload_returns_409(): void
    {
        $this->actAsUserWithAbility();
        $headers = ['Idempotency-Key' => 'k1'];

        $this->withHeaders($headers)->postJson('/api/v1/messaging/sms', ['to' => '+5521999998888', 'content' => 'A']);
        $resp = $this->withHeaders($headers)->postJson('/api/v1/messaging/sms', ['to' => '+5521999998888', 'content' => 'B']);

        $resp->assertStatus(409)->assertJson(['error' => 'IDEMPOTENCY_KEY_REUSE']);
    }
}
```

- [ ] **Step 2: Run test**

```powershell
php artisan test --filter=SendSmsApiTest
```

Expected: PASS (8 tests).

- [ ] **Step 3: Commit**

```powershell
git add backend/tests/Feature/Messaging/SendSmsApiTest.php
git commit -m "test(messaging): feature tests for SMS API (8 scenarios)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.2: SendEmailApiTest + SendVoiceApiTest

**Files:**
- Create: `backend/tests/Feature/Messaging/SendEmailApiTest.php`
- Create: `backend/tests/Feature/Messaging/SendVoiceApiTest.php`

- [ ] **Step 1: SendEmailApiTest (mirror SMS structure, also test HTML sanitization)**

```php
<?php
namespace Tests\Feature\Messaging;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SendEmailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_email', '2', 'string');
        Http::fake([
            'api.infobip.com/email/3/send' => Http::response(['messages' => [['messageId' => 'eml-1']]], 200),
        ]);
    }

    public function test_happy_path_returns_202(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to' => 'joao@example.com', 'subject' => 'Hi', 'content' => '<p>hello</p>',
        ]);
        $resp->assertStatus(202);
    }

    public function test_strips_xss_script_tag(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to' => 'joao@example.com', 'subject' => 'X', 'content' => 'ok<script>alert(1)</script>',
        ]);
        $resp->assertStatus(202);

        $dispatch = \App\Models\MessageDispatch::first();
        $this->assertStringNotContainsString('<script', $dispatch->content);
    }

    public function test_strips_header_injection_in_subject(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 10]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:email']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to' => 'joao@example.com',
            'subject' => "Hello\r\nBcc: evil@x.com",
            'content' => 'hi',
        ]);
        $resp->assertStatus(202);
        $dispatch = \App\Models\MessageDispatch::first();
        $this->assertStringNotContainsString("\n", $dispatch->subject);
    }
}
```

- [ ] **Step 2: SendVoiceApiTest (TTS + SSRF guard)**

```php
<?php
namespace Tests\Feature\Messaging;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SendVoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        app(SettingsService::class)->upsertGlobal('billing', 'credits_per_voice', '5', 'string');
        Http::fake([
            'api.infobip.com/tts/3/single' => Http::response(['messageId' => 'voice-1'], 200),
        ]);
    }

    public function test_tts_happy_path(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 100]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:voice']);

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to' => '+5521999998888', 'content' => 'hello',
        ]);
        $resp->assertStatus(202);
    }

    public function test_rejects_audio_url_outside_allowlist(): void
    {
        $tenant = Tenant::factory()->create(['credits_balance' => 100]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, ['messaging:voice']);

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to' => '+5521999998888',
            'audio_url' => 'https://evil.com/audio.mp3',
        ]);
        $resp->assertStatus(422);
    }
}
```

- [ ] **Step 3: Run tests**

```powershell
php artisan test --filter="SendEmailApiTest|SendVoiceApiTest"
```

Expected: PASS (all tests in both files).

- [ ] **Step 4: Commit**

```powershell
git add backend/tests/Feature/Messaging/SendEmailApiTest.php backend/tests/Feature/Messaging/SendVoiceApiTest.php
git commit -m "test(messaging): feature tests for Email and Voice APIs (XSS, header injection, SSRF)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.3: Additional Feature Tests

> For brevity, the remaining tests follow the same TDD pattern. Each task = one file = write test → run → fix any gaps in implementation → commit. Each test should:
> - Use `RefreshDatabase`
> - Use `Sanctum::actingAs($user, $abilities)`
> - Use `Http::fake()` for any external HTTP
> - Cover happy path + at least one auth/policy boundary + one validation boundary

**Files to create (one per Task 3.3.X):**

- **3.3.1** `tests/Feature/Messaging/GetDispatchStatusTest.php` — show happy path, IDOR returns 404, content masking for non-owner non-superadmin, index filters by channel/status, only own-tenant rows
- **3.3.2** `tests/Feature/Messaging/OptOutsApiTest.php` — CRUD: GET/POST/DELETE, ability `messaging:*` required for write, cross-tenant 404
- **3.3.3** `tests/Feature/Messaging/UnsubscribeTest.php` — valid token marks consumed + adds opt-out + returns HTML; replay same token still works (idempotent); altered token returns 404
- **3.3.4** `tests/Feature/Messaging/ApiTokenCrudTest.php` — create returns plaintext, list omits plaintext, delete revokes (subsequent calls 401), abilities respected
- **3.3.5** `tests/Feature/Messaging/InboundWebhookSmsOptOutTest.php` — SAIR keyword adds opt-out (tenant resolved via tenant_channels); ENTRAR removes; invalid secret = 401; tenant not found = silent (no error)
- **3.3.6** `tests/Feature/Messaging/BillingFlowTest.php` — full E2E: reserve in controller (-N from balance), success in job (charged=N), failure in job (released, balance restored); idempotent replay does NOT double-charge; retry exhaustion releases
- **3.3.7** `tests/Feature/Messaging/RateLimiterMessagingTest.php` — 60 reqs SMS pass, 61st returns 429 with proper error body, separate buckets per channel, separate buckets per tenant
- **3.3.8** `tests/Feature/Messaging/TransactionalEmailRegistersDispatchTest.php` — calling `TransactionalMailer::send($dispatch, $mailable)` from inside MessagingService with `source=transactional`, `provider=laravel_mail`, sends via `Mail::fake()` assertion
- **3.3.9** `tests/Feature/Admin/MessagingAdminTest.php` — superadmin can update pricing; regular user gets 403; stats endpoint returns aggregated counts
- **3.3.10** `tests/Unit/Policies/MessageDispatchPolicyTest.php` — same-tenant true, cross-tenant false

- [ ] **Step 1: For each subtask, follow the TDD cycle**

For each of the 10 files above:
1. Read the spec section 9 "Estratégia de Testes" to confirm assertions match
2. Write the test class
3. Run `php artisan test --filter=<TestClassName>` — expect PASS (implementation already exists from Phase 2)
4. If any test fails due to implementation gap, fix the implementation, NOT the test
5. Commit: `git commit -m "test(messaging): <TestName>"`

- [ ] **Step 2: Run full test suite at the end**

```powershell
php artisan test
```

Expected: all existing + new tests PASS. Zero failures.

- [ ] **Step 3: Generate coverage report**

```powershell
php artisan test --coverage --min=80
```

Expected: ≥80% coverage on files in `app/Services/Messaging/`, `app/Http/Controllers/API/V1/Messaging/`, `app/Jobs/SendMessageJob.php`, `app/Http/Middleware/EnsureTokenAbility.php`.

If under 80%, add missing tests (usually edge cases or error branches).

### Task 3.4: PM Gate Phase 3

- [ ] **Step 1: Show test count + coverage to PM**

```powershell
php artisan test --testsuite=Feature
php artisan test --coverage
```

- [ ] **Step 2: PM confirms with user**: "Phase 3 QA complete. N feature tests + M unit tests, X% coverage on new files. All green. Proceed to Phase 4 (Red Team)?"

---

## Phase 4 — Red Team (Penetration Testing)

> Owner: Red Team subagent. Output: `tests/Pentest/MessagingSecurityTest.php` + a report (markdown) classifying findings by severity (critical / high / medium / low). All critical and high MUST be fixed before passing the gate. Re-runs until clean.

### Task 4.1: Create the pentest test suite

**Files:**
- Create: `backend/tests/Pentest/MessagingSecurityTest.php`
- Create: `backend/docs/pentest-messaging-2026-05-26.md` (report artifact)

- [ ] **Step 1: Implement the 20 scenarios from spec section 8**

The Red Team agent receives this exact mapping (one PHPUnit method per item from spec table):

| # | Method | Asserts |
|---|--------|---------|
| 1 | `test_routes_reject_missing_token`        | every messaging route returns 401 without `Authorization` |
| 2 | `test_cross_tenant_dispatch_is_404`        | tenant A cannot GET dispatch belonging to tenant B |
| 3 | `test_token_with_only_sms_cannot_call_voice` | 403 when ability mismatch |
| 4 | `test_idempotency_replay_diverging_payload_returns_409` | already covered in SendSmsApiTest but reasserted here |
| 5 | `test_parallel_dispatch_with_limited_balance_does_not_overcharge` | 10 requests, balance for 5, exactly 5 succeed, balance = 0 |
| 6 | `test_opt_out_bypass_via_case_or_whitespace` | uppercase email, padded number, both blocked |
| 7 | `test_audio_url_ssrf_blocked_for_private_ip` | `http://127.0.0.1/`, `file://`, `gopher://`, `http://[::1]/` all rejected |
| 8 | `test_email_html_strips_iframe_onerror_javascript` | each variant sanitized |
| 9 | `test_subject_strips_crlf` | `\r\n` removed from subject |
| 10 | `test_inbound_webhook_rejects_wrong_secret` | 401 |
| 11 | `test_unsubscribe_with_forged_token_returns_404` | random 64-char strings rejected |
| 12 | `test_rate_limit_is_per_tenant_not_per_token` | two tokens of same tenant share bucket |
| 13 | `test_quiet_hours_cannot_be_bypassed_by_scheduled_for_past` | past `scheduled_for` rejected at validation |
| 14 | `test_opt_out_probing_does_not_debit_credits` | 1000 opt-out attempts leave balance unchanged |
| 15 | `test_no_token_appears_in_log_output` | dispatch a request, grep storage/logs for raw Bearer token |
| 16 | `test_non_owner_sees_masked_content` | already covered in GetDispatchStatusTest, reasserted |
| 17 | `test_api_tokens_endpoint_requires_sanctum` | CSRF/SPA mode protection check |
| 18 | `test_phone_validation_rejects_premium_and_null_byte` | `0900...`, `+55\x0021...` rejected |
| 19 | `test_extra_fields_in_payload_are_ignored` | `is_superadmin`, `tenant_id` in body don't change state |
| 20 | `test_webhook_secret_comparison_is_constant_time` | reflect on code: uses `hash_equals` (manual assertion) |

For each method, write the assertion code following Phase 3 patterns. Use `Http::fake()` for outbound, `RefreshDatabase`, `Sanctum::actingAs`.

- [ ] **Step 2: Run the suite**

```powershell
php artisan test --filter=MessagingSecurityTest
```

- [ ] **Step 3: Write report**

`backend/docs/pentest-messaging-2026-05-26.md`:
```markdown
# Pentest Report — Messaging APIs (2026-05-26)

## Summary
- Scenarios tested: 20
- Critical: 0
- High: 0
- Medium: 0
- Low: 0

## Findings
(none — all scenarios passed)

## Methodology
- Automated PHPUnit suite in `tests/Pentest/MessagingSecurityTest.php`
- Manual review of webhook secret comparison (item 20): uses `hash_equals()` in `WebhookController::validateSecret()` and `InboundWebhookController::handle()` — constant time ✓
```

If any scenario fails, the report lists findings with: severity, description, reproduction (failed test name), proposed fix, status.

- [ ] **Step 4: Fix critical/high findings (loop)**

For each critical/high finding:
1. Dev Senior implements the fix
2. Re-run the failing pentest test
3. Confirm green
4. Update report status to "fixed"
5. Commit fix referencing the report

Loop until 0 critical + 0 high.

- [ ] **Step 5: Commit pentest suite + report**

```powershell
git add backend/tests/Pentest/MessagingSecurityTest.php backend/docs/pentest-messaging-2026-05-26.md
git commit -m "test(security): pentest suite + clean report for messaging APIs

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 4.2: PM Gate Phase 4

- [ ] **Step 1: Show report to PM**

```powershell
type backend\docs\pentest-messaging-2026-05-26.md
php artisan test --filter=MessagingSecurityTest
```

- [ ] **Step 2: PM confirms with user**: "Phase 4 Red Team complete. 20 scenarios, 0 critical, 0 high, [N] medium/low documented. Proceed to Phase 5 (Code Review)?"

---

## Phase 5 — Code Review

> Owner: Code Reviewer subagent (`superpowers:code-reviewer`).

### Task 5.1: Run code review against spec + plan

- [ ] **Step 1: Dispatch code-reviewer agent**

Agent receives:
- Spec: `docs/superpowers/specs/2026-05-26-messaging-apis-design.md`
- Plan: `docs/superpowers/plans/2026-05-26-messaging-apis.md`
- Diff: `git diff master..HEAD`
- Pentest report: `backend/docs/pentest-messaging-2026-05-26.md`

Review criteria:
1. Implementation matches spec section-by-section
2. Code follows existing project conventions (Laravel idioms, naming, structure)
3. No dead code, no leftover debugging output
4. Tests are meaningful (not assert-true placeholders)
5. Migrations are reversible (down methods present)
6. No secrets / API keys in code
7. Logging is consistent with channel pattern (`Log::channel('infobip')`)
8. Authorization checks present on every read endpoint
9. FormRequest used for all POST/PUT (not raw `$request->all()`)
10. `DB::transaction` wraps any multi-step writes

Output: a list of findings with severity (must-fix, should-fix, nice-to-have).

- [ ] **Step 2: Fix must-fix items**

Dev Senior implements fixes; re-run tests after each.

- [ ] **Step 3: Commit fixes**

One commit per cluster of related fixes.

### Task 5.2: PM Gate Phase 5

- [ ] **Step 1: Show summary to PM**: "Code review complete. N must-fix items addressed. Ready to merge?"

---

## Phase 6 — Merge + Smoke E2E

### Task 6.1: Merge to master

- [ ] **Step 1: Re-run full suite one final time**

```powershell
cd c:\xampp\htdocs\new_saas-messaging\backend
php artisan test
```

Expected: 100% PASS.

- [ ] **Step 2: Verify route count**

```powershell
php artisan route:list | findstr messaging
```

Expected: exactly 12 messaging routes (3 send + 2 dispatches + 3 opt-outs + 1 unsubscribe + 3 admin) + 3 api-tokens routes + 1 inbound webhook = 16 routes total in messaging scope.

- [ ] **Step 3: Squash-friendly log preview**

```powershell
git log --oneline master..HEAD
```

PM reviews list; user approves merge strategy (merge commit vs rebase).

- [ ] **Step 4: Merge worktree branch into master**

```powershell
cd c:\xampp\htdocs\new_saas
git merge --no-ff feat/messaging-apis -m "feat(messaging): SMS/voice/email APIs with billing, opt-out, idempotency, rate limit

See: docs/superpowers/specs/2026-05-26-messaging-apis-design.md
See: docs/superpowers/plans/2026-05-26-messaging-apis.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 5: Clean worktree**

```powershell
git worktree remove ..\new_saas-messaging
git branch -d feat/messaging-apis
```

### Task 6.2: Smoke E2E (real Infobip)

- [ ] **Step 1: Run migrations on staging/local DB**

```powershell
php artisan migrate
```

- [ ] **Step 2: Configure real Infobip credentials in `.env`**

User provides production keys; PM does NOT paste them in chat.

- [ ] **Step 3: Send 1 real SMS via API**

```powershell
$body = @{ to = "+55<your-test-number>"; content = "Smoke test BusinessCode messaging API" } | ConvertTo-Json
curl.exe -X POST http://localhost:8000/api/v1/messaging/sms `
  -H "Authorization: Bearer <sanctum-token>" `
  -H "Content-Type: application/json" `
  -H "Idempotency-Key: smoke-001" `
  -d $body
```

Expected: 202 with dispatch_id. Worker processes (or run `php artisan queue:work --queue=messaging --once`). Check `message_dispatches` table — status moves `queued` → `sending` → `sent`. SMS arrives on phone within seconds.

- [ ] **Step 4: Send 1 real voice + 1 real email** (same pattern, different endpoints)

- [ ] **Step 5: Verify delivery webhook updates status to `delivered`** after Infobip callback.

- [ ] **Step 6: Confirm credits deducted correctly in `tenants.credits_balance` and `credit_transactions` table**

```powershell
php artisan tinker --execute="echo json_encode([
    'balance' => DB::table('tenants')->where('id', 1)->value('credits_balance'),
    'last_txns' => DB::table('credit_transactions')->orderByDesc('id')->limit(5)->get(),
]);"
```

- [ ] **Step 7: Final commit**

```powershell
git add -A
git commit --allow-empty -m "chore(messaging): smoke E2E passed for SMS+voice+email with real Infobip

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 6.3: Done

- [ ] **PM declares done.** Spec, plan, code, tests, pentest report, smoke results all committed on `master`. Project ready for production deploy.

---

## Open Decisions Deferred (out of scope for this plan)

1. **Voice DTMF opt-out** (press 9 to unsubscribe) — Infobip TTS supports it but requires multi-step IVR config. Add in v2.
2. **SMS bounce/complaint webhook** for SMTP-style email opt-outs — depends on the SMTP provider chosen (Postmark/SES). Add when production SMTP is wired.
3. **HTMLPurifier package install** — currently inline regex; install `ezyang/htmlpurifier` and replace `EmailController::sanitizeHtml()` before public launch.
4. **Frontend UI** for managing API tokens and viewing dispatches — separate plan (`docs/superpowers/plans/<date>-messaging-frontend.md`).
5. **Fallback provider for SMS** (Twilio/Zenvia) — single-provider risk noted in spec. Add when Infobip SLA becomes a constraint.
