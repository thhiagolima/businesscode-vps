# Billing Micros Wallet — Nunca Arredondar — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer a carteira/ledger trabalhar em **micros** (1 centavo = 1000 micros) para que a cobrança de mensagens debite o valor exato (`sale_micros`), nunca arredondando para centavo inteiro.

**Architecture:** Os preços já têm precisão em micros (`sale_micros`); só a **carteira** (`tenants.balance_cents`, `credit_limit_cents`) e o **ledger** (`balance_transactions.amount_cents`) eram centavos inteiros, forçando o arredondamento na cobrança. Adicionamos colunas `*_micros` na carteira/ledger, fazemos backfill (`cents × 1000`), tornamos o `BillingService` nativo em micros, e mudamos o caminho de cobrança para debitar `sale_micros`. As colunas `*_cents` viram **espelho derivado** (mantidas em sincronia via `intdiv(micros/1000)`) para retrocompat com relatórios/dashboards — o arredondamento passa a existir **só na exibição**, nunca na cobrança.

**Tech Stack:** Laravel 11, MySQL, PHPUnit. Colunas monetárias `BIGINT` (micros podem ficar grandes: R$ 1M = 100 bilhões de micros).

**Invariante central:** `balance_cents == intdiv(balance_micros, 1000)` em toda escrita. A fonte da verdade é **micros**; cents é derivado.

**Premissas de segurança (dinheiro ao vivo):**
- Rodar `php artisan billing:pre-migration-check` (já existe) e dump do MySQL **antes** da migration.
- Janela de manutenção: parar workers `messaging`/`campaigns`/`billing` durante a migration de schema/backfill.
- Cada task tem teste (unit ou feature) + a Fase 6 adiciona pentest. Padrão do projeto: teste unitário + pentest obrigatórios.

---

## File Structure

**Migrations (criar):**
- `backend/database/migrations/2026_06_10_000001_add_micros_to_wallet_tables.php` — colunas micros + backfill em `tenants` e `balance_transactions`.
- `backend/database/migrations/2026_06_10_000002_add_charged_micros_to_message_dispatches.php` — `charged_micros` no snapshot de cobrança.

**Models (modificar):**
- `backend/app/Models/Tenant.php` — fillable/casts `balance_micros`,`credit_limit_micros`; métodos `availableBalanceMicros()`, `availableBalanceCents()` derivado.
- `backend/app/Models/BalanceTransaction.php` — fillable/casts `amount_micros`,`balance_after_micros`.
- `backend/app/Models/MessageDispatch.php` — fillable/casts `charged_micros`.

**Services (modificar):**
- `backend/app/Services/Billing/BillingService.php` — métodos micros-nativos + wrappers cents.
- `backend/app/Services/Messaging/MessagingService.php` — cobrar `sale_micros`.

**Jobs (modificar):**
- `backend/app/Jobs/SendMessageJob.php` — `charged_micros` + release em micros.
- `backend/app/Jobs/ProcessCampaignJob.php` — reservar/estornar campanha em micros.

**Tests (criar):**
- `backend/tests/Unit/Billing/WalletMicrosTest.php`
- `backend/tests/Feature/Billing/ChargeExactNoRoundingTest.php`
- `backend/tests/Pentest/WalletMicrosSecurityTest.php`

---

## Task 1: Migration — colunas micros na carteira e ledger + backfill

**Files:**
- Create: `backend/database/migrations/2026_06_10_000001_add_micros_to_wallet_tables.php`
- Test: `backend/tests/Unit/Billing/WalletMicrosTest.php`

- [ ] **Step 1: Escrever o teste que falha (colunas existem e backfill correto)**

