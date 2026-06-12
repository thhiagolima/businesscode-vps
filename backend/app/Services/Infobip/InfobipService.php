<?php

namespace App\Services\Infobip;

use App\Exceptions\InfobipNotConfiguredException;
use App\Services\SettingsService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InfobipService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Public callback URL that Infobip POSTs status to. The secret rides in the
     * path because Infobip's per-message notifyUrl cannot send auth headers.
     * Returns null when not configured so sends never break — they just won't
     * register a callback.
     *
     * @param string $segment 'delivery' (SMS/voice) or 'email-events' (email)
     */
    private function buildNotifyUrl(string $segment): ?string
    {
        $secret = (string) $this->settings->getGlobal('infobip', 'webhook_secret', '');
        if ($secret === '') {
            return null;
        }

        $base = (string) $this->settings->getGlobal('infobip', 'webhook_base_url', '');
        if ($base === '') {
            $base = (string) config('app.url', '');
        }
        $base = rtrim($base, '/');
        if ($base === '') {
            return null;
        }

        return "{$base}/api/v1/webhooks/infobip/{$segment}/" . rawurlencode($secret);
    }

    public function makeClient(?string $apiKey = null, ?string $baseUrl = null): PendingRequest
    {
        $apiKey  = $apiKey === '__USE_SAVED__' || $apiKey === null || $apiKey === '' ? $this->settings->getGlobal('infobip', 'api_key', '') : $apiKey;
        $baseUrl = $baseUrl === null || $baseUrl === '' ? $this->settings->getGlobal('infobip', 'base_url', 'api.infobip.com') : $baseUrl;

        if (empty($apiKey) || empty($baseUrl)) {
            throw new InfobipNotConfiguredException();
        }

        $url = rtrim($baseUrl, '/');
        if (!str_starts_with($url, 'http')) {
            $url = "https://{$url}";
        }

        return Http::withHeaders([
                'Authorization' => 'App '.$apiKey,
                'Accept'        => 'application/json',
            ])
            ->baseUrl($url)
            ->timeout(15)
            ->retry(2, 300);
    }

    public function testConnection(?string $apiKey = null, ?string $baseUrl = null): array
    {
        try {
            $client = $this->makeClient($apiKey, $baseUrl);
            Log::channel('infobip')->info('test.start');

            // Usar endpoint de SMS delivery reports como health check (sempre disponível)
            $resp = $client->get('/sms/1/reports', ['limit' => 1]);

            if ($resp->successful()) {
                Log::channel('infobip')->info('test.ok');
                return ['ok' => true, 'status' => $resp->status()];
            }

            // 401 = chave inválida, outros = endpoint pode não estar habilitado
            if ($resp->status() === 401) {
                Log::channel('infobip')->warning('test.unauthorized');
                return ['ok' => false, 'error' => 'API Key inválida (401 Unauthorized)'];
            }

            // Tentar endpoint alternativo
            $resp2 = $client->get('/sms/2/text/advanced');
            if ($resp2->status() !== 401) {
                Log::channel('infobip')->info('test.ok_alt');
                return ['ok' => true, 'status' => 200];
            }

            Log::channel('infobip')->warning('test.fail', ['status' => $resp->status()]);
            return ['ok' => false, 'error' => 'HTTP ' . $resp->status() . ': ' . $resp->body()];
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('test.exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Envia SMS para um número via Infobip (API v3).
     *
     * Migrado de /sms/2/text/advanced para /sms/3/messages em 2026-05-26.
     * Mudanças no schema: `from` → `sender`, `text` (flat) → `content.text`.
     *
     * @return array{ok: bool, message_id: string|null, error: string|null}
     */
    public function sendSms(string $to, string $text, string $from = 'InfoSMS'): array
    {
        $this->checkCircuitBreaker('infobip');
        try {
            $client = $this->makeClient();

            $message = [
                'sender'       => $from,
                'destinations' => [['to' => $to]],
                'content'      => [
                    'text' => $text,
                ],
            ];
            if ($notifyUrl = $this->buildNotifyUrl('delivery')) {
                $message['webhooks'] = ['delivery' => ['url' => $notifyUrl]];
            }

            $resp = $client->post('/sms/3/messages', ['messages' => [$message]]);

            if ($resp->successful()) {
                $messageId = $resp->json('messages.0.messageId');
                Log::channel('infobip')->info('sms.sent', ['to' => $to, 'message_id' => $messageId]);
                $this->recordSuccess('infobip');
                return ['ok' => true, 'message_id' => $messageId, 'error' => null];
            }

            // 5xx → transient (retry)
            if ($resp->serverError()) {
                Log::channel('infobip')->warning('sms.5xx_transient', ['to' => $to, 'status' => $resp->status()]);
                $this->recordFailure('infobip');
                throw new \App\Exceptions\Messaging\TransientProviderException(
                    "Provider 5xx: HTTP {$resp->status()}"
                );
            }

            // 4xx → definitive
            $error = $resp->json('requestError.serviceException.text') ?? $resp->body();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
            Log::channel('infobip')->warning('sms.failed', ['to' => $to, 'status' => $resp->status(), 'error' => $error]);
            $this->recordFailure('infobip');
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\App\Exceptions\Messaging\TransientProviderException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\RequestException $e) {
            // HTTP error raised by retry() throw=true. 5xx => transient; 4xx => definitive.
            $status = $e->response?->status() ?? 0;
            if ($status >= 500) {
                Log::channel('infobip')->warning('sms.5xx_transient', ['to' => $to, 'status' => $status]);
                $this->recordFailure('infobip');
                throw new \App\Exceptions\Messaging\TransientProviderException(
                    "Provider 5xx: HTTP {$status}", 0, $e
                );
            }
            $error = $e->response?->json('requestError.serviceException.text') ?? $e->response?->body() ?? $e->getMessage();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
            Log::channel('infobip')->warning('sms.failed', ['to' => $to, 'status' => $status, 'error' => $error]);
            $this->recordFailure('infobip');
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('sms.exception', ['to' => $to, 'error' => $e->getMessage()]);
            $this->recordFailure('infobip');
            throw new \App\Exceptions\Messaging\TransientProviderException(
                $e->getMessage(), 0, $e
            );
        }
    }

    /**
     * Envia Email via Infobip.
     *
     * @return array{ok: bool, message_id: string|null, error: string|null}
     */
    public function sendEmail(
        string $to,
        string $subject,
        string $body,
        string $from,
        string $fromName = '',
        ?string $replyTo = null
    ): array {
        $this->checkCircuitBreaker('infobip');
        try {
            $client = $this->makeClient();
            $fromHeader = $fromName ? "{$fromName} <{$from}>" : $from;

            // Send as HTML (multipart field `html`) so formatting renders in the inbox.
            // Always include a plain-text alternative derived from the HTML so
            // plain-text-only clients still get readable content (multipart/alternative).
            $textFallback = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $parts = [
                ['name' => 'from',    'contents' => $fromHeader],
                ['name' => 'to',      'contents' => $to],
                ['name' => 'subject', 'contents' => $subject],
                ['name' => 'html',    'contents' => $body],
                ['name' => 'text',    'contents' => $textFallback !== '' ? $textFallback : ' '],
            ];

            // Reply-To header: Infobip /email/3/send accepts "replyTo" multipart field.
            // Only include when set; absent header makes replies go to From.
            if ($replyTo !== null && $replyTo !== '') {
                $parts[] = ['name' => 'replyTo', 'contents' => $replyTo];
            }

            // Delivery + tracking callbacks (status, opens, clicks, bounces).
            if ($notifyUrl = $this->buildNotifyUrl('email-events')) {
                $parts[] = ['name' => 'notifyUrl', 'contents' => $notifyUrl];
            }

            $resp = $client->asMultipart()->post('/email/3/send', $parts);

            if ($resp->successful()) {
                $messageId = $resp->json('messages.0.messageId');
                Log::channel('infobip')->info('email.sent', ['to' => $to, 'message_id' => $messageId]);
                $this->recordSuccess('infobip');
                return ['ok' => true, 'message_id' => $messageId, 'error' => null];
            }

            if ($resp->serverError()) {
                Log::channel('infobip')->warning('email.5xx_transient', ['to' => $to, 'status' => $resp->status()]);
                $this->recordFailure('infobip');
                throw new \App\Exceptions\Messaging\TransientProviderException(
                    "Provider 5xx: HTTP {$resp->status()}"
                );
            }

            $error = $resp->json('requestError.serviceException.text') ?? $resp->body();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
            Log::channel('infobip')->warning('email.failed', ['to' => $to, 'status' => $resp->status(), 'error' => $error]);
            $this->recordFailure('infobip');
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\App\Exceptions\Messaging\TransientProviderException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $status = $e->response?->status() ?? 0;
            if ($status >= 500) {
                Log::channel('infobip')->warning('email.5xx_transient', ['to' => $to, 'status' => $status]);
                $this->recordFailure('infobip');
                throw new \App\Exceptions\Messaging\TransientProviderException(
                    "Provider 5xx: HTTP {$status}", 0, $e
                );
            }
            $error = $e->response?->json('requestError.serviceException.text') ?? $e->response?->body() ?? $e->getMessage();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
            Log::channel('infobip')->warning('email.failed', ['to' => $to, 'status' => $status, 'error' => $error]);
            $this->recordFailure('infobip');
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('email.exception', ['to' => $to, 'error' => $e->getMessage()]);
            $this->recordFailure('infobip');
            throw new \App\Exceptions\Messaging\TransientProviderException(
                $e->getMessage(), 0, $e
            );
        }
    }

    /**
     * Envia mensagem de voz (TTS ou arquivo de áudio) via Infobip.
     *
     * @return array{ok: bool, message_id: string|null, error: string|null}
     */
    public function sendVoice(string $to, string $text, string $from = 'InfoVoice', ?string $audioUrl = null): array
    {
        $this->checkCircuitBreaker('infobip');
        try {
            $client = $this->makeClient();

            $payload = [
                'from' => $from,
                'to'   => $to,
            ];

            if ($notifyUrl = $this->buildNotifyUrl('delivery')) {
                $payload['notifyUrl'] = $notifyUrl;
            }

            if ($audioUrl) {
                // Audio file mode: Infobip simply plays the file. Do NOT include
                // `language`/`voice` — TTS-only fields. Sending them causes
                // REJECTED_ONLY_NEURAL_VOICES_AVAILABLE_FOR_LANGUAGE for languages
                // (e.g. pt) where Infobip removed non-neural voices and requires
                // an explicit voice.name selection.
                $payload['audioFileUrl'] = $audioUrl;
            } else {
                // TTS mode: include language + voice. For neural-only languages
                // (pt, pt-BR, ...) Infobip requires an explicit voice.name.
                $payload['text']     = $text;
                $payload['language'] = $this->settings->getGlobal('infobip', 'voice_language', 'pt');

                $voiceName   = (string) $this->settings->getGlobal('infobip', 'voice_name', 'Camila');
                $voiceGender = (string) $this->settings->getGlobal('infobip', 'voice_gender', 'female');
                if ($voiceName !== '') {
                    $payload['voice'] = ['name' => $voiceName, 'gender' => $voiceGender];
                }
            }

            $resp = $client->post('/tts/3/single', $payload);

            if ($resp->successful()) {
                // Infobip wraps the id inside a "messages" array (same envelope as
                // /sms/3/messages and /email/3/send). Reading the top-level
                // "messageId" yields null, which breaks delivery-report matching
                // and silently kills every voice status webhook (call.answered/
                // completed/failed + message.delivered). Fall back to the legacy
                // top-level key just in case a future API version flattens it.
                $messageId = $resp->json('messages.0.messageId') ?? $resp->json('messageId');
                Log::channel('infobip')->info('voice.sent', ['to' => $to, 'message_id' => $messageId]);
                $this->recordSuccess('infobip');
                return ['ok' => true, 'message_id' => $messageId, 'error' => null];
            }

            if ($resp->serverError()) {
                Log::channel('infobip')->warning('voice.5xx_transient', ['to' => $to, 'status' => $resp->status()]);
                $this->recordFailure('infobip');
                throw new \App\Exceptions\Messaging\TransientProviderException(
                    "Provider 5xx: HTTP {$resp->status()}"
                );
            }

            $error = $resp->json('requestError.serviceException.text') ?? $resp->body();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
            Log::channel('infobip')->warning('voice.failed', ['to' => $to, 'status' => $resp->status(), 'error' => $error]);
            $this->recordFailure('infobip');
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\App\Exceptions\Messaging\TransientProviderException $e) {
            throw $e;
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $status = $e->response?->status() ?? 0;
            if ($status >= 500) {
                Log::channel('infobip')->warning('voice.5xx_transient', ['to' => $to, 'status' => $status]);
                $this->recordFailure('infobip');
                throw new \App\Exceptions\Messaging\TransientProviderException(
                    "Provider 5xx: HTTP {$status}", 0, $e
                );
            }
            $error = $e->response?->json('requestError.serviceException.text') ?? $e->response?->body() ?? $e->getMessage();
            $error = is_string($error) ? mb_substr($error, 0, 500) : json_encode($error);
            Log::channel('infobip')->warning('voice.failed', ['to' => $to, 'status' => $status, 'error' => $error]);
            $this->recordFailure('infobip');
            return ['ok' => false, 'message_id' => null, 'error' => $error];
        } catch (\Throwable $e) {
            Log::channel('infobip')->error('voice.exception', ['to' => $to, 'error' => $e->getMessage()]);
            $this->recordFailure('infobip');
            throw new \App\Exceptions\Messaging\TransientProviderException(
                $e->getMessage(), 0, $e
            );
        }
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
            // Cooldown expirado — resetar
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
        Cache::put($key, $failures, 600); // TTL 10 min
    }

    private function recordSuccess(string $service): void
    {
        Cache::forget("circuit_breaker_{$service}");
    }
}

