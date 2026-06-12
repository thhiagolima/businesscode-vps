<?php

namespace Tests\Feature\Messaging;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cobre o middleware LogVoiceApiRequests: cada chamada à rota de voz precisa
 * deixar um par request/response no canal `voice`, sem vazar PII (telefone).
 */
class VoiceApiLoggingTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('logs/voice-test-' . uniqid() . '.log');
        config(['logging.channels.voice' => ['driver' => 'single', 'path' => $this->logFile]]);

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
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        parent::tearDown();
    }

    private function actAs(array $abilities = ['messaging:voice']): User
    {
        $plan   = Plan::factory()->create();
        $tenant = Tenant::factory()->create(['plan_id' => $plan->id, 'balance_cents' => 8000]);
        $user   = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user, $abilities);
        return $user;
    }

    public function test_voice_request_writes_request_and_response_lines(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/messaging/voice', [
            'to'      => '+5521999998888',
            'content' => 'Olá, mensagem de voz.',
        ])->assertStatus(202);

        $log = file_get_contents($this->logFile);
        $this->assertStringContainsString('voice.api.request', $log);
        $this->assertStringContainsString('voice.api.response', $log);
        // Status da resposta registrado.
        $this->assertStringContainsString('"status":202', $log);
    }

    public function test_phone_is_masked_and_full_number_never_logged(): void
    {
        $this->actAs();

        $this->postJson('/api/v1/messaging/voice', [
            'to'      => '+5521999998888',
            'content' => 'Olá',
        ])->assertStatus(202);

        $log = file_get_contents($this->logFile);
        // Apenas os 4 últimos dígitos preservados.
        $this->assertStringContainsString('8888', $log);
        // O número completo NÃO pode aparecer no log (pentest: sem vazar PII).
        $this->assertStringNotContainsString('5521999998888', $log);
    }

    public function test_validation_failure_still_logs_request_and_status(): void
    {
        // A allowlist de audio_url falha no FormRequest. O Laravel renderiza a
        // ValidationException como resposta 422 ainda dentro do pipeline, então o
        // middleware captura o status numérico real — não como 'exception'.
        $this->actAs();

        $this->postJson('/api/v1/messaging/voice', [
            'to'        => '+5521999998888',
            'audio_url' => 'https://evil.com/audio.mp3',
        ])->assertStatus(422);

        $log = file_get_contents($this->logFile);
        $this->assertStringContainsString('voice.api.request', $log);
        $this->assertStringContainsString('"status":422', $log);
    }
}
