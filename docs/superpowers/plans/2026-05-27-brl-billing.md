# BRL Billing — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the credit-based billing model with R$ (integer cents) across the entire system — admin pricing UI, per-tenant override, optional credit line with 7-day grace dunning, monthly billing on tenant anniversary via stored MP card, new `finance` role. No regression of existing 175 tests.

**Architecture:** Substitution of `CreditService` with `BillingService` (cents-based). New `PricingService` resolving in 3 levels (TenantServicePrice → Plan.sale_cents_overrides → ServicePrice global). 7 data-aware migrations with idempotent down(). `MonthlyBillingJob` orchestrated by daily cron at tenant anniversary; `OverdueRetryJob` for dunning. Frontend renames "créditos" → "saldo R$" across 12+ components, adds 4 admin/finance pages.

**Tech Stack:** Laravel 12, PHP 8.2+, MySQL, Laravel Sanctum, Vue 3 + TypeScript, Bootstrap/Tabler, Mercado Pago SDK.

**Spec:** [docs/superpowers/specs/2026-05-27-brl-billing-design.md](../specs/2026-05-27-brl-billing-design.md)

---

## Phase Roadmap

| Phase | Owner | Output | Gate |
|-------|-------|--------|------|
| 0 | PM | Spec + plan + branch | conversation |
| 1 | Dev | Config + 7 migrations + 2 new models + 4 altered models | PM diff review |
| 2 | Dev | BillingService + PricingService + exceptions | PM diff review |
| 3 | Dev | MonthlyBillingJob + OverdueRetryJob + cron + MP::chargeStoredCard + email templates | PM diff review |
| 4 | Dev | Admin controllers + routes + EnsureRole + audit | PM diff review |
| 5 | Dev | Frontend admin/finance pages | PM screenshot review |
| 6 | Dev | Frontend user-facing renames + banner | PM diff + screenshot |
| 7 | QA | Unit + Feature tests; coverage ≥85% | PM output + coverage review |
| 8 | Red Team | Pentest 20 scenarios + fixes | PM relatório review |
| 9 | Code Reviewer | Final review vs spec + financial audit | PM aprova report |
| 10 | PM | Pre-flight + janela + smoke + observação 48h | done |

---

## File Map

### Backend — New Files
- `backend/config/billing.php`
- `backend/database/migrations/2026_05_27_000001_create_service_prices_table.php`
- `backend/database/migrations/2026_05_27_000002_create_tenant_service_prices_table.php`
- `backend/database/migrations/2026_05_27_000003_alter_tenants_for_brl_billing.php`
- `backend/database/migrations/2026_05_27_000004_alter_plans_for_brl_billing.php`
- `backend/database/migrations/2026_05_27_000005_alter_message_dispatches_for_brl.php`
- `backend/database/migrations/2026_05_27_000006_rename_credit_transactions_to_balance_transactions.php`
- `backend/database/migrations/2026_05_27_000007_add_finance_role_to_users.php`
- `backend/database/seeders/ServicePricesSeeder.php`
- `backend/app/Models/ServicePrice.php`
- `backend/app/Models/TenantServicePrice.php`
- `backend/app/Models/BalanceTransaction.php` (renamed)
- `backend/app/Services/Billing/BillingService.php` (replaces `CreditService.php`)
- `backend/app/Services/Billing/PricingService.php` (replaces messaging one)
- `backend/app/Exceptions/Billing/InsufficientFundsException.php`
- `backend/app/Exceptions/Billing/CreditLimitReachedException.php`
- `backend/app/Exceptions/Billing/BillingSuspendedException.php`
- `backend/app/Exceptions/Billing/BillingBlockedException.php`
- `backend/app/Jobs/DispatchMonthlyBillingJob.php`
- `backend/app/Jobs/MonthlyBillingJob.php`
- `backend/app/Jobs/DispatchOverdueRetryJob.php`
- `backend/app/Jobs/OverdueRetryJob.php`
- `backend/app/Http/Middleware/EnsureRole.php`
- `backend/app/Http/Controllers/API/V1/Admin/ServicePricingController.php`
- `backend/app/Http/Controllers/API/V1/Admin/TenantPricingController.php`
- `backend/app/Http/Controllers/API/V1/Admin/TenantCreditLineController.php`
- `backend/app/Http/Controllers/API/V1/Admin/BillingReportController.php`
- `backend/app/Http/Requests/Admin/UpdateServicePriceRequest.php`
- `backend/app/Http/Requests/Admin/UpdateTenantPricingRequest.php`
- `backend/app/Http/Requests/Admin/UpdateCreditLimitRequest.php`
- `backend/app/Http/Requests/Admin/ManualBalanceAdjustmentRequest.php`
- `backend/app/Notifications/Billing/MonthlySuccessNotification.php`
- `backend/app/Notifications/Billing/OverdueWarningNotification.php`
- `backend/app/Notifications/Billing/TenantSuspendedNotification.php`
- `backend/app/Notifications/Billing/CardMissingNotification.php`
- `backend/app/Notifications/Billing/CardExpiredNotification.php`
- `backend/app/Console/Commands/Billing/{Status,Bill,Retry,Unlock,Adjust,RecheckStatus,Report,PreMigrationCheck}.php` (8 commands)
- `backend/resources/views/emails/billing/{monthly-success,overdue-warning-day1,overdue-warning-retry,overdue-final,tenant-suspended,card-missing,card-expired}.blade.php`
- `backend/tests/Unit/Billing/{PricingService,BillingService,InsufficientFundsException}Test.php`
- `backend/tests/Unit/Jobs/{MonthlyBillingJob,OverdueRetryJob}Test.php`
- `backend/tests/Unit/Middleware/EnsureRoleTest.php`
- `backend/tests/Unit/Policies/ServicePricePolicyTest.php`
- `backend/tests/Feature/Admin/{AdminPricingApi,TenantPricingApi,TenantCreditLineApi,BillingReport}Test.php`
- `backend/tests/Feature/Billing/{BalanceFlow,BlockedTenantCannotSend,MonthlyBillingDispatch,OverdueDunning,MercadoPagoChargeStoredCard,MpWebhookMonthlyChargeIdempotent,MessageDispatchSnapshotsPrice,MigrationRoundtrip}Test.php`
- `backend/tests/Pentest/BillingSecurityTest.php`
- `backend/docs/runbooks/billing-rollback.md`

### Backend — Modified Files
- `backend/.env.example` — add `BILLING_*` keys
- `backend/.env` — add same keys (local)
- `backend/bootstrap/app.php` — register `EnsureRole` alias; schedule jobs
- `backend/app/Models/Tenant.php` — fillable + casts for billing fields, helper methods
- `backend/app/Models/Plan.php` — fillable + casts for `included_balance_cents` + `sale_cents_overrides`
- `backend/app/Models/MessageDispatch.php` — fillable + casts for `cost_cents/sale_cents/charged_cents`
- `backend/app/Services/Messaging/MessagingService.php` — use BillingService
- `backend/app/Services/Messaging/PricingService.php` — DELETE (replaced)
- `backend/app/Services/Billing/CreditService.php` — DELETE (replaced)
- `backend/app/Models/CreditTransaction.php` — DELETE (replaced)
- `backend/app/Jobs/SendMessageJob.php` — use BillingService release
- `backend/app/Services/MercadoPagoService.php` — add `chargeStoredCard()` and `createTokenFromStoredCard()`
- `backend/app/Http/Controllers/API/V1/WebhookController.php` — extend `mercadopago()` to handle `monthly:*` external_reference
- `backend/app/Http/Controllers/API/V1/CheckoutController.php` — persist mp_customer_id + mp_default_card_id
- `backend/routes/api.php` — register new admin/billing routes; superadmin → role:finance|superadmin
- `backend/database/factories/TenantFactory.php` — generate billing fields
- `backend/database/factories/PlanFactory.php` — generate included_balance_cents
- `backend/database/factories/MessageDispatchFactory.php` — generate cost/sale cents
- Existing tests touching `credits_*` — search & replace + cents-aware assertions
- `backend/app/Http/Controllers/API/V1/Admin/TenantsController.php` — show billing fields, allow finance role
- `backend/app/Http/Controllers/API/V1/Admin/PlansController.php` — show/edit `included_balance_cents`
- `backend/app/Http/Controllers/API/V1/ReportController.php` — credits report → balance_transactions
- `backend/app/Http/Controllers/API/V1/PaymentController.php` — purchase credits → recharge balance
- `backend/app/Console/Kernel.php` (or `bootstrap/app.php`) — schedule wiring

### Frontend — New Files
- `frontend/src/pages/admin/billing/Pricing.vue`
- `frontend/src/pages/admin/billing/Tenants.vue`
- `frontend/src/pages/admin/billing/TenantDetail.vue`
- `frontend/src/pages/admin/billing/Reports.vue`
- `frontend/src/components/billing/PriceEditModal.vue`
- `frontend/src/components/billing/CreditLimitModal.vue`
- `frontend/src/components/billing/ManualAdjustModal.vue`
- `frontend/src/components/billing/BalanceBanner.vue`
- `frontend/src/stores/billing.ts`

### Frontend — Modified Files
- `frontend/src/router/index.ts` — add 4 admin/billing routes; rename /settings/credits → /settings/saldo; role guard
- `frontend/src/components/layout/AppSidebar.vue` — "Financeiro" section + "Meu Saldo" link
- `frontend/src/stores/auth.ts` — expose `user.role`; type extends 'finance'
- `frontend/src/pages/settings/CreditPurchase.vue` — rename + R$ in display
- `frontend/src/components/checkout/CheckoutForm.vue` — add "salvar cartão" checkbox
- `frontend/src/components/checkout/OrderSummary.vue` — display R$ everywhere
- `frontend/src/components/campaigns/ConfirmSendModal.vue` — "Custo: R$ X"
- `frontend/src/components/campaigns/steps/Step2Content/AiMode.vue` — R$
- `frontend/src/components/campaigns/steps/Step5Review.vue` — R$ + estimate
- `frontend/src/components/campaigns/steps/VoiceStudio.vue` — R$
- `frontend/src/pages/admin/Plans.vue` — `included_balance_cents`
- `frontend/src/pages/admin/Tenants.vue` — billing fields shown
- `frontend/src/pages/admin/SettingsElevenLabs.vue` — cost in R$
- `frontend/src/pages/dashboard/Index.vue` — saldo card in R$
- `frontend/src/App.vue` — mount BalanceBanner globally

---

## Phase 0 — Setup

### Task 0.1: Branch and baseline

- [ ] **Step 1: Create feature branch from master**

```bash
cd c:/xampp/htdocs
git checkout master
git pull --ff-only
git checkout -b feat/brl-billing
```

- [ ] **Step 2: Verify baseline test suite green**

```bash
cd c:/xampp/htdocs/new_saas/backend
php artisan test
```
Expected: 175 passed / 0 failed.

- [ ] **Step 3: Confirm staging DB backup exists** (manual — PM checks)

- [ ] **Step 4: PM gate** — "Branch ready, baseline 175/175. Proceed to Phase 1?"

---

## Phase 1 — Config + 7 Migrations + Models

### Task 1.1: Config + .env

**Files:**
- Create: `backend/config/billing.php`
- Modify: `backend/.env.example`
- Modify: `backend/.env`

- [ ] **Step 1: Create `backend/config/billing.php`**

```php
<?php
return [
    'overdue_grace_days' => (int) env('BILLING_OVERDUE_GRACE_DAYS', 7),
    'overdue_retry_hour' => (int) env('BILLING_OVERDUE_RETRY_HOUR', 4),
    'monthly_dispatch_hour' => (int) env('BILLING_MONTHLY_DISPATCH_HOUR', 3),
    'credit_limit_warning_pct' => (int) env('BILLING_CREDIT_LIMIT_WARNING_PCT', 80),
    'default_included_balance_cents' => (int) env('BILLING_DEFAULT_INCLUDED_BALANCE_CENTS', 0),
    'migration_price_cents' => (int) env('BILLING_MIGRATION_PRICE_CENTS', 15),
    'brl_enabled' => filter_var(env('BILLING_BRL_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
];
```

- [ ] **Step 2: Append to `backend/.env.example` and `backend/.env`**

```
# Billing (BRL)
BILLING_OVERDUE_GRACE_DAYS=7
BILLING_OVERDUE_RETRY_HOUR=04
BILLING_MONTHLY_DISPATCH_HOUR=03
BILLING_CREDIT_LIMIT_WARNING_PCT=80
BILLING_DEFAULT_INCLUDED_BALANCE_CENTS=0
BILLING_MIGRATION_PRICE_CENTS=15
BILLING_BRL_ENABLED=true
```

- [ ] **Step 3: Verify config loads**

```bash
cd c:/xampp/htdocs/new_saas/backend
php artisan config:clear
php artisan tinker --execute="echo json_encode(config('billing'), JSON_PRETTY_PRINT);"
```
Expected: prints all 7 keys with the values above.

- [ ] **Step 4: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/config/billing.php new_saas/backend/.env.example new_saas/backend/.env
git commit -m "feat(billing): config + .env defaults

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.2: Migration — service_prices

**Files:**
- Create: `backend/database/migrations/2026_05_27_000001_create_service_prices_table.php`
- Create: `backend/database/seeders/ServicePricesSeeder.php`