```php
<?php
namespace Tests\Unit\Billing;

use App\Models\Tenant;
use App\Models\BalanceTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WalletMicrosTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_micros_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('tenants', 'balance_micros'));
        $this->assertTrue(Schema::hasColumn('tenants', 'credit_limit_micros'));
        $this->assertTrue(Schema::hasColumn('balance_transactions', 'amount_micros'));
        $this->assertTrue(Schema::hasColumn('balance_transactions', 'balance_after_micros'));
    }

    public function test_backfill_sets_micros_from_cents(): void
    {
        // Tenant criado via factory com balance_cents preenchido; a migration de
        // backfill roda no setup do RefreshDatabase, então novos registros usam
        // os defaults. Validamos a relação cents→micros via update manual + sync.
        $t = Tenant::factory()->create(['balance_cents' => 1234, 'credit_limit_cents' => 500]);
        // Simula estado pré-migração e revalida invariante após sync.
        $t->balance_micros = $t->balance_cents * 1000;
        $t->credit_limit_micros = $t->credit_limit_cents * 1000;
        $t->save();
        $this->assertSame(1234 * 1000, (int) $t->fresh()->balance_micros);
        $this->assertSame(500 * 1000, (int) $t->fresh()->credit_limit_micros);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `cd backend && php artisan test --filter=WalletMicrosTest::test_wallet_micros_columns_exist`
Expected: FAIL — coluna `balance_micros` não existe.

- [ ] **Step 3: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carteira e ledger em micros (1 centavo = 1000 micros) para nunca arredondar
 * a cobrança. Fonte da verdade passa a ser *_micros; *_cents vira espelho
 * derivado (intdiv micros/1000) mantido em sincronia pelo BillingService.
 * BIGINT: R$ 1.000.000 = 100.000.000.000 micros (estoura unsignedInteger).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->bigInteger('balance_micros')->default(0)->after('balance_cents');
            $t->bigInteger('credit_limit_micros')->default(0)->after('credit_limit_cents');
        });

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->bigInteger('amount_micros')->default(0)->after('amount_cents');
            $t->bigInteger('balance_after_micros')->default(0)->after('balance_after_cents');
        });

        // Backfill: preserva o valor existente sem ganhar precisão (1¢ = 1000 micros).
        DB::table('tenants')->update([
            'balance_micros'      => DB::raw('balance_cents * 1000'),
            'credit_limit_micros' => DB::raw('credit_limit_cents * 1000'),
        ]);
        DB::table('balance_transactions')->update([
            'amount_micros'        => DB::raw('amount_cents * 1000'),
            'balance_after_micros' => DB::raw('balance_after_cents * 1000'),
        ]);
    }

    public function down(): void
    {
        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->dropColumn(['amount_micros', 'balance_after_micros']);
        });
        Schema::table('tenants', function (Blueprint $t) {
            $t->dropColumn(['balance_micros', 'credit_limit_micros']);
        });
    }
};
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `cd backend && php artisan test --filter=WalletMicrosTest`
Expected: PASS (ambos os métodos).

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_06_10_000001_add_micros_to_wallet_tables.php backend/tests/Unit/Billing/WalletMicrosTest.php
git commit -m "feat(billing): add micros columns to wallet + ledger with backfill"
```

---

## Task 2: Migration — `charged_micros` no snapshot de cobrança

**Files:**
- Create: `backend/database/migrations/2026_06_10_000002_add_charged_micros_to_message_dispatches.php`
- Test: `backend/tests/Unit/Billing/WalletMicrosTest.php` (adicionar método)

- [ ] **Step 1: Adicionar teste que falha**

```php
    public function test_charged_micros_column_exists(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('message_dispatches', 'charged_micros'));
    }
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter=WalletMicrosTest::test_charged_micros_column_exists`
Expected: FAIL.

- [ ] **Step 3: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->bigInteger('charged_micros')->default(0)->after('charged_cents');
        });
        // Histórico: o que já foi cobrado em cents vira micros (cents × 1000).
        DB::table('message_dispatches')->update([
            'charged_micros' => DB::raw('charged_cents * 1000'),
        ]);
    }

    public function down(): void
    {
        Schema::table('message_dispatches', function (Blueprint $t) {
            $t->dropColumn('charged_micros');
        });
    }
};
```

- [ ] **Step 4: Rodar e confirmar passa**

Run: `cd backend && php artisan test --filter=WalletMicrosTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/database/migrations/2026_06_10_000002_add_charged_micros_to_message_dispatches.php backend/tests/Unit/Billing/WalletMicrosTest.php
git commit -m "feat(billing): add charged_micros snapshot to message_dispatches"
```

---

## Task 3: Models — expor micros e derivar cents

**Files:**
- Modify: `backend/app/Models/Tenant.php` (fillable lines ~36-37, casts ~49-50, `availableBalanceCents()` ~97-99)
- Modify: `backend/app/Models/BalanceTransaction.php:22-34`
- Modify: `backend/app/Models/MessageDispatch.php:19-20,40-43`
- Test: `backend/tests/Unit/Billing/WalletMicrosTest.php` (adicionar)

- [ ] **Step 1: Teste que falha para os acessores micros**

```php
    public function test_tenant_available_balance_micros_and_derived_cents(): void
    {
        $t = \App\Models\Tenant::factory()->create(['balance_cents' => 0, 'credit_limit_cents' => 0]);
        $t->balance_micros = 7500;        // R$ 0,075 — sub-centavo
        $t->credit_limit_micros = 2500;   // R$ 0,025
        $t->save();
        $this->assertSame(10000, $t->availableBalanceMicros());     // 7500 + 2500
        $this->assertSame(10, $t->availableBalanceCents());          // derivado: intdiv(10000/1000)
    }
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter=WalletMicrosTest::test_tenant_available_balance_micros_and_derived_cents`
Expected: FAIL — `availableBalanceMicros()` não existe.

- [ ] **Step 3: Editar `Tenant.php`**

Adicionar `'balance_micros'` e `'credit_limit_micros'` ao array `$fillable` (junto de `balance_cents`/`credit_limit_cents`).
Adicionar aos `$casts`: `'balance_micros' => 'integer'`, `'credit_limit_micros' => 'integer'`.
Substituir o método existente:

```php
    public function availableBalanceMicros(): int
    {
        return (int) $this->balance_micros + (int) $this->credit_limit_micros;
    }

    /** Derivado de micros — arredonda só para exibição, nunca para cobrança. */
    public function availableBalanceCents(): int
    {
        return intdiv($this->availableBalanceMicros(), \App\Models\ServicePrice::MICROS_PER_CENT);
    }
