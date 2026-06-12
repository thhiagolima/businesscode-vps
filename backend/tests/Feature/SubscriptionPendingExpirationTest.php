<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPendingExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_pending_subscription_blocks_new_store(): void
    {
        $plan = Plan::factory()->create(['slug' => 'block-' . uniqid(), 'price_monthly' => 97]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'payment_method' => 'pix',
            'billing_cycle' => 'monthly',
            'price' => 97.00,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'created_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/subscriptions', [
            'plan_id' => $plan->id,
            'card_token' => 'fake_token',
            'payer_email' => 'buyer@example.com',
        ]);

        $response->assertStatus(422);
    }

    public function test_abandoned_pending_subscription_does_not_block_retry(): void
    {
        config(['business.subscription_pending_timeout_hours' => 24]);

        $plan = Plan::factory()->create(['slug' => 'retry-' . uniqid(), 'price_monthly' => 97]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);

        $abandoned = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'payment_method' => 'pix',
            'billing_cycle' => 'monthly',
            'price' => 97.00,
            'current_period_start' => now()->subDays(2),
            'current_period_end' => now()->subDays(2)->addMonth(),
        ]);
        // Force created_at into the past (factory default is now)
        $abandoned->created_at = now()->subDays(2);
        $abandoned->save();

        // We stop short of the MP integration but the store should not be blocked by the abandoned row.
        // The abandoned subscription should be auto-cancelled when a new store attempt is made.
        $this->actingAs($user)->postJson('/api/v1/subscriptions', [
            'plan_id' => $plan->id,
            'card_token' => 'fake_token',
            'payer_email' => 'buyer@example.com',
        ]);

        $this->assertSame('cancelled', $abandoned->fresh()->status);
    }
}
