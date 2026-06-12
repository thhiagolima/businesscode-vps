<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfobipWebhookSecretTest extends TestCase
{
    use RefreshDatabase;

    private function seedSecret(string $secret): void
    {
        Setting::updateOrCreate(
            ['tenant_id' => null, 'group' => 'infobip', 'key' => 'webhook_secret'],
            ['value' => $secret, 'type' => 'string']
        );
    }

    public function test_rejects_when_no_secret_configured(): void
    {
        $response = $this->postJson('/api/v1/webhooks/infobip/delivery', [
            'results' => [],
        ]);
        $response->assertStatus(401);
    }

    public function test_rejects_secret_passed_via_query_string(): void
    {
        // CWE-598: secrets must never be accepted via query string.
        $this->seedSecret('super-secret');

        $response = $this->postJson('/api/v1/webhooks/infobip/delivery?secret=super-secret', [
            'results' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_accepts_secret_via_ibm_signature_header(): void
    {
        $this->seedSecret('super-secret');

        $response = $this->postJson('/api/v1/webhooks/infobip/delivery', [
            'results' => [],
        ], [
            'ibm-signature-v2' => 'super-secret',
        ]);

        $response->assertOk();
    }

    public function test_accepts_bearer_authorization(): void
    {
        $this->seedSecret('super-secret');

        $response = $this->postJson('/api/v1/webhooks/infobip/delivery', [
            'results' => [],
        ], [
            'Authorization' => 'Bearer super-secret',
        ]);

        $response->assertOk();
    }
}
