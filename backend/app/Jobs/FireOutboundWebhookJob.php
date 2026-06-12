<?php
namespace App\Jobs;

use App\Models\OutboundWebhook;
use App\Models\WebhookDelivery;
use App\Services\Security\OutboundWebhookGuard;
use App\Services\Security\OutboundWebhookGuardException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FireOutboundWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 10;

    /**
     * Spaced retries (seconds) so a transient InnoDB deadlock during a bulk-send
     * storm doesn't burn all attempts in the same locked instant and silently
     * drop the event. Without this, $tries=3 retries fire back-to-back and tend
     * to re-deadlock immediately.
     */
    public array $backoff = [5, 15, 30];

    public function __construct(
        public int $tenantId,
        public string $event,
        public array $payload
    ) {}

    public function handle(): void
    {
        $active = OutboundWebhook::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->get();

        $webhooks = $active->filter(fn ($wh) => in_array($this->event, $wh->events ?? []));

        if ($webhooks->isEmpty()) {
            // High-signal, bounded log: the tenant HAS active webhooks but none
            // subscribed to THIS event — almost always a misconfiguration (the
            // exact symptom that silently hid the missing call.* events). The
            // no-webhook-at-all case is the normal majority, so keep it at debug.
            if ($active->isNotEmpty()) {
                Log::channel('whatsapp')->notice('outbound_webhook.event_no_subscriber', [
                    'tenant_id'    => $this->tenantId,
                    'event'        => $this->event,
                    'active_hooks' => $active->count(),
                ]);
            } else {
                Log::channel('whatsapp')->debug('outbound_webhook.no_active_webhook', [
                    'tenant_id' => $this->tenantId,
                    'event'     => $this->event,
                ]);
            }
            return;
        }

        foreach ($webhooks as $webhook) {
            $body = [
                'event'     => $this->event,
                'timestamp' => now()->toIso8601String(),
                'data'      => $this->payload,
            ];

            $headers = ['Content-Type' => 'application/json'];
            if ($webhook->secret) {
                $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($body), $webhook->secret);
            }

            $start          = microtime(true);
            $status         = null;
            $responseBody   = null;
            $errorMessage   = null;

            try {
                // SSRF defense-in-depth: re-validate the host AND pin the resolved IP
                // into curl to close the DNS rebinding TOCTOU window between our
                // validate() and curl's own DNS lookup (P0R-05).
                // Redirects are allowed (Slack/Discord/GitHub use 301/302) but EACH hop
                // re-validates through the guard so an attacker can't bounce via 302 to
                // internal IPs (IMDS, RFC1918, etc.).
                $guard = app(OutboundWebhookGuard::class);
                $pin   = $guard->pinnedResolution($webhook->url);

                $response = Http::withHeaders($headers)
                    ->timeout(5)
                    ->withOptions([
                        'curl' => [
                            CURLOPT_RESOLVE => ["{$pin['host']}:{$pin['port']}:{$pin['ip']}"],
                        ],
                        'allow_redirects' => [
                            'max'         => 5,
                            'strict'      => true,
                            'referer'     => false,
                            'protocols'   => ['http', 'https'],
                            'on_redirect' => function ($request, $response, $uri) use ($guard) {
                                // Throws OutboundWebhookGuardException on internal IPs;
                                // Guzzle aborts the chain and caller catches.
                                $guard->assertSafeUrl((string) $uri);
                            },
                        ],
                    ])
                    ->post($webhook->url, $body);

                $status       = $response->status();
                $responseBody = mb_substr((string) $response->body(), 0, 2000);

                Log::channel('whatsapp')->info('outbound_webhook.sent', [
                    'webhook_id' => $webhook->id,
                    'event'      => $this->event,
                    'status'     => $status,
                ]);
            } catch (OutboundWebhookGuardException $e) {
                $errorMessage = 'SSRF guard: ' . mb_substr($e->getMessage(), 0, 480);

                Log::channel('whatsapp')->warning('outbound_webhook.blocked_ssrf', [
                    'webhook_id' => $webhook->id,
                    'error'      => $errorMessage,
                ]);
            } catch (\Throwable $e) {
                $errorMessage = mb_substr($e->getMessage(), 0, 500);

                Log::channel('whatsapp')->warning('outbound_webhook.failed', [
                    'webhook_id' => $webhook->id,
                    'error'      => $errorMessage,
                ]);
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            try {
                WebhookDelivery::create([
                    'outbound_webhook_id' => $webhook->id,
                    'tenant_id'           => $webhook->tenant_id,
                    'event'               => $this->event,
                    'request_body'        => $body,
                    'response_status'     => $status,
                    'response_body'       => $responseBody,
                    'duration_ms'         => $durationMs,
                    'attempt_number'      => (int) ($this->attempts() ?: 1),
                    'error_message'       => $errorMessage,
                    'fired_at'            => now(),
                ]);
            } catch (\Throwable $logE) {
                Log::channel('whatsapp')->warning('outbound_webhook.delivery_log_failed', [
                    'webhook_id' => $webhook->id,
                    'error'      => $logE->getMessage(),
                ]);
            }
        }
    }
}
