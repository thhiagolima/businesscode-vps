<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\OutboundWebhook;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OutboundWebhookSsrfTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): array
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
        ]);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        return [$tenant, $user];
    }

    public function test_cannot_create_webhook_pointing_to_loopback(): void
    {
        [$tenant, $user] = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/webhooks/outbound', [
            'url'    => 'https://127.0.0.1/hook',
            'events' => ['campaign.completed'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('outbound_webhooks', 0);
    }

    public function test_cannot_create_webhook_pointing_to_imds_aws(): void
    {
        [$tenant, $user] = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/webhooks/outbound', [
            'url'    => 'https://169.254.169.254/latest/meta-data/',
            'events' => ['campaign.completed'],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('outbound_webhooks', 0);
    }

    public function test_cannot_create_webhook_pointing_to_rfc1918(): void
    {
        [$tenant, $user] = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/webhooks/outbound', [
            'url'    => 'https://10.0.0.5/hook',
            'events' => ['campaign.completed'],
        ]);

        $response->assertStatus(422);
    }

    public function test_fire_outbound_job_skips_blocked_host(): void
    {
        // Defense-in-depth: if a malicious URL slipped past validation (e.g. legacy data),
        // the job MUST refuse to make the HTTP request.
        Http::fake([
            '*' => Http::response('should not be called', 200),
        ]);

        [$tenant] = $this->makeUser();

        // Force-create a webhook with internal URL bypassing the FormRequest validation
        // (simulates data that pre-dates the SSRF guard or was inserted by an admin).
        $webhook = new OutboundWebhook();
        $webhook->forceFill([
            'tenant_id' => $tenant->id,
            'url'       => 'http://127.0.0.1:9090/internal',
            'events'    => ['campaign.completed'],
            'is_active' => true,
            'secret'    => null,
        ])->save();

        (new FireOutboundWebhookJob($tenant->id, 'campaign.completed', ['x' => 1]))->handle();

        // No HTTP request should have been issued.
        Http::assertNothingSent();

        // A failed delivery record should mark the SSRF rejection for audit.
        $deliveries = WebhookDelivery::where('outbound_webhook_id', $webhook->id)->get();
        $this->assertCount(1, $deliveries);
        $this->assertNull($deliveries->first()->response_status);
        $this->assertNotEmpty($deliveries->first()->error_message);
    }
}
