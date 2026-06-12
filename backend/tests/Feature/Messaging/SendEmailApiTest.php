<?php

namespace Tests\Feature\Messaging;

use App\Jobs\SendMessageJob;
use App\Models\MessageDispatch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SendEmailApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');

        $this->seed(\Database\Seeders\ServicePricesSeeder::class);

        Http::fake([
            'api.infobip.com/email/3/send' => Http::response(['messages' => [['messageId' => 'email-mid-1']]], 200),
        ]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(array $abilities = ['messaging:email'], int $balanceCents = 1000, bool $quietEnabled = false): User
    {
        $plan = Plan::factory()->create([
            'quiet_hours_enabled'  => $quietEnabled,
            'quiet_hours_start'    => '22:00',
            'quiet_hours_end'      => '08:00',
            'quiet_hours_timezone' => 'America/Sao_Paulo',
        ]);
        $tenant = Tenant::factory()->create([
            'plan_id'       => $plan->id,
            'balance_cents' => $balanceCents,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, $abilities);
        return $user;
    }

    public function test_happy_path_returns_202(): void
    {
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'      => 'dest@example.com',
            'subject' => 'Hello',
            'content' => 'Plain content',
        ]);

        $resp->assertStatus(202)
            ->assertJsonStructure(['dispatch_id', 'status', 'reserved_cents', '_links' => ['status']])
            ->assertJson([
                'status'         => 'queued',
                'reserved_cents' => 5,
            ]);

        // 1000 - 5 = 995
        $this->assertSame(995, $user->tenant->fresh()->balance_cents);
        Queue::assertPushed(SendMessageJob::class);
    }

    public function test_strips_xss_script_tag(): void
    {
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'      => 'dest@example.com',
            'subject' => 'Hi',
            'content' => 'ok<script>alert(1)</script>',
        ]);

        $resp->assertStatus(202);

        $dispatch = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->latest('id')
            ->first();

        $this->assertNotNull($dispatch);
        $this->assertStringNotContainsString('<script', $dispatch->content);
        $this->assertStringNotContainsString('</script>', $dispatch->content);
    }

    public function test_strips_header_injection_in_subject(): void
    {
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'      => 'dest@example.com',
            'subject' => "Hello\r\nBcc: evil@x.com",
            'content' => 'Body',
        ]);

        $resp->assertStatus(202);

        $dispatch = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->latest('id')
            ->first();

        $this->assertNotNull($dispatch);
        $this->assertStringNotContainsString("\n", $dispatch->subject);
        $this->assertStringNotContainsString("\r", $dispatch->subject);
    }

    public function test_persists_from_name_and_reply_to_when_provided(): void
    {
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'        => 'dest@example.com',
            'subject'   => 'Olá',
            'content'   => 'Body',
            'from_name' => 'Empresa Parceria',
            'reply_to'  => 'suporte@example.com',
        ]);

        $resp->assertStatus(202);

        $dispatch = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->latest('id')
            ->first();

        $this->assertEquals('Empresa Parceria', $dispatch->from_name);
        $this->assertEquals('suporte@example.com', $dispatch->reply_to);
    }

    public function test_strips_crlf_from_from_name(): void
    {
        // CWE-93 — SMTP header injection via the new field
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'        => 'dest@example.com',
            'subject'   => 'Hi',
            'content'   => 'Body',
            'from_name' => "Empresa\r\nBcc: evil@x.com",
        ]);

        $resp->assertStatus(202);

        $dispatch = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->latest('id')
            ->first();

        $this->assertStringNotContainsString("\n", (string) $dispatch->from_name);
        $this->assertStringNotContainsString("\r", (string) $dispatch->from_name);
    }

    public function test_rejects_invalid_reply_to_email(): void
    {
        $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'       => 'dest@example.com',
            'subject'  => 'Hi',
            'content'  => 'Body',
            'reply_to' => 'nao-eh-email',
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['reply_to']);
    }

    public function test_requires_email_ability(): void
    {
        $this->actAs(['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/email', [
            'to'      => 'dest@example.com',
            'subject' => 'Hi',
            'content' => 'Body',
        ]);

        $resp->assertStatus(403)
            ->assertJson(['error' => 'INSUFFICIENT_TOKEN_ABILITY', 'required' => 'messaging:email']);
    }
}