- [ ] **Step 1: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('service_prices', function (Blueprint $t) {
            $t->id();
            $t->string('service', 50)->unique();
            $t->unsignedInteger('cost_cents')->default(0);
            $t->unsignedInteger('sale_cents')->default(0);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
```

- [ ] **Step 2: Seeder**

```php
<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServicePricesSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            ['service' => 'sms',           'cost_cents' => 8,  'sale_cents' => 15],
            ['service' => 'voice',         'cost_cents' => 40, 'sale_cents' => 80],
            ['service' => 'email',         'cost_cents' => 2,  'sale_cents' => 5],
            ['service' => 'ai_generation', 'cost_cents' => 10, 'sale_cents' => 25],
            ['service' => 'audio_tts',     'cost_cents' => 30, 'sale_cents' => 60],
        ];
        foreach ($rows as $row) {
            DB::table('service_prices')->updateOrInsert(
                ['service' => $row['service']],
                array_merge($row, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
```

- [ ] **Step 3: Run migration + seed**

```bash
cd c:/xampp/htdocs/new_saas/backend
php artisan migrate
php artisan db:seed --class=ServicePricesSeeder
```
Expected: `service_prices` table created with 5 rows.

- [ ] **Step 4: Verify**

```bash
php artisan tinker --execute="echo json_encode(DB::table('service_prices')->get(), JSON_PRETTY_PRINT);"
```
Expected: 5 rows printed.

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/database/migrations/2026_05_27_000001_create_service_prices_table.php new_saas/backend/database/seeders/ServicePricesSeeder.php
git commit -m "feat(billing): service_prices table + seed

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.3: Migration — tenant_service_prices

**Files:**
- Create: `backend/database/migrations/2026_05_27_000002_create_tenant_service_prices_table.php`

- [ ] **Step 1: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenant_service_prices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('service', 50);
            $t->unsignedInteger('sale_cents');
            $t->string('reason', 200)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();

            $t->unique(['tenant_id', 'service'], 'tsp_tenant_service_unique');
            $t->index(['tenant_id'], 'tsp_tenant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_service_prices');
    }
};
```

- [ ] **Step 2: Run + commit**

```bash
cd c:/xampp/htdocs/new_saas/backend && php artisan migrate
cd c:/xampp/htdocs
git add new_saas/backend/database/migrations/2026_05_27_000002_create_tenant_service_prices_table.php
git commit -m "feat(billing): tenant_service_prices table for per-tenant override

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.4: Migration — alter tenants (data-aware)

**Files:**
- Create: `backend/database/migrations/2026_05_27_000003_alter_tenants_for_brl_billing.php`

- [ ] **Step 1: Migration with data conversion**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('tenants', function (Blueprint $t) {
            $t->bigInteger('balance_cents')->default(0);
            $t->bigInteger('credit_limit_cents')->default(0);
            $t->enum('billing_status', ['active','grace','suspended','blocked'])->default('active');
            $t->unsignedTinyInteger('billing_cycle_day')->nullable();
            $t->timestamp('last_billing_at')->nullable();
            $t->timestamp('overdue_since')->nullable();
            $t->unsignedTinyInteger('overdue_attempts')->default(0);
            $t->string('mp_customer_id', 64)->nullable();
            $t->string('mp_default_card_id', 64)->nullable();
        });

        // Data migration: credits_balance * price_cents → balance_cents
        DB::statement(
            "UPDATE tenants SET balance_cents = COALESCE(credits_balance, 0) * ?",
            [$priceCents]
        );

        // billing_cycle_day = LEAST(DAY(created_at), 28)
        DB::statement("UPDATE tenants SET billing_cycle_day = LEAST(DAY(created_at), 28)");

        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn('credits_balance');
        });
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('tenants', function (Blueprint $t) {
            $t->integer('credits_balance')->default(0);
        });

        DB::statement(
            "UPDATE tenants SET credits_balance = FLOOR(balance_cents / ?)",
            [$priceCents]
        );

        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn([
                'balance_cents','credit_limit_cents','billing_status',
                'billing_cycle_day','last_billing_at','overdue_since','overdue_attempts',
                'mp_customer_id','mp_default_card_id',
            ]);
        });
    }
};
```

- [ ] **Step 2: Run + verify**

```bash
cd c:/xampp/htdocs/new_saas/backend && php artisan migrate
php artisan tinker --execute="echo json_encode(DB::table('tenants')->select('id','name','balance_cents','credit_limit_cents','billing_status','billing_cycle_day')->limit(5)->get(), JSON_PRETTY_PRINT);"
```
Expected: 5 tenants printed, `balance_cents` = credits_balance * 15.

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/database/migrations/2026_05_27_000003_alter_tenants_for_brl_billing.php
git commit -m "feat(billing): alter tenants — drop credits_balance, add balance_cents + billing fields

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.5: Migration — alter plans (data-aware)

**Files:**
- Create: `backend/database/migrations/2026_05_27_000004_alter_plans_for_brl_billing.php`

- [ ] **Step 1: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('plans', function (Blueprint $t) {
            $t->bigInteger('included_balance_cents')->default(0);
            $t->json('sale_cents_overrides')->nullable();
        });

        // Data migration: credits_included * price_cents → included_balance_cents
        DB::statement(
            "UPDATE plans SET included_balance_cents = COALESCE(credits_included, 0) * ?",
            [$priceCents]
        );

        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn([
                'credits_included',
                'overage_rate_sms',
                'overage_rate_voice',
                'overage_rate_email',
                'overage_rate_ai',
                'credits_per_sms_override',
                'credits_per_voice_override',
                'credits_per_email_override',
            ]);
        });
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('plans', function (Blueprint $t) {
            $t->integer('credits_included')->default(0);
            $t->decimal('overage_rate_sms', 8, 4)->nullable();
            $t->decimal('overage_rate_voice', 8, 4)->nullable();
            $t->decimal('overage_rate_email', 8, 4)->nullable();
            $t->decimal('overage_rate_ai', 8, 4)->nullable();
            $t->unsignedInteger('credits_per_sms_override')->nullable();
            $t->unsignedInteger('credits_per_voice_override')->nullable();
            $t->unsignedInteger('credits_per_email_override')->nullable();
        });

        DB::statement(
            "UPDATE plans SET credits_included = FLOOR(included_balance_cents / ?)",
            [$priceCents]
        );

        Schema::table('plans', function (Blueprint $t) {
            $t->dropColumn(['included_balance_cents', 'sale_cents_overrides']);
        });
    }
};
```

- [ ] **Step 2: Run + commit**

```bash
cd c:/xampp/htdocs/new_saas/backend && php artisan migrate
cd c:/xampp/htdocs
git add new_saas/backend/database/migrations/2026_05_27_000004_alter_plans_for_brl_billing.php
git commit -m "feat(billing): alter plans — drop credits_*, add included_balance_cents + sale_cents_overrides

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.6: Migration — alter message_dispatches (data-aware)

**Files:**
- Create: `backend/database/migrations/2026_05_27_000005_alter_message_dispatches_for_brl.php`

- [ ] **Step 1: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);
        // Approximate cost as ~half of sale for legacy rows; new dispatches snapshot real values
        $costApprox = max(1, (int) floor($priceCents / 2));

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->unsignedInteger('cost_cents')->default(0);
            $t->unsignedInteger('sale_cents')->default(0);
            $t->unsignedInteger('charged_cents')->default(0);
        });

        // Data migration
        DB::statement(
            "UPDATE message_dispatches SET cost_cents = COALESCE(credits_unit, 0) * ?, sale_cents = COALESCE(credits_unit, 0) * ?, charged_cents = COALESCE(credits_charged, 0) * ?",
            [$costApprox, $priceCents, $priceCents]
        );

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn(['credits_unit', 'credits_charged']);
        });
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->unsignedInteger('credits_unit')->default(0);
            $t->unsignedInteger('credits_charged')->default(0);
        });

        DB::statement(
            "UPDATE message_dispatches SET credits_unit = FLOOR(sale_cents / ?), credits_charged = FLOOR(charged_cents / ?)",
            [$priceCents, $priceCents]
        );

        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn(['cost_cents', 'sale_cents', 'charged_cents']);
        });
    }
};
```

- [ ] **Step 2: Run + commit**

```bash
cd c:/xampp/htdocs/new_saas/backend && php artisan migrate
cd c:/xampp/htdocs
git add new_saas/backend/database/migrations/2026_05_27_000005_alter_message_dispatches_for_brl.php
git commit -m "feat(billing): alter message_dispatches — drop credits_*, add cost/sale/charged cents

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.7: Migration — rename credit_transactions → balance_transactions

**Files:**
- Create: `backend/database/migrations/2026_05_27_000006_rename_credit_transactions_to_balance_transactions.php`

- [ ] **Step 1: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        Schema::rename('credit_transactions', 'balance_transactions');

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->bigInteger('amount_cents')->default(0);
            $t->bigInteger('balance_after_cents')->default(0);
        });

        DB::statement(
            "UPDATE balance_transactions SET amount_cents = amount * ?, balance_after_cents = balance_after * ?",
            [$priceCents, $priceCents]
        );

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropColumn(['amount', 'balance_after']);
        });

        // Extend ENUM type — MySQL requires MODIFY COLUMN with full new ENUM list
        DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN type ENUM('debit','credit','reserve','release','recharge','monthly_charge','manual_adjustment') NOT NULL");
    }

    public function down(): void
    {
        $priceCents = (int) config('billing.migration_price_cents', 15);

        DB::statement("ALTER TABLE balance_transactions MODIFY COLUMN type ENUM('debit','credit','reserve','release') NOT NULL");

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->integer('amount')->default(0);
            $t->integer('balance_after')->default(0);
        });

        DB::statement(
            "UPDATE balance_transactions SET amount = FLOOR(amount_cents / ?), balance_after = FLOOR(balance_after_cents / ?)",
            [$priceCents, $priceCents]
        );

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropColumn(['amount_cents', 'balance_after_cents']);
        });

        Schema::rename('balance_transactions', 'credit_transactions');
    }
};
```

- [ ] **Step 2: Run + commit**

```bash
cd c:/xampp/htdocs/new_saas/backend && php artisan migrate
cd c:/xampp/htdocs
git add new_saas/backend/database/migrations/2026_05_27_000006_rename_credit_transactions_to_balance_transactions.php
git commit -m "feat(billing): rename credit_transactions → balance_transactions, add cents columns + new types

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.8: Migration — add finance role

**Files:**
- Create: `backend/database/migrations/2026_05_27_000007_add_finance_role_to_users.php`

- [ ] **Step 1: Migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','superadmin','finance') NOT NULL DEFAULT 'user'");
    }

    public function down(): void
    {
        DB::statement("UPDATE users SET role = 'admin' WHERE role = 'finance'");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('user','admin','superadmin') NOT NULL DEFAULT 'user'");
    }
};
```

- [ ] **Step 2: Run + commit**

```bash
cd c:/xampp/htdocs/new_saas/backend && php artisan migrate
cd c:/xampp/htdocs
git add new_saas/backend/database/migrations/2026_05_27_000007_add_finance_role_to_users.php
git commit -m "feat(billing): add finance role to users ENUM

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.9: Models — ServicePrice + TenantServicePrice + BalanceTransaction

**Files:**
- Create: `backend/app/Models/ServicePrice.php`
- Create: `backend/app/Models/TenantServicePrice.php`
- Create: `backend/app/Models/BalanceTransaction.php`
- Delete: `backend/app/Models/CreditTransaction.php`

