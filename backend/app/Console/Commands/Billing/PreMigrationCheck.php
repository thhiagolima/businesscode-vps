<?php

namespace App\Console\Commands\Billing;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreMigrationCheck extends Command
{
    protected $signature = 'billing:pre-migration-check {--force : Skip interactive confirmation}';

    protected $description = 'Pre-flight check before BRL billing migration. Verifies env, queue state, MP creds, and shows conversion summary.';

    public function handle(): int
    {
        $this->info('=== BRL Billing — Pre-Migration Check ===');
        $this->newLine();

        $hasOldSchema = Schema::hasColumn('tenants', 'credits_balance');
        $hasNewSchema = Schema::hasColumn('tenants', 'balance_cents');

        if ($hasNewSchema && ! $hasOldSchema) {
            $this->info('Schema already migrated to BRL (tenants.balance_cents present, credits_balance dropped).');
            $this->info('Nothing to do. Exiting.');
            return self::SUCCESS;
        }

        if (! $hasOldSchema) {
            $this->error('Neither credits_balance nor balance_cents found on tenants. Schema state unknown — abort.');
            return self::FAILURE;
        }

        $checks = [
            'APP_ENV is staging or production' => fn () => in_array(app()->environment(), ['staging', 'production'], true),
            'BILLING_MIGRATION_PRICE_CENTS set (env)' => fn () => env('BILLING_MIGRATION_PRICE_CENTS') !== null,
            'Zero pending jobs in queue=messaging' => fn () => DB::table('jobs')->where('queue', 'messaging')->count() === 0,
            'Zero pending jobs in queue=billing' => fn () => DB::table('jobs')->where('queue', 'billing')->count() === 0,
            'Zero pending jobs in queue=campaigns' => fn () => DB::table('jobs')->where('queue', 'campaigns')->count() === 0,
            'Zero campaigns in status=running' => fn () => DB::table('campaigns')->where('status', 'running')->count() === 0,
            'Mercado Pago access token present' => fn () => ! empty(env('MP_ACCESS_TOKEN')),
        ];

        $allOk = true;
        foreach ($checks as $name => $check) {
            try {
                $ok = $check();
            } catch (\Throwable $e) {
                $ok = false;
                $name .= ' (exception: '.$e->getMessage().')';
            }
            $this->line(($ok ? '<info>  ✓</info>' : '<error>  ✗</error>')." {$name}");
            if (! $ok) {
                $allOk = false;
            }
        }

        $this->newLine();

        if (! $allOk) {
            $this->error('Pre-flight FAILED. Resolve the items above before running `php artisan migrate`.');
            return self::FAILURE;
        }

        $priceCents = (int) env('BILLING_MIGRATION_PRICE_CENTS', 15);
        $tenantCount = DB::table('tenants')->count();
        $totalCredits = (int) DB::table('tenants')->sum('credits_balance');
        $totalCents = $totalCredits * $priceCents;
        $planCount = DB::table('plans')->count();
        $totalPlanCredits = (int) DB::table('plans')->sum('credits_included');
        $totalPlanCents = $totalPlanCredits * $priceCents;
        $msgDispatches = Schema::hasColumn('message_dispatches', 'credits_unit')
            ? DB::table('message_dispatches')->count()
            : 0;
        $creditTxns = Schema::hasTable('credit_transactions')
            ? DB::table('credit_transactions')->count()
            : 0;

        $this->info('Conversion summary:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Tenants to convert', $tenantCount],
                ['Total credits across tenants', $totalCredits],
                ['Resulting balance_cents total', $this->formatBrl($totalCents)],
                ['Plans to convert', $planCount],
                ['Total plan credits_included', $totalPlanCredits],
                ['Resulting included_balance_cents', $this->formatBrl($totalPlanCents)],
                ['message_dispatches rows (cost/sale snapshotted)', $msgDispatches],
                ['credit_transactions rows (renamed + multiplied)', $creditTxns],
                ['Conversion rate (cents per credit)', $priceCents],
            ]
        );

        $this->newLine();
        $this->warn('After this point:');
        $this->warn('  1. Run `php artisan migrate --force` (executes 8 BRL migrations).');
        $this->warn('  2. Run `php artisan db:seed --class=ServicePricesSeeder`.');
        $this->warn('  3. Set BILLING_BRL_ENABLED=true in .env.');
        $this->warn('  4. Run `php artisan config:clear`.');
        $this->warn('  5. Run smoke: `php artisan billing:status` (after Phase 10 commands ship).');

        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Pre-flight passed. Proceed with migration NOW?', false)) {
            $this->info('Aborted by user. No changes made.');
            return self::SUCCESS;
        }

        $this->info('User confirmed. The next step is `php artisan migrate --force` (run separately).');
        return self::SUCCESS;
    }

    private function formatBrl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
