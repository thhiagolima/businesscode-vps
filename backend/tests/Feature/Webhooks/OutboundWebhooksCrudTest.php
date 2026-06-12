<?php

namespace Tests\Feature\Webhooks;

use App\Models\OutboundWebhook;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutboundWebhooksCrudTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_index_lists_only_own_tenant_webhooks(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $wA = new OutboundWebhook(['url' => 'https://a.test/hook', 'events' => ['message.sent']]);
        $wA->tenant_id = $userA->tenant_id; $wA->save();

        $wB = new OutboundWebhook(['url' => 'https://b.test/hook', 'events' => ['message.sent']]);
        $wB->tenant_id = $userB->tenant_id; $wB->save();

        Sanctum::actingAs($userA);
        $resp = $this->getJson('/api/v1/webhooks/outbound');
        $resp->assertStatus(200);

        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($wA->id, $ids);
        $this->assertNotContains($wB->id, $ids);
    }

    public function test_store_creates_webhook_and_auto_generates_secret(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->postJson('/api/v1/webhooks/outbound', [
            'url'       => 'https://example.com/hook/path',
            'events'    => ['message.sent', 'message.failed'],
            'is_active' => true,
        ]);

        $resp->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'url', 'events', 'secret', 'created_at']]);

        $secret = $resp->json('data.secret');
        $this->assertNotEmpty($secret);
        $this->assertEquals(32, strlen($secret), 'auto-generated secret should be 32 hex chars');
    }

    public function test_store_rejects_http_only_https_allowed(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/webhooks/outbound', [
            'url'    => 'http://example.com/insecure',
            'events' => ['message.sent'],
        ])->assertStatus(422)->assertJsonValidationErrors(['url']);
    }

    public function test_store_validates_event_names(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/webhooks/outbound', [
            'url'    => 'https://example.com/hook/path',
            'events' => ['not.a.real.event'],
        ])->assertStatus(422)->assertJsonValidationErrors(['events.0']);
    }

    public function test_show_returns_webhook_with_deliveries(): void
    {
        $user = $this->makeUser();
        $w = new OutboundWebhook(['url' => 'https://x.test/h', 'events' => ['message.sent']]);
        $w->tenant_id = $user->tenant_id; $w->save();

        Sanctum::actingAs($user);
        $resp = $this->getJson("/api/v1/webhooks/outbound/{$w->id}");
        $resp->assertStatus(200)
            ->assertJsonStructure(['data' => ['id', 'url', 'events', 'deliveries']]);
    }

    public function test_update_modifies_webhook(): void
    {
        $user = $this->makeUser();
        $w = new OutboundWebhook(['url' => 'https://x.test/h', 'events' => ['message.sent']]);
        $w->tenant_id = $user->tenant_id; $w->save();

        Sanctum::actingAs($user);
        $resp = $this->putJson("/api/v1/webhooks/outbound/{$w->id}", [
            'is_active' => false,
            'events'    => ['message.delivered'],
        ]);
        $resp->assertStatus(200);

        $w->refresh();
        $this->assertFalse((bool) $w->is_active);
        $this->assertSame(['message.delivered'], $w->events);
    }

    public function test_destroy_removes_webhook(): void
    {
        $user = $this->makeUser();
        $w = new OutboundWebhook(['url' => 'https://x.test/h', 'events' => ['message.sent']]);
        $w->tenant_id = $user->tenant_id; $w->save();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/webhooks/outbound/{$w->id}")->assertStatus(204);

        $this->assertNull(OutboundWebhook::withoutGlobalScopes()->find($w->id));
    }

    public function test_cross_tenant_isolation_prevents_show(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $wB = new OutboundWebhook(['url' => 'https://b.test/hook', 'events' => ['message.sent']]);
        $wB->tenant_id = $userB->tenant_id; $wB->save();

        Sanctum::actingAs($userA);
        $this->getJson("/api/v1/webhooks/outbound/{$wB->id}")->assertStatus(404);
        $this->deleteJson("/api/v1/webhooks/outbound/{$wB->id}")->assertStatus(404);
    }

    public function test_events_catalog_endpoint(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $resp = $this->getJson('/api/v1/webhooks/events');
        $resp->assertStatus(200);
        $events = $resp->json('data');
        $this->assertContains('message.sent', $events);
        $this->assertContains('billing.recharged', $events);
        $this->assertContains('webhook.test', $events);
    }
}
