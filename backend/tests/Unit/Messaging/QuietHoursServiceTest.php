<?php
namespace Tests\Unit\Messaging;

use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Messaging\QuietHoursService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuietHoursServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuietHoursService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new QuietHoursService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inside_quiet_window_returns_true(): void
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => true,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        Carbon::setTestNow(Carbon::create(2026, 5, 26, 23, 30, 0, 'America/Sao_Paulo'));

        $this->assertTrue($this->svc->isQuietHour($tenant));
    }

    public function test_outside_quiet_window_returns_false(): void
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => true,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        Carbon::setTestNow(Carbon::create(2026, 5, 26, 14, 0, 0, 'America/Sao_Paulo'));

        $this->assertFalse($this->svc->isQuietHour($tenant));
    }

    public function test_disabled_when_plan_sets_false(): void
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => false,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        Carbon::setTestNow(Carbon::create(2026, 5, 26, 23, 30, 0, 'America/Sao_Paulo'));

        $this->assertFalse($this->svc->isQuietHour($tenant));
    }

    public function test_next_valid_time_after_quiet_returns_morning(): void
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => true,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        // Inside quiet at 23:30 — next valid is 08:00 the next day
        Carbon::setTestNow(Carbon::create(2026, 5, 26, 23, 30, 0, 'America/Sao_Paulo'));

        $next = $this->svc->nextValidTime($tenant);
        $this->assertEquals(8, $next->hour);
        $this->assertEquals(0, $next->minute);
    }

    public function test_next_valid_time_when_already_outside_quiet_returns_now(): void
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => true,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);

        Carbon::setTestNow(Carbon::parse('2026-05-26 14:00:00', 'America/Sao_Paulo'));

        $next = app(QuietHoursService::class)->nextValidTime($tenant);

        $this->assertEquals('2026-05-26 14:00:00', $next->format('Y-m-d H:i:s'));
    }
}
