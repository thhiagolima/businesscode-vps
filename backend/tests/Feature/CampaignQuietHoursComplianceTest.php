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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class CampaignQuietHoursComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function setupCampaignWithQuietHours(string $startStr, string $endStr): array
    {
        // Plan with quiet-hours enabled, window covering "now" in São Paulo TZ.
        $plan = new Plan();
        $plan->forceFill([
            'name' => 'Quiet', 'slug' => 'quiet-'.uniqid(),
            'price_monthly' => 0, 'max_contacts' => 100, 'max_campaigns' => 10,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => $startStr,
            'quiet_hours_end' => $endStr,
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ])->save();

        ServicePrice::updateOrCreate(['service' => 'sms'], ['cost_cents' => 5, 'sale_cents' => 10]);
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'plan_id' => $plan->id, 'balance_cents' => 100000,
        ]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'sms', 'status' => 'enabled', 'config' => []]);
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        Contact::create([
            'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
            'phone' => '+5511999990001', 'status' => 'active',
        ]);

        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'sms', 'status' => 'processing',
            'content' => 'oi', 'contact_list_id' => $list->id, 'estimated_contacts' => 1,
        ])->save();

        return [$tenant, $campaign];
    }

    public function test_quiet_hours_reschedules_campaign_and_does_not_send(): void
    {
        Bus::fake([SendCampaignBatchJob::class]);
        // Quiet window 00:00-23:59 (covers any "now") => campaign must not run.
        [$tenant, $campaign] = $this->setupCampaignWithQuietHours('00:00:00', '23:59:00');
        $balanceBefore = (int) $tenant->fresh()->balance_cents;

        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);

        // Campaign was rescheduled to next valid time; status is 'scheduled', no batch dispatched.
        $fresh = $campaign->fresh();
        $this->assertSame('scheduled', $fresh->status, 'Campaign hit by quiet-hours must move to scheduled');
        $this->assertNotNull($fresh->scheduled_at, 'scheduled_at must be set to next valid time');
        $this->assertTrue(
            Carbon::parse($fresh->scheduled_at)->isFuture(),
            'scheduled_at must be in the future'
        );

        // No funds were reserved/debited because nothing was dispatched.
        $this->assertSame($balanceBefore, (int) $tenant->fresh()->balance_cents);
        Bus::assertNothingBatched();
    }

    public function test_quiet_hours_outside_window_dispatches_normally(): void
    {
        Bus::fake([SendCampaignBatchJob::class]);
        // Quiet window that does NOT cover now (e.g. 1 minute window ending 1 minute ago).
        $past = Carbon::now('America/Sao_Paulo')->subMinutes(5)->format('H:i:s');
        $pastEnd = Carbon::now('America/Sao_Paulo')->subMinutes(4)->format('H:i:s');
        [$tenant, $campaign] = $this->setupCampaignWithQuietHours($past, $pastEnd);

        ProcessCampaignJob::dispatchSync($campaign->id, $tenant->id);

        // Outside quiet window: batch dispatched + funds reserved.
        Bus::assertBatched(fn ($batch) => $batch->name === "campaign-{$campaign->id}");
        $this->assertNotSame('scheduled', $campaign->fresh()->status);
    }
}
