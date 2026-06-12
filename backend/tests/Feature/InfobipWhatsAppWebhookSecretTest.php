<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfobipWhatsAppWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    private function seedSecret(string $secret): void
    {
        Setting::updateOrCreate(
            ['tenant_id' => null, 'group' => 'infobip', 'key' => 'webhook_secret'],
            ['value' => $secret, 'type' => 'string']
        );
    }

    public function test_rejects_secret_passed_via_query_string(): void
    {
        // CWE-598: secrets must never be accepted via query string.
        $this->seedSecret('super-secret');

        $response = $this->postJson('/api/v1/webhooks/infobip/whatsapp?secret=super-secret', [
            'results' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_rejects_when_no_secret_configured(): void
    {
        $response = $this->postJson('/api/v1/webhooks/infobip/whatsapp', [
            'results' => [],
        ]);

        // Must NOT leak 500 (configuration internal); must reject with 401.
        $response->assertStatus(401);
    }

    public function test_accepts_bearer_authorization(): void
    {
        $this->seedSecret('super-secret');

        $response = $this->postJson('/api/v1/webhooks/infobip/whatsapp', [
            'results' => [],
        ], [
            'Authorization' => 'Bearer super-secret',
        ]);

        $response->assertOk();
    }

    public function test_rejects_wrong_secret(): void
    {
        $this->seedSecret('super-secret');

        $response = $this->postJson('/api/v1/webhooks/infobip/whatsapp', [
            'results' => [],
        ], [
            'Authorization' => 'Bearer wrong',
        ]);

        $response->assertStatus(401);
    }
}
