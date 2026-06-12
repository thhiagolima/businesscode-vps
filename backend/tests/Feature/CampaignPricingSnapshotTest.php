<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignJob;
use App\Jobs\SendCampaignBatchJob;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Plan;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CampaignPricingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function setupCampaign(int $contacts, int $unitCents): array
    {
        $plan = new Plan();
        $plan->forceFill([
            'name' => 'NoQuiet', 'slug' => 'nq-'.uniqid(), 'price_monthly' => 0,
            'max_contacts' => 1000, 'max_campaigns' => 100, 'quiet_hours_enabled' => false,
        ])->save();
        ServicePrice::updateOrCreate(['service' => 'sms'], ['cost_cents' => 5, 'sale_cents' => $unitCents]);
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'plan_id' => $plan->id, 'balance_cents' => 100000, 'credit_limit_cents' => 0,
        ]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'sms', 'status' => 'enabled', 'config' => []]);
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        for ($i = 0; $i < $contacts; $i++) {
            Contact::create([
                'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
                'phone' => '+5511999990'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'status' => 'active',
            ]);
        }
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'sms', 'status' => 'processing',
            'content' => 'oi', 'contact_list_id' => $list->id, 'estimated_contacts' => $contacts,
        ])->save();
        return [$tenant, $campaign];
    }

    public function test_dispatch_persists_pricing_snapshot_in_dedicated_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('campaigns', 'unit_cents_at_dispatch'),
            'Migration must add unit_cents_at_dispatch column');
        $this->assertTrue(Schema::hasColumn('campaigns', 'reserved_cents'),
            'Migration must add reserved_cents column');

        Bus::fake([SendCampaignBatchJob::class]);
        [$tenant, $campaign] = $this->setupCampaign(contacts: 7, unitCents: 12);

        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);

        $fresh = $campaign->fresh();
        $this->assertSame(12, (int) $fresh->unit_cents_at_dispatch);
        $this->assertSame(7 * 12, (int) $fresh->reserved_cents);
    }

    public function test_admin_price_change_after_dispatch_does_not_affect_campaign(): void
    {
        Bus::fake([SendCampaignBatchJob::class]);
        [$tenant, $campaign] = $this->setupCampaign(contacts: 5, unitCents: 10);

        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);
        $this->assertSame(10, (int) $campaign->fresh()->unit_cents_at_dispatch);

        // Admin doubles the price after the campaign was already dispatched.
        ServicePrice::updateOrCreate(['service' => 'sms'], ['cost_cents' => 5, 'sale_cents' => 999]);

        // Snapshot is immutable on the campaign — the price change cannot retroactively
        // change what the customer was charged.
        $this->assertSame(10, (int) $campaign->fresh()->unit_cents_at_dispatch);
        $this->assertSame(50, (int) $campaign->fresh()->reserved_cents);
    }
}
