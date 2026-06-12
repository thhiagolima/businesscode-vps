<?php

namespace Tests\Feature;

use App\Models\InfobipWhatsAppNumber;
use App\Models\Tenant;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WhatsAppServiceResolverTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppService $service;
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(SettingsService::class);
        $this->service = app(WhatsAppService::class);

        $this->settings->upsertGlobal('infobip', 'api_key', 'test-key', 'encrypted');
        $this->settings->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
    }

    public function test_resolves_meta_provider_by_default(): void
    {
        $tenant = Tenant::create(['name' => 'Meta T', 'slug' => 'meta-t', 'balance_cents' => 1500, 'status' => 'active']);

        $this->settings->upsert($tenant->id, 'whatsapp', 'access_token', 'meta-token', 'encrypted');
        $this->settings->upsert($tenant->id, 'whatsapp', 'phone_number_id', '111222333', 'string');

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.meta']]], 200),
        ]);

        $result = $this->service->sendText('+5521999999999', 'Test', $tenant->id);
        $this->assertTrue($result['ok']);
        $this->assertEquals('wamid.meta', $result['message_id']);
        $this->assertEquals('meta', $this->service->getProviderName($tenant->id));
    }

    public function test_resolves_infobip_provider_when_configured(): void
    {
        $tenant = Tenant::create(['name' => 'Infobip T', 'slug' => 'infobip-t', 'balance_cents' => 1500, 'status' => 'active']);

        $this->settings->upsert($tenant->id, 'whatsapp', 'provider', 'infobip', 'string');

        InfobipWhatsAppNumber::create([
            'sender' => '5511888777666', 'number' => '+5511888777666', 'tenant_id' => $tenant->id, 'status' => 'active',
        ]);

        Http::fake([
            'api.infobip.com/whatsapp/1/message/text' => Http::response(['messages' => [['messageId' => 'ib-msg-001']]], 200),
        ]);

        $result = $this->service->sendText('+5521999999999', 'Test Infobip', $tenant->id);
        $this->assertTrue($result['ok']);
        $this->assertEquals('ib-msg-001', $result['message_id']);
        $this->assertEquals('infobip', $this->service->getProviderName($tenant->id));
    }

    public function test_different_tenants_use_different_providers(): void
    {
        $tenantMeta = Tenant::create(['name' => 'Meta Co', 'slug' => 'meta-co', 'balance_cents' => 1500, 'status' => 'active']);
        $tenantIb = Tenant::create(['name' => 'IB Co', 'slug' => 'ib-co', 'balance_cents' => 1500, 'status' => 'active']);

        // Meta tenant
        $this->settings->upsert($tenantMeta->id, 'whatsapp', 'provider', 'meta', 'string');
        $this->settings->upsert($tenantMeta->id, 'whatsapp', 'access_token', 'token-meta', 'encrypted');
        $this->settings->upsert($tenantMeta->id, 'whatsapp', 'phone_number_id', '444555666', 'string');

        // Infobip tenant
        $this->settings->upsert($tenantIb->id, 'whatsapp', 'provider', 'infobip', 'string');
        InfobipWhatsAppNumber::create(['sender' => '5500111222', 'number' => '+5500111222', 'tenant_id' => $tenantIb->id, 'status' => 'active']);

        $this->assertEquals('meta', $this->service->getProviderName($tenantMeta->id));
        $this->assertEquals('infobip', $this->service->getProviderName($tenantIb->id));
    }
}
