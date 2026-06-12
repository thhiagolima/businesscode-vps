<?php

namespace Tests\Feature;

use App\Jobs\SendCampaignBatchJob;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\EmailSenderDomain;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Services\Infobip\InfobipService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignFromEmailGuardTest extends TestCase
{
    use RefreshDatabase;

    private function bootstrap(string $fromEmail, bool $registerDomain): array
    {
        ServicePrice::updateOrCreate(['service' => 'email'], ['cost_cents' => 1, 'sale_cents' => 2]);
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 100000,
        ]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'status' => 'enabled', 'config' => []]);
        if ($registerDomain) {
            $domain = explode('@', $fromEmail)[1];
            (new EmailSenderDomain())->forceFill([
                'tenant_id' => $tenant->id, 'domain' => $domain, 'status' => 'active',
                'dkim_verified' => true, 'spf_verified' => true, 'return_path_verified' => true,
            ])->save();
        }
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        $contact = Contact::create([
            'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
            'phone' => '+5511999990001', 'email' => 'recipient@example.com', 'status' => 'active',
        ]);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'email', 'status' => 'running',
            'subject' => 'Olá', 'content' => '<p>Promo</p>', 'contact_list_id' => $list->id,
            'settings' => ['from_email' => $fromEmail],
        ])->save();
        return [$tenant, $campaign, $contact];
    }

    public function test_unauthorized_from_email_blocks_send_and_marks_failed(): void
    {
        [$tenant, $campaign, $contact] = $this->bootstrap('fake@notmine.example.com', registerDomain: false);

        $stubInfobip = new class extends InfobipService {
            public array $sent = [];
            public function __construct() {}
            public function sendEmail(string $to, string $subject, string $body, string $from, string $fromName = '', ?string $replyTo = null): array
            {
                $this->sent[] = $to;
                return ['ok' => true, 'message_id' => 'm-1', 'error' => null];
            }
        };

        (new SendCampaignBatchJob($campaign->id, $tenant->id, [$contact->id], []))
            ->handle($stubInfobip, app(SettingsService::class));

        // Provider must NOT have been called.
        $this->assertEmpty($stubInfobip->sent, 'Email with unauthorized from must not be sent');

        // Dispatch must be marked as failed with a clear reason.
        $dispatch = CampaignDispatch::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->first();
        $this->assertNotNull($dispatch);
        $this->assertSame('failed', $dispatch->status);
        $this->assertStringContainsString('from_email', (string) $dispatch->error_message);
    }

    public function test_authorized_from_email_sends_normally(): void
    {
        [$tenant, $campaign, $contact] = $this->bootstrap('me@mine.example.com', registerDomain: true);

        $stubInfobip = new class extends InfobipService {
            public array $sent = [];
            public function __construct() {}
            public function sendEmail(string $to, string $subject, string $body, string $from, string $fromName = '', ?string $replyTo = null): array
            {
                $this->sent[] = $to;
                return ['ok' => true, 'message_id' => 'm-1', 'error' => null];
            }
        };

        (new SendCampaignBatchJob($campaign->id, $tenant->id, [$contact->id], []))
            ->handle($stubInfobip, app(SettingsService::class));

        $this->assertContains('recipient@example.com', $stubInfobip->sent);
    }
}
