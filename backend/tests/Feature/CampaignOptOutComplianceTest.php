<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignBatchJob;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\MessageOptOut;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignOptOutComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithCampaign(string $channel = 'sms'): array
    {
        ServicePrice::updateOrCreate(['service' => $channel], ['cost_cents' => 5, 'sale_cents' => 10]);
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'balance_cents' => 100000,
        ]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => $channel, 'status' => 'enabled', 'config' => []]);
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => $channel,
            'status' => 'running',
            'content' => 'oi', 'contact_list_id' => $list->id,
        ])->save();
        return [$tenant, $list, $campaign];
    }

    public function test_send_campaign_batch_skips_opted_out_phone(): void
    {
        [$tenant, $list, $campaign] = $this->makeTenantWithCampaign('sms');

        $optedOut = Contact::create([
            'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
            'phone' => '+5511999990001', 'status' => 'active',
        ]);
        $allowed = Contact::create([
            'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
            'phone' => '+5511999990002', 'status' => 'active',
        ]);

        // Mark first contact as opted out for SMS.
        app(\App\Services\Messaging\OptOutService::class)->add(
            $tenant->id, 'sms', '+5511999990001', 'test'
        );

        // Stub provider that would otherwise hit the network.
        $stubInfobip = new class extends InfobipService {
            public array $sent = [];
            public function __construct() {}
            public function sendSms(string $phone, string $content, string $from = 'CampaignAI'): array
            {
                $this->sent[] = $phone;
                return ['ok' => true, 'message_id' => 'm-'.uniqid(), 'error' => null];
            }
        };
        $stubSettings = app(SettingsService::class);

        (new SendCampaignBatchJob($campaign->id, $tenant->id, [$optedOut->id, $allowed->id], []))
            ->handle($stubInfobip, $stubSettings);

        // The opted-out phone must NOT have been sent.
        $this->assertNotContains('+5511999990001', $stubInfobip->sent, 'Opted-out phone must be skipped');
        $this->assertContains('+5511999990002', $stubInfobip->sent, 'Allowed phone must be sent');

        // A failed dispatch row must be recorded for the opted-out contact for auditability.
        $skip = CampaignDispatch::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->where('contact_id', $optedOut->id)
            ->first();
        $this->assertNotNull($skip, 'Skipped opt-out must still leave an audit row');
        $this->assertSame('failed', $skip->status);
        $this->assertStringContainsString('opt_out', (string) $skip->error_message);
    }

    public function test_send_campaign_batch_skips_opted_out_adhoc_phone(): void
    {
        [$tenant, , $campaign] = $this->makeTenantWithCampaign('sms');

        app(\App\Services\Messaging\OptOutService::class)->add(
            $tenant->id, 'sms', '+5511999990001', 'test'
        );

        $stubInfobip = new class extends InfobipService {
            public array $sent = [];
            public function __construct() {}
            public function sendSms(string $phone, string $content, string $from = 'CampaignAI'): array
            {
                $this->sent[] = $phone;
                return ['ok' => true, 'message_id' => 'm-'.uniqid(), 'error' => null];
            }
        };

        (new SendCampaignBatchJob(
            $campaign->id, $tenant->id, [], ['+5511999990001', '+5511999990002']
        ))->handle($stubInfobip, app(SettingsService::class));

        $this->assertNotContains('+5511999990001', $stubInfobip->sent);
        $this->assertContains('+5511999990002', $stubInfobip->sent);
    }
}
