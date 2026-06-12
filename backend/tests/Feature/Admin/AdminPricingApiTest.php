<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPricingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
    }

    private function userWithRole(string $role): User
    {
        $plan = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
    }

    public function test_superadmin_can_list_all_services(): void
    {
        Sanctum::actingAs($this->userWithRole('superadmin'), ['*']);

        $resp = $this->getJson('/api/v1/admin/billing/pricing');

        $resp->assertStatus(200)
            ->assertJsonStructure(['data' => [['service', 'cost_cents', 'sale_cents', 'margin_cents']]]);

        // Catálogo atual cobre mensageria, WhatsApp por categoria, IA e TTS.
        $services = collect($resp->json('data'))->pluck('service')->sort()->values()->all();
        $expected = [
            'ai_generation', 'audio_tts', 'email', 'sms', 'voice',
            'whatsapp_auth', 'whatsapp_marketing', 'whatsapp_utility',
        ];
        $this->assertSame($expected, $services);
    }

    public function test_finance_can_update_and_audit_logged(): void
    {
        $user = $this->userWithRole('finance');
        Sanctum::actingAs($user, ['*']);

        $resp = $this->putJson('/api/v1/admin/billing/pricing/sms', [
            'cost_cents' => 10,
            'sale_cents' => 20,
            'reason'     => 'Aumento de margem por reajuste de fornecedor',
        ]);

        $resp->assertStatus(200)
            ->assertJsonPath('data.cost_cents', 10)
            ->assertJsonPath('data.sale_cents', 20);

        $sp = ServicePrice::where('service', 'sms')->first();
        $this->assertSame(20, (int) $sp->sale_cents);

        // Audit row exists with before/after
        $log = AuditLog::where('action', 'service_price.updated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('sms', $log->metadata['service']);
        // Briefing 30/05/2026: SMS venda 8c
        $this->assertSame(8, (int) $log->metadata['before']['sale_cents']);
        $this->assertSame(20, (int) $log->metadata['after']['sale_cents']);
    }

    public function test_regular_user_gets_403(): void
    {
        Sanctum::actingAs($this->userWithRole('user'), ['*']);

        $resp = $this->putJson('/api/v1/admin/billing/pricing/sms', [
            'cost_cents' => 1,
            'sale_cents' => 2,
            'reason'     => 'tentativa não autorizada',
        ]);

        $resp->assertStatus(403);
    }

    public function test_validation_rejects_missing_reason(): void
    {
        Sanctum::actingAs($this->userWithRole('superadmin'), ['*']);

        $resp = $this->putJson('/api/v1/admin/billing/pricing/sms', [
            'cost_cents' => 10,
            'sale_cents' => 20,
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }
}