- [ ] **Step 1: ServicePrice model**

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePrice extends Model
{
    protected $fillable = ['service', 'cost_cents', 'sale_cents', 'updated_by'];

    protected $casts = [
        'cost_cents' => 'integer',
        'sale_cents' => 'integer',
    ];

    public function marginCents(): int
    {
        return $this->sale_cents - $this->cost_cents;
    }

    public function marginPercent(): float
    {
        return $this->cost_cents > 0
            ? round(($this->marginCents() / $this->cost_cents) * 100, 2)
            : 0.0;
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
```

- [ ] **Step 2: TenantServicePrice model**

```php
<?php
namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantServicePrice extends Model
{
    use AppliesTenantScope;

    protected $fillable = ['tenant_id', 'service', 'sale_cents', 'reason', 'created_by'];

    protected $casts = [
        'sale_cents' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 3: BalanceTransaction (replace CreditTransaction)**

Read existing `CreditTransaction.php` first to mirror its conventions:
```bash
cat backend/app/Models/CreditTransaction.php
```

Create:
```php
<?php
namespace App\Models;

use App\Models\Traits\AppliesTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceTransaction extends Model
{
    use AppliesTenantScope;

    protected $fillable = [
        'tenant_id', 'type', 'amount_cents', 'balance_after_cents',
        'reference_type', 'reference_id', 'description', 'meta',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'balance_after_cents' => 'integer',
        'meta' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

Delete the old model:
```bash
rm backend/app/Models/CreditTransaction.php
```

- [ ] **Step 4: Replace usages of CreditTransaction across the codebase**

```bash
cd c:/xampp/htdocs/new_saas/backend
grep -rln "CreditTransaction\|credit_transactions" app/ tests/ | head -20
```
For each file: change `\App\Models\CreditTransaction::` → `\App\Models\BalanceTransaction::`, change `DB::table('credit_transactions')` → `DB::table('balance_transactions')`. Field renames: `amount` → `amount_cents`, `balance_after` → `balance_after_cents`.

- [ ] **Step 5: Run tests to confirm nothing critical broke**

```bash
php artisan test 2>&1 | tail -10
```
Expect: some tests may fail because tests still reference credits. Acceptable for now — they'll be fixed by Phase 2.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Models/ServicePrice.php new_saas/backend/app/Models/TenantServicePrice.php new_saas/backend/app/Models/BalanceTransaction.php
git rm new_saas/backend/app/Models/CreditTransaction.php
git add new_saas/backend/app/ new_saas/backend/tests/  # updated references
git commit -m "feat(billing): ServicePrice, TenantServicePrice, BalanceTransaction models; remove CreditTransaction

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.10: Update Tenant, Plan, MessageDispatch models

**Files:**
- Modify: `backend/app/Models/Tenant.php`
- Modify: `backend/app/Models/Plan.php`
- Modify: `backend/app/Models/MessageDispatch.php`

- [ ] **Step 1: Tenant — append fillable + casts + helper methods**

In `backend/app/Models/Tenant.php`:
- Remove `credits_balance` from `$fillable` and `$casts`
- Add to `$fillable`:
```
'balance_cents', 'credit_limit_cents', 'billing_status',
'billing_cycle_day', 'last_billing_at', 'overdue_since', 'overdue_attempts',
'mp_customer_id', 'mp_default_card_id',
```
- Add to `$casts`:
```php
'balance_cents'      => 'integer',
'credit_limit_cents' => 'integer',
'billing_cycle_day'  => 'integer',
'overdue_attempts'   => 'integer',
'last_billing_at'    => 'datetime',
'overdue_since'      => 'datetime',
```
- Add helper methods:
```php
public function availableBalanceCents(): int
{
    return $this->balance_cents + $this->credit_limit_cents;
}

public function isBillingBlocked(): bool
{
    return in_array($this->billing_status, ['suspended', 'blocked'], true);
}

public function balanceTransactions()
{
    return $this->hasMany(BalanceTransaction::class);
}
```

- [ ] **Step 2: Plan — update fillable + casts**

Remove credits_* fields from `$fillable` and `$casts`. Add:
```php
'included_balance_cents', 'sale_cents_overrides',
```
to fillable, and:
```php
'included_balance_cents' => 'integer',
'sale_cents_overrides'   => 'array',
```
to casts.

- [ ] **Step 3: MessageDispatch — update fillable + casts**

Remove `credits_unit`, `credits_charged` from `$fillable` and `$casts`. Add:
```php
'cost_cents', 'sale_cents', 'charged_cents',
```
to fillable, and:
```php
'cost_cents'    => 'integer',
'sale_cents'    => 'integer',
'charged_cents' => 'integer',
```
to casts.

- [ ] **Step 4: Update factories**

`backend/database/factories/TenantFactory.php`:
- Replace `'credits_balance' => fake()->numberBetween(...)` with `'balance_cents' => fake()->numberBetween(0, 100000)` and `'billing_cycle_day' => fake()->numberBetween(1, 28)`.

`backend/database/factories/PlanFactory.php`:
- Replace `'credits_included' => ...` with `'included_balance_cents' => fake()->numberBetween(0, 50000)`.

`backend/database/factories/MessageDispatchFactory.php`:
- Replace `'credits_unit' => 1, 'credits_charged' => 0` with `'cost_cents' => 8, 'sale_cents' => 15, 'charged_cents' => 0`.

- [ ] **Step 5: Verify**

```bash
cd c:/xampp/htdocs/new_saas/backend
php artisan tinker --execute="echo json_encode(\App\Models\Tenant::factory()->make()->toArray(), JSON_PRETTY_PRINT);"
```
Expected: shows new fields with values, no credits_balance.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Models/Tenant.php new_saas/backend/app/Models/Plan.php new_saas/backend/app/Models/MessageDispatch.php new_saas/backend/database/factories/
git commit -m "feat(billing): update Tenant/Plan/MessageDispatch models + factories for cents fields

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 1.11: PM Gate Phase 1

- [ ] **Step 1: Show diff and migration status**

```bash
cd c:/xampp/htdocs
git log --oneline master..feat/brl-billing
git diff master..HEAD --stat
cd c:/xampp/htdocs/new_saas/backend
php artisan migrate:status | findstr 2026_05_27
```

- [ ] **Step 2: PM confirms with user**: "Phase 1 complete — config + 7 migrations + 3 new models + 3 altered models + 3 factories. 11 commits. Proceed to Phase 2?"

---

## Phase 2 — BillingService + PricingService + Exceptions

### Task 2.1: Billing exceptions

**Files:**
- Create: `backend/app/Exceptions/Billing/InsufficientFundsException.php`
- Create: `backend/app/Exceptions/Billing/CreditLimitReachedException.php`
- Create: `backend/app/Exceptions/Billing/BillingSuspendedException.php`
- Create: `backend/app/Exceptions/Billing/BillingBlockedException.php`

- [ ] **Step 1: Create all 4 exception classes**

```php
<?php
namespace App\Exceptions\Billing;

class InsufficientFundsException extends \RuntimeException
{
    public function __construct(
        public readonly int $required_cents,
        public readonly int $available_cents
    ) {
        parent::__construct("Insufficient funds: required {$required_cents}c, available {$available_cents}c");
    }
}
```

Repeat the pattern for `CreditLimitReachedException`, `BillingSuspendedException`, `BillingBlockedException` — all extend `\RuntimeException`. `BillingSuspendedException` and `BillingBlockedException` take no constructor args.

- [ ] **Step 2: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Exceptions/Billing/
git commit -m "feat(billing): 4 domain exceptions (insufficient funds, credit limit, suspended, blocked)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.2: PricingService (TDD)

**Files:**
- Create: `backend/app/Services/Billing/PricingService.php`
- Create: `backend/tests/Unit/Billing/PricingServiceTest.php`
- Delete: `backend/app/Services/Messaging/PricingService.php` (legacy)
- Delete: `backend/tests/Unit/Messaging/PricingServiceTest.php` (legacy)

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Billing;

use App\Models\Plan;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use App\Models\User;
use App\Services\Billing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ServicePrice::create(['service'=>'sms','cost_cents'=>8,'sale_cents'=>15]);
        ServicePrice::create(['service'=>'voice','cost_cents'=>40,'sale_cents'=>80]);
    }

    public function test_uses_global_when_no_override(): void
    {
        $tenant = Tenant::factory()->create();
        $svc = app(PricingService::class);
        $price = $svc->priceFor($tenant, 'sms');
        $this->assertEquals(8, $price['cost_cents']);
        $this->assertEquals(15, $price['sale_cents']);
    }

    public function test_plan_override_wins_over_global(): void
    {
        $plan = Plan::factory()->create(['sale_cents_overrides' => ['sms' => 12]]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $svc = app(PricingService::class);
        $price = $svc->priceFor($tenant, 'sms');
        $this->assertEquals(12, $price['sale_cents']);
        $this->assertEquals(8, $price['cost_cents']); // cost from global
    }

    public function test_tenant_override_wins_over_plan_and_global(): void
    {
        $plan = Plan::factory()->create(['sale_cents_overrides' => ['sms' => 12]]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        TenantServicePrice::create([
            'tenant_id' => $tenant->id, 'service' => 'sms',
            'sale_cents' => 10, 'reason' => 'VIP', 'created_by' => $user->id,
        ]);

        $svc = app(PricingService::class);
        $price = $svc->priceFor($tenant, 'sms');
        $this->assertEquals(10, $price['sale_cents']);
    }

    public function test_throws_for_unknown_service(): void
    {
        $tenant = Tenant::factory()->create();
        $this->expectException(\InvalidArgumentException::class);
        app(PricingService::class)->priceFor($tenant, 'unknown_svc');
    }
}
```

Run: `php artisan test --filter=Tests\\Unit\\Billing\\PricingServiceTest` — expect FAIL (class missing).

- [ ] **Step 2: Implement PricingService**

```php
<?php
namespace App\Services\Billing;

use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use Illuminate\Support\Facades\Cache;

class PricingService
{
    public function priceFor(Tenant $tenant, string $service): array
    {
        $global = $this->globalPrice($service);
        if (! $global) {
            throw new \InvalidArgumentException("Unknown service: {$service}");
        }

        $costCents = (int) $global->cost_cents;
        $saleCents = (int) $global->sale_cents;

        // Plan-level override
        $plan = $tenant->plan ?? null;
        if ($plan && is_array($plan->sale_cents_overrides ?? null)) {
            if (isset($plan->sale_cents_overrides[$service])) {
                $saleCents = (int) $plan->sale_cents_overrides[$service];
            }
        }

        // Tenant-level override (highest precedence)
        $override = TenantServicePrice::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('service', $service)
            ->first();
        if ($override) {
            $saleCents = (int) $override->sale_cents;
        }

        return [
            'cost_cents' => $costCents,
            'sale_cents' => $saleCents,
            'source'     => $override ? 'tenant_override' : ($plan && isset($plan->sale_cents_overrides[$service]) ? 'plan_override' : 'global'),
        ];
    }

    private function globalPrice(string $service): ?ServicePrice
    {
        return Cache::remember(
            "service_price:{$service}",
            now()->addMinutes(5),
            fn() => ServicePrice::where('service', $service)->first()
        );
    }
}
```

- [ ] **Step 3: Run test → PASS**

```bash
php artisan test --filter=Tests\\Unit\\Billing\\PricingServiceTest
```

- [ ] **Step 4: Replace messaging PricingService callers**

```bash
cd c:/xampp/htdocs/new_saas/backend
grep -rln "App\\\\Services\\\\Messaging\\\\PricingService" app/ tests/ | head
```
Update each to use `App\Services\Billing\PricingService` and change `unitFor($tenant, $channel)` to `priceFor($tenant, $service)` (returning array).

Delete legacy files:
```bash
rm app/Services/Messaging/PricingService.php
rm tests/Unit/Messaging/PricingServiceTest.php
```

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Services/Billing/PricingService.php new_saas/backend/tests/Unit/Billing/PricingServiceTest.php
git rm new_saas/backend/app/Services/Messaging/PricingService.php new_saas/backend/tests/Unit/Messaging/PricingServiceTest.php
git add new_saas/backend/  # files updated to use new service
git commit -m "feat(billing): PricingService with 3-level resolution (override > plan > global)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.3: BillingService (TDD)

**Files:**
- Create: `backend/app/Services/Billing/BillingService.php`
- Create: `backend/tests/Unit/Billing/BillingServiceTest.php`
- Delete: `backend/app/Services/Billing/CreditService.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Billing;

use App\Exceptions\Billing\InsufficientFundsException;
use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserve_decrements_balance_and_records_transaction(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 1000]);
        $svc = app(BillingService::class);

        $ok = $svc->reserve($tenant->id, 150, 'message_dispatch', 1);
        $this->assertTrue($ok);
        $this->assertEquals(850, $tenant->fresh()->balance_cents);
        $this->assertDatabaseHas('balance_transactions', [
            'tenant_id' => $tenant->id, 'type' => 'reserve', 'amount_cents' => -150,
        ]);
    }

    public function test_reserve_respects_credit_limit(): void
    {
        $tenant = Tenant::factory()->create([
            'balance_cents' => 100, 'credit_limit_cents' => 500,
        ]);
        $svc = app(BillingService::class);

        // 100 + 500 = 600 available; reserving 600 should succeed
        $this->assertTrue($svc->reserve($tenant->id, 600, 'msg', 1));
        $this->assertEquals(-500, $tenant->fresh()->balance_cents);

        // Reserving 1 more should fail
        $this->assertFalse($svc->reserve($tenant->id, 1, 'msg', 2));
    }

    public function test_reserve_throws_for_insufficient(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 50, 'credit_limit_cents' => 0]);
        $svc = app(BillingService::class);
        $this->assertFalse($svc->reserve($tenant->id, 100, 'msg', 1));
        $this->assertEquals(50, $tenant->fresh()->balance_cents);
    }

    public function test_release_restores_balance(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 850]);
        // Simulate a prior reserve
        BalanceTransaction::create([
            'tenant_id' => $tenant->id, 'type' => 'reserve',
            'amount_cents' => -150, 'balance_after_cents' => 850,
            'reference_type' => 'msg', 'reference_id' => 1,
        ]);

        $svc = app(BillingService::class);
        $svc->release($tenant->id, 150, 'msg', 1);

        $this->assertEquals(1000, $tenant->fresh()->balance_cents);
        $this->assertDatabaseHas('balance_transactions', [
            'tenant_id' => $tenant->id, 'type' => 'release', 'amount_cents' => 150,
        ]);
    }

    public function test_recharge_adds_balance(): void
    {
        $tenant = Tenant::factory()->create(['balance_cents' => 100]);
        $svc = app(BillingService::class);
        $svc->recharge($tenant->id, 5000, 'mp_payment', 12345, 'MP recharge');
        $this->assertEquals(5100, $tenant->fresh()->balance_cents);
    }
}
```

Run: expect FAIL.

- [ ] **Step 2: Implement BillingService**

```php
<?php
namespace App\Services\Billing;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingService
{
    /**
     * Reserve cents from tenant balance. Respects credit_limit_cents (can go negative).
     * Returns true on success, false if would exceed limit.
     */
    public function reserve(int $tenantId, int $amountCents, string $referenceType, int $referenceId): bool
    {
        return DB::transaction(function () use ($tenantId, $amountCents, $referenceType, $referenceId) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) return false;

            $available = $tenant->balance_cents + $tenant->credit_limit_cents;
            if ($available < $amountCents) {
                return false;
            }

            $tenant->decrement('balance_cents', $amountCents);
            $tenant->refresh();

            BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'reserve',
                'amount_cents'        => -$amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'description'         => "Reserve {$referenceType} #{$referenceId}",
            ]);

            Log::channel('campaign')->info('billing.reserve', compact('tenantId', 'amountCents', 'referenceType', 'referenceId'));
            return true;
        });
    }

    public function release(int $tenantId, int $amountCents, string $referenceType, int $referenceId): void
    {
        if ($amountCents <= 0) return;

        DB::transaction(function () use ($tenantId, $amountCents, $referenceType, $referenceId) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) return;

            $tenant->increment('balance_cents', $amountCents);
            $tenant->refresh();

            BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'release',
                'amount_cents'        => $amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'description'         => "Release {$referenceType} #{$referenceId}",
            ]);
        });
    }

    public function recharge(int $tenantId, int $amountCents, string $referenceType, int $referenceId, string $description = ''): void
    {
        DB::transaction(function () use ($tenantId, $amountCents, $referenceType, $referenceId, $description) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) return;

            $tenant->increment('balance_cents', $amountCents);
            $tenant->refresh();

            BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'recharge',
                'amount_cents'        => $amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => $referenceType,
                'reference_id'        => $referenceId,
                'description'         => $description ?: "Recharge {$referenceType} #{$referenceId}",
            ]);
        });
    }

    public function manualAdjustment(int $tenantId, int $amountCents, string $reason, int $adminUserId): BalanceTransaction
    {
        return DB::transaction(function () use ($tenantId, $amountCents, $reason, $adminUserId) {
            $tenant = Tenant::lockForUpdate()->findOrFail($tenantId);
            $tenant->increment('balance_cents', $amountCents);
            $tenant->refresh();

            return BalanceTransaction::create([
                'tenant_id'           => $tenantId,
                'type'                => 'manual_adjustment',
                'amount_cents'        => $amountCents,
                'balance_after_cents' => $tenant->balance_cents,
                'reference_type'      => 'admin_action',
                'reference_id'        => $adminUserId,
                'description'         => $reason,
            ]);
        });
    }
}
```

- [ ] **Step 3: Run test → PASS**

```bash
php artisan test --filter=Tests\\Unit\\Billing\\BillingServiceTest
```

- [ ] **Step 4: Replace CreditService usages**

```bash
cd c:/xampp/htdocs/new_saas/backend
grep -rln "CreditService" app/ tests/ | head
```
For each: `App\Services\Billing\CreditService` → `App\Services\Billing\BillingService`. Method renames:
- `reserve(tenantId, amount, ref, id)` → same signature, amount is now in cents
- `release(...)` → same
- `deduct(...)` → REMOVED (no longer needed; confirm is implicit after reserve)
- `debit(...)` → `manualAdjustment(...)` with negative amount
- `checkBalance(...)` → use `$tenant->availableBalanceCents()` directly
- `lockCampaignIfInsufficient(...)` → keep but rewrite to use cents

Delete old:
```bash
rm app/Services/Billing/CreditService.php
```

- [ ] **Step 5: Run full suite — expect some failures still (tests use old fields)**

```bash
php artisan test 2>&1 | tail -10
```
Note remaining failures; they'll be fixed by test updates in Phase 7.

- [ ] **Step 6: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Services/Billing/BillingService.php new_saas/backend/tests/Unit/Billing/BillingServiceTest.php
git rm new_saas/backend/app/Services/Billing/CreditService.php
git add new_saas/backend/
git commit -m "feat(billing): BillingService replaces CreditService, cents-based with credit_limit

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.4: Update MessagingService + SendMessageJob to use BillingService

**Files:**
- Modify: `backend/app/Services/Messaging/MessagingService.php`
- Modify: `backend/app/Jobs/SendMessageJob.php`

- [ ] **Step 1: MessagingService changes**

In `MessagingService::dispatch()`:
- Replace `PricingService::unitFor(tenant, channel)` call with `PricingService::priceFor(tenant, channel)` returning array.
- Use `$price['sale_cents']` as the amount to reserve.
- Store `cost_cents` and `sale_cents` on the dispatch row (snapshot at send time).
- Replace `CreditService::reserve(tenantId, unit, ...)` → `BillingService::reserve(tenantId, sale_cents, ...)`.
- Insufficient funds branch now throws `InsufficientFundsException(required, available)`.
- Add billing_status check before reserve: if `$tenant->billing_status === 'suspended'` throw `BillingSuspendedException`; if `'blocked'` throw `BillingBlockedException`.

- [ ] **Step 2: SendMessageJob changes**

In `handle()`:
- On success: `$dispatch->update(['status' => 'sent', 'charged_cents' => $dispatch->sale_cents, ...])` (was `credits_charged = credits_unit`).
- On definitive failure: `BillingService::release($dispatch->tenant_id, $dispatch->sale_cents, 'message_dispatch', $dispatch->id)`.

- [ ] **Step 3: Update controllers to map new exceptions**

In `SmsController`, `VoiceController`, `EmailController` (`backend/app/Http/Controllers/API/V1/Messaging/`):
```php
} catch (\App\Exceptions\Billing\InsufficientFundsException $e) {
    return response()->json([
        'error'     => 'INSUFFICIENT_FUNDS',
        'required'  => $e->required_cents,
        'available' => $e->available_cents,
    ], 402);
} catch (\App\Exceptions\Billing\BillingSuspendedException) {
    return response()->json(['error' => 'BILLING_SUSPENDED'], 402);
} catch (\App\Exceptions\Billing\BillingBlockedException) {
    return response()->json(['error' => 'BILLING_BLOCKED'], 402);
}
```
Remove old `InsufficientCreditsException` handler (or keep as no-op for backward compat, then remove).

- [ ] **Step 4: Run messaging tests**

```bash
php artisan test --filter="Messaging|SendMessage"
```
Fix any test failures by adjusting assertions to use cents.

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Services/Messaging/MessagingService.php new_saas/backend/app/Jobs/SendMessageJob.php new_saas/backend/app/Http/Controllers/API/V1/Messaging/
git commit -m "feat(billing): MessagingService + SendMessageJob use BillingService (cents)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 2.5: PM Gate Phase 2

- [ ] PM confirms: "Phase 2 complete — BillingService + PricingService + 4 exceptions + MessagingService/SendMessageJob refactored. Proceed to Phase 3?"

---

## Phase 3 — Monthly Billing Job + Overdue Retry + MP Stored Card + Emails

### Task 3.1: MercadoPagoService::chargeStoredCard + createTokenFromStoredCard

**Files:**
- Modify: `backend/app/Services/MercadoPagoService.php`

- [ ] **Step 1: Read existing MercadoPagoService**

```bash
cat backend/app/Services/MercadoPagoService.php | head -50
```
Understand the existing HTTP client setup pattern.

- [ ] **Step 2: Add 2 new methods**

```php
/**
 * Create a one-time card_token from a stored customer card.
 * Required by /v1/payments when paying with a saved card.
 */
public function createTokenFromStoredCard(string $customerId, string $cardId): ?string
{
    $resp = $this->client()->post('/v1/card_tokens', [
        'customer_id' => $customerId,
        'card_id'     => $cardId,
    ]);
    if (! $resp->successful()) {
        \Log::channel('mp')->warning('mp.card_token_failed', [
            'customer_id' => $customerId, 'card_id' => $cardId,
            'status' => $resp->status(), 'body' => $resp->body(),
        ]);
        return null;
    }
    return $resp->json('id');
}

/**
 * Charge a tenant's stored card without user interaction.
 * Returns array {ok, mp_payment_id, mp_status, error}.
 */
public function chargeStoredCard(\App\Models\Tenant $tenant, int $amountCents, string $description): array
{
    if (! $tenant->mp_customer_id || ! $tenant->mp_default_card_id) {
        return ['ok' => false, 'error' => 'NO_STORED_CARD', 'mp_payment_id' => null];
    }

    $token = $this->createTokenFromStoredCard($tenant->mp_customer_id, $tenant->mp_default_card_id);
    if (! $token) {
        return ['ok' => false, 'error' => 'CARD_TOKEN_FAILED', 'mp_payment_id' => null];
    }

    $externalRef = 'monthly:' . $tenant->id . ':' . now()->format('Ym');
    $resp = $this->client()->post('/v1/payments', [
        'transaction_amount' => $amountCents / 100,
        'description'        => $description,
        'payment_method_id'  => 'credit_card',
        'payer'              => ['type' => 'customer', 'id' => $tenant->mp_customer_id],
        'token'              => $token,
        'installments'       => 1,
        'external_reference' => $externalRef,
        'notification_url'   => url('/api/v1/webhooks/mercadopago'),
        'metadata'           => ['tenant_id' => $tenant->id, 'billing_kind' => $description],
    ]);

    if (! $resp->successful()) {
        return [
            'ok' => false,
            'error' => $resp->json('message') ?? "MP_API_HTTP_{$resp->status()}",
            'mp_payment_id' => null,
        ];
    }

    $body = $resp->json();
    return [
        'ok'            => $body['status'] === 'approved',
        'mp_payment_id' => $body['id'],
        'mp_status'     => $body['status'],
        'error'         => $body['status'] === 'approved' ? null : ($body['status_detail'] ?? 'rejected'),
    ];
}
```

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Services/MercadoPagoService.php
git commit -m "feat(billing): MercadoPagoService.chargeStoredCard + createTokenFromStoredCard

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.2: Email notification templates

**Files:**
- Create: `backend/resources/views/emails/billing/monthly-success.blade.php`
- Create: `backend/resources/views/emails/billing/overdue-warning-day1.blade.php`
- Create: `backend/resources/views/emails/billing/overdue-warning-retry.blade.php`
- Create: `backend/resources/views/emails/billing/overdue-final.blade.php`
- Create: `backend/resources/views/emails/billing/tenant-suspended.blade.php`
- Create: `backend/resources/views/emails/billing/card-missing.blade.php`
- Create: `backend/resources/views/emails/billing/card-expired.blade.php`

- [ ] **Step 1: Inspect existing email layout**

```bash
ls backend/resources/views/emails/
```
Find any existing template and mirror its structure (subject + body conventions).

- [ ] **Step 2: Create all 7 templates**

Each follows the pattern:
```blade
@component('mail::message')
# Cobrança mensal realizada

Olá {{ $tenant->name }},

Sua cobrança mensal foi processada com sucesso.

- **Valor cobrado:** R$ {{ number_format($amountCents / 100, 2, ',', '.') }}
- **Saldo após cobrança:** R$ {{ number_format($newBalanceCents / 100, 2, ',', '.') }}

@component('mail::button', ['url' => $statementUrl])
Ver extrato completo
@endcomponent

Atenciosamente,<br>
{{ config('business.brand_name') }}
@endcomponent
```

For each template, customize subject + body per the spec section 6.4 table. Use `mail::message` and `mail::button` from Laravel notification system.

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/resources/views/emails/billing/
git commit -m "feat(billing): 7 email templates (success, warnings, suspended, card states)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.3: Notification classes

**Files:**
- Create: `backend/app/Notifications/Billing/MonthlySuccessNotification.php`
- Create: `backend/app/Notifications/Billing/OverdueWarningNotification.php` (handles day1/retry/final via constructor param)
- Create: `backend/app/Notifications/Billing/TenantSuspendedNotification.php`
- Create: `backend/app/Notifications/Billing/CardMissingNotification.php`
- Create: `backend/app/Notifications/Billing/CardExpiredNotification.php`

- [ ] **Step 1: Create each Notification class extending `Illuminate\Notifications\Notification`**

Pattern:
```php
<?php
namespace App\Notifications\Billing;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MonthlySuccessNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Tenant $tenant,
        public int $amountCents,
        public int $newBalanceCents,
    ) {}

    public function via($notifiable): array { return ['mail']; }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Cobrança mensal realizada — ' . config('business.brand_name'))
            ->markdown('emails.billing.monthly-success', [
                'tenant' => $this->tenant,
                'amountCents' => $this->amountCents,
                'newBalanceCents' => $this->newBalanceCents,
                'statementUrl' => url('/settings/saldo'),
            ]);
    }
}
```

For `OverdueWarningNotification`, constructor takes `$dayOfGrace` and picks the template accordingly:
```php
$template = match (true) {
    $this->dayOfGrace === 1 => 'emails.billing.overdue-warning-day1',
    $this->dayOfGrace >= 7 => 'emails.billing.overdue-final',
    default => 'emails.billing.overdue-warning-retry',
};
```

- [ ] **Step 2: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Notifications/Billing/
git commit -m "feat(billing): 5 notification classes for billing emails

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.4: MonthlyBillingJob (TDD)

**Files:**
- Create: `backend/app/Jobs/MonthlyBillingJob.php`
- Create: `backend/app/Jobs/DispatchMonthlyBillingJob.php`
- Create: `backend/tests/Unit/Jobs/MonthlyBillingJobTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Jobs;

use App\Jobs\MonthlyBillingJob;
use App\Models\BalanceTransaction;
use App\Models\Plan;
use App\Models\Tenant;
use App\Notifications\Billing\MonthlySuccessNotification;
use App\Notifications\Billing\OverdueWarningNotification;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MonthlyBillingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_credits_plan_included_balance(): void
    {
        $plan = Plan::factory()->create(['included_balance_cents' => 5000]);
        $tenant = Tenant::factory()->create([
            'plan_id' => $plan->id, 'balance_cents' => 100,
            'billing_cycle_day' => now()->day,
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldNotReceive('chargeStoredCard');

        (new MonthlyBillingJob($tenant->id))->handle($mp);

        $this->assertEquals(5100, $tenant->fresh()->balance_cents);
        $this->assertDatabaseHas('balance_transactions', [
            'tenant_id' => $tenant->id, 'type' => 'credit', 'amount_cents' => 5000,
        ]);
    }

    public function test_charges_card_when_negative(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create([
            'balance_cents' => -500,
            'mp_customer_id' => 'cust_1', 'mp_default_card_id' => 'card_1',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldReceive('chargeStoredCard')->once()
           ->andReturn(['ok' => true, 'mp_payment_id' => 'pay_xyz', 'error' => null]);

        (new MonthlyBillingJob($tenant->id))->handle($mp);

        $this->assertEquals(0, $tenant->fresh()->balance_cents);
        $this->assertEquals('active', $tenant->fresh()->billing_status);
    }

    public function test_enters_grace_on_card_rejection(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create([
            'balance_cents' => -500,
            'mp_customer_id' => 'cust_1', 'mp_default_card_id' => 'card_1',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldReceive('chargeStoredCard')->once()
           ->andReturn(['ok' => false, 'error' => 'rejected', 'mp_payment_id' => null]);

        (new MonthlyBillingJob($tenant->id))->handle($mp);

        $tenant->refresh();
        $this->assertEquals('grace', $tenant->billing_status);
        $this->assertNotNull($tenant->overdue_since);
        $this->assertEquals(1, $tenant->overdue_attempts);
        Notification::assertSentTo($tenant, OverdueWarningNotification::class);
    }
}
```

Run: FAIL.

- [ ] **Step 2: Implement MonthlyBillingJob**

```php
<?php
namespace App\Jobs;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Notifications\Billing\CardMissingNotification;
use App\Notifications\Billing\MonthlySuccessNotification;
use App\Notifications\Billing\OverdueWarningNotification;
use App\Services\MercadoPagoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonthlyBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 600, 3600];

    public function __construct(public int $tenantId)
    {
        $this->onQueue('billing');
    }

    public function handle(MercadoPagoService $mp): void
    {
        DB::transaction(function () use ($mp) {
            $tenant = Tenant::lockForUpdate()->find($this->tenantId);
            if (! $tenant) return;

            // 1) Credit included balance from plan
            $plan = $tenant->plan;
            if ($plan && $plan->included_balance_cents > 0) {
                $tenant->increment('balance_cents', $plan->included_balance_cents);
                $tenant->refresh();
                BalanceTransaction::create([
                    'tenant_id'           => $tenant->id,
                    'type'                => 'credit',
                    'amount_cents'        => $plan->included_balance_cents,
                    'balance_after_cents' => $tenant->balance_cents,
                    'reference_type'      => 'monthly_plan_credit',
                    'reference_id'        => $plan->id,
                    'description'         => 'Monthly plan credit',
                ]);
            }

            // 2) If still negative, charge stored card
            if ($tenant->balance_cents < 0) {
                $debt = abs($tenant->balance_cents);

                if (! $tenant->mp_customer_id || ! $tenant->mp_default_card_id) {
                    // No card to charge — keep status, notify, skip
                    $tenant->notify(new CardMissingNotification($tenant, $debt));
                    Log::channel('campaign')->warning('billing.no_card', ['tenant_id' => $tenant->id]);
                    $tenant->update(['last_billing_at' => now()]);
                    return;
                }

                $result = $mp->chargeStoredCard($tenant, $debt, 'monthly_overdue');

                if ($result['ok']) {
                    $tenant->increment('balance_cents', $debt);
                    $tenant->refresh();
                    BalanceTransaction::create([
                        'tenant_id'           => $tenant->id,
                        'type'                => 'monthly_charge',
                        'amount_cents'        => $debt,
                        'balance_after_cents' => $tenant->balance_cents,
                        'reference_type'      => 'monthly_billing',
                        'reference_id'        => $result['mp_payment_id'] ?? 0,
                        'description'         => 'Monthly automatic charge',
                    ]);
                    $tenant->update([
                        'billing_status' => 'active',
                        'overdue_since'  => null,
                        'overdue_attempts' => 0,
                    ]);
                    $tenant->notify(new MonthlySuccessNotification($tenant, $debt, $tenant->balance_cents));
                } else {
                    $tenant->update([
                        'billing_status'   => 'grace',
                        'overdue_since'    => now(),
                        'overdue_attempts' => 1,
                    ]);
                    $tenant->notify(new OverdueWarningNotification($tenant, 1));
                }
            }

            $tenant->update(['last_billing_at' => now()]);
        });
    }
}
```

- [ ] **Step 3: Implement DispatchMonthlyBillingJob**

```php
<?php
namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class DispatchMonthlyBillingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $today = Carbon::now('America/Sao_Paulo')->day;

        Tenant::query()
            ->where('billing_cycle_day', $today)
            ->where(function ($q) {
                $q->whereNull('last_billing_at')
                  ->orWhere('last_billing_at', '<', now()->subDays(25));
            })
            ->whereNotIn('billing_status', ['blocked'])
            ->chunk(50, function ($tenants) {
                foreach ($tenants as $tenant) {
                    MonthlyBillingJob::dispatch($tenant->id);
                }
            });
    }
}
```

- [ ] **Step 4: Run tests → PASS**

```bash
php artisan test --filter=MonthlyBillingJobTest
```

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Jobs/MonthlyBillingJob.php new_saas/backend/app/Jobs/DispatchMonthlyBillingJob.php new_saas/backend/tests/Unit/Jobs/MonthlyBillingJobTest.php
git commit -m "feat(billing): MonthlyBillingJob + DispatchMonthlyBillingJob orchestrator

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.5: OverdueRetryJob (TDD)

**Files:**
- Create: `backend/app/Jobs/OverdueRetryJob.php`
- Create: `backend/app/Jobs/DispatchOverdueRetryJob.php`
- Create: `backend/tests/Unit/Jobs/OverdueRetryJobTest.php`

- [ ] **Step 1: Tests**

```php
<?php
namespace Tests\Unit\Jobs;

use App\Jobs\OverdueRetryJob;
use App\Models\Tenant;
use App\Notifications\Billing\OverdueWarningNotification;
use App\Notifications\Billing\TenantSuspendedNotification;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OverdueRetryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_clears_grace(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create([
            'balance_cents' => -500, 'billing_status' => 'grace',
            'overdue_since' => now()->subDays(3), 'overdue_attempts' => 3,
            'mp_customer_id' => 'cust', 'mp_default_card_id' => 'card',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldReceive('chargeStoredCard')->andReturn(['ok' => true, 'mp_payment_id' => 'pay', 'error' => null]);

        (new OverdueRetryJob($tenant->id))->handle($mp);

        $tenant->refresh();
        $this->assertEquals('active', $tenant->billing_status);
        $this->assertNull($tenant->overdue_since);
        $this->assertEquals(0, $tenant->overdue_attempts);
        $this->assertEquals(0, $tenant->balance_cents);
    }

    public function test_blocks_after_8_days(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create([
            'balance_cents' => -500, 'billing_status' => 'grace',
            'overdue_since' => now()->subDays(8), 'overdue_attempts' => 7,
            'mp_customer_id' => 'cust', 'mp_default_card_id' => 'card',
        ]);

        $mp = $this->mock(MercadoPagoService::class);
        $mp->shouldNotReceive('chargeStoredCard'); // day 8 = no retry, just block

        (new OverdueRetryJob($tenant->id))->handle($mp);

        $tenant->refresh();
        $this->assertEquals('blocked', $tenant->billing_status);
        Notification::assertSentTo($tenant, TenantSuspendedNotification::class);
    }
}
```

- [ ] **Step 2: Implement OverdueRetryJob**

```php
<?php
namespace App\Jobs;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Notifications\Billing\OverdueWarningNotification;
use App\Notifications\Billing\TenantSuspendedNotification;
use App\Services\MercadoPagoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class OverdueRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $tenantId)
    {
        $this->onQueue('billing');
    }

    public function handle(MercadoPagoService $mp): void
    {
        DB::transaction(function () use ($mp) {
            $tenant = Tenant::lockForUpdate()->find($this->tenantId);
            if (! $tenant || ! in_array($tenant->billing_status, ['grace', 'suspended'])) return;

            $graceDays = (int) config('billing.overdue_grace_days', 7);
            $daysOverdue = $tenant->overdue_since
                ? now()->diffInDays($tenant->overdue_since)
                : 0;

            if ($daysOverdue >= ($graceDays + 1)) {
                // Block
                $tenant->update(['billing_status' => 'blocked']);
                $tenant->notify(new TenantSuspendedNotification($tenant));
                return;
            }

            if ($daysOverdue <= $graceDays && $tenant->balance_cents < 0) {
                $debt = abs($tenant->balance_cents);
                $result = $mp->chargeStoredCard($tenant, $debt, 'overdue_retry');

                if ($result['ok']) {
                    $tenant->increment('balance_cents', $debt);
                    $tenant->refresh();
                    BalanceTransaction::create([
                        'tenant_id'           => $tenant->id,
                        'type'                => 'monthly_charge',
                        'amount_cents'        => $debt,
                        'balance_after_cents' => $tenant->balance_cents,
                        'reference_type'      => 'monthly_billing',
                        'reference_id'        => $result['mp_payment_id'] ?? 0,
                        'description'         => 'Overdue retry',
                    ]);
                    $tenant->update([
                        'billing_status' => 'active', 'overdue_since' => null, 'overdue_attempts' => 0,
                    ]);
                } else {
                    $tenant->increment('overdue_attempts');
                    $tenant->notify(new OverdueWarningNotification($tenant, $daysOverdue));
                }
            }
        });
    }
}
```

- [ ] **Step 3: Implement DispatchOverdueRetryJob**

```php
<?php
namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchOverdueRetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Tenant::query()
            ->whereIn('billing_status', ['grace', 'suspended'])
            ->chunk(50, function ($tenants) {
                foreach ($tenants as $tenant) {
                    OverdueRetryJob::dispatch($tenant->id);
                }
            });
    }
}
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test --filter=OverdueRetryJobTest
cd c:/xampp/htdocs
git add new_saas/backend/app/Jobs/OverdueRetryJob.php new_saas/backend/app/Jobs/DispatchOverdueRetryJob.php new_saas/backend/tests/Unit/Jobs/OverdueRetryJobTest.php
git commit -m "feat(billing): OverdueRetryJob with 7d grace + block on day 8

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.6: Schedule wiring + webhook handler extension

**Files:**
- Modify: `backend/bootstrap/app.php` (or `app/Console/Kernel.php`)
- Modify: `backend/app/Http/Controllers/API/V1/WebhookController.php`

- [ ] **Step 1: Schedule jobs**

In `bootstrap/app.php` `->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) { ... })`:

```php
$schedule->job(new \App\Jobs\DispatchMonthlyBillingJob)
    ->dailyAt(sprintf('%02d:00', config('billing.monthly_dispatch_hour', 3)))
    ->timezone('America/Sao_Paulo')
    ->name('billing.monthly-dispatch')
    ->withoutOverlapping(60)
    ->onOneServer();

$schedule->job(new \App\Jobs\DispatchOverdueRetryJob)
    ->dailyAt(sprintf('%02d:00', config('billing.overdue_retry_hour', 4)))
    ->timezone('America/Sao_Paulo')
    ->name('billing.overdue-retry')
    ->withoutOverlapping(60)
    ->onOneServer();
```

- [ ] **Step 2: Extend MP webhook for monthly_charge**

In `WebhookController::mercadopago()`, after the current `processWebhook()` call, check if `data.id` corresponds to a `monthly:*` external_reference. If so, after MP service processes it, also call `BillingService::recharge()` (idempotent via UNIQUE on `(reference_type='monthly_billing', reference_id=mp_payment_id)`).

Verify the existing MercadoPagoService has a way to fetch the payment by ID; if so, add a branch:
```php
// After existing processWebhook:
$payment = $mpService->fetchPayment($dataId);
if ($payment && str_starts_with($payment['external_reference'] ?? '', 'monthly:')) {
    // Already credited inside MonthlyBillingJob / OverdueRetryJob — webhook just confirms
    // No-op unless we need to update billing_status based on async status change
    Log::info('billing.monthly_charge.webhook_confirm', [
        'payment_id' => $dataId, 'status' => $payment['status'], 'tenant_ref' => $payment['external_reference']
    ]);
}
```

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/bootstrap/app.php new_saas/backend/app/Http/Controllers/API/V1/WebhookController.php
git commit -m "feat(billing): schedule monthly + overdue jobs; webhook recognizes monthly_charge

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 3.7: PM Gate Phase 3

- [ ] PM confirms: "Phase 3 complete — 4 jobs + 5 notifications + 7 templates + MP::chargeStoredCard + webhook handler + schedule. Proceed to Phase 4?"

---

## Phase 4 — Admin Controllers + Routes + EnsureRole

### Task 4.1: EnsureRole middleware (TDD)

**Files:**
- Create: `backend/app/Http/Middleware/EnsureRole.php`
- Create: `backend/tests/Unit/Middleware/EnsureRoleTest.php`
- Modify: `backend/bootstrap/app.php` (register alias)

- [ ] **Step 1: Tests**

```php
<?php
namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class EnsureRoleTest extends TestCase
{
    use RefreshDatabase;

    private function call(User $user, string $required)
    {
        $request = Request::create('/x', 'POST');
        $request->setUserResolver(fn() => $user);
        return (new EnsureRole)->handle($request, fn() => response('ok'), $required);
    }

    public function test_passes_for_exact_role(): void
    {
        $user = User::factory()->create(['role' => 'finance']);
        $resp = $this->call($user, 'finance');
        $this->assertEquals('ok', $resp->getContent());
    }

    public function test_passes_for_superadmin_wildcard(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $resp = $this->call($user, 'finance');
        $this->assertEquals('ok', $resp->getContent());
    }

    public function test_blocks_user_role(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $resp = $this->call($user, 'finance');
        $this->assertEquals(403, $resp->getStatusCode());
    }

    public function test_accepts_pipe_separated_list(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $resp = $this->call($user, 'admin|finance');
        $this->assertEquals('ok', $resp->getContent());
    }
}
```

- [ ] **Step 2: Implement middleware**

```php
<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $roles)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'UNAUTHENTICATED'], 401);
        }

        // Superadmin always passes
        if ($user->role === 'superadmin') {
            return $next($request);
        }

        $allowed = explode('|', $roles);
        if (! in_array($user->role, $allowed, true)) {
            return response()->json(['error' => 'FORBIDDEN', 'required_role' => $roles], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 3: Register alias in bootstrap/app.php**

```php
$middleware->alias([
    // ... existing ...
    'role' => \App\Http\Middleware\EnsureRole::class,
]);
```

- [ ] **Step 4: Run + commit**

```bash
php artisan test --filter=EnsureRoleTest
cd c:/xampp/htdocs
git add new_saas/backend/app/Http/Middleware/EnsureRole.php new_saas/backend/tests/Unit/Middleware/EnsureRoleTest.php new_saas/backend/bootstrap/app.php
git commit -m "feat(billing): EnsureRole middleware (superadmin always passes; finance for billing UI)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 4.2: FormRequests for admin/billing

**Files:**
- Create: `backend/app/Http/Requests/Admin/UpdateServicePriceRequest.php`
- Create: `backend/app/Http/Requests/Admin/UpdateTenantPricingRequest.php`
- Create: `backend/app/Http/Requests/Admin/UpdateCreditLimitRequest.php`
- Create: `backend/app/Http/Requests/Admin/ManualBalanceAdjustmentRequest.php`

- [ ] **Step 1: UpdateServicePriceRequest**

```php
<?php
namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServicePriceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'cost_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'sale_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'reason'     => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
```

- [ ] **Step 2: UpdateTenantPricingRequest**

```php
<?php
namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantPricingRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'service'    => ['required', 'in:sms,voice,email,ai_generation,audio_tts'],
            'sale_cents' => ['nullable', 'integer', 'min:0', 'max:10000000'],  // null = remove override
            'reason'     => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
```

- [ ] **Step 3: UpdateCreditLimitRequest**

```php
<?php
namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCreditLimitRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'credit_limit_cents' => ['required', 'integer', 'min:0', 'max:100000000'],  // up to R$ 1M
            'reason'             => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
```

- [ ] **Step 4: ManualBalanceAdjustmentRequest**

```php
<?php
namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ManualBalanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'not_in:0'],  // can be negative
            'reason'       => ['required', 'string', 'min:10', 'max:200'],
        ];
    }
}
```

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Http/Requests/Admin/
git commit -m "feat(billing): FormRequests for service price, tenant override, credit line, manual adjust

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 4.3: ServicePricingController + TenantPricingController

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/ServicePricingController.php`
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantPricingController.php`

- [ ] **Step 1: ServicePricingController**

```php
<?php
namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateServicePriceRequest;
use App\Models\AuditLog;
use App\Models\ServicePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServicePricingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ServicePrice::all()->map(fn($p) => [
                'service'         => $p->service,
                'cost_cents'      => $p->cost_cents,
                'sale_cents'      => $p->sale_cents,
                'margin_cents'    => $p->marginCents(),
                'margin_percent'  => $p->marginPercent(),
                'updated_at'      => $p->updated_at,
                'updated_by_name' => $p->updater?->name,
            ]),
        ]);
    }

    public function update(UpdateServicePriceRequest $request, string $service): JsonResponse
    {
        $price = ServicePrice::where('service', $service)->firstOrFail();

        $before = ['cost_cents' => $price->cost_cents, 'sale_cents' => $price->sale_cents];
        $price->update([
            'cost_cents' => $request->validated('cost_cents'),
            'sale_cents' => $request->validated('sale_cents'),
            'updated_by' => $request->user()->id,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action'  => 'service_price.updated',
            'subject_type' => ServicePrice::class,
            'subject_id'   => $price->id,
            'meta' => [
                'before' => $before,
                'after'  => ['cost_cents' => $price->cost_cents, 'sale_cents' => $price->sale_cents],
                'reason' => $request->validated('reason'),
            ],
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        Cache::forget("service_price:{$service}");
        return response()->json(['ok' => true, 'data' => $price->fresh()]);
    }
}
```

- [ ] **Step 2: TenantPricingController**

```php
<?php
namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTenantPricingRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantPricingController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'data' => TenantServicePrice::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->get(),
        ]);
    }

    public function upsert(UpdateTenantPricingRequest $request, Tenant $tenant): JsonResponse
    {
        $service = $request->validated('service');
        $saleCents = $request->validated('sale_cents');

        if ($saleCents === null) {
            // Remove override
            $deleted = TenantServicePrice::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('service', $service)
                ->delete();

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action'  => 'tenant_service_price.removed',
                'subject_type' => Tenant::class,
                'subject_id' => $tenant->id,
                'meta' => ['service' => $service, 'reason' => $request->validated('reason')],
            ]);

            return response()->json(['ok' => true, 'deleted' => $deleted]);
        }

        $override = TenantServicePrice::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'service' => $service],
            [
                'sale_cents' => $saleCents,
                'reason'     => $request->validated('reason'),
                'created_by' => $request->user()->id,
            ]
        );

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action'  => 'tenant_service_price.upserted',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'meta' => [
                'service' => $service, 'sale_cents' => $saleCents,
                'reason' => $request->validated('reason'),
            ],
        ]);

        return response()->json(['ok' => true, 'data' => $override]);
    }
}
```

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Http/Controllers/API/V1/Admin/ServicePricingController.php new_saas/backend/app/Http/Controllers/API/V1/Admin/TenantPricingController.php
git commit -m "feat(billing): ServicePricingController + TenantPricingController with audit

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 4.4: TenantCreditLineController + BillingReportController

**Files:**
- Create: `backend/app/Http/Controllers/API/V1/Admin/TenantCreditLineController.php`
- Create: `backend/app/Http/Controllers/API/V1/Admin/BillingReportController.php`

- [ ] **Step 1: TenantCreditLineController**

```php
<?php
namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ManualBalanceAdjustmentRequest;
use App\Http\Requests\Admin\UpdateCreditLimitRequest;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantCreditLineController extends Controller
{
    public function setLimit(UpdateCreditLimitRequest $request, Tenant $tenant): JsonResponse
    {
        $before = $tenant->credit_limit_cents;
        $tenant->update(['credit_limit_cents' => $request->validated('credit_limit_cents')]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action'  => 'tenant_credit_limit.changed',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'meta' => [
                'before' => $before, 'after' => $tenant->credit_limit_cents,
                'reason' => $request->validated('reason'),
            ],
        ]);

        return response()->json(['ok' => true, 'data' => $tenant->fresh()]);
    }

    public function adjust(ManualBalanceAdjustmentRequest $request, Tenant $tenant, BillingService $billing): JsonResponse
    {
        $tx = $billing->manualAdjustment(
            $tenant->id,
            $request->validated('amount_cents'),
            $request->validated('reason'),
            $request->user()->id,
        );

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action'  => 'manual_adjustment',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'meta' => [
                'amount_cents' => $request->validated('amount_cents'),
                'reason' => $request->validated('reason'),
                'tx_id' => $tx->id,
            ],
        ]);

        return response()->json(['ok' => true, 'data' => $tx]);
    }

    public function unlock(Request $request, Tenant $tenant): JsonResponse
    {
        if ($request->user()->role !== 'superadmin') {
            return response()->json(['error' => 'SUPERADMIN_ONLY'], 403);
        }
        $reason = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:200']])['reason'];

        $before = $tenant->billing_status;
        $tenant->update([
            'billing_status'   => 'active',
            'overdue_since'    => null,
            'overdue_attempts' => 0,
        ]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action'  => 'tenant_unlocked',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'meta' => ['before' => $before, 'reason' => $reason],
        ]);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 2: BillingReportController**

