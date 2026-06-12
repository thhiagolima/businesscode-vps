<?php

namespace Tests\Feature\Messaging;

use App\Jobs\SendMessageJob;
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

class SendVoiceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SettingsService::class)->upsertGlobal('infobip', 'api_key', 'k', 'encrypted');
        app(SettingsService::class)->upsertGlobal('infobip', 'base_url', 'api.infobip.com', 'string');

        $this->seed(\Database\Seeders\ServicePricesSeeder::class);

        Http::fake([
            'api.infobip.com/tts/3/single' => Http::response(['messages' => [['messageId' => 'voice-mid-1']]], 200),
        ]);

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actAs(array $abilities = ['messaging:voice'], int $balanceCents = 8000, bool $quietEnabled = false): User
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

    public function test_tts_happy_path(): void
    {
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to'      => '+5521999998888',
            'content' => 'Olá, esta é uma mensagem de voz.',
        ]);

        $resp->assertStatus(202)
            ->assertJsonStructure(['dispatch_id', 'status', 'reserved_cents', '_links' => ['status']])
            ->assertJson([
                'status'         => 'queued',
                'reserved_cents' => 80,
            ]);

        // 8000 - 80 = 7920
        $this->assertSame(7920, $user->tenant->fresh()->balance_cents);
        Queue::assertPushed(SendMessageJob::class);
    }

    public function test_rejects_audio_url_outside_allowlist(): void
    {
        $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to'        => '+5521999998888',
            'audio_url' => 'https://evil.com/audio.mp3',
        ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['audio_url']);
    }

    public function test_audio_url_https_required(): void
    {
        $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to'        => '+5521999998888',
            'audio_url' => 'http://s3.amazonaws.com/x.mp3',
        ]);

        $resp->assertStatus(422)
            ->assertJsonValidationErrors(['audio_url']);
    }

    public function test_audio_url_from_allowlist_host_is_accepted(): void
    {
        $user = $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to'        => '+5521999998888',
            'audio_url' => 'https://meu-bucket.s3.amazonaws.com/audio.mp3',
        ]);

        $resp->assertStatus(202);
        $dispatch = \App\Models\MessageDispatch::withoutGlobalScopes()->latest('id')->first();
        $this->assertEquals('https://meu-bucket.s3.amazonaws.com/audio.mp3', $dispatch->audio_url);
        $this->assertEquals('', $dispatch->content);
    }

    public function test_allow_any_https_when_toggle_enabled(): void
    {
        config(['messaging.audio_url_allow_any' => true]);
        $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to'        => '+5521999998888',
            'audio_url' => 'https://cdn.qualquer-host.com/audio.mp3',
        ]);
        $resp->assertStatus(202);
    }

    public function test_allow_any_still_blocks_private_ip(): void
    {
        config(['messaging.audio_url_allow_any' => true]);
        $this->actAs();

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to'        => '+5521999998888',
            'audio_url' => 'https://127.0.0.1/audio.mp3',
        ]);
        $resp->assertStatus(422)->assertJsonValidationErrors(['audio_url']);
    }

    public function test_requires_voice_ability(): void
    {
        // Token without messaging:voice ability
        $this->actAs(['messaging:sms']);

        $resp = $this->postJson('/api/v1/messaging/voice', [
            'to'      => '+5521999998888',
            'content' => 'Hi',
        ]);

        $resp->assertStatus(403)
            ->assertJson(['error' => 'INSUFFICIENT_TOKEN_ABILITY', 'required' => 'messaging:voice']);
    }
}
