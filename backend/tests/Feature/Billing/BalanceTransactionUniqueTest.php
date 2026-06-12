<?php

namespace Tests\Feature\Billing;

use App\Models\BalanceTransaction;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0R-01 — defesa em profundidade no DB para idempotência de
 * BillingService::reserve/release/recharge.
 */
class BalanceTransactionUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_inserting_duplicate_idempotency_key_throws_unique_violation(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);

        BalanceTransaction::create([
            'tenant_id' => $tenant->id,
            'type' => 'recharge',
            'amount_cents' => 1000,
            'balance_after_cents' => 1000,
            'reference_type' => 'credit_purchase',
            'reference_id' => 42,
            'description' => 'first',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        BalanceTransaction::create([
            'tenant_id' => $tenant->id,
            'type' => 'recharge',
            'amount_cents' => 1000,
            'balance_after_cents' => 2000,
            'reference_type' => 'credit_purchase',
            'reference_id' => 42, // same idempotency key
            'description' => 'duplicate — must blow up',
        ]);
    }

    public function test_different_reference_id_succeeds(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);

        BalanceTransaction::create([
            'tenant_id' => $tenant->id, 'type' => 'recharge',
            'amount_cents' => 1000, 'balance_after_cents' => 1000,
            'reference_type' => 'credit_purchase', 'reference_id' => 1,
            'description' => 'first',
        ]);
        BalanceTransaction::create([
            'tenant_id' => $tenant->id, 'type' => 'recharge',
            'amount_cents' => 1000, 'balance_after_cents' => 2000,
            'reference_type' => 'credit_purchase', 'reference_id' => 2,
            'description' => 'second',
        ]);

        $this->assertSame(2, BalanceTransaction::count());
    }

    public function test_same_reference_id_with_different_type_succeeds(): void
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);

        BalanceTransaction::create([
            'tenant_id' => $tenant->id, 'type' => 'reserve',
            'amount_cents' => -1000, 'balance_after_cents' => 0,
            'reference_type' => 'campaign_dispatch', 'reference_id' => 10,
            'description' => 'reserve',
        ]);
        BalanceTransaction::create([
            'tenant_id' => $tenant->id, 'type' => 'release',
            'amount_cents' => 1000, 'balance_after_cents' => 1000,
            'reference_type' => 'campaign_dispatch', 'reference_id' => 10,
            'description' => 'release',
        ]);

        $this->assertSame(2, BalanceTransaction::count());
    }
}
