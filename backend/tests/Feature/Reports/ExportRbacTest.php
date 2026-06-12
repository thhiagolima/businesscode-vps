<?php

namespace Tests\Feature\Reports;

use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExportRbacTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenantWithCampaign(string $role): array
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
        ]);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u'.uniqid().'@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => $role,
        ]);
        $user->save();
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'sms', 'status' => 'completed',
            'content' => 'oi', 'contact_list_id' => $list->id,
        ])->save();
        return [$tenant, $user, $campaign];
    }

    public function test_user_role_cannot_export_campaign_csv(): void
    {
        [, $user, $campaign] = $this->setupTenantWithCampaign('user');
        Sanctum::actingAs($user);

        $response = $this->get("/api/v1/reports/campaigns/{$campaign->id}/export");

        // CSV contains PII (phone, email). Only admin/superadmin may export.
        $response->assertStatus(403);
    }

    public function test_admin_role_can_export_campaign_csv(): void
    {
        [, $user, $campaign] = $this->setupTenantWithCampaign('admin');
        Sanctum::actingAs($user);

        $response = $this->get("/api/v1/reports/campaigns/{$campaign->id}/export");

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
    }

    public function test_superadmin_role_can_export_campaign_csv(): void
    {
        [, $user, $campaign] = $this->setupTenantWithCampaign('superadmin');
        Sanctum::actingAs($user);

        $response = $this->get("/api/v1/reports/campaigns/{$campaign->id}/export");

        $response->assertOk();
    }
}
