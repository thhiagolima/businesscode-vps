<?php

namespace Tests\Feature;

use App\Jobs\ProcessCampaignJob;
use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignDoubleDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantUserCampaign(): array
    {
        $tenant = Tenant::create([
            'name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active',
            'balance_cents' => 100000, 'credit_limit_cents' => 0,
        ]);
        TenantChannel::create(['tenant_id' => $tenant->id, 'channel' => 'sms', 'status' => 'enabled', 'config' => []]);
        ServicePrice::updateOrCreate(['service' => 'sms'], ['cost_cents' => 5, 'sale_cents' => 10]);
        $user = (new User())->forceFill([
            'name' => 'U', 'email' => 'u@x.com', 'password' => bcrypt('secret123'),
            'tenant_id' => $tenant->id, 'role' => 'admin',
        ]);
        $user->save();
        $list = ContactList::create(['tenant_id' => $tenant->id, 'name' => 'L']);
        $campaign = new Campaign();
        $campaign->forceFill([
            'tenant_id' => $tenant->id, 'name' => 'C', 'type' => 'sms', 'status' => 'draft',
            'content' => 'oi', 'contact_list_id' => $list->id, 'estimated_contacts' => 0,
        ])->save();
        return [$tenant, $user, $campaign];
    }

    public function test_second_send_now_fails_when_campaign_already_processing(): void
    {
        Bus::fake();
        [$tenant, $user, $campaign] = $this->makeTenantUserCampaign();
        Sanctum::actingAs($user);

        $first = $this->postJson("/api/v1/campaigns/{$campaign->id}/send-now");
        $first->assertOk();

        $second = $this->postJson("/api/v1/campaigns/{$campaign->id}/send-now");
        $second->assertStatus(422);

        Bus::assertDispatchedTimes(ProcessCampaignJob::class, 1);
    }

    public function test_process_campaign_job_uses_without_overlapping_middleware(): void
    {
        $job = new ProcessCampaignJob(1, 1);
        $middlewares = $job->middleware();

        $hasOverlap = false;
        foreach ($middlewares as $m) {
            if ($m instanceof WithoutOverlapping) {
                $hasOverlap = true;
                break;
            }
        }

        $this->assertTrue(
            $hasOverlap,
            'ProcessCampaignJob must declare WithoutOverlapping middleware to prevent double dispatch.'
        );
    }
}
