<?php

namespace Tests\Unit\Policies;

use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\MessageDispatchPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageDispatchPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(int $tenantId): User
    {
        return User::factory()->create(['tenant_id' => $tenantId]);
    }

    private function makeTenant(): Tenant
    {
        $plan = Plan::factory()->create();
        return Tenant::factory()->create(['plan_id' => $plan->id]);
    }

    private function makeDispatch(int $tenantId): MessageDispatch
    {
        return MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'     => $tenantId,
            'channel'       => 'sms',
            'source'        => 'api',
            'to'            => '+5521988887777',
            'content'       => 'x',
            'provider'      => 'infobip',
            'status'        => 'queued',
            'cost_cents'    => 8,
            'sale_cents'    => 15,
            'charged_cents' => 0,
        ]);
    }

    public function test_view_same_tenant_returns_true(): void
    {
        $tenant   = $this->makeTenant();
        $user     = $this->makeUser($tenant->id);
        $dispatch = $this->makeDispatch($tenant->id);

        $policy = new MessageDispatchPolicy();
        $this->assertTrue($policy->view($user, $dispatch));
    }

    public function test_view_cross_tenant_returns_false(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $user    = $this->makeUser($tenantA->id);
        $dispatch = $this->makeDispatch($tenantB->id);

        $policy = new MessageDispatchPolicy();
        $this->assertFalse($policy->view($user, $dispatch));
    }

    public function test_viewAny_returns_true_for_tenant_user(): void
    {
        $tenant = $this->makeTenant();
        $user   = $this->makeUser($tenant->id);

        $policy = new MessageDispatchPolicy();
        $this->assertTrue($policy->viewAny($user));
    }
}