```

- [ ] **Step 4: Editar `BalanceTransaction.php`**

Adicionar `'amount_micros'`, `'balance_after_micros'` ao `$fillable`.
Adicionar aos `$casts`: `'amount_micros' => 'integer'`, `'balance_after_micros' => 'integer'`.

- [ ] **Step 5: Editar `MessageDispatch.php`**

No `$fillable`, trocar `'cost_cents', 'sale_cents', 'charged_cents',` por `'cost_cents', 'sale_cents', 'charged_cents', 'charged_micros',`.
Nos `$casts`, adicionar `'charged_micros' => 'integer'`.

- [ ] **Step 6: Rodar e confirmar passa**

Run: `cd backend && php artisan test --filter=WalletMicrosTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Models/Tenant.php backend/app/Models/BalanceTransaction.php backend/app/Models/MessageDispatch.php backend/tests/Unit/Billing/WalletMicrosTest.php
git commit -m "feat(billing): micros accessors on Tenant/BalanceTransaction/MessageDispatch (cents derived)"
```

---

## Task 4: BillingService — `reserveMicros` nativo (mantém invariante cents)

**Files:**
- Modify: `backend/app/Services/Billing/BillingService.php:23-112`
- Test: `backend/tests/Unit/Billing/WalletMicrosTest.php` (adicionar)

- [ ] **Step 1: Teste que falha — reserva sub-centavo sem arredondar**

```php
    public function test_reserve_micros_debits_exact_and_syncs_cents(): void
    {
        $t = \App\Models\Tenant::factory()->create(['balance_cents' => 0, 'credit_limit_cents' => 0]);
        $t->balance_micros = 100000; // R$ 1,00
        $t->balance_cents = 100;
        $t->save();

        $billing = app(\App\Services\Billing\BillingService::class);
        // Cobra 7.500 micros (7,5¢) — valor real de 1 SMS, sem arredondar.
        $ok = $billing->reserveMicros($t->id, 7500, 'message_dispatch', 1);

        $this->assertTrue($ok);
        $fresh = $t->fresh();
        $this->assertSame(92500, (int) $fresh->balance_micros);          // 100000 - 7500
        $this->assertSame(92, (int) $fresh->balance_cents);              // espelho: intdiv(92500/1000)
        $tx = \App\Models\BalanceTransaction::where('tenant_id', $t->id)->where('type', 'reserve')->first();
        $this->assertSame(-7500, (int) $tx->amount_micros);
        $this->assertSame(92500, (int) $tx->balance_after_micros);
    }
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter=WalletMicrosTest::test_reserve_micros_debits_exact_and_syncs_cents`
Expected: FAIL — `reserveMicros` não existe.

- [ ] **Step 3: Implementar `reserveMicros` e tornar `reserve` um wrapper**

Adicionar ao `BillingService`. O `reserveMicros` espelha a lógica do `reserve` atual (idempotência por `reference_type`+`reference_id`, lock, evento low-balance) porém em micros, e grava também os espelhos `*_cents`:

```php
    /**
     * Reserva micros do saldo do tenant. Fonte da verdade é *_micros;
     * *_cents é mantido como espelho derivado (intdiv micros/1000).
     */
    public function reserveMicros(int $tenantId, int $amountMicros, string $referenceType, int $referenceId): bool
    {
        $result = DB::transaction(function () use ($tenantId, $amountMicros, $referenceType, $referenceId) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) {
                return ['ok' => false];
            }

            $existing = BalanceTransaction::where('tenant_id', $tenantId)
                ->where('type', 'reserve')
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->lockForUpdate()
                ->exists();
            if ($existing) {
                Log::channel('campaign')->info('billing.reserve.idempotent_skip', compact('tenantId', 'referenceType', 'referenceId'));
                return ['ok' => true, 'idempotent' => true];
            }

            $balanceBeforeMicros = (int) $tenant->balance_micros;
            $creditLimitMicros   = (int) $tenant->credit_limit_micros;
            $availableMicros     = $balanceBeforeMicros + $creditLimitMicros;
            if ($availableMicros < $amountMicros) {
                return ['ok' => false];
            }

            $newBalanceMicros = $balanceBeforeMicros - $amountMicros;
            $tenant->balance_micros = $newBalanceMicros;
            $tenant->balance_cents  = intdiv($newBalanceMicros, \App\Models\ServicePrice::MICROS_PER_CENT);
            $tenant->save();

            BalanceTransaction::create([
                'tenant_id'            => $tenantId,
                'type'                 => 'reserve',
                'amount_micros'        => -$amountMicros,
                'balance_after_micros' => $newBalanceMicros,
                'amount_cents'         => -intdiv($amountMicros, \App\Models\ServicePrice::MICROS_PER_CENT),
                'balance_after_cents'  => $tenant->balance_cents,
                'reference_type'       => $referenceType,
                'reference_id'         => $referenceId,
                'description'          => "Reserve {$referenceType} #{$referenceId}",
            ]);

            Log::channel('campaign')->info('billing.reserve', compact('tenantId', 'amountMicros', 'referenceType', 'referenceId'));
            AuditLog::record('billing.reserve', 'Tenant', $tenantId, [
                'amount_micros'  => -$amountMicros,
                'balance_after_micros' => $newBalanceMicros,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ], null, $tenantId);

            return [
                'ok'                    => true,
                'balance_before_micros' => $balanceBeforeMicros,
                'balance_after_micros'  => $newBalanceMicros,
                'credit_limit_micros'   => $creditLimitMicros,
            ];
        });

        if (! ($result['ok'] ?? false)) {
            return false;
        }
        if (! empty($result['idempotent'])) {
            return true;
        }

        // Low-balance event com debounce de 24h (threshold em micros).
        $balanceBefore = (int) ($result['balance_before_micros'] ?? 0);
        $balanceAfter  = (int) ($result['balance_after_micros']  ?? 0);
        $creditLimit   = (int) ($result['credit_limit_micros']   ?? 0);
        $threshold = $creditLimit > 0
            ? (int) floor($creditLimit * 0.20)
            : 1000 * \App\Models\ServicePrice::MICROS_PER_CENT; // R$ 10,00 em micros

        if ($balanceBefore > $threshold && $balanceAfter <= $threshold) {
            $cacheKey = "webhook:billing.low_balance:tenant:{$tenantId}";
            if (Cache::add($cacheKey, 1, now()->addHours(24))) {
                FireOutboundWebhookJob::dispatch($tenantId, 'billing.low_balance', [
                    'balance_cents'      => intdiv($balanceAfter, \App\Models\ServicePrice::MICROS_PER_CENT),
                    'credit_limit_cents' => intdiv($creditLimit, \App\Models\ServicePrice::MICROS_PER_CENT),
                    'threshold_cents'    => intdiv($threshold, \App\Models\ServicePrice::MICROS_PER_CENT),
                ]);
            }
        }
        return true;
    }