```php
<?php
namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingReportController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $last30 = now()->subDays(30);

        return response()->json([
            'total_balance_brl' => DB::table('tenants')->where('balance_cents', '>', 0)->sum('balance_cents') / 100,
            'total_owed_brl'    => DB::table('tenants')->where('balance_cents', '<', 0)->sum('balance_cents') / 100,
            'tenants_by_status' => DB::table('tenants')->select('billing_status', DB::raw('COUNT(*) as n'))
                                    ->groupBy('billing_status')->pluck('n', 'billing_status'),
            'mrr_last_30d'      => DB::table('balance_transactions')
                                    ->whereIn('type', ['recharge', 'monthly_charge'])
                                    ->where('created_at', '>=', $last30)
                                    ->sum('amount_cents') / 100,
            'margin_last_30d'   => DB::table('message_dispatches')
                                    ->where('status', 'sent')
                                    ->where('created_at', '>=', $last30)
                                    ->select(DB::raw('SUM(charged_cents - cost_cents) as margin'))
                                    ->value('margin') / 100,
            'overdue_recovery_rate_30d' => $this->recoveryRate($last30),
        ]);
    }

    private function recoveryRate(\Carbon\Carbon $since): float
    {
        $totalGrace = DB::table('audit_logs')
            ->where('action', 'monthly_billing.charged')
            ->where('meta->status', 'failed')
            ->where('created_at', '>=', $since)
            ->count();
        if ($totalGrace === 0) return 1.0;
        $recovered = DB::table('balance_transactions')
            ->where('type', 'monthly_charge')
            ->where('description', 'Overdue retry')
            ->where('created_at', '>=', $since)
            ->count();
        return round($recovered / $totalGrace, 2);
    }
}
```

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Http/Controllers/API/V1/Admin/TenantCreditLineController.php new_saas/backend/app/Http/Controllers/API/V1/Admin/BillingReportController.php
git commit -m "feat(billing): TenantCreditLineController + BillingReportController

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 4.5: Routes wiring

