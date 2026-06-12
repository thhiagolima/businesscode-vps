<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve financial audit trail when a tenant is hard-deleted.
 *
 * Phase 8 Red Team finding #20: the original `credit_transactions` table
 * (now `balance_transactions` after migration 2026_05_27_000006) was
 * created with `ON DELETE CASCADE` on `tenant_id`. Combined with finding
 * #16 (TenantsController::destroy not blocking debtor delete), a single
 * superadmin API call could delete a debtor tenant AND erase every trace
 * of the debt — making post-hoc reconciliation impossible and breaking
 * the LGPD/CFC 5-year retention promise on financial records.
 *
 * Fix: switch the FK to ON DELETE SET NULL so the row survives the
 * tenant deletion as an orphan record retained for audit.
 */
return new class extends Migration {
    public function up(): void
    {
        // The FK was created by `foreignId('tenant_id')->constrained()` on
        // the original `credit_transactions` table. Schema::rename does not
        // rename constraints, so the carried-over name does not match the
        // current table — Laravel's `dropForeign(['tenant_id'])` would guess
        // `balance_transactions_tenant_id_foreign` and fail. Look up the
        // real name from INFORMATION_SCHEMA and drop it explicitly.
        $fk = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS name
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1',
            ['balance_transactions', 'tenant_id']
        );

        if ($fk) {
            DB::statement('ALTER TABLE balance_transactions DROP FOREIGN KEY `'.$fk->name.'`');
        }

        // Allow NULL so the FK can use SET NULL on tenant delete.
        DB::statement('ALTER TABLE balance_transactions MODIFY COLUMN tenant_id BIGINT UNSIGNED NULL');

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->foreign('tenant_id', 'balance_transactions_tenant_id_foreign')
              ->references('id')->on('tenants')
              ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Reverting requires deleting orphan rows first, otherwise the
        // NOT NULL change will fail with "Data truncated for column".
        // This is safe because down() is only used in dev rollback.
        DB::table('balance_transactions')->whereNull('tenant_id')->delete();

        $fk = DB::selectOne(
            'SELECT CONSTRAINT_NAME AS name
               FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
                AND REFERENCED_TABLE_NAME IS NOT NULL
              LIMIT 1',
            ['balance_transactions', 'tenant_id']
        );

        if ($fk) {
            DB::statement('ALTER TABLE balance_transactions DROP FOREIGN KEY `'.$fk->name.'`');
        }

        DB::statement('ALTER TABLE balance_transactions MODIFY COLUMN tenant_id BIGINT UNSIGNED NOT NULL');

        Schema::table('balance_transactions', function (Blueprint $t) {
            $t->foreign('tenant_id', 'credit_transactions_tenant_id_foreign')
              ->references('id')->on('tenants')
              ->cascadeOnDelete();
        });
    }
};
