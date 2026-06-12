<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0R-02 — UNIQUE no DB para impedir disparo duplicado da mesma campanha
 * para o mesmo contato/telefone. Defesa em profundidade ao
 * WithoutOverlapping + pessimistic lock no controller.
 */
class CampaignDispatchUniqueTest extends TestCase
{
    use RefreshDatabase;

    private function bootstrap(): array
    {
        $tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'sms', 'status' => 'running',
            'content' => 'oi', 'contact_list_id' => $list->id,
        ])->save();
        return [$tenant, $list, $campaign];
    }

    public function test_duplicate_dispatch_for_same_campaign_contact_throws(): void
    {
        [$tenant, $list, $campaign] = $this->bootstrap();
        $contact = Contact::create([
            'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
            'phone' => '+5511999990001', 'status' => 'active',
        ]);

        CampaignDispatch::create([
            'tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'contact_id' => $contact->id, 'phone' => $contact->phone,
            'status' => 'sent',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        CampaignDispatch::create([
            'tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'contact_id' => $contact->id, 'phone' => $contact->phone,
            'status' => 'sent',
        ]);
    }

    public function test_duplicate_dispatch_for_same_campaign_phone_throws(): void
    {
        [$tenant, , $campaign] = $this->bootstrap();

        CampaignDispatch::create([
            'tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'contact_id' => null, 'phone' => '+5511999990002',
            'status' => 'sent',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        CampaignDispatch::create([
            'tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'contact_id' => null, 'phone' => '+5511999990002',
            'status' => 'sent',
        ]);
    }

    public function test_same_contact_different_campaigns_succeeds(): void
    {
        [$tenant, $list, $campaign] = $this->bootstrap();
        $campaign2 = new Campaign();
        $campaign2->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C2', 'type' => 'sms', 'status' => 'running',
            'content' => 'oi', 'contact_list_id' => $list->id,
        ])->save();
        $contact = Contact::create([
            'tenant_id' => $tenant->id, 'contact_list_id' => $list->id,
            'phone' => '+5511999990003', 'status' => 'active',
        ]);

        CampaignDispatch::create([
            'tenant_id' => $tenant->id, 'campaign_id' => $campaign->id,
            'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'sent',
        ]);
        CampaignDispatch::create([
            'tenant_id' => $tenant->id, 'campaign_id' => $campaign2->id,
            'contact_id' => $contact->id, 'phone' => $contact->phone, 'status' => 'sent',
        ]);

        $this->assertSame(2, CampaignDispatch::withoutGlobalScopes()->count());
    }
}