**Files:**
- Modify: `backend/routes/api.php`

- [ ] **Step 1: Add use statements + routes**

After existing `use App\Http\Controllers\API\V1\Admin\*;` lines, add:
```php
use App\Http\Controllers\API\V1\Admin\ServicePricingController;
use App\Http\Controllers\API\V1\Admin\TenantPricingController;
use App\Http\Controllers\API\V1\Admin\TenantCreditLineController;
use App\Http\Controllers\API\V1\Admin\BillingReportController;
```

Inside `Route::middleware('auth:sanctum')->group(...)`, add a sub-group for billing UI (role: superadmin|finance):
```php
Route::middleware('role:finance')->prefix('admin/billing')->group(function () {
    Route::get('pricing',                  [ServicePricingController::class, 'index']);
    Route::put('pricing/{service}',        [ServicePricingController::class, 'update']);
    Route::get('tenants/{tenant}/pricing', [TenantPricingController::class, 'index']);
    Route::post('tenants/{tenant}/pricing',[TenantPricingController::class, 'upsert']);
    Route::patch('tenants/{tenant}/credit-limit', [TenantCreditLineController::class, 'setLimit']);
    Route::post('tenants/{tenant}/adjust',[TenantCreditLineController::class, 'adjust']);
    Route::post('tenants/{tenant}/unlock', [TenantCreditLineController::class, 'unlock']);  // controller enforces superadmin
    Route::get('stats',                    [BillingReportController::class, 'stats']);
});
```

