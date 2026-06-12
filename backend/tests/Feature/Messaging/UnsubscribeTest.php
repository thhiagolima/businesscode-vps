<?php

namespace Tests\Feature\Messaging;

use App\Models\MessageDispatch;
use App\Models\MessageOptOut;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        $plan = Plan::factory()->create();
        return Tenant::factory()->create(['plan_id' => $plan->id]);
    }

    private function makeEmailDispatch(Tenant $tenant, array $overrides = []): MessageDispatch
    {
        return MessageDispatch::withoutGlobalScopes()->create(array_merge([
            'tenant_id'         => $tenant->id,
            'channel'           => 'email',
            'source'            => 'api',
            'to'                => 'dest@example.com',
            'subject'           => 'Hi',
            'content'           => 'body',
            'provider'          => 'infobip',
            'status'            => 'sent',
            'credits_unit'      => 2,
            'credits_charged'   => 2,
            'unsubscribe_token' => Str::random(64),
        ], $overrides));
    }

    public function test_valid_token_marks_consumed_and_adds_opt_out(): void
    {
        $tenant   = $this->makeTenant();
        $dispatch = $this->makeEmailDispatch($tenant);

        $resp = $this->get("/api/v1/messaging/unsubscribe/{$dispatch->unsubscribe_token}");

        $resp->assertStatus(200);
        $this->assertStringContainsString('text/html', $resp->headers->get('Content-Type'));

        $dispatch->refresh();
        $this->assertNotNull($dispatch->unsubscribe_consumed_at);

        $this->assertDatabaseHas('message_opt_outs', [
            'tenant_id'          => $tenant->id,
            'channel'            => 'email',
            'source_dispatch_id' => $dispatch->id,
        ]);
    }

    public function test_replay_is_idempotent(): void
    {
        $tenant   = $this->makeTenant();
        $dispatch = $this->makeEmailDispatch($tenant);

        $first = $this->get("/api/v1/messaging/unsubscribe/{$dispatch->unsubscribe_token}");
        $first->assertStatus(200);
        $dispatch->refresh();
        $firstConsumedAt = $dispatch->unsubscribe_consumed_at;

        $second = $this->get("/api/v1/messaging/unsubscribe/{$dispatch->unsubscribe_token}");
        $second->assertStatus(200);

        $countAfter = MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('channel', 'email')
            ->count();
        $this->assertSame(1, $countAfter);

        $dispatch->refresh();
        $this->assertEquals(
            $firstConsumedAt->format('Y-m-d H:i:s'),
            $dispatch->unsubscribe_consumed_at->format('Y-m-d H:i:s')
        );
    }

    public function test_invalid_token_returns_404(): void
    {
        $resp = $this->get('/api/v1/messaging/unsubscribe/this-token-does-not-exist-anywhere');
        $resp->assertStatus(404);
    }

    public function test_non_email_dispatch_token_returns_404(): void
    {
        $tenant = $this->makeTenant();
        $smsDispatch = MessageDispatch::withoutGlobalScopes()->create([
            'tenant_id'         => $tenant->id,
            'channel'           => 'sms',
            'source'            => 'api',
            'to'                => '+5521988887777',
            'content'           => 'sms body',
            'provider'          => 'infobip',
            'status'            => 'sent',
            'credits_unit'      => 1,
            'credits_charged'   => 1,
            'unsubscribe_token' => Str::random(64),
        ]);

        $resp = $this->get("/api/v1/messaging/unsubscribe/{$smsDispatch->unsubscribe_token}");
        $resp->assertStatus(404);
    }
}