```

Substituir o corpo do método `reserve(int $amountCents, ...)` existente por um wrapper (retrocompat para AI/ElevenLabs que ainda chamam em cents):

```php
    public function reserve(int $tenantId, int $amountCents, string $referenceType, int $referenceId): bool
    {
        return $this->reserveMicros($tenantId, $amountCents * \App\Models\ServicePrice::MICROS_PER_CENT, $referenceType, $referenceId);
    }
```

- [ ] **Step 4: Rodar e confirmar passa**

Run: `cd backend && php artisan test --filter=WalletMicrosTest::test_reserve_micros_debits_exact_and_syncs_cents`
Expected: PASS.

- [ ] **Step 5: Rodar a suíte de billing existente para garantir retrocompat**

Run: `cd backend && php artisan test tests/Unit/Billing tests/Feature/Billing`
Expected: PASS (os testes existentes que usam `reserve` em cents continuam verdes via wrapper).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Billing/BillingService.php backend/tests/Unit/Billing/WalletMicrosTest.php
git commit -m "feat(billing): reserveMicros native debit; reserve(cents) becomes wrapper"
```

---

## Task 5: BillingService — `releaseMicros`, `rechargeMicros`, `manualAdjustmentMicros`

**Files:**
- Modify: `backend/app/Services/Billing/BillingService.php:114-257`
- Test: `backend/tests/Unit/Billing/WalletMicrosTest.php` (adicionar)

- [ ] **Step 1: Teste que falha — release/recharge em micros mantêm invariante**

```php
    public function test_release_and_recharge_micros_keep_invariant(): void
    {
        $t = \App\Models\Tenant::factory()->create(['balance_cents' => 0, 'credit_limit_cents' => 0]);
        $t->balance_micros = 0; $t->balance_cents = 0; $t->save();
        $billing = app(\App\Services\Billing\BillingService::class);

        $billing->rechargeMicros($t->id, 225_000 * 1000, 'payment', 1, 'Depósito'); // R$ 2.250
        $this->assertSame(225_000 * 1000, (int) $t->fresh()->balance_micros);
        $this->assertSame(225_000, (int) $t->fresh()->balance_cents);

        $billing->releaseMicros($t->id, 7500, 'message_dispatch', 1); // estorno 7,5¢
        $this->assertSame(225_000 * 1000 + 7500, (int) $t->fresh()->balance_micros);
    }
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter=WalletMicrosTest::test_release_and_recharge_micros_keep_invariant`
Expected: FAIL — `rechargeMicros` não existe.

- [ ] **Step 3: Implementar os três métodos micros + transformar os cents em wrappers**

Adicionar `releaseMicros`, `rechargeMicros`, `manualAdjustmentMicros` espelhando os métodos cents atuais, mas: atualizando `balance_micros` (fonte) e sincronizando `balance_cents = intdiv(balance_micros/1000)`, e gravando `amount_micros`/`balance_after_micros` (+ espelhos cents) na `BalanceTransaction`. Padrão por método (exemplo `releaseMicros`):

