<?php

namespace Tests\Unit\Messaging;

use App\Exceptions\Billing\InsufficientFundsException;
use App\Exceptions\Messaging\QuietHoursException;
use App\Exceptions\Messaging\RecipientOptedOutException;
use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\OptOutService;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessagingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MessagingService $svc;
    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(SettingsService::class);

        // Seed service prices (SMS = 15 cents, voice = 80, email = 5)
        $this->seed(\Database\Seeders\ServicePricesSeeder::class);

        $this->svc = app(MessagingService::class);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Defaults: balance 150 cents = ~10 SMS @ 15c each. */
    private function makeTenant(int $balanceCents = 150, bool $quietEnabled = false, string $start = '22:00', string $end = '08:00'): Tenant
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => $quietEnabled,
            'quiet_hours_start'    => $start,
            'quiet_hours_end'      => $end,
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        return Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $balanceCents,
        ]);
    }

    public function test_dispatch_creates_queued_record_and_reserves_credits(): void
    {
        $tenant = $this->makeTenant(balanceCents: 150);

        $dispatch = $this->svc->dispatch(
            $tenant,
            null,
            'sms',
            ['to' => '+5521999998888', 'content' => 'Olá'],
            null
        );

        $this->assertEquals('queued', $dispatch->status);
        // SMS venda 8c (briefing 30/05) → balance 150 - 8 = 142
        $this->assertEquals(8, (int) $dispatch->sale_cents);
        $this->assertEquals(0, (int) $dispatch->charged_cents);
        $this->assertEquals('+5521999998888', $dispatch->to);
        $this->assertEquals('infobip', $dispatch->provider);

        $this->assertEquals(142, (int) $tenant->fresh()->balance_cents);

        Queue::assertPushed(SendMessageJob::class);
    }

    public function test_dispatch_rejects_opt_out_without_debit(): void
    {
        $tenant = $this->makeTenant(balanceCents: 150);
        app(OptOutService::class)->add($tenant->id, 'sms', '+5521999998888', 'user_request');

        try {
            $this->svc->dispatch(
                $tenant,
                null,
                'sms',
                ['to' => '+5521999998888', 'content' => 'Olá'],
                null
            );
            $this->fail('Expected RecipientOptedOutException');
        } catch (RecipientOptedOutException $e) {
            // expected
        }

        $this->assertEquals(150, (int) $tenant->fresh()->balance_cents);

        $row = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('rejected_opt_out', $row->status);
        $this->assertEquals(0, (int) $row->sale_cents);
        $this->assertEquals(0, (int) $row->charged_cents);

        Queue::assertNotPushed(SendMessageJob::class);
    }

    public function test_dispatch_throws_when_insufficient(): void
    {
        $tenant = $this->makeTenant(balanceCents: 0);

        $this->expectException(InsufficientFundsException::class);

        $this->svc->dispatch(
            $tenant,
            null,
            'sms',
            ['to' => '+5521999998888', 'content' => 'Olá'],
            null
        );
    }

    public function test_idempotency_replay_returns_same_dispatch_no_debit(): void
    {
        $tenant = $this->makeTenant(balanceCents: 150);
        $payload = ['to' => '+5521999998888', 'content' => 'Olá'];

        // SMS venda 8c → 150 - 8 = 142 após primeiro dispatch.
        $first = $this->svc->dispatch($tenant, null, 'sms', $payload, 'key-xyz');
        $this->assertEquals(142, (int) $tenant->fresh()->balance_cents);

        $second = $this->svc->dispatch($tenant, null, 'sms', $payload, 'key-xyz');

        // Idempotency replay: mesmo dispatch, sem novo débito.
        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(142, (int) $tenant->fresh()->balance_cents);
    }

    public function test_quiet_hours_reject_strategy_creates_rejected_record(): void
    {
        $tenant = $this->makeTenant(balanceCents: 150, quietEnabled: true);
        Carbon::setTestNow(Carbon::create(2026, 5, 26, 23, 30, 0, 'America/Sao_Paulo'));

        try {
            $this->svc->dispatch(
                $tenant,
                null,
                'sms',
                ['to' => '+5521999998888', 'content' => 'Olá'],
                null,
                'reject'
            );
            $this->fail('Expected QuietHoursException');
        } catch (QuietHoursException $e) {
            // expected
        }

        $this->assertEquals(150, (int) $tenant->fresh()->balance_cents);

        $row = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('rejected_quiet_hours', $row->status);

        Queue::assertNotPushed(SendMessageJob::class);
    }

    public function test_idempotency_replay_during_quiet_hours_reject_returns_same_rejected_dispatch(): void
    {
        $tenant = $this->makeTenant(balanceCents: 150, quietEnabled: true);
        Carbon::setTestNow(Carbon::create(2026, 5, 26, 23, 30, 0, 'America/Sao_Paulo'));

        $payload = ['to' => '+5521999998888', 'content' => 'hi'];

        try {
            $this->svc->dispatch($tenant, null, 'sms', $payload, 'idem-quiet', 'reject');
            $this->fail('Expected QuietHoursException on first call');
        } catch (QuietHoursException $e) {
            // expected on first dispatch
        }

        // Replay with same idempotency key — should return the existing rejected dispatch
        $hit = $this->svc->dispatch($tenant, null, 'sms', $payload, 'idem-quiet', 'reject');

        $this->assertEquals('rejected_quiet_hours', $hit->status);
        $this->assertEquals(
            1,
            MessageDispatch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
            'replay should return existing rejected dispatch, not create a new one'
        );
    }

    public function test_quiet_hours_defer_strategy_sets_scheduled_for(): void
    {
        $tenant = $this->makeTenant(balanceCents: 150, quietEnabled: true);
        Carbon::setTestNow(Carbon::create(2026, 5, 26, 23, 30, 0, 'America/Sao_Paulo'));

        $dispatch = $this->svc->dispatch(
            $tenant,
            null,
            'sms',
            ['to' => '+5521999998888', 'content' => 'Olá'],
            null,
            'defer'
        );

        $this->assertEquals('queued', $dispatch->status);
        $this->assertNotNull($dispatch->scheduled_for);
        $this->assertEquals(8, $dispatch->scheduled_for->setTimezone('America/Sao_Paulo')->hour);
        // SMS venda 8c → 150 - 8 = 142
        $this->assertEquals(142, (int) $tenant->fresh()->balance_cents);
    }
}
