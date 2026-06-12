<?php

namespace Tests\Feature\Messaging;

use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GetDispatchStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithUser(): array
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        return [$tenant, $user];
    }

    private function makeDispatch(int $tenantId, ?int $userId, string $channel = 'sms', array $overrides = []): MessageDispatch
    {
        return MessageDispatch::withoutGlobalScopes()->create(array_merge([
            'tenant_id'       => $tenantId,
            'user_id'         => $userId,
            'channel'         => $channel,
            'source'          => 'api',
            'to'              => $channel === 'email' ? 'dest@example.com' : '+5521988887777',
            'content'         => 'Conteúdo longo para validar mascaramento e mais texto ainda',
            'provider'        => 'infobip',
            'status'          => 'queued',
            'credits_unit'    => 1,
            'credits_charged' => 0,
        ], $overrides));
    }

    public function test_show_happy_path(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        Sanctum::actingAs($user, ['messaging:read']);

        $dispatch = $this->makeDispatch($tenant->id, $user->id);

        $resp = $this->getJson("/api/v1/messaging/dispatches/{$dispatch->id}");

        $resp->assertStatus(200)
            ->assertJsonPath('data.id', $dispatch->id)
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.tenant_id', $tenant->id);
    }

    public function test_cross_tenant_returns_404(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB, ]       = $this->makeTenantWithUser();

        $dispatchB = $this->makeDispatch($tenantB->id, null);

        Sanctum::actingAs($userA, ['messaging:read']);
        $resp = $this->getJson("/api/v1/messaging/dispatches/{$dispatchB->id}");

        // Must be uniform 404 for both "doesn't exist" and "exists in another tenant"
        // to prevent existence enumeration (QA Phase 3B/C, Red Team checklist item 2 & 16).
        $resp->assertStatus(404);
    }

    public function test_index_filters_by_channel(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser();
        Sanctum::actingAs($user, ['messaging:read']);

        $this->makeDispatch($tenant->id, $user->id, 'sms');
        $this->makeDispatch($tenant->id, $user->id, 'email', ['to' => 'a@b.com']);
        $this->makeDispatch($tenant->id, $user->id, 'email', ['to' => 'c@d.com']);

        $resp = $this->getJson('/api/v1/messaging/dispatches?channel=email');

        $resp->assertStatus(200);
        $this->assertCount(2, $resp->json('data'));
        foreach ($resp->json('data') as $row) {
            $this->assertSame('email', $row['channel']);
        }
    }

    public function test_index_only_own_tenant(): void
    {
        [$tenantA, $userA] = $this->makeTenantWithUser();
        [$tenantB, $userB] = $this->makeTenantWithUser();

        $dispatchA = $this->makeDispatch($tenantA->id, $userA->id);

        Sanctum::actingAs($userB, ['messaging:read']);
        $resp = $this->getJson('/api/v1/messaging/dispatches');

        $resp->assertStatus(200);
        $ids = array_column($resp->json('data'), 'id');
        $this->assertNotContains($dispatchA->id, $ids);
    }

    public function test_content_masked_for_non_owner_in_same_tenant(): void
    {
        [$tenant, $user1] = $this->makeTenantWithUser();
        $user2 = User::factory()->create(['tenant_id' => $tenant->id]);

        $dispatch = $this->makeDispatch($tenant->id, $user1->id, 'sms', [
            'content' => 'Conteúdo confidencial muito longo que deve ser truncado',
        ]);

        Sanctum::actingAs($user2, ['messaging:read']);
        $resp = $this->getJson("/api/v1/messaging/dispatches/{$dispatch->id}");

        $resp->assertStatus(200);
        $content = $resp->json('data.content');
        $this->assertStringEndsWith('…', $content);
        $this->assertLessThan(mb_strlen('Conteúdo confidencial muito longo que deve ser truncado'), mb_strlen($content));
    }
}