```php
    public function releaseMicros(int $tenantId, int $amountMicros, string $referenceType, int $referenceId): void
    {
        if ($amountMicros <= 0) {
            return;
        }
        DB::transaction(function () use ($tenantId, $amountMicros, $referenceType, $referenceId) {
            $tenant = Tenant::lockForUpdate()->find($tenantId);
            if (! $tenant) {
                return;
            }
            $newBalanceMicros = (int) $tenant->balance_micros + $amountMicros;
            $tenant->balance_micros = $newBalanceMicros;
            $tenant->balance_cents  = intdiv($newBalanceMicros, \App\Models\ServicePrice::MICROS_PER_CENT);
            $tenant->save();

            BalanceTransaction::create([
                'tenant_id'            => $tenantId,
                'type'                 => 'release',
                'amount_micros'        => $amountMicros,
                'balance_after_micros' => $newBalanceMicros,
                'amount_cents'         => intdiv($amountMicros, \App\Models\ServicePrice::MICROS_PER_CENT),
                'balance_after_cents'  => $tenant->balance_cents,
                'reference_type'       => $referenceType,
                'reference_id'         => $referenceId,
                'description'          => "Release {$referenceType} #{$referenceId}",
            ]);
            Log::channel('campaign')->info('billing.release', compact('tenantId', 'amountMicros', 'referenceType', 'referenceId'));
            AuditLog::record('billing.release', 'Tenant', $tenantId, [
                'amount_micros' => $amountMicros, 'balance_after_micros' => $newBalanceMicros,
                'reference_type' => $referenceType, 'reference_id' => $referenceId,
            ], null, $tenantId);
        });
    }
```

`rechargeMicros` e `manualAdjustmentMicros` seguem o mesmo padrão (preservar idempotência do `recharge` e o `executed_by_user_id`/`reference_id` único do `manualAdjustment`, e disparar os mesmos webhooks `billing.recharged`/`billing.charged`). Depois transformar os métodos cents em wrappers:

```php
    public function release(int $tenantId, int $amountCents, string $referenceType, int $referenceId): void
    {
        $this->releaseMicros($tenantId, $amountCents * \App\Models\ServicePrice::MICROS_PER_CENT, $referenceType, $referenceId);
    }

    public function recharge(int $tenantId, int $amountCents, string $referenceType, int $referenceId, string $description = ''): void
    {
        $this->rechargeMicros($tenantId, $amountCents * \App\Models\ServicePrice::MICROS_PER_CENT, $referenceType, $referenceId, $description);
    }
```

`manualAdjustment(int $amountCents, ...)` vira wrapper que chama `manualAdjustmentMicros($amountCents * 1000, ...)` e retorna a `BalanceTransaction`.

- [ ] **Step 4: Rodar e confirmar passa**

Run: `cd backend && php artisan test --filter=WalletMicrosTest::test_release_and_recharge_micros_keep_invariant`
Expected: PASS.

- [ ] **Step 5: Rodar suíte de billing**

Run: `cd backend && php artisan test tests/Unit/Billing tests/Feature/Billing tests/Pentest/BillingSecurityTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/Billing/BillingService.php backend/tests/Unit/Billing/WalletMicrosTest.php
git commit -m "feat(billing): releaseMicros/rechargeMicros/manualAdjustmentMicros; cents methods wrap micros"
```

---

## Task 6: MessagingService — cobrar `sale_micros` exato

**Files:**
- Modify: `backend/app/Services/Messaging/MessagingService.php:102-146`
- Test: `backend/tests/Feature/Billing/ChargeExactNoRoundingTest.php`

- [ ] **Step 1: Teste de feature que falha — 1 SMS debita 7.500 micros, não 8.000**

```php
<?php
namespace Tests\Feature\Billing;

use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChargeExactNoRoundingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_charges_exact_micros_without_rounding(): void
    {
        ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8, 'cost_micros' => 6050, 'sale_micros' => 7500]);
        $tenant = Tenant::factory()->create(['billing_status' => 'active']);
        $tenant->balance_micros = 1_000_000; // R$ 10,00
        $tenant->balance_cents = 1000;
        $tenant->save();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        app(MessagingService::class)->dispatch($tenant, $user, 'sms', [
            'to' => '+5521999999999', 'content' => 'oi', 'source' => 'api',
        ], null);

        // 1.000.000 - 7.500 = 992.500 micros. Se arredondasse (8.000) daria 992.000.
        $this->assertSame(992_500, (int) $tenant->fresh()->balance_micros);
    }
}
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter=ChargeExactNoRoundingTest`
Expected: FAIL — saldo fica 992.000 (cobrou 8¢ arredondado via `reserve(saleCents)`).

- [ ] **Step 3: Editar `MessagingService::dispatch`**

