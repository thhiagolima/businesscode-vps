<?php

namespace App\Services\ElevenLabs;

use App\Exceptions\ElevenLabsNotConfiguredException;
use App\Models\AudioGeneration;
use App\Models\ElevenLabsVoice;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ElevenLabsService
{
    public function __construct(private SettingsService $settings) {}

    public function resolveApiKey(?string $apiKey = null): string
    {
        $key = $apiKey === '__USE_SAVED__' || $apiKey === null || $apiKey === '' ? $this->settings->getGlobal('elevenlabs', 'api_key', '') : $apiKey;
        if (empty($key)) throw new ElevenLabsNotConfiguredException();
        return $key;
    }

    public function testConnection(?string $apiKey = null): array
    {
        try {
            $key = $this->resolveApiKey($apiKey);
            Log::channel('elevenlabs')->info('test.start');
            $resp = Http::withHeaders([
                    'xi-api-key' => $key,
                    'Accept'     => 'application/json',
                ])
                ->timeout(10)
                ->retry(2, 200)
                ->get('https://api.elevenlabs.io/v1/voices');
            if ($resp->successful()) {
                Log::channel('elevenlabs')->info('test.ok', ['status' => $resp->status()]);
                return ['ok' => true, 'status' => $resp->status()];
            }
            Log::channel('elevenlabs')->warning('test.fail', ['status' => $resp->status(), 'body' => $resp->body()]);
            return ['ok' => false, 'status' => $resp->status(), 'body' => $resp->json()];
        } catch (\Throwable $e) {
            Log::channel('elevenlabs')->error('test.exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function fetchVoices(?string $apiKey = null): array
    {
        $apiKey = $this->resolveApiKey($apiKey);
        $url = 'https://api.elevenlabs.io/v1/voices';

        try {
            Log::channel('elevenlabs')->info('voices.list.start', ['url' => $url]);

            $resp = Http::withHeaders([
                'xi-api-key' => $apiKey,
                'Accept'     => 'application/json',
            ])->timeout(10)->retry(2, 200)->get($url);

            if (!$resp->successful()) {
                Log::channel('elevenlabs')->error('voices.list.error', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
                ]);
                throw new \RuntimeException('Falha ao listar vozes ElevenLabs');
            }

            $json = $resp->json();
            $voices = $json['voices'] ?? [];

            Log::channel('elevenlabs')->info('voices.list.ok', ['count' => count($voices)]);
            return $voices;
        } catch (\Throwable $e) {
            Log::channel('elevenlabs')->error('voices.list.exception', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function upsertVoices(array $voices): int
    {
        $count = 0;
        foreach ($voices as $v) {
            ElevenLabsVoice::updateOrCreate(
                ['voice_id' => $v['voice_id'] ?? $v['id'] ?? null],
                [
                    'name'        => $v['name'] ?? '',
                    'category'    => $v['category'] ?? null,
                    'gender'      => $v['labels']['gender'] ?? null,
                    'accent'      => $v['labels']['accent'] ?? null,
                    'language'    => $v['labels']['language'] ?? null,
                    'preview_url' => $v['preview_url'] ?? null,
                    'labels'      => $v['labels'] ?? null,
                    'is_active'   => true,
                ]
            );
            $count++;
        }
        Log::channel('elevenlabs')->info('voices.upsert.ok', ['count' => $count]);
        return $count;
    }

    public function syncVoices(?string $apiKey = null): int
    {
        $voices = $this->fetchVoices($apiKey);
        $ids = [];
        foreach ($voices as $v) {
            $ids[] = $v['voice_id'] ?? $v['id'] ?? null;
        }
        $count = $this->upsertVoices($voices);
        // Deactivate voices that are no longer present
        if (!empty($ids)) {
            ElevenLabsVoice::whereNotIn('voice_id', array_filter($ids))->update(['is_active' => false]);
        }
        // Update last sync timestamp
        try {
            $this->settings->upsertGlobal('elevenlabs', 'last_sync_at', now()->toDateTimeString(), 'string');
        } catch (\Throwable $e) {
            Log::channel('elevenlabs')->warning('voices.sync.last_sync_at.fail', ['error' => $e->getMessage()]);
        }
        Log::channel('elevenlabs')->info('voices.sync.completed', ['count' => $count]);
        return $count;
    }

    // ─── Geração de áudio TTS ───────────────────────────────────────

    public function generateAudio(
        string $script,
        string $voiceId,
        int    $campaignId,
        int    $tenantId
    ): AudioGeneration {
        $apiKey  = $this->resolveApiKey();
        $modelId = $this->settings->getGlobal('elevenlabs', 'model_id', 'eleven_multilingual_v2');

        $voice = ElevenLabsVoice::where('voice_id', $voiceId)->firstOrFail();
        $chars = mb_strlen($script);

        // Calcular custo e preço antes de gerar
        $costPerChar  = (float) $this->settings->getGlobal('elevenlabs', 'cost_per_char', '0.0003');
        $salePerChar  = (float) $this->settings->getGlobal('elevenlabs', 'sale_per_char', '0.001');
        $credPerChar  = (int)   $this->settings->getGlobal('elevenlabs', 'credits_per_char', '1');

        $costPrice    = round($chars * $costPerChar, 4);
        $salePrice    = round($chars * $salePerChar, 4);
        $credits      = $chars * $credPerChar;

        // Verificar saldo antes de gerar (cents)
        $tenantForCheck = Tenant::withoutGlobalScopes()->find($tenantId);
        $available = $tenantForCheck ? $tenantForCheck->availableBalanceCents() : 0;
        if ($available < $credits) {
            throw new \RuntimeException(
                "Saldo insuficiente para gerar áudio. Necessário: {$credits} cents."
            );
        }

        // Verificar circuit breaker antes de criar o registro e chamar a API
        $this->checkCircuitBreaker('elevenlabs');

        // Criar registro pending
        $generation = AudioGeneration::create([
            'tenant_id'        => $tenantId,
            'campaign_id'      => $campaignId,
            'voice_id'         => $voiceId,
            'voice_name'       => $voice->name,
            'script'           => $script,
            'characters_used'  => $chars,
            'cost_price'       => $costPrice,
            'sale_price'       => $salePrice,
            'credits_charged'  => $credits,
            'status'           => 'processing',
        ]);

        try {
            // Chamar API ElevenLabs
            $response = Http::withHeaders([
                'xi-api-key'   => $apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'audio/mpeg',
            ])
            ->timeout(60)
            ->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}", [
                'text'     => $script,
                'model_id' => $modelId,
                'voice_settings' => [
                    'stability'        => 0.5,
                    'similarity_boost' => 0.75,
                ],
            ]);

            if (!$response->successful()) {
                $this->recordFailure('elevenlabs');
                throw new \RuntimeException(
                    "ElevenLabs error {$response->status()}: " . $response->body()
                );
            }
            $this->recordSuccess('elevenlabs');

            // Salvar áudio no storage
            $filename = "audio/{$tenantId}/{$generation->id}.mp3";
            Storage::put($filename, $response->body());

            // Gerar URL pública temporária (24h) - fallback para url pública se não suportado
            $audioUrl = null;
            try {
                $audioUrl = Storage::temporaryUrl($filename, now()->addHours(24));
            } catch (\Throwable $e) {
                $audioUrl = Storage::url($filename);
            }

            // Estimar duração (aprox: 150 palavras/min)
            $wordCount = str_word_count($script);
            $duration  = (int) ceil(($wordCount / 150) * 60);

            $generation->update([
                'audio_path'       => $filename,
                'audio_url'        => $audioUrl,
                'duration_seconds' => $duration,
                'status'           => 'completed',
            ]);

            // Debitar saldo (cents) — reserve decrements directly
            app(BillingService::class)->reserve(
                $tenantId,
                $credits,
                'audio_generation',
                $generation->id
            );

            Log::channel('elevenlabs')->info('audio.generated', [
                'generation_id' => $generation->id,
                'chars'         => $chars,
                'duration'      => $duration,
                'cost_usd'      => $costPrice,
            ]);

            return $generation;

        } catch (\Throwable $e) {
            $this->recordFailure('elevenlabs');
            $generation->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::channel('elevenlabs')->error('audio.failed', [
                'generation_id' => $generation->id,
                'error'         => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ─── Listar vozes disponíveis ───────────────────────────────────

    public function getAvailableVoices(?string $language = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ElevenLabsVoice::where('is_active', true)
            ->orderBy('language')
            ->orderBy('name');

        if ($language) {
            $query->where('language', $language);
        }

        return $query->get();
    }

    // ─── Circuit Breaker ───────────────────────────────────────────

    private function checkCircuitBreaker(string $service): void
    {
        $key      = "circuit_breaker_{$service}";
        $failures = (int) Cache::get($key, 0);
        if ($failures >= 5) {
            $until = Cache::get("{$key}_until");
            if ($until && now()->lt($until)) {
                throw new \RuntimeException(
                    "Serviço {$service} temporariamente indisponível (circuit breaker aberto). Tente novamente em alguns minutos."
                );
            }
            Cache::forget($key);
        }
    }

    private function recordFailure(string $service): void
    {
        $key      = "circuit_breaker_{$service}";
        $failures = (int) Cache::increment($key);
        if ($failures >= 5) {
            Cache::put("{$key}_until", now()->addMinutes(2), 300);
        }
        Cache::put($key, $failures, 600);
    }

    private function recordSuccess(string $service): void
    {
        Cache::forget("circuit_breaker_{$service}");
    }

    // ─── Renovar URL do áudio (expira em 24h) ───────────────────────

    public function refreshAudioUrl(AudioGeneration $generation): string
    {
        if (!$generation->audio_path) {
            throw new \RuntimeException('Arquivo de áudio não encontrado.');
        }

        try {
            $url = Storage::temporaryUrl(
                $generation->audio_path,
                now()->addHours(24)
            );
        } catch (\Throwable $e) {
            $url = Storage::url($generation->audio_path);
        }

        $generation->update(['audio_url' => $url]);
        return $url;
    }
}
