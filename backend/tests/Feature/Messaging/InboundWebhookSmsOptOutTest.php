<?php

namespace Tests\Feature\Messaging;

use App\Models\MessageOptOut;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Services\Messaging\OptOutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundWebhookSmsOptOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['messaging.inbound_webhook_secret' => 'test-secret']);
    }

    private function makeTenantWithNumber(string $number = '+5511999999999'): Tenant
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        TenantChannel::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'channel'   => 'sms',
            'status'    => 'enabled',
            'config'    => ['identifier' => $number],
        ]);

        return $tenant;
    }

    public function test_sair_keyword_adds_opt_out(): void
    {
        $number = '+5511999999999';
        $tenant = $this->makeTenantWithNumber($number);

        $resp = $this->withHeader('Authorization', 'Bearer test-secret')
            ->postJson('/api/v1/webhooks/infobip/inbound', [
                'results' => [[
                    'from' => '+5521988887777',
                    'to'   => $number,
                    'text' => 'SAIR',
                ]],
            ]);

        $resp->assertStatus(200)
            ->assertJson(['ok' => true, 'processed' => 1]);

        $this->assertDatabaseHas('message_opt_outs', [
            'tenant_id' => $tenant->id,
            'channel'   => 'sms',
        ]);
    }

    public function test_entrar_keyword_removes(): void
    {
        $number = '+5511999999999';
        $tenant = $this->makeTenantWithNumber($number);

        // Pre-seed an opt-out for the inbound number
        app(OptOutService::class)->add($tenant->id, 'sms', '+5521988887777', 'sms_stop');
        $this->assertDatabaseHas('message_opt_outs', [
            'tenant_id' => $tenant->id,
            'channel'   => 'sms',
        ]);

        $resp = $this->withHeader('Authorization', 'Bearer test-secret')
            ->postJson('/api/v1/webhooks/infobip/inbound', [
                'results' => [[
                    'from' => '+5521988887777',
                    'to'   => $number,
                    'text' => 'ENTRAR',
                ]],
            ]);

        $resp->assertStatus(200)
            ->assertJson(['ok' => true, 'processed' => 1]);

        $count = MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'sms')
            ->count();
        $this->assertSame(0, $count);
    }

    public function test_invalid_secret_returns_401(): void
    {
        $this->makeTenantWithNumber('+5511999999999');

        $resp = $this->withHeader('Authorization', 'Bearer wrong-secret')
            ->postJson('/api/v1/webhooks/infobip/inbound', [
                'results' => [[
                    'from' => '+5521988887777',
                    'to'   => '+5511999999999',
                    'text' => 'SAIR',
                ]],
            ]);

        $resp->assertStatus(401);
    }

    public function test_tenant_not_found_returns_200_silently(): void
    {
        // No TenantChannel created
        $resp = $this->withHeader('Authorization', 'Bearer test-secret')
            ->postJson('/api/v1/webhooks/infobip/inbound', [
                'results' => [[
                    'from' => '+5521988887777',
                    'to'   => '+5500000000000',
                    'text' => 'SAIR',
                ]],
            ]);

        $resp->assertStatus(200)
            ->assertJson(['ok' => true, 'processed' => 0]);
    }

    public function test_sair_keyword_normalizes_sender_to_e164(): void
    {
        // Setup: a TenantChannel pointing to a destination number.
        // Use the project's helper so we match the enum + global-scope handling
        // already validated by the sibling tests.
        config(['messaging.inbound_webhook_secret' => 'test-inbound-secret']);
        $tenant = $this->makeTenantWithNumber('+5511999000111');

        // Send a SAIR with sender lacking + prefix (raw digits with country code embedded)
        $resp = $this->withHeaders(['Authorization' => 'Bearer test-inbound-secret'])
            ->postJson('/api/v1/webhooks/infobip/inbound', [
                'results' => [[
                    'from' => '5521988887777',
                    'to'   => '+5511999000111',
                    'text' => 'SAIR',
                ]],
            ]);

        $resp->assertOk()->assertJson(['ok' => true, 'processed' => 1]);

        // The opt-out should be stored under the normalized E.164 form so a later API call
        // that uses +5521988887777 (or 5521988887777, or +5521 9888-7777) will hit it.
        $optOut = \App\Models\MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->first();
        $this->assertNotNull($optOut);
        $this->assertEquals(\App\Models\MessageOptOut::hashFor('+5521988887777'), $optOut->identifier_hash);
    }
}