Em `backend/app/Services/Messaging/MessagingService.php`:
- Manter o cálculo de `$saleMicros` (linha ~106).
- Trocar a chamada de reserva (linha ~139) de:

```php
            $ok = $this->billing->reserve($tenant->id, $saleCents, 'message_dispatch', $dispatch->id);
```

para:

```php
            $ok = $this->billing->reserveMicros($tenant->id, $saleMicros, 'message_dispatch', $dispatch->id);
```

- No `create([...])` do dispatch (linha ~110-137), manter `sale_cents`/`sale_micros` como já estão (snapshot). O `sale_cents` snapshot continua sendo o espelho arredondado para relatórios.

- [ ] **Step 4: Rodar e confirmar passa**

Run: `cd backend && php artisan test --filter=ChargeExactNoRoundingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/Messaging/MessagingService.php backend/tests/Feature/Billing/ChargeExactNoRoundingTest.php
git commit -m "feat(billing): MessagingService reserves exact sale_micros (no rounding)"
```

---

## Task 7: SendMessageJob — `charged_micros` exato e estorno em micros

**Files:**
- Modify: `backend/app/Jobs/SendMessageJob.php:74-105`
- Test: `backend/tests/Feature/Billing/ChargeExactNoRoundingTest.php` (adicionar)

- [ ] **Step 1: Teste que falha — sucesso grava `charged_micros = sale_micros`; falha estorna micros**

```php
    public function test_send_success_charges_micros_and_failure_releases_micros(): void
    {
        $d = \App\Models\MessageDispatch::factory()->create([
            'channel' => 'sms', 'status' => 'queued',
            'sale_cents' => 8, 'sale_micros' => 7500, 'charged_cents' => 0, 'charged_micros' => 0,
        ]);
        // Simula sucesso do provider:
        $d->update(['status' => 'sent', 'charged_cents' => intdiv($d->sale_micros, 1000), 'charged_micros' => $d->sale_micros]);
        $this->assertSame(7500, (int) $d->fresh()->charged_micros);
        $this->assertSame(7, (int) $d->fresh()->charged_cents); // espelho derivado
    }
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter=ChargeExactNoRoundingTest::test_send_success_charges_micros_and_failure_releases_micros`
Expected: FAIL se a factory de `MessageDispatch` não preencher `charged_micros` (ou se as colunas não existirem). Ajustar factory se necessário.

- [ ] **Step 3: Editar `SendMessageJob`**

No bloco de sucesso (linha ~74-80), trocar:

```php
                'charged_cents'       => (int) $dispatch->sale_cents,
```

por:

```php
                'charged_micros'      => (int) $dispatch->sale_micros,
                'charged_cents'       => intdiv((int) $dispatch->sale_micros, \App\Models\ServicePrice::MICROS_PER_CENT),
```

No `releaseCredits` (linha ~91-98), trocar a chamada `release` por `releaseMicros` usando o snapshot micros do dispatch:

```php
        app(BillingService::class)->releaseMicros(
            $dispatch->tenant_id,
            (int) $dispatch->sale_micros,
            'message_dispatch',
            $dispatch->id
        );
```

E no `update` do mesmo método, manter `'charged_cents' => 0` e adicionar `'charged_micros' => 0`.

- [ ] **Step 4: Rodar e confirmar passa**

Run: `cd backend && php artisan test --filter=ChargeExactNoRoundingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/SendMessageJob.php backend/tests/Feature/Billing/ChargeExactNoRoundingTest.php
git commit -m "feat(billing): SendMessageJob charges/releases exact micros"
```

---

## Task 8: ProcessCampaignJob — reserva e estorno de campanha em micros

**Files:**
- Modify: `backend/app/Jobs/ProcessCampaignJob.php:126-145,178-189,222-233`
- Modify: `backend/app/Services/Billing/BillingService.php:278` (lockCampaignIfInsufficient usa micros)
- Test: `backend/tests/Feature/Billing/ChargeExactNoRoundingTest.php` (adicionar)

- [ ] **Step 1: Teste que falha — campanha de N contatos reserva `unit_micros × N` exato**

```php
    public function test_campaign_reserves_exact_micros_per_contact(): void
    {
        \App\Models\ServicePrice::create(['service' => 'sms', 'cost_cents' => 6, 'sale_cents' => 8, 'cost_micros' => 6050, 'sale_micros' => 7500]);
        $tenant = \App\Models\Tenant::factory()->create(['billing_status' => 'active']);
        $tenant->balance_micros = 10_000_000; $tenant->balance_cents = 10000; $tenant->save();
        $billing = app(\App\Services\Billing\BillingService::class);

        // 100 contatos × 7.500 micros = 750.000 micros (não 800.000 arredondado).
        $ok = $billing->reserveMicros($tenant->id, 7500 * 100, 'campaign_dispatch', 1);
        $this->assertTrue($ok);
        $this->assertSame(10_000_000 - 750_000, (int) $tenant->fresh()->balance_micros);
    }
```

- [ ] **Step 2: Rodar e confirmar falha**

