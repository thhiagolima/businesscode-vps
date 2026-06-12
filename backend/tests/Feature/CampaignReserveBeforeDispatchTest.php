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
use Tests\TestCase;

class CampaignReserveBeforeDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function setupCampaign(int $balanceCents, int $contacts, int $unitCents = 10): array
    {
        ServicePrice::updateOrCreate(['service' => 'sms'], ['cost_cents' => 5, 'sale_cents' => $unitCents]);
        // Quiet-hours OFF so this test exercises only the reserve logic.
        $plan = new Plan();
        $plan->forceFill([
            'name' => 'NoQuiet', 'slug' => 'nq-'.uniqid(), 'price_monthly' => 0,
            'max_contacts' => 1000, 'max_campaigns' => 100,
            'quiet_hours_enabled' => false,
        ])->save();
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'plan_id' => $plan->id,
            'balance_cents' => $balanceCents, 'credit_limit_cents' => 0,
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
            'content' => 'oi', 'contact_list_id' => $list->id,
            'estimated_contacts' => $contacts,
        ])->save();
        return [$tenant, $campaign];
    }

    public function test_balance_is_debited_before_batches_dispatch(): void
    {
        Bus::fake([SendCampaignBatchJob::class]);
        [$tenant, $campaign] = $this->setupCampaign(balanceCents: 5000, contacts: 10, unitCents: 10);

        // 10 contacts × 10 cents = 100 cents required.
        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);

        // Balance must have been debited (5000 - 100 = 4900) BEFORE any send.
        $this->assertSame(4900, (int) $tenant->fresh()->balance_cents);
        Bus::assertBatched(fn ($batch) => $batch->name === "campaign-{$campaign->id}");
    }

    public function test_insufficient_balance_fails_campaign_and_does_not_dispatch_batches(): void
    {
        Bus::fake([SendCampaignBatchJob::class]);
        [$tenant, $campaign] = $this->setupCampaign(balanceCents: 50, contacts: 10, unitCents: 10);
        // Need 100 cents, have 50 cents, no credit limit.

        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);

        // Campaign must transition to failed; no batch dispatched; balance unchanged.
        $this->assertSame('failed', $campaign->fresh()->status);
        $this->assertSame(50, (int) $tenant->fresh()->balance_cents);
        Bus::assertNothingBatched();
    }

    public function test_retry_does_not_double_debit(): void
    {
        Bus::fake([SendCampaignBatchJob::class]);
        [$tenant, $campaign] = $this->setupCampaign(balanceCents: 5000, contacts: 5, unitCents: 10);
        // Need 50 cents.

        // First run — reserves 50.
        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);
        $this->assertSame(4950, (int) $tenant->fresh()->balance_cents);

        // Reset campaign to running so the job will run again.
        $campaign->forceFill(['status' => 'processing'])->save();

        // Second run (simulates retry) — must NOT debit again.
        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);
        $this->assertSame(4950, (int) $tenant->fresh()->balance_cents);
    }
}