- [ ] **Step 2: Verify**

```bash
cd c:/xampp/htdocs/new_saas/backend
php artisan route:list | grep "admin/billing"
```
Expected: 8 routes printed.

- [ ] **Step 3: Update PublicRoutesWhitelistTest if needed** — none of the new routes should be public, so the test should still pass without changes.

- [ ] **Step 4: Run full suite**

```bash
php artisan test
```
Some tests may still be red (Phase 7 fixes them).

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/routes/api.php
git commit -m "feat(billing): wire 8 admin/billing routes under role:finance middleware

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 4.6: PM Gate Phase 4

- [ ] PM confirms: "Phase 4 complete — EnsureRole + 4 controllers + 4 FormRequests + 8 routes + audit on every action. Proceed to Phase 5 (frontend admin)?"

---

## Phase 5 — Frontend Admin/Finance Pages

> Owner: Frontend Dev Senior. Each page = Vue 3 SFC following existing project conventions (Tabler UI, Pinia, useApi composable).

### Task 5.1: Billing store

**Files:**
- Create: `frontend/src/stores/billing.ts`

- [ ] **Step 1: Pinia store wrapping API calls**

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'

export interface ServicePrice {
  service: string
  cost_cents: number
  sale_cents: number
  margin_cents: number
  margin_percent: number
  updated_at: string
  updated_by_name?: string
}

export interface BillingTenant {
  id: number
  name: string
  billing_status: 'active'|'grace'|'suspended'|'blocked'
  balance_cents: number
  credit_limit_cents: number
  billing_cycle_day: number
  last_billing_at?: string
}

export const useBillingStore = defineStore('billing', () => {
  const prices = ref<ServicePrice[]>([])
  const tenants = ref<BillingTenant[]>([])
  const stats = ref<any>(null)

  const { get, post, put, patch } = useApi()

  async function loadPrices() {
    const r = await get<{data: ServicePrice[]}>('/admin/billing/pricing')
    prices.value = r.data
  }

  async function updatePrice(service: string, payload: {cost_cents: number; sale_cents: number; reason: string}) {
    await put(`/admin/billing/pricing/${service}`, payload)
    await loadPrices()
  }

  async function loadStats() {
    stats.value = await get('/admin/billing/stats')
  }

  async function setCreditLimit(tenantId: number, cents: number, reason: string) {
    await patch(`/admin/billing/tenants/${tenantId}/credit-limit`, {credit_limit_cents: cents, reason})
  }

  async function adjustBalance(tenantId: number, cents: number, reason: string) {
    await post(`/admin/billing/tenants/${tenantId}/adjust`, {amount_cents: cents, reason})
  }

  async function setTenantPrice(tenantId: number, service: string, saleCents: number|null, reason: string) {
    await post(`/admin/billing/tenants/${tenantId}/pricing`, {service, sale_cents: saleCents, reason})
  }

  return {prices, tenants, stats, loadPrices, updatePrice, loadStats, setCreditLimit, adjustBalance, setTenantPrice}
})
```

- [ ] **Step 2: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/stores/billing.ts
git commit -m "feat(billing): Pinia store for admin billing API

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 5.2: `/admin/billing/pricing` page

**Files:**
- Create: `frontend/src/pages/admin/billing/Pricing.vue`
- Create: `frontend/src/components/billing/PriceEditModal.vue`

- [ ] **Step 1: Pricing.vue**

Inspect `frontend/src/pages/admin/SettingsInfobip.vue` for the project's form/page pattern. Then create:

```vue
<template>
  <div>
    <div class="d-flex align-items-center justify-content-between mb-4">
      <h2 style="font-size:1.4rem;font-weight:700;letter-spacing:-0.03em;margin:0">Preços de Serviços</h2>
    </div>

    <div class="alert alert-warning mb-3">
      <i class="ti ti-alert-triangle me-1"></i>
      Alterações afetam APENAS novos envios. Dispatches já cobrados não são reembolsados.
    </div>

    <div class="card">
      <div class="table-responsive">
        <table class="table card-table table-vcenter">
          <thead>
            <tr>
              <th>Serviço</th>
              <th class="text-end">Custo</th>
              <th class="text-end">Venda</th>
              <th class="text-end">Margem</th>
              <th class="text-end">Margem %</th>
              <th>Última alteração</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in store.prices" :key="p.service">
              <td>{{ serviceLabel(p.service) }}</td>
              <td class="text-end">R$ {{ formatCents(p.cost_cents) }}</td>
              <td class="text-end fw-bold">R$ {{ formatCents(p.sale_cents) }}</td>
              <td class="text-end">R$ {{ formatCents(p.margin_cents) }}</td>
              <td class="text-end">{{ p.margin_percent }}%</td>
              <td class="text-muted small">
                {{ p.updated_at ? new Date(p.updated_at).toLocaleString('pt-BR') : '—' }}<br>
                <span v-if="p.updated_by_name">por {{ p.updated_by_name }}</span>
              </td>
              <td><button class="btn btn-sm btn-primary" @click="edit(p)">Editar</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <PriceEditModal v-if="editing" :price="editing" @close="editing=null" @saved="onSaved"/>
  </div>