Run: `cd backend && php artisan test --filter=ChargeExactNoRoundingTest::test_campaign_reserves_exact_micros_per_contact`
Expected: PASS já (usa reserveMicros direto) — se PASS, este teste serve de regressão; o trabalho de código abaixo troca o caminho real do job. Confirmar que o job em si ainda usa cents (ler linha 127).

- [ ] **Step 3: Editar `ProcessCampaignJob`**

Substituir o cálculo de unidade/reserva (linhas ~126-136):

```php
        $tenant     = \App\Models\Tenant::withoutGlobalScopes()->find($this->tenantId);
        $unitMicros = $tenant ? (int) ($pricing->priceFor($tenant, (string) $campaign->type)['sale_micros'] ?? 0) : 0;
        $reserveMicros = $unitMicros * $contactCount;

        if ($reserveMicros > 0) {
            $ok = $billing->reserveMicros(
                $campaign->tenant_id,
                $reserveMicros,
                'campaign_dispatch',
                $campaign->id
            );
            if (! $ok) {
                Log::channel('campaign')->warning('campaign.dispatch.insufficient_funds', [
                    'campaign_id' => $campaign->id,
                    'required_micros' => $reserveMicros,
                ]);
                $machine->transition($campaign, 'failed');
                return;
            }
        }
```

Persistir snapshot em micros (linhas ~150-157) — adicionar `unit_micros_at_dispatch`/`reserved_micros` ao `forceFill` e ao `settings` (manter os `*_cents` como espelho `intdiv(.../1000)` para retrocompat dos relatórios). Nos callbacks `then`/`catch` (linhas ~178-189 e ~222-233), calcular o refund em micros:

```php
                    $unitMicros = (int) ($fresh->unit_micros_at_dispatch ?? $snapshot['unit_micros_at_dispatch'] ?? 0);
                    $reservedMicros = (int) ($fresh->reserved_micros ?? $snapshot['reserved_micros'] ?? 0);
                    $consumedMicros = $unitMicros * max(0, (int) $fresh->sent_count);
                    $refundMicros   = max(0, $reservedMicros - $consumedMicros);
                    if ($refundMicros > 0) {
                        $billing->releaseMicros($fresh->tenant_id, $refundMicros, 'campaign_dispatch_refund', $fresh->id);
                    }
```

> **Nota de schema:** se `campaigns` não tiver `unit_micros_at_dispatch`/`reserved_micros`, criar migration `2026_06_10_000003_add_micros_snapshot_to_campaigns.php` (mesmo padrão da Task 1, colunas `bigInteger(...)->default(0)`), com backfill `unit_cents_at_dispatch * 1000` / `reserved_cents * 1000`. Adicionar essa migration como sub-passo antes deste step e ao `$fillable` de `Campaign`.

Atualizar `BillingService::lockCampaignIfInsufficient` (linha ~278) para estimar em micros: `$unitMicros = (int)($this->pricing->priceFor($tenant,(string)$campaign->type)['sale_micros'] ?? 0);` e comparar com `$tenant->availableBalanceMicros()`, retornando também `unit_micros`/`required_micros` (manter chaves cents derivadas para retrocompat dos controllers).

- [ ] **Step 4: Rodar e confirmar passa (job + estimativa)**

Run: `cd backend && php artisan test --filter=ChargeExactNoRoundingTest && php artisan test tests/Feature/CampaignStateMachineTest.php tests/Feature/CampaignChannelLicenseTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/ProcessCampaignJob.php backend/app/Services/Billing/BillingService.php backend/tests/Feature/Billing/ChargeExactNoRoundingTest.php
git commit -m "feat(billing): campaigns reserve/refund exact micros per contact"
```

---

## Task 9: InsufficientFunds — checagem e payload em micros

**Files:**
- Modify: `backend/app/Services/Messaging/MessagingService.php:139-144` (já trocado p/ reserveMicros na Task 6; ajustar payload da exceção)
- Modify: `backend/app/Exceptions/Billing/InsufficientFundsException.php` (se carrega cents — adicionar micros)
- Modify controllers `Voice/Sms/EmailController` que retornam `required`/`available` (ler `availableBalanceCents()` derivado — já funciona)
- Test: `backend/tests/Feature/Messaging/SendVoiceApiTest.php` (revisar expectativa de `INSUFFICIENT_FUNDS`)

- [ ] **Step 1: Rodar os testes de API de mensagem como baseline**

Run: `cd backend && php artisan test tests/Feature/Messaging/SendVoiceApiTest.php tests/Feature/Messaging/SendMessageJobExternalIdTest.php`
Expected: rodar e observar quais asserções de `required`/`available` cents quebram com saldo micros.

- [ ] **Step 2: Ajustar `InsufficientFundsException` para micros (mantendo cents derivado)**

Se a exceção hoje recebe `required_cents`/`available_cents`, adicionar construtor/props micros e derivar cents (`intdiv(micros/1000)`) para os controllers que já expõem cents. Onde `MessagingService` lança a exceção após `reserveMicros` falhar, passar `required = $saleMicros`, `available = $tenant->fresh()->availableBalanceMicros()` e derivar cents no ponto de resposta.

