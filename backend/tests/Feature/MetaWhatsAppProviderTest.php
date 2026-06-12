<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\WhatsApp\MetaWhatsAppProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MetaWhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    private MetaWhatsAppProvider $provider;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'Meta Test', 'slug' => 'meta-test', 'balance_cents' => 15000, 'status' => 'active']);
        $this->tenantId = $tenant->id;

        $settings = app(SettingsService::class);
        $settings->upsert($this->tenantId, 'whatsapp', 'access_token', 'meta-test-token', 'encrypted');
        $settings->upsert($this->tenantId, 'whatsapp', 'phone_number_id', '123456789', 'string');
        $settings->upsert($this->tenantId, 'whatsapp', 'waba_id', 'waba-999', 'string');

        $this->provider = app(MetaWhatsAppProvider::class);
    }

    public function test_send_text_success(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/123456789/messages' => Http::response([
                'messages' => [['id' => 'wamid.abc123']],
            ], 200),
        ]);

        $result = $this->provider->sendText('+5521980194445', 'Hello Meta', $this->tenantId);

        $this->assertTrue($result['ok']);
        $this->assertEquals('wamid.abc123', $result['message_id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '123456789/messages')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '+5521980194445'
                && $request['text']['body'] === 'Hello Meta';
        });
    }

    public function test_send_text_failure(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/123456789/messages' => Http::response([
                'error' => ['message' => 'Invalid recipient'],
            ], 400),
        ]);

        $result = $this->provider->sendText('+5521000000000', 'Test', $this->tenantId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Invalid recipient', $result['error'] ?? '');
    }

    public function test_send_template_success(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/123456789/messages' => Http::response([
                'messages' => [['id' => 'wamid.tpl456']],
            ], 200),
        ]);

        $components = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'João']]]];

        $result = $this->provider->sendTemplate('+5521980194445', 'promo_verao', 'pt_BR', $components, $this->tenantId);

        $this->assertTrue($result['ok']);
        $this->assertEquals('wamid.tpl456', $result['message_id']);

        Http::assertSent(function ($request) {
            return $request['type'] === 'template'
                && $request['template']['name'] === 'promo_verao'
                && $request['template']['language']['code'] === 'pt_BR';
        });
    }

    public function test_send_media_success(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/123456789/messages' => Http::response([
                'messages' => [['id' => 'wamid.media789']],
            ], 200),
        ]);

        $result = $this->provider->sendMedia('+5521980194445', 'image', 'https://example.com/photo.jpg', 'Nice pic', $this->tenantId);

        $this->assertTrue($result['ok']);
        $this->assertEquals('wamid.media789', $result['message_id']);
    }

    public function test_get_templates_success(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/waba-999/message_templates*' => Http::response([
                'data' => [
                    ['name' => 'welcome', 'language' => 'pt_BR', 'status' => 'APPROVED'],
                ],
            ], 200),
        ]);

        $result = $this->provider->getTemplates($this->tenantId);

        $this->assertCount(1, $result);
        $this->assertEquals('welcome', $result[0]['name']);
    }

    public function test_test_connection_success(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/123456789' => Http::response([
                'id' => '123456789',
                'display_phone_number' => '+55 11 99999-9999',
            ], 200),
        ]);

        $result = $this->provider->testConnection($this->tenantId);

        $this->assertTrue($result['ok']);
        $this->assertEquals('+55 11 99999-9999', $result['phone_display']);
    }

    public function test_test_connection_invalid_token(): void
    {
        Http::fake([
            'graph.facebook.com/v20.0/123456789' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token'],
            ], 401),
        ]);

        $result = $this->provider->testConnection($this->tenantId);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error']);
    }
}
