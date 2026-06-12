<?php

namespace Tests\Feature\Billing;

use App\Jobs\MonthlyBillingJob;
use App\Jobs\OverdueRetryJob;
use App\Models\BalanceTransaction;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\MercadoPagoService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * DB-level idempotency guard: the composite UNIQUE
 * (tenant_id, reference_type, monthly_cycle_key) must prevent the same
 * monthly_charge row from being inserted twice for the same cycle.
 *
 * Complements the soft `last_billing_at < 25 days` gate in
 * DispatchMonthlyBillingJob — even if that gate is bypassed (race,
 * manual dispatch, retry storm), DB blocks the second insert.
 */
class MonthlyCycleKeyIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.mercadopago.access_token' => 'TEST-token-xyz']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_balance_transaction_persists_monthly_cycle_key(): void
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        BalanceTransaction::create([
            'tenant_id'           => $tenant->id,
            'type'                => 'monthly_charge',
            'amount_cents'        => 1000,
            'balance_after_cents' => 0,
            'reference_type'      => 'monthly_billing',
            'reference_id'        => 1,
            'monthly_cycle_key'   => '202606',
            'description'         => 'test',
        ]);

        $row = BalanceTransaction::where('tenant_id', $tenant->id)->first();
        $this->assertSame('202606', $row->monthly_cycle_key);
    }

    public function test_duplicate_monthly_charge_for_same_cycle_throws_query_exception(): void
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        BalanceTransaction::create([
            'tenant_id'           => $tenant->id,
            'type'                => 'monthly_charge',
            'amount_cents'        => 1000,
            'balance_after_cents' => 0,
            'reference_type'      => 'monthly_billing',
            'reference_id'        => 1,
            'monthly_cycle_key'   => '202606',
            'description'         => 'first',
        ]);

        $this->expectException(QueryException::class);

        BalanceTransaction::create([
            'tenant_id'           => $tenant->id,
            'type'                => 'monthly_charge',
            'amount_cents'        => 1000,
            'balance_after_cents' => 0,
            'reference_type'      => 'monthly_billing',
            'reference_id'        => 2,
            'monthly_cycle_key'   => '202606',
            'description'         => 'duplicate',
        ]);
    }

    public function test_null_monthly_cycle_key_allows_multiple_rows(): void
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        // Non-monthly transactions (e.g. message debits) leave monthly_cycle_key NULL.
        // MySQL treats NULL as distinct under UNIQUE, so multiple NULLs are allowed.
        BalanceTransaction::create([
            'tenant_id'           => $tenant->id,
            'type'                => 'debit',
            'amount_cents'        => 10,
            'balance_after_cents' => 100,
            'reference_type'      => 'message_dispatch',
            'reference_id'        => 1,
            'description'         => 'msg1',
        ]);
        BalanceTransaction::create([
            'tenant_id'           => $tenant->id,
            'type'                => 'debit',
            'amount_cents'        => 10,
            'balance_after_cents' => 90,
            'reference_type'      => 'message_dispatch',
            'reference_id'        => 2,
            'description'         => 'msg2',
        ]);

        $this->assertSame(2, BalanceTransaction::where('tenant_id', $tenant->id)->count());
    }

    public function test_monthly_billing_job_double_run_does_not_double_charge(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 9, 0, 0, 'America/Sao_Paulo'));

        $plan = Plan::factory()->create([
            'included_balance_cents' => 0, // no credit so we go straight to debt path
        ]);
        $tenant = Tenant::factory()->create([
            'plan_id'            => $plan->id,
            'balance_cents'      => -5000,
            'billing_status'     => 'active',
            'mp_customer_id'     => 'cust_dbl',
            'mp_default_card_id' => 'card_dbl',
        ]);

        Http::fake([
            '*/v1/customers/*/cards/*' => Http::response(['payment_method' => ['id' => 'visa']], 200),
            '*/v1/card_tokens'         => Http::response(['id' => 'tok-dbl'], 200),
            '*/v1/payments'            => Http::response(['id' => 88888, 'status' => 'approved'], 200),
        ]);

        (new MonthlyBillingJob($tenant->id))->handle(app(MercadoPagoService::class));

        // Second dispatch in the same cycle (e.g. race / manual run) must NOT double-charge.
        (new MonthlyBillingJob($tenant->id))->handle(app(MercadoPagoService::class));

        $monthlyChargeRows = BalanceTransaction::where('tenant_id', $tenant->id)
            ->where('type', 'monthly_charge')
            ->where('monthly_cycle_key', '202606')
            ->count();

        $this->assertSame(1, $monthlyChargeRows, 'DB UNIQUE must allow only one monthly_charge per cycle');
    }
}
