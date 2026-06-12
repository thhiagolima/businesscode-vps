<?php

namespace Tests\Feature\Admin;

use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessagingAdminTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperadmin(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role'      => 'superadmin',
        ]);
    }

    private function makeRegular(): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'role'      => 'user',
        ]);
    }

    public function test_superadmin_can_update_pricing(): void
    {
        $admin = $this->makeSuperadmin();
        Sanctum::actingAs($admin);

        $resp = $this->putJson('/api/v1/admin/messaging/pricing', [
            'credits_per_sms' => 3,
        ]);

        $resp->assertStatus(200)
            ->assertJson(['ok' => true]);

        $stored = app(SettingsService::class)->getGlobal('billing', 'credits_per_sms');
        $this->assertSame('3', (string) $stored);
    }

    public function test_regular_user_gets_403(): void
    {
        $user = $this->makeRegular();
        Sanctum::actingAs($user);

        $resp = $this->putJson('/api/v1/admin/messaging/pricing', [
            'credits_per_sms' => 9,
        ]);

        $resp->assertStatus(403);
    }

    public function test_stats_returns_aggregated_counts(): void
    {
        $admin = $this->makeSuperadmin();
        Sanctum::actingAs($admin);

        // Seed a few dispatches across statuses (using new cents columns)
        $tenant = $admin->tenant;
        foreach ([
            ['channel' => 'sms',   'status' => 'sent',   'charged_cents' => 15],
            ['channel' => 'sms',   'status' => 'sent',   'charged_cents' => 15],
            ['channel' => 'sms',   'status' => 'failed', 'charged_cents' => 0],
            ['channel' => 'email', 'status' => 'sent',   'charged_cents' => 5],
        ] as $row) {
            MessageDispatch::withoutGlobalScopes()->create([
                'tenant_id'     => $tenant->id,
                'channel'       => $row['channel'],
                'source'        => 'api',
                'to'            => $row['channel'] === 'email' ? 'd@e.com' : '+5521988887777',
                'content'       => 'x',
                'provider'      => 'infobip',
                'status'        => $row['status'],
                'cost_cents'    => $row['channel'] === 'email' ? 2 : 8,
                'sale_cents'    => $row['channel'] === 'email' ? 5 : 15,
                'charged_cents' => $row['charged_cents'],
            ]);
        }

        $resp = $this->getJson('/api/v1/admin/messaging/stats');
        $resp->assertStatus(200)
            ->assertJsonStructure(['data']);

        $rows = collect($resp->json('data'));

        // Rows grouped by (tenant_id, channel, status). For this tenant we expect:
        //  - sms / sent   => n=2, charged_cents=30
        //  - sms / failed => n=1, charged_cents=0
        //  - email / sent => n=1, charged_cents=5
        $smsSent = $rows->first(fn ($r) => $r['tenant_id'] === $tenant->id && $r['channel'] === 'sms' && $r['status'] === 'sent');
        $this->assertNotNull($smsSent);
        $this->assertSame(2, (int) $smsSent['n']);
        $this->assertSame(30, (int) $smsSent['charged_cents']);

        $emailSent = $rows->first(fn ($r) => $r['tenant_id'] === $tenant->id && $r['channel'] === 'email' && $r['status'] === 'sent');
        $this->assertNotNull($emailSent);
        $this->assertSame(1, (int) $emailSent['n']);
        $this->assertSame(5, (int) $emailSent['charged_cents']);
    }
}
