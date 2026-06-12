<?php

namespace Tests\Feature\Billing;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RechargeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_double_recharge_with_same_reference_does_not_double_credit(): void
    {
        // Reproduces P0-05: PaymentController::credits credits synchronously AND
        // MercadoPagoService::activatePayment credits again on webhook arrival.
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 0,
        ]);
        $billing = app(BillingService::class);

        $billing->recharge($tenant->id, 5000, 'credit_purchase', 42, 'Compra de saldo (payment #42)');
        $billing->recharge($tenant->id, 5000, 'credit_purchase', 42, 'Compra de saldo (payment #42)');

        // Balance must be credited exactly once.
        $this->assertSame(5000, (int) $tenant->fresh()->balance_cents);

        // BalanceTransaction must contain exactly one row for this reference.
        $count = BalanceTransaction::where('tenant_id', $tenant->id)
            ->where('type', 'recharge')
            ->where('reference_type', 'credit_purchase')
            ->where('reference_id', 42)
            ->count();
        $this->assertSame(1, $count, 'Duplicate recharge for same payment must be ignored');
    }

    public function test_recharge_different_payments_each_credit(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 0,
        ]);
        $billing = app(BillingService::class);

        $billing->recharge($tenant->id, 1000, 'credit_purchase', 1);
        $billing->recharge($tenant->id, 1000, 'credit_purchase', 2);

        $this->assertSame(2000, (int) $tenant->fresh()->balance_cents);
    }

    public function test_recharge_marks_idempotency_observable_in_transactions_table(): void
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 0,
        ]);
        $billing = app(BillingService::class);

        $billing->recharge($tenant->id, 5000, 'credit_purchase', 99);
        $billing->recharge($tenant->id, 5000, 'credit_purchase', 99); // race / replay

        // Idempotency must be observable: exactly 1 recharge row, balance once.
        $this->assertSame(
            1,
            BalanceTransaction::where('reference_id', 99)
                ->where('type', 'recharge')
                ->count()
        );
        $this->assertSame(5000, (int) $tenant->fresh()->balance_cents);
    }
}
