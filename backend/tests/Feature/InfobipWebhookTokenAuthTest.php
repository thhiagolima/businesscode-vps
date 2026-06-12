<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Infobip delivers status callbacks via per-message notifyUrl and does NOT
 * send any auth header. So the callback URL itself carries a secret token in
 * the path: /webhooks/infobip/delivery/{token}. This guards that path-token
 * authentication works and rejects wrong/missing tokens.
 */
class InfobipWebhookTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    private function seedSecret(string $secret): void
    {
        Setting::updateOrCreate(
            ['tenant_id' => null, 'group' => 'infobip', 'key' => 'webhook_secret'],
            ['value' => $secret, 'type' => 'string']
        );
    }

    public function test_delivery_accepts_valid_path_token(): void
    {
        $this->seedSecret('tok-123');

        $this->postJson('/api/v1/webhooks/infobip/delivery/tok-123', ['results' => []])
            ->assertOk();
    }

    public function test_delivery_rejects_wrong_path_token(): void
    {
        $this->seedSecret('tok-123');

        $this->postJson('/api/v1/webhooks/infobip/delivery/wrong-token', ['results' => []])
            ->assertStatus(401);
    }

    public function test_email_events_accepts_valid_path_token(): void
    {
        $this->seedSecret('tok-123');

        $this->postJson('/api/v1/webhooks/infobip/email-events/tok-123', ['results' => []])
            ->assertOk();
    }

    public function test_email_events_rejects_wrong_path_token(): void
    {
        $this->seedSecret('tok-123');

        $this->postJson('/api/v1/webhooks/infobip/email-events/wrong', ['results' => []])
            ->assertStatus(401);
    }
}
