<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantConversationsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_and_updates_status(): void
    {
        $t = Tenant::factory()->create();
        $conv = Conversation::factory()->create(['tenant_id' => $t->id, 'status' => 'open']);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);

        $this->getJson("/api/v1/admin/tenants/{$t->id}/conversations")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Status enum on this codebase is ['open', 'bot', 'human', 'closed']
        // (no 'archived'). Move the conversation from open -> closed.
        $this->patchJson("/api/v1/admin/tenants/{$t->id}/conversations/{$conv->id}", [
            'status' => 'closed',
        ])->assertStatus(200);

        $this->assertSame('closed', $conv->fresh()->status);
        $this->assertTrue(AuditLog::where('action', 'admin.conversation.update')->exists());
    }

    public function test_only_lists_target_tenant_conversations(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        Conversation::factory()->count(2)->create(['tenant_id' => $a->id]);
        Conversation::factory()->count(4)->create(['tenant_id' => $b->id]);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);

        $this->getJson("/api/v1/admin/tenants/{$a->id}/conversations")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_cross_tenant_update_returns_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $conv = Conversation::factory()->create(['tenant_id' => $b->id, 'status' => 'open']);
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);

        $this->patchJson("/api/v1/admin/tenants/{$a->id}/conversations/{$conv->id}", [
            'status' => 'closed',
        ])->assertStatus(404);
    }

    public function test_403_for_non_superadmin(): void
    {
        $t = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
        $this->getJson("/api/v1/admin/tenants/{$t->id}/conversations")->assertStatus(403);
    }
}
