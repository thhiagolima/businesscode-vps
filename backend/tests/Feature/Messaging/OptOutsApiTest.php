<?php

namespace Tests\Feature\Messaging;

use App\Models\MessageOptOut;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\OptOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OptOutsApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_index_lists_only_own_tenant_opt_outs(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        app(OptOutService::class)->add($userA->tenant_id, 'sms', '+5521911111111', 'user_request');
        app(OptOutService::class)->add($userB->tenant_id, 'sms', '+5521922222222', 'user_request');

        Sanctum::actingAs($userA, ['messaging:read']);
        $resp = $this->getJson('/api/v1/messaging/opt-outs');

        $resp->assertStatus(200);
        $rows = $resp->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($userA->tenant_id, $rows[0]['tenant_id']);
    }

    public function test_store_creates_opt_out(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user, ['messaging:*']);

        $resp = $this->postJson('/api/v1/messaging/opt-outs', [
            'channel'    => 'sms',
            'identifier' => '+5521933334444',
            'reason'     => 'user_request',
        ]);

        $resp->assertStatus(201)
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.tenant_id', $user->tenant_id);

        $this->assertDatabaseHas('message_opt_outs', [
            'tenant_id' => $user->tenant_id,
            'channel'   => 'sms',
        ]);
    }

    public function test_destroy_removes(): void
    {
        $user = $this->makeUser();
        $entry = app(OptOutService::class)->add($user->tenant_id, 'sms', '+5521955556666', 'user_request');

        Sanctum::actingAs($user, ['messaging:*']);
        $resp = $this->deleteJson("/api/v1/messaging/opt-outs/{$entry->id}");

        $resp->assertStatus(204);
        $this->assertDatabaseMissing('message_opt_outs', ['id' => $entry->id]);
    }

    public function test_store_requires_write_ability(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user, ['messaging:read']);

        $resp = $this->postJson('/api/v1/messaging/opt-outs', [
            'channel'    => 'sms',
            'identifier' => '+5521977778888',
            'reason'     => 'user_request',
        ]);

        $resp->assertStatus(403)
            ->assertJson(['error' => 'INSUFFICIENT_TOKEN_ABILITY', 'required' => 'messaging:*']);
    }

    public function test_destroy_cross_tenant_returns_404(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $entryB = app(OptOutService::class)->add($userB->tenant_id, 'sms', '+5521999990000', 'user_request');

        Sanctum::actingAs($userA, ['messaging:*']);
        $resp = $this->deleteJson("/api/v1/messaging/opt-outs/{$entryB->id}");

        $resp->assertStatus(404);
    }
}
