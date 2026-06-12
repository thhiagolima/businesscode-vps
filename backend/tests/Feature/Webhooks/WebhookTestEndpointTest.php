<?php

namespace Tests\Feature\Webhooks;

use App\Models\OutboundWebhook;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebhookTestEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_test_endpoint_fires_synchronously_and_records_delivery(): void
    {
        $user = $this->makeUser();

        $w = new OutboundWebhook([
            'url'    => 'https://example.com/hook/test',
            'events' => ['webhook.test'],
        ]);
        $w->tenant_id = $user->tenant_id;
        $w->secret    = 'my-secret-key-1234567890abcd';
        $w->save();

        Http::fake([
            'example.com/hook/*' => Http::response(['received' => true], 201),
        ]);

        Sanctum::actingAs($user);
        $resp = $this->postJson("/api/v1/webhooks/outbound/{$w->id}/test");

        $resp->assertStatus(200)
            ->assertJsonStructure(['data' => ['delivery_id', 'ok', 'response_status', 'duration_ms', 'response_body']])
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.response_status', 201);

        $delivery = WebhookDelivery::withoutGlobalScopes()
            ->where('outbound_webhook_id', $w->id)
            ->first();
        $this->assertNotNull($delivery);
        $this->assertSame('webhook.test', $delivery->event);
        $this->assertSame(201, (int) $delivery->response_status);

        Http::assertSent(function ($request) {
            return $request['event'] === 'webhook.test'
                && ! empty($request->header('X-Webhook-Signature')[0] ?? null);
        });
    }

    public function test_test_endpoint_returns_error_details_on_failure(): void
    {
        $user = $this->makeUser();

        $w = new OutboundWebhook([
            'url'    => 'https://example.com/broken/path',
            'events' => ['webhook.test'],
        ]);
        $w->tenant_id = $user->tenant_id;
        $w->save();

        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('host unreachable');
        });

        Sanctum::actingAs($user);
        $resp = $this->postJson("/api/v1/webhooks/outbound/{$w->id}/test");

        $resp->assertStatus(200)
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.response_status', null);

        $this->assertStringContainsString('host unreachable', (string) $resp->json('data.error_message'));
    }
}