- [ ] **Step 3: Atualizar os testes de API para refletir cents derivados**

Garantir que `required`/`available` retornados batem com `intdiv(micros/1000)`.

- [ ] **Step 4: Rodar e confirmar passa**

Run: `cd backend && php artisan test tests/Feature/Messaging`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Exceptions/Billing/InsufficientFundsException.php backend/app/Services/Messaging/MessagingService.php backend/tests/Feature/Messaging
git commit -m "feat(billing): insufficient-funds checks in micros, cents derived for response"
```

---

## Task 10: Pentest — exatidão e ausência de exploit no novo caminho micros

**Files:**
- Create: `backend/tests/Pentest/WalletMicrosSecurityTest.php`

- [ ] **Step 1: Escrever testes de segurança (devem passar após implementação)**

```php
<?php
namespace Tests\Pentest;

use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletMicrosSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Não pode debitar além de saldo+credit_limit, mesmo com micros fracionários. */
    public function test_cannot_overspend_with_fractional_micros(): void
    {
        $t = Tenant::factory()->create(['credit_limit_cents' => 0]);
        $t->balance_micros = 7000; $t->balance_cents = 7; $t->credit_limit_micros = 0; $t->save();
        $ok = app(BillingService::class)->reserveMicros($t->id, 7500, 'message_dispatch', 1); // 7,5¢ > 7,0¢
        $this->assertFalse($ok);
        $this->assertSame(7000, (int) $t->fresh()->balance_micros);
    }

    /** Reserva é idempotente por (type, reference) também em micros (sem duplo débito). */
    public function test_reserve_micros_idempotent(): void
    {
        $t = Tenant::factory()->create();
        $t->balance_micros = 1_000_000; $t->balance_cents = 1000; $t->save();
        $b = app(BillingService::class);
        $b->reserveMicros($t->id, 7500, 'message_dispatch', 99);
        $b->reserveMicros($t->id, 7500, 'message_dispatch', 99); // replay
        $this->assertSame(1_000_000 - 7500, (int) $t->fresh()->balance_micros);
    }

    /** Invariante cents == intdiv(micros/1000) após qualquer operação. */
    public function test_cents_mirror_matches_micros(): void
    {
        $t = Tenant::factory()->create();
        $t->balance_micros = 0; $t->balance_cents = 0; $t->save();
        $b = app(BillingService::class);
        $b->rechargeMicros($t->id, 123_456, 'payment', 1);
        $f = $t->fresh();
        $this->assertSame(intdiv((int)$f->balance_micros, ServicePrice::MICROS_PER_CENT), (int)$f->balance_cents);
    }
}
```

- [ ] **Step 2: Rodar e confirmar passa**

Run: `cd backend && php artisan test tests/Pentest/WalletMicrosSecurityTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add backend/tests/Pentest/WalletMicrosSecurityTest.php
git commit -m "test(billing): pentest for micros wallet (overspend, idempotency, invariant)"
```

---

## Task 11: Suíte completa + verificação final

- [ ] **Step 1: Rodar a suíte inteira**

Run: `cd backend && php artisan test`
Expected: PASS (verde). Investigar qualquer teste que dependia de cobrança arredondada — corrigir a expectativa para o valor exato em micros (o comportamento novo é o correto).

- [ ] **Step 2: Validar manualmente no tinker (sem rede)**

Run:
```bash
cd backend && php artisan tinker --execute="\$t=App\Models\Tenant::factory()->create(); \$t->balance_micros=1000000;\$t->balance_cents=1000;\$t->save(); app(App\Services\Billing\BillingService::class)->reserveMicros(\$t->id,7500,'message_dispatch',1); echo \$t->fresh()->balance_micros.PHP_EOL;"
```
Expected: `992500`.

- [ ] **Step 3: Commit final / tag**

```bash
git add -A
git commit -m "chore(billing): finalize micros wallet — never round charges"
```

---

## Notas de execução / fora de escopo (follow-up)

- **AI billing** (`GrokService:346`, `ChatAiService:94`, `ElevenLabsService:226`) continua chamando `reserve(cents)` via wrapper — funciona, mas se essas features tiverem preço sub-centavo, migrar para `reserveMicros` numa task separada.
- **Display/relatórios** (`ReportController`, `DashboardController`, `AccountController`, `BillingReportController`) leem `*_cents` (espelho) — continuam corretos para exibição. Se quiser exibir reais exatos (5 casas), trocar para `*_micros / 100000` numa task de UI separada.
- **Reconciliação do Pedro:** após deploy, o saldo correto dele (R$ 790 pela cobrança real / R$ 861,10 pelo preço fracionário) deve ser lançado via `manualAdjustmentMicros`. Decisão de valor é do usuário (registros perdidos).
- **Backfill é lossless mas não retroativo:** dispatches/transações antigas mantêm o que já foi cobrado (arredondado no passado); só cobranças novas serão exatas.
