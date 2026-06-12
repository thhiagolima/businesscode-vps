<?php

namespace Tests\Feature\Admin;

use App\Models\Campaign;
use App\Models\MessageDispatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantReportsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_by_channel_daily_top_campaigns(): void
    {
        $t = Tenant::factory()->create();
        // top_campaigns_30d reads sent_count/failed_count straight from the
        // campaigns table (no FK from message_dispatches → campaigns exists in
        // current schema), so we seed counters directly on the Campaign row.
        Campaign::factory()->create([
            'tenant_id'    => $t->id,
            'name'         => 'BF',
            'sent_count'   => 3,
            'failed_count' => 0,
        ]);
        MessageDispatch::factory()->count(3)->create([
            'tenant_id' => $t->id,
            'channel'   => 'sms',
            'status'    => 'delivered',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);
        $resp = $this->getJson("/api/v1/admin/tenants/{$t->id}/reports");

        $resp->assertStatus(200)
            ->assertJsonStructure(['data' => ['by_channel_30d', 'daily_sent_30d', 'top_campaigns_30d']]);
    }

    public function test_403_for_non_superadmin(): void
    {
        $t = Tenant::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']), ['*']);
        $this->getJson("/api/v1/admin/tenants/{$t->id}/reports")->assertStatus(403);
    }

    public function test_unknown_tenant_404(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']), ['*']);
        $this->getJson("/api/v1/admin/tenants/9999/reports")->assertStatus(404);
    }
}