</template>

<script setup lang="ts">
import {onMounted, ref} from 'vue'
import {useBillingStore, ServicePrice} from '@/stores/billing'
import PriceEditModal from '@/components/billing/PriceEditModal.vue'

const store = useBillingStore()
const editing = ref<ServicePrice | null>(null)

onMounted(() => store.loadPrices())

function edit(p: ServicePrice) { editing.value = p }
function onSaved() { editing.value = null; store.loadPrices() }
function formatCents(c: number) { return (c / 100).toFixed(2).replace('.', ',') }
function serviceLabel(s: string) {
  return {sms:'SMS', voice:'Voz (TTS)', email:'Email', ai_generation:'Geração IA', audio_tts:'Áudio TTS'}[s] || s
}
</script>
```

- [ ] **Step 2: PriceEditModal.vue**

```vue
<template>
  <div class="modal modal-blur fade show d-block" style="background:rgba(0,0,0,0.5)" @click.self="$emit('close')">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Editar preço — {{ price.service }}</h5>
          <button class="btn-close" @click="$emit('close')"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Custo (R$)</label>
            <input type="number" class="form-control" v-model.number="costBrl" step="0.01" min="0">
          </div>
          <div class="mb-3">
            <label class="form-label">Venda (R$)</label>
            <input type="number" class="form-control" v-model.number="saleBrl" step="0.01" min="0">
          </div>
          <div class="mb-3">
            <label class="form-label required">Razão</label>
            <textarea class="form-control" v-model="reason" rows="3" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-link" @click="$emit('close')">Cancelar</button>
          <button class="btn btn-primary" @click="save" :disabled="saving || !reason || reason.length < 5">
            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
            Salvar
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import {ref} from 'vue'
import {useBillingStore} from '@/stores/billing'
import {useToast} from '@/composables/useToast'

const props = defineProps<{price: any}>()
const emit = defineEmits(['close', 'saved'])

const costBrl = ref(props.price.cost_cents / 100)
const saleBrl = ref(props.price.sale_cents / 100)
const reason = ref('')
const saving = ref(false)
const toast = useToast()
const store = useBillingStore()

async function save() {
  saving.value = true
  try {
    await store.updatePrice(props.price.service, {
      cost_cents: Math.round(costBrl.value * 100),
      sale_cents: Math.round(saleBrl.value * 100),
      reason: reason.value,
    })
    toast.success('Preço atualizado')
    emit('saved')
  } catch (e: any) {
    toast.error(e?.message || 'Erro ao salvar')
  } finally {
    saving.value = false
  }
}
</script>
```

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/pages/admin/billing/Pricing.vue new_saas/frontend/src/components/billing/PriceEditModal.vue
git commit -m "feat(billing): admin/billing/Pricing page + PriceEditModal

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 5.3: `/admin/billing/tenants` + TenantDetail pages

**Files:**
- Create: `frontend/src/pages/admin/billing/Tenants.vue`
- Create: `frontend/src/pages/admin/billing/TenantDetail.vue`
- Create: `frontend/src/components/billing/CreditLimitModal.vue`
- Create: `frontend/src/components/billing/ManualAdjustModal.vue`

- [ ] **Step 1: Tenants.vue — listagem com filtros**

Build a page following section 5.1.2 of the spec: table with columns ID, Nome, Status (badge colored: green=active, orange=grace, red=suspended/blocked), Saldo (R$), Limite, Uso (%), Próx. cobrança, Ações (link to detail). Filters: status select, search input, balance positive/negative toggle. Use store.loadTenants() (add this method to billing store: GET /admin/tenants?with_billing=1 — extend existing TenantsController or add new endpoint).

- [ ] **Step 2: TenantDetail.vue — 3 abas (Saldo&Limite, Preços, Histórico)**

Tabs implemented via v-show toggling. Aba 1: cards + buttons to open `CreditLimitModal`, `ManualAdjustModal`, "Forçar cobrança", "Desbloquear". Aba 2: similar to Pricing.vue but with override values + remove-override button. Aba 3: paginated balance_transactions list (add API GET /admin/billing/tenants/{id}/transactions to BillingReportController).

- [ ] **Step 3: CreditLimitModal.vue + ManualAdjustModal.vue**

Same pattern as PriceEditModal but with single field + reason.

- [ ] **Step 4: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/pages/admin/billing/Tenants.vue new_saas/frontend/src/pages/admin/billing/TenantDetail.vue new_saas/frontend/src/components/billing/CreditLimitModal.vue new_saas/frontend/src/components/billing/ManualAdjustModal.vue
git commit -m "feat(billing): admin Tenants list + TenantDetail (3 tabs) + 2 action modals

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 5.4: `/admin/billing/reports` page

**Files:**
- Create: `frontend/src/pages/admin/billing/Reports.vue`

- [ ] **Step 1: Reports.vue**

5 stat cards at top (from `billing/stats`). Below: Chart.js line (receita diária 90d), bar (margem por serviço 30d), pie (tenants por status), table (top 10 consumo do mês). Period filter (7d/30d/90d/custom). "Exportar CSV" button calling new endpoint `GET /admin/billing/stats/csv`.

The project likely already has Chart.js configured (look at dashboard/Index.vue). Reuse the same wrapper.

- [ ] **Step 2: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/pages/admin/billing/Reports.vue
git commit -m "feat(billing): admin Reports dashboard (cards + 4 charts + CSV)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 5.5: Router + Sidebar wiring

**Files:**
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/components/layout/AppSidebar.vue`
- Modify: `frontend/src/stores/auth.ts` (extend role type)

- [ ] **Step 1: Add 4 routes + meta.role**

In `router/index.ts`:
```ts
{ path: '/admin/billing/pricing',      component: () => import('@/pages/admin/billing/Pricing.vue'), meta: { role: 'finance', title: 'Preços de Serviços' } },
{ path: '/admin/billing/tenants',      component: () => import('@/pages/admin/billing/Tenants.vue'), meta: { role: 'finance', title: 'Tenants — Financeiro' } },
{ path: '/admin/billing/tenants/:id',  component: () => import('@/pages/admin/billing/TenantDetail.vue'), meta: { role: 'finance' } },
{ path: '/admin/billing/reports',      component: () => import('@/pages/admin/billing/Reports.vue'), meta: { role: 'finance', title: 'Relatórios Financeiros' } },
```

Update the `beforeEach` guard to support `meta.role`:
```ts
} else if (to.meta.role && !['superadmin', to.meta.role].includes(auth.user?.role ?? '')) {
  next('/dashboard')
}
```
(Keep the existing `meta.superadmin` check for legacy routes.)

- [ ] **Step 2: Sidebar section**

In `AppSidebar.vue`, add a new collapsible section "Financeiro" (visible only if `user.role` ∈ `['superadmin', 'finance']`):
```vue
<li v-if="['superadmin', 'finance'].includes(auth.user?.role || '')" class="nav-item">
  <a class="nav-link" data-bs-toggle="collapse" href="#nav-finance">
    <span class="nav-link-icon"><i class="ti ti-cash"></i></span>
    <span class="nav-link-title">Financeiro</span>
  </a>
  <div class="collapse" id="nav-finance">
    <ul class="nav nav-pills nav-fill flex-column">
      <li><router-link class="nav-link" to="/admin/billing/pricing">Preços de Serviços</router-link></li>
      <li><router-link class="nav-link" to="/admin/billing/tenants">Tenants</router-link></li>
      <li><router-link class="nav-link" to="/admin/billing/reports">Relatórios</router-link></li>
    </ul>
  </div>
</li>
```

- [ ] **Step 3: auth store type**

In `stores/auth.ts`, update `User.role` type:
```ts
role: 'user' | 'admin' | 'superadmin' | 'finance'
```

- [ ] **Step 4: Verify build**

```bash
cd c:/xampp/htdocs/new_saas/frontend
npm run build
```
Expected: clean build.

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/router/index.ts new_saas/frontend/src/components/layout/AppSidebar.vue new_saas/frontend/src/stores/auth.ts
git commit -m "feat(billing): wire 4 admin/billing routes + sidebar Financeiro section + finance role type

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 5.6: PM Gate Phase 5

- [ ] PM confirms: "Phase 5 complete — 4 admin pages + 4 modals + store + router + sidebar. Proceed to Phase 6 (user-facing renames)?"

---

## Phase 6 — Frontend User-Facing Renames + Banner

### Task 6.1: Rename `/settings/credits` → `/settings/saldo`

**Files:**
- Modify: `frontend/src/router/index.ts`
- Modify: `frontend/src/pages/settings/CreditPurchase.vue` → rename to `Saldo.vue` (or keep file name)
- Modify: `frontend/src/components/layout/AppSidebar.vue`

- [ ] **Step 1: Rename file**

```bash
cd c:/xampp/htdocs/new_saas/frontend/src/pages/settings
git mv CreditPurchase.vue Saldo.vue
```

- [ ] **Step 2: Update content of Saldo.vue**

Inside, find every "crédito" → "saldo", every credit-count display → R$ format. Add banner if `tenant.billing_status` ∈ `['grace','suspended','blocked']`. Show cards: Saldo atual (R$), Limite de crédito (R$, hide if 0), Próxima cobrança (data + valor estimado se devedor).

- [ ] **Step 3: Update router**

In `router/index.ts`:
```ts
{ path: '/settings/saldo', component: () => import('@/pages/settings/Saldo.vue'), meta: { title: 'Meu Saldo' } },
// Keep legacy redirect for 30 days:
{ path: '/settings/credits', redirect: '/settings/saldo' },
```

- [ ] **Step 4: Update sidebar link** in AppSidebar.vue: "Comprar Créditos" → "Meu Saldo" pointing to /settings/saldo.

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/pages/settings/Saldo.vue new_saas/frontend/src/router/index.ts new_saas/frontend/src/components/layout/AppSidebar.vue
git commit -m "feat(billing): rename /settings/credits → /settings/saldo with R$ display

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 6.2: BalanceBanner global component

**Files:**
- Create: `frontend/src/components/billing/BalanceBanner.vue`
- Modify: `frontend/src/App.vue`

- [ ] **Step 1: BalanceBanner.vue**

```vue
<template>
  <div v-if="banner" :class="['alert', `alert-${banner.color}`, 'mb-0', 'rounded-0']">
    <i class="ti ti-alert-triangle me-1"></i> {{ banner.text }}
    <router-link v-if="banner.cta" to="/settings/saldo" class="ms-2 fw-bold">{{ banner.cta }}</router-link>
  </div>
</template>

<script setup lang="ts">
import {computed} from 'vue'
import {useAuthStore} from '@/stores/auth'

const auth = useAuthStore()

const banner = computed(() => {
  const t = auth.user?.tenant
  if (!t) return null
  const status = (t as any).billing_status
  const balance = (t as any).balance_cents ?? 0
  const limit = (t as any).credit_limit_cents ?? 0

  if (status === 'blocked') return {color:'danger', text:'Conta bloqueada por inadimplência.', cta:'Regularize agora'}
  if (status === 'suspended') return {color:'danger', text:'Conta suspensa.', cta:'Regularize'}
  if (status === 'grace') return {color:'warning', text:'Pagamento pendente.', cta:'Atualizar cartão'}

  if (balance < 0 && limit > 0) {
    const used = Math.min(100, Math.round((Math.abs(balance) / limit) * 100))
    if (used >= 100) return {color:'danger', text:'Limite de crédito atingido.', cta:'Recarregue'}
    if (used >= 80)  return {color:'warning', text:`Você usou ${used}% da linha de crédito.`, cta:'Recarregar'}
    if (used >= 50)  return {color:'info', text:`Você usou ${used}% da linha de crédito.`, cta:undefined}
  }
  return null
})
</script>
```

- [ ] **Step 2: Mount in App.vue**

```vue
<template>
  <BalanceBanner v-if="auth.isAuthenticated"/>
  <!-- existing layout -->
</template>
```

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/components/billing/BalanceBanner.vue new_saas/frontend/src/App.vue
git commit -m "feat(billing): BalanceBanner global (grace/suspended/blocked + credit limit usage)

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 6.3: Substituir "créditos" → "R$" em componentes

**Files (12 components):**
- `OrderSummary.vue`, `CheckoutForm.vue`, `ConfirmSendModal.vue`, `Step2Content/AiMode.vue`, `Step5Review.vue`, `VoiceStudio.vue`, `AppSidebar.vue`, `pages/admin/Plans.vue`, `pages/admin/SettingsElevenLabs.vue`, `pages/admin/Tenants.vue`, `pages/dashboard/Index.vue`

- [ ] **Step 1: Grep all files**

```bash
cd c:/xampp/htdocs/new_saas/frontend/src
grep -rn "crédito\|credit\|Crédito" --include="*.vue" --include="*.ts" | grep -v "node_modules"
```

- [ ] **Step 2: For each file, update text + numeric format**

