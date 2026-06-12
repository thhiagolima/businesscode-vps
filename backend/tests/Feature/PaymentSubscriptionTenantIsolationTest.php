<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSubscriptionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_cannot_see_other_tenant_payments(): void
    {
        $plan = Plan::factory()->create(['slug' => 'iso-pay-' . uniqid()]);
        $tenantA = Tenant::factory()->create(['plan_id' => $plan->id]);
        $tenantB = Tenant::factory()->create(['plan_id' => $plan->id]);
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => 'admin']);

        Payment::create([
            'tenant_id' => $tenantB->id,
            'type' => 'credit_purchase',
            'status' => 'approved',
            'payment_method' => 'pix',
            'amount' => 100.00,
            'credits_purchased' => 1000,
        ]);

        $response = $this->actingAs($userA)->getJson('/api/v1/payments');

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('pagination.total') ?? $response->json('meta.pagination.total') ?? count($response->json('data') ?? []));
    }

    public function test_tenant_cannot_read_other_tenant_payment_status(): void
    {
        $plan = Plan::factory()->create(['slug' => 'iso-pay2-' . uniqid()]);
        $tenantA = Tenant::factory()->create(['plan_id' => $plan->id]);
        $tenantB = Tenant::factory()->create(['plan_id' => $plan->id]);
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => 'admin']);

        $bPayment = Payment::create([
            'tenant_id' => $tenantB->id,
            'type' => 'credit_purchase',
            'status' => 'approved',
            'payment_method' => 'pix',
            'amount' => 100.00,
            'credits_purchased' => 1000,
        ]);

        $response = $this->actingAs($userA)->getJson("/api/v1/payments/{$bPayment->id}/status");

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_tenant_cannot_see_other_tenant_subscription(): void
    {
        $plan = Plan::factory()->create(['slug' => 'iso-sub-' . uniqid()]);
        $tenantA = Tenant::factory()->create(['plan_id' => $plan->id]);
        $tenantB = Tenant::factory()->create(['plan_id' => $plan->id]);
        $userA   = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => 'admin']);

        Subscription::create([
            'tenant_id' => $tenantB->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'payment_method' => 'credit_card',
            'billing_cycle' => 'monthly',
            'price' => 97.00,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $response = $this->actingAs($userA)->getJson('/api/v1/subscriptions/current');

        $response->assertStatus(200);
        $this->assertNull($response->json('data'));
    }
}
