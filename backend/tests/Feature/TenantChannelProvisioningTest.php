<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantChannelProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_provision_defaults_creates_default_channels(): void
    {
        $tenant = Tenant::create(['name' => 'Legacy', 'slug' => 'legacy-'.uniqid(), 'status' => 'active']);
        $this->assertSame(0, TenantChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        TenantChannel::provisionDefaults($tenant->id);

        $this->assertTrue(TenantChannel::isAvailable($tenant->id, 'sms'));
        $this->assertTrue(TenantChannel::isAvailable($tenant->id, 'voice'));
        $this->assertFalse(TenantChannel::isAvailable($tenant->id, 'email'));
        $this->assertFalse(TenantChannel::isAvailable($tenant->id, 'whatsapp'));
    }

    public function test_provision_defaults_is_idempotent_and_preserves_existing_status(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        // Email já habilitado manualmente — não deve ser sobrescrito.
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'status' => 'enabled', 'config' => []]);

        TenantChannel::provisionDefaults($tenant->id);
        TenantChannel::provisionDefaults($tenant->id); // segunda chamada não duplica

        $this->assertSame(4, TenantChannel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertTrue(TenantChannel::isAvailable($tenant->id, 'email'), 'status existente preservado');
    }

    public function test_legacy_tenant_without_channels_can_create_campaign_after_provisioning(): void
    {
        // Reproduz o bug: tenant sem nenhuma linha de canal não consegue criar campanha.
        $tenant = Tenant::create(['name' => 'Pedro', 'slug' => 'pedro-'.uniqid(), 'status' => 'active']);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        Sanctum::actingAs($user);

        $payload = ['name' => 'teste', 'type' => 'sms'];

        $this->postJson('/api/v1/campaigns', $payload)->assertStatus(422);
        $this->assertSame(0, Campaign::withoutGlobalScopes()->count());

        // Backfill provisiona os canais → criação passa a funcionar.
        TenantChannel::provisionDefaults($tenant->id);

        $this->postJson('/api/v1/campaigns', $payload)->assertStatus(201);
        $this->assertSame(1, Campaign::withoutGlobalScopes()->count());
    }
}
