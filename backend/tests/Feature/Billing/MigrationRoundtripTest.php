<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Migration roundtrip: roll the Phase-1 BRL-billing migrations (and everything stacked on
 * top of them) back down, then re-apply, and verify the schema ends up consistent.
 *
 * Isolation note (P0R-03 redux): this test mutates the shared MySQL schema with real DDL
 * (migrate:rollback / migrate), which is NOT covered by RefreshDatabase's transaction. It
 * previously used DatabaseMigrations, whose teardown rollback left the shared database in a
 * partially-rolled-back state (only the first ~36 migrations applied); because every other
 * class uses RefreshDatabase — which migrates only ONCE per process — they then ran against
 * that broken schema and failed en masse with "Unknown column 'listed'"-style errors.
 *
 * We therefore manage isolation explicitly: no auto-migrating trait, and tearDown() always
 * rebuilds a full, current schema (db:wipe-based, FK-safe) and marks RefreshDatabase as
 * migrated so sibling classes inherit a complete schema.
 */
class MigrationRoundtripTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Guarantee a complete baseline regardless of what a previous class left behind.
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
    }

    protected function tearDown(): void
    {
        // Restore a full, current schema for whatever class runs next.
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
        parent::tearDown();
    }

    public function test_rollback_then_migrate_leaves_consistent_state(): void
    {
        // Sanity baseline
        $this->assertTrue(Schema::hasTable('service_prices'));
        $this->assertTrue(Schema::hasColumn('plans', 'included_balance_cents'));
        $this->assertTrue(Schema::hasColumn('plans', 'listed'));
        $this->assertTrue(Schema::hasColumn('balance_transactions', 'monthly_cycle_key'));

        // The hardcoded "--step 17" drifted as the project grew (it no longer reached the
        // Phase-1 boundary, so the test silently rolled back the WRONG migrations). Compute
        // the step count dynamically: roll back every migration applied on or after the first
        // Phase-1 billing migration, no matter how many newer ones now sit on top.
        $applied = DB::table('migrations')->orderByDesc('id')->pluck('migration');
        $steps = 0;
        foreach ($applied as $migration) {
            $steps++;
            if (str_contains($migration, '2026_05_27_000001')) {
                break; // first Phase-1 migration (create_service_prices_table)
            }
        }

        $exit = Artisan::call('migrate:rollback', ['--step' => $steps]);
        $this->assertSame(0, $exit, Artisan::output());

        // After rollback, the Phase-1 changes are gone and the legacy shape is restored.
        $this->assertFalse(Schema::hasTable('service_prices'), 'service_prices must be dropped on rollback');
        $this->assertFalse(Schema::hasColumn('plans', 'listed'), 'listed must be gone after rollback');
        $this->assertFalse(Schema::hasColumn('tenants', 'balance_cents'));
        $this->assertTrue(Schema::hasColumn('tenants', 'credits_balance'), 'legacy credits_balance must be restored');
        $this->assertTrue(Schema::hasTable('credit_transactions'), 'balance_transactions must be renamed back');

        // Re-apply everything.
        $exit2 = Artisan::call('migrate');
        $this->assertSame(0, $exit2, Artisan::output());

        // Back to the current state.
        $this->assertTrue(Schema::hasTable('service_prices'));
        $this->assertTrue(Schema::hasColumn('tenants', 'balance_cents'));
        $this->assertFalse(Schema::hasColumn('tenants', 'credits_balance'));
        $this->assertTrue(Schema::hasColumn('plans', 'included_balance_cents'));
        $this->assertTrue(Schema::hasColumn('plans', 'listed'));
        $this->assertTrue(Schema::hasColumn('balance_transactions', 'monthly_cycle_key'));
    }
}
