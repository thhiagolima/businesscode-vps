<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantContactsAdminTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperadmin(): User
    {
        $u = User::factory()->create(['role' => 'superadmin']);
        Sanctum::actingAs($u, ['*']);
        return $u;
    }

    public function test_lists_contacts_of_target_tenant_only(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        Contact::factory()->count(3)->create(['tenant_id' => $a->id]);
        Contact::factory()->count(7)->create(['tenant_id' => $b->id]);

        $this->asSuperadmin();
        $resp = $this->getJson("/api/v1/admin/tenants/{$a->id}/contacts");
        $resp->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_creates_contact_with_correct_tenant_id_even_if_body_says_otherwise(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $this->asSuperadmin();

        $resp = $this->postJson("/api/v1/admin/tenants/{$a->id}/contacts", [
            'name'      => 'João',
            'phone'     => '+5511999999999',
            'tenant_id' => $b->id,
        ]);

        $resp->assertStatus(201);
        $id = $resp->json('data.id');
        $this->assertSame($a->id, Contact::find($id)->tenant_id);

        $log = AuditLog::where('action', 'admin.contact.create')->latest('id')->first();
        $this->assertSame($a->id, $log->metadata['target_tenant_id']);
    }

    public function test_update_and_destroy_audit_logged(): void
    {
        $a = Tenant::factory()->create();
        $c = Contact::factory()->create(['tenant_id' => $a->id, 'name' => 'antes']);
        $this->asSuperadmin();

        $this->putJson("/api/v1/admin/tenants/{$a->id}/contacts/{$c->id}", ['name' => 'depois'])
            ->assertStatus(200);
        $this->assertSame('depois', $c->fresh()->name);

        $this->deleteJson("/api/v1/admin/tenants/{$a->id}/contacts/{$c->id}")
            ->assertStatus(200);

        $this->assertTrue(AuditLog::where('action', 'admin.contact.update')->exists());
        $this->assertTrue(AuditLog::where('action', 'admin.contact.delete')->exists());
    }

    public function test_cross_tenant_contact_returns_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $c = Contact::factory()->create(['tenant_id' => $b->id]);
        $this->asSuperadmin();

        $this->putJson("/api/v1/admin/tenants/{$a->id}/contacts/{$c->id}", ['name' => 'x'])
            ->assertStatus(404);
    }
}