Examples:
- "Custo: 5 créditos" → "Custo: R$ {{ (cents / 100).toFixed(2) }}"
- "Saldo: 100 créditos" → "Saldo: R$ {{ (balance_cents / 100).toFixed(2) }}"
- "5 créditos por SMS" → "R$ {{ (sale_cents / 100).toFixed(2) }} por SMS"

Add a helper utility `frontend/src/utils/currency.ts`:
```ts
export function brl(cents: number): string {
  return 'R$ ' + (cents / 100).toFixed(2).replace('.', ',')
}
```

Import where needed: `import {brl} from '@/utils/currency'`.

- [ ] **Step 3: Verify**

```bash
cd c:/xampp/htdocs/new_saas/frontend
npm run build
```
Expected: clean build.

```bash
grep -rn "crédito\|credit\|Crédito" src --include="*.vue" --include="*.ts" | grep -v "node_modules"
```
Expected: zero or only intentional remnants (e.g., comments documenting the legacy redirect).

- [ ] **Step 4: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/
git commit -m "feat(billing): replace 'créditos' with R\$ across 12 components + brl() util

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 6.4: Checkout — salvar cartão pra débito mensal

**Files:**
- Modify: `frontend/src/components/checkout/CheckoutForm.vue`
- Modify: `backend/app/Http/Controllers/API/V1/CheckoutController.php`

- [ ] **Step 1: Frontend checkbox**

In CheckoutForm.vue, add a checkbox (default `checked=true`):
```vue
<div class="form-check">
  <input class="form-check-input" type="checkbox" v-model="saveCard" id="save-card">
  <label class="form-check-label" for="save-card">
    Salvar cartão para débito mensal automático do consumo excedente.
  </label>
</div>
```

When submitting checkout, include `save_card_for_billing: true` in payload.

- [ ] **Step 2: Backend persists mp_customer_id + mp_default_card_id**

In CheckoutController, after MP payment approved, if `save_card_for_billing` is true:
- Call MP API to create customer (if not exists) with the payer email
- Get card_id from the payment response
- Update tenant: `$tenant->update(['mp_customer_id' => $custId, 'mp_default_card_id' => $cardId])`

- [ ] **Step 3: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/frontend/src/components/checkout/CheckoutForm.vue new_saas/backend/app/Http/Controllers/API/V1/CheckoutController.php
git commit -m "feat(billing): checkout saves mp_customer_id + mp_default_card_id for monthly debit

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 6.5: PM Gate Phase 6

- [ ] PM confirms: "Phase 6 complete — Saldo page + BalanceBanner + 12 components updated + checkout card-save. Proceed to Phase 7 (QA tests)?"

---

## Phase 7 — QA Tests

> Owner: QA Engineer. ~50 unit + feature tests covering everything. Coverage ≥85% on billing files. Fix any test failures caused by Phase 1-6 refactor.

### Task 7.1: Fix legacy test failures from credit→balance refactor

- [ ] **Step 1: Run full suite, list failures**

```bash
cd c:/xampp/htdocs/new_saas/backend
php artisan test 2>&1 | grep -E "FAIL|✗" | head -30
```

- [ ] **Step 2: For each failing test**

Common updates:
- `credits_balance` → `balance_cents` (multiply expected value by 15 OR change to cents)
- `credits_unit` → `sale_cents`
- `CreditTransaction` → `BalanceTransaction`
- `amount` field → `amount_cents`
- Expected exception `InsufficientCreditsException` → `InsufficientFundsException`
- HTTP error code `INSUFFICIENT_CREDITS` → `INSUFFICIENT_FUNDS`

Don't change implementation if a test reveals a real regression — flag it as a finding.

- [ ] **Step 3: Re-run until 175+ all green**

```bash
php artisan test
```
Expected: at least 175 PASS (existing) + the unit tests added in Phase 1-4 (BillingService, PricingService, MonthlyBillingJob, OverdueRetryJob, EnsureRole, PricingService — about 6-8 new test files).

- [ ] **Step 4: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/tests/
git commit -m "test(billing): update legacy tests for credit→balance/cents rename

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 7.2: Add 12 new feature test files

For each (one per task subdivision):
- AdminPricingApiTest
- TenantPricingApiTest
- TenantCreditLineApiTest
- BillingReportTest
- BalanceFlowTest (end-to-end via HTTP)
- BlockedTenantCannotSendTest
- MonthlyBillingDispatchTest
- OverdueDunningTest
- MercadoPagoChargeStoredCardTest
- MpWebhookMonthlyChargeIdempotentTest
- MessageDispatchSnapshotsPriceTest
- MigrationRoundtripTest

Each follows the pattern from Phase 3 of the messaging plan:
- `use RefreshDatabase`
- `setUp()` seeds ServicePrices + creates tenant+user
- `Sanctum::actingAs($user, ['*'])`
- Cover at least 3 scenarios per file (happy + auth + boundary)

- [ ] **Step 1: Write all 12 files**

(Following Phase 3 pattern of the messaging plan — `use RefreshDatabase`, `Sanctum::actingAs`, etc. Each file ≥3 tests.)

- [ ] **Step 2: Run + coverage**

```bash
php artisan test --coverage --min=85 -- tests/Feature/Admin tests/Feature/Billing
```
Expected: ≥85% coverage on billing files.

- [ ] **Step 3: Commit per file or batched**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/tests/Feature/Admin new_saas/backend/tests/Feature/Billing
git commit -m "test(billing): 12 feature test files covering full billing lifecycle

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 7.3: PM Gate Phase 7

- [ ] PM: "Phase 7 QA complete — all green, ≥85% coverage. Proceed to Phase 8 (Red Team)?"

---

## Phase 8 — Red Team Pentest

### Task 8.1: Pentest suite + report

**Files:**
- Create: `backend/tests/Pentest/BillingSecurityTest.php`
- Create: `backend/docs/pentest-billing-2026-05-27.md`

- [ ] **Step 1: Implement 20 scenarios from spec §9.3**

Each method one scenario:

```php
public function test_01_negative_balance_overflow_blocked_by_one_cent(): void
{
    $tenant = Tenant::factory()->create(['balance_cents' => 0, 'credit_limit_cents' => 100]);
    $svc = app(BillingService::class);
    $this->assertTrue($svc->reserve($tenant->id, 100, 'm', 1));
    $this->assertFalse($svc->reserve($tenant->id, 1, 'm', 2));
    $this->assertEquals(-100, $tenant->fresh()->balance_cents);
}
```

(Repeat for the 19 other scenarios listed in spec §9.3 table.)

- [ ] **Step 2: Run**

```bash
php artisan test --filter=BillingSecurityTest
```

- [ ] **Step 3: Generate report**

Markdown file with:
- Summary table (Critical / High / Medium / Low counts)
- Methodology
- Findings (each with: severity, test, status, description, reproduction, recommendation)
- Verified mitigations table
- Closing statement

- [ ] **Step 4: Fix critical/high findings**

For each red test that exposes real vuln: Dev fixes implementation, re-run, mark report as fixed.

- [ ] **Step 5: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/tests/Pentest/BillingSecurityTest.php new_saas/backend/docs/pentest-billing-2026-05-27.md
git commit -m "test(security): Red Team pentest suite — 20 scenarios + report

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 8.2: PM Gate Phase 8

- [ ] PM: "Phase 8 complete — 20 scenarios, 0 critical/high outstanding. Proceed to Phase 9 (final review)?"

---

## Phase 9 — Final Code Review

### Task 9.1: Code-reviewer agent dispatch

- [ ] **Step 1: Dispatch superpowers:code-reviewer**

Agent receives:
- Spec: `docs/superpowers/specs/2026-05-27-brl-billing-design.md`
- Plan: this file
- Diff: `git diff master..feat/brl-billing`
- Pentest report: `docs/pentest-billing-2026-05-27.md`

Review criteria:
1. Implementation matches spec section-by-section
2. Integer cents everywhere (no float)
3. Snapshot pricing on MessageDispatch (cost_cents / sale_cents immutable)
4. lockForUpdate in BillingService for all multi-step writes
5. Audit log every financial action
6. Reversible migrations
7. No `env()` calls in runtime code (only via `config()`)
8. Idempotency on MP webhook + monthly_charge UNIQUE
9. Role middleware applied to all admin/billing routes
10. Email templates don't expose sensitive info

- [ ] **Step 2: Fix must-fix items**

Dispatch implementer subagent for each cluster of issues; re-run tests.

- [ ] **Step 3: Commit fixes**

### Task 9.2: PM Gate Phase 9

- [ ] PM: "Phase 9 review complete, all must-fix addressed. Ready to merge feat/brl-billing → master?"

---

## Phase 10 — Deploy

### Task 10.1: Pre-flight check command

**Files:**
- Create: `backend/app/Console/Commands/Billing/PreMigrationCheck.php`

- [ ] **Step 1: Command**

```php
<?php
namespace App\Console\Commands\Billing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PreMigrationCheck extends Command
{
    protected $signature = 'billing:pre-migration-check';
    protected $description = 'Pre-flight check before BRL billing migration';

    public function handle(): int
    {
        $checks = [
            'APP_ENV is staging or production' => fn() => in_array(app()->environment(), ['staging','production']),
            'BILLING_MIGRATION_PRICE_CENTS set' => fn() => env('BILLING_MIGRATION_PRICE_CENTS') !== null,
            'Zero pending jobs in messaging queue' => fn() => DB::table('jobs')->where('queue', 'messaging')->count() === 0,
            'Zero pending jobs in billing queue' => fn() => DB::table('jobs')->where('queue', 'billing')->count() === 0,
            'Zero campaigns in running state' => fn() => DB::table('campaigns')->where('status', 'running')->count() === 0,
            'MP credentials present' => fn() => !empty(env('MP_ACCESS_TOKEN')),
        ];

        $allOk = true;
        foreach ($checks as $name => $check) {
            $ok = $check();
            $this->line(($ok ? '<info>✓</info>' : '<error>✗</error>') . " {$name}");
            if (!$ok) $allOk = false;
        }

        if (!$allOk) {
            $this->error('Pre-flight check FAILED. Aborting migration.');
            return 1;
        }

        $tenantCount = DB::table('tenants')->count();
        $totalCredits = DB::table('tenants')->sum('credits_balance');
        $priceCents = (int) env('BILLING_MIGRATION_PRICE_CENTS', 15);
        $totalBalanceCents = $totalCredits * $priceCents;

        $this->info("Summary:");
        $this->table(['Metric', 'Value'], [
            ['Tenants to convert', $tenantCount],
            ['Total credits', $totalCredits],
            ['Resulting balance_cents', "R\$ " . number_format($totalBalanceCents / 100, 2, ',', '.')],
            ['Migration price (cents/credit)', $priceCents],
        ]);

        if (!$this->confirm('Continue with migration?', false)) {
            $this->info('Aborted by user.');
            return 0;
        }

        return 0;
    }
}
```

- [ ] **Step 2: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/app/Console/Commands/Billing/PreMigrationCheck.php
git commit -m "feat(billing): billing:pre-migration-check command

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 10.2: Rollback runbook

**Files:**
- Create: `backend/docs/runbooks/billing-rollback.md`

- [ ] **Step 1: Document the 3 rollback scenarios from spec §8.3**

Scenario A (migration fails mid-way), B (smoke test fails), C (problem hours later with new data).

For each: exact commands, what to expect, what data is preserved/lost, how to communicate with users.

- [ ] **Step 2: Commit**

```bash
cd c:/xampp/htdocs
git add new_saas/backend/docs/runbooks/billing-rollback.md
git commit -m "docs(billing): rollback runbook for 3 failure scenarios

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

### Task 10.3: Communication T-7 / T-1 / D (manual — PM owns)

- [ ] T-7 days: Email to all tenants (template in spec §8.4)
- [ ] T-1 day: Reminder + staging link
- [ ] Dia D: Post-migration email with new R$ balance
- [ ] T+30 days: NPS survey

### Task 10.4: Merge + deploy

- [ ] **Step 1: Final test on branch**

```bash
cd c:/xampp/htdocs/new_saas/backend && php artisan test
```
Expected: 100% PASS.

- [ ] **Step 2: Merge feat/brl-billing → master (fast-forward if possible)**

```bash
cd c:/xampp/htdocs
git checkout master
git merge --ff-only feat/brl-billing
```

- [ ] **Step 3: Deploy code to staging (without enabling migration yet)**

`BILLING_BRL_ENABLED=false` in staging .env. Smoke that the app still works on credit model.

- [ ] **Step 4: Run pre-migration check on staging**

```bash
php artisan billing:pre-migration-check
```

- [ ] **Step 5: Maintenance window — staging**

```bash
php artisan down --secret=stg-secret --refresh=30
php artisan migrate --force
php artisan db:seed --class=ServicePricesSeeder
# Set BILLING_BRL_ENABLED=true
php artisan config:clear
php artisan billing:status
# Smoke: send 1 SMS to test number, verify dispatch and balance correct
php artisan up
```

- [ ] **Step 6: Observe staging for 48h**

Check logs, dashboard metrics, sample some tenant balances vs prior values.

- [ ] **Step 7: Production deploy** (only after staging green + PM approval)

Repeat steps 3-5 in production.

- [ ] **Step 8: Final commit**

```bash
cd c:/xampp/htdocs
git commit --allow-empty -m "chore(billing): v2.0.0-brl deployed to production"
git tag v2.0.0-brl
```

### Task 10.5: Done

- [ ] PM declares: "BRL billing migration COMPLETE in production. Monitoring 48h."

---

## Out of Scope (documented for future)

1. **Postpaid faturamento mensal** — full invoicing model with NF-e generation
2. **Promotional pricing** (% discount, time-limited)
3. **Per-tenant cost override** (negotiated provider rates)
4. **Multi-currency** (USD, EUR for international tenants)
5. **HTMLPurifier** (already noted from messaging Phase 5)
6. **Frontend role 'finance' UI for user management** (manage finance team members)
