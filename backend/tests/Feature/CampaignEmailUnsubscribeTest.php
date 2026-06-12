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

class CampaignEmailUnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function setupEmailCampaign(int $contacts = 2): array
    {
        ServicePrice::updateOrCreate(['service' => 'email'], ['cost_cents' => 1, 'sale_cents' => 2]);
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active', 'balance_cents' => 100000,
        ]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'email', 'status' => 'enabled', 'config' => []]);
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        $contactIds = [];
        for ($i = 0; $i < $contacts; $i++) {
            $contactIds[] = Contact::create([
                'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
                'phone' => '+5511999990'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'email' => "u{$i}@example.com",
                'status' => 'active',
            ])->id;
        }
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'email', 'status' => 'running',
            'subject' => 'Olá', 'content' => '<p>Promo do mês.</p>', 'contact_list_id' => $list->id,
        ])->save();
        return [$tenant, $campaign, $contactIds];
    }

    public function test_email_campaign_attaches_unique_unsubscribe_token_per_dispatch(): void
    {
        [$tenant, $campaign, $contactIds] = $this->setupEmailCampaign(2);

        $stubInfobip = new class extends InfobipService {
            public array $sentBodies = [];
            public function __construct() {}
            public function sendEmail(string $to, string $subject, string $body, string $from, string $fromName = '', ?string $replyTo = null): array
            {
                $this->sentBodies[$to] = $body;
                return ['ok' => true, 'message_id' => 'm-'.uniqid(), 'error' => null];
            }
        };

        (new SendCampaignBatchJob($campaign->id, $tenant->id, $contactIds, []))
            ->handle($stubInfobip, app(SettingsService::class));

        $dispatches = CampaignDispatch::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->get();
        $this->assertCount(2, $dispatches);

        $tokens = $dispatches->pluck('unsubscribe_token')->filter()->all();
        $this->assertCount(2, $tokens, 'Each email dispatch must have its own unsubscribe_token');
        $this->assertCount(2, array_unique($tokens), 'Tokens must be unique per recipient');

        // Sent email body MUST contain the unsubscribe URL for that recipient.
        foreach ($dispatches as $d) {
            $body = $stubInfobip->sentBodies[$d->phone] ?? null;
            // dispatch.phone holds the email destination for email channel via recordDispatch's $destination.
            // Some channels may store email in 'phone' column; check by token presence.
            $bodyForEmail = null;
            foreach ($stubInfobip->sentBodies as $to => $b) {
                if (str_contains($b, $d->unsubscribe_token)) {
                    $bodyForEmail = $b;
                    break;
                }
            }
            $this->assertNotNull($bodyForEmail, "Email body must contain dispatch token");
        }
    }

    public function test_clicking_campaign_unsubscribe_token_opts_out_recipient(): void
    {
        [$tenant, $campaign, $contactIds] = $this->setupEmailCampaign(1);

        $stubInfobip = new class extends InfobipService {
            public function __construct() {}
            public function sendEmail(string $to, string $subject, string $body, string $from, string $fromName = '', ?string $replyTo = null): array
            {
                return ['ok' => true, 'message_id' => 'm-1', 'error' => null];
            }
        };

        (new SendCampaignBatchJob($campaign->id, $tenant->id, $contactIds, []))
            ->handle($stubInfobip, app(SettingsService::class));

        $dispatch = CampaignDispatch::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('unsubscribe_token')
            ->first();
        $this->assertNotNull($dispatch);

        $response = $this->get("/api/v1/messaging/unsubscribe/{$dispatch->unsubscribe_token}");
        $response->assertOk();

        // Recipient must now be opted out.
        $this->assertTrue(
            MessageOptOut::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('channel', 'email')
                ->where('identifier_hash', MessageOptOut::hashFor('u0@example.com'))
                ->exists()
        );

        // Dispatch must be marked as consumed.
        $this->assertNotNull($dispatch->fresh()->unsubscribe_consumed_at);
    }
}
