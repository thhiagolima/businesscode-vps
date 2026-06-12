<?php

namespace Tests\Feature;

use App\Models\InfobipWhatsAppNumber;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Services\WhatsApp\InfobipWhatsAppProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InfobipWhatsAppProviderTest extends TestCase
{
    use RefreshDatabase;

    private InfobipWhatsAppProvider $provider;
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $settings = app(SettingsService::class);
        $settings->upsertGlobal('infobip', 'api_key', 'test-key-123', 'encrypted');
        $settings->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');

        // Create tenant + assign number
        $tenant = Tenant::create(['name' => 'Test Co', 'slug' => 'test-co', 'balance_cents' => 15000, 'status' => 'active']);
        $this->tenantId = $tenant->id;

        InfobipWhatsAppNumber::create([
            'sender'    => '5511999888777',
            'number'    => '+5511999888777',
            'tenant_id' => $this->tenantId,
            'status'    => 'active',
        ]);

        $this->provider = app(InfobipWhatsAppProvider::class);
    }

    public function test_send_text_success(): void
    {
        Http::fake([
            'api.infobip.com/whatsapp/1/message/text' => Http::response([
                'messages' => [['messageId' => 'wa-text-001']],
            ], 200),
        ]);

        $result = $this->provider->sendText('+5521980194445', 'Hello from test', $this->tenantId);

        $this->assertTrue($result['ok']);
        $this->assertEquals('wa-text-001', $result['message_id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/whatsapp/1/message/text')
                && $request['from'] === '5511999888777'
                && $request['to'] === '+5521980194445'
                && $request['content']['text'] === 'Hello from test';
        });
    }

    public function test_send_text_failure(): void
    {
        Http::fake([
            'api.infobip.com/whatsapp/1/message/text' => Http::response([
                'requestError' => ['serviceException' => ['text' => 'Sender not found']],
            ], 400),
        ]);

        $result = $this->provider->sendText('+5521980194445', 'Test', $this->tenantId);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Sender not found', $result['error'] ?? '');
    }

    public function test_send_template_success(): void
    {
        Http::fake([
            'api.infobip.com/whatsapp/1/message/template' => Http::response([
                'messages' => [['messageId' => 'wa-tpl-002']],
            ], 200),
        ]);

        $components = [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'João']]]];

        $result = $this->provider->sendTemplate('+5521980194445', 'promo_verao', 'pt_BR', $components, $this->tenantId);

        $this->assertTrue($result['ok']);
        $this->assertEquals('wa-tpl-002', $result['message_id']);

        Http::assertSent(function ($request) {
            $data = $request->data();
            return str_contains($request->url(), '/whatsapp/1/message/template')
                && $data['messages'][0]['from'] === '5511999888777'
                && $data['messages'][0]['content']['templateName'] === 'promo_verao';
        });
    }

    public function test_send_media_image_success(): void
    {
        Http::fake([
            'api.infobip.com/whatsapp/1/message/image' => Http::response([
                'messages' => [['messageId' => 'wa-img-003']],
            ], 200),
        ]);

        $result = $this->provider->sendMedia('+5521980194445', 'image', 'https://example.com/img.jpg', 'Caption', $this->tenantId);

        $this->assertTrue($result['ok']);
        $this->assertEquals('wa-img-003', $result['message_id']);
    }

    public function test_get_templates_success(): void
    {
        Http::fake([
            'api.infobip.com/whatsapp/2/senders/5511999888777/templates' => Http::response([
                'templates' => [
                    ['name' => 'promo_verao', 'language' => 'pt_BR', 'status' => 'approved', 'category' => 'MARKETING', 'structure' => ['body' => ['text' => 'Olá {{1}}']]],
                    ['name' => 'welcome', 'language' => 'pt_BR', 'status' => 'approved', 'category' => 'UTILITY', 'structure' => ['body' => ['text' => 'Bem-vindo {{1}}']]],
                ],
            ], 200),
        ]);

        $result = $this->provider->getTemplates($this->tenantId);

        $this->assertCount(2, $result);
        $this->assertEquals('promo_verao', $result[0]['name']);
        $this->assertEquals('APPROVED', $result[0]['status']);
    }

    public function test_test_connection_success(): void
    {
        Http::fake([
            'api.infobip.com/whatsapp/2/senders' => Http::response([
                'results' => [
                    ['sender' => '5511999888777', 'number' => '+5511999888777', 'connectionStatus' => 'CONNECTED'],
                ],
            ], 200),
        ]);

        $result = $this->provider->testConnection($this->tenantId);

        $this->assertTrue($result['ok']);
    }

    public function test_send_text_no_number_assigned(): void
    {
        // Create tenant without number
        $tenant2 = Tenant::create(['name' => 'No Number', 'slug' => 'no-num', 'balance_cents' => 1500, 'status' => 'active']);

        $result = $this->provider->sendText('+5521980194445', 'Test', $tenant2->id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Nenhum número', $result['error']);
    }
}
