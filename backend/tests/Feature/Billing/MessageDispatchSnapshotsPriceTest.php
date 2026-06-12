<?php

namespace Tests\Feature\Billing;

use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Messaging\MessagingService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pricing snapshot: a dispatch must carry the cost_cents/sale_cents resolved
 * at the moment of dispatch, regardless of later price changes.
 */
class MessageDispatchSnapshotsPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(SettingsService::class);
        $settings->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        $settings->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);
        Queue::fake();
    }

    private function tenantWithBalance(int $cents): Tenant
    {
        $plan = Plan::factory()->create(['quiet_hours_enabled' => false]);
        return Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $cents,
        ]);
    }

    public function test_dispatch_snapshots_current_sale_cents(): void
    {
        $tenant = $this->tenantWithBalance(1000);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $dispatch = app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521988887777', 'content' => 'snapshot'],
            null
        );

        // Preços vigentes do briefing: SMS venda 8c / custo 6c.
        $this->assertSame(8, (int) $dispatch->sale_cents);
        $this->assertSame(6, (int) $dispatch->cost_cents);
        // Snapshot em micros preserva precisão real (custo R$ 0,0605 = 6050 micros).
        $this->assertSame(7500, (int) $dispatch->sale_micros);
        $this->assertSame(6050, (int) $dispatch->cost_micros);
    }

    public function test_later_global_price_change_does_not_modify_old_dispatch(): void
    {
        $tenant = $this->tenantWithBalance(2000);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $first = app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521988887777', 'content' => 'old-price'],
            null
        );

        // Bump global price → cache invalidated → new dispatches priced higher
        ServicePrice::where('service', 'sms')->update([
            'cost_cents' => 50, 'sale_cents' => 99,
            'cost_micros' => 50000, 'sale_micros' => 99000,
        ]);
        Cache::forget('service_price:sms');

        $second = app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521988887777', 'content' => 'new-price'],
            null
        );

        // Old dispatch unchanged (preserva preço vigente no momento)
        $first->refresh();
        $this->assertSame(8, (int) $first->sale_cents);
        $this->assertSame(6, (int) $first->cost_cents);
        $this->assertSame(7500, (int) $first->sale_micros);
        $this->assertSame(6050, (int) $first->cost_micros);

        // New dispatch reflects new price
        $this->assertSame(99, (int) $second->sale_cents);
        $this->assertSame(50, (int) $second->cost_cents);
        $this->assertSame(99000, (int) $second->sale_micros);
        $this->assertSame(50000, (int) $second->cost_micros);
    }

    public function test_tenant_override_takes_precedence_over_global(): void
    {
        $tenant = $this->tenantWithBalance(1000);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Insert tenant override of 3c
        \App\Models\TenantServicePrice::withoutGlobalScopes()->create([
            'tenant_id'  => $tenant->id,
            'service'    => 'sms',
            'sale_cents' => 3,
            'reason'     => 'enterprise',
            'created_by' => $user->id,
        ]);

        $dispatch = app(MessagingService::class)->dispatch(
            $tenant, $user, 'sms',
            ['to' => '+5521988887777', 'content' => 'override'],
            null
        );

        $this->assertSame(3, (int) $dispatch->sale_cents);
        // cost_cents always comes from global ServicePrice (SMS custo 6c agora)
        $this->assertSame(6, (int) $dispatch->cost_cents);
        $this->assertSame(6050, (int) $dispatch->cost_micros);
    }
}
