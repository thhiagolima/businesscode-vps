<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Diagnóstico do erro 503 no envio de voz.
 *
 * Registra UM par de linhas (request → response) por chamada às rotas de voz,
 * no canal `voice` (storage/logs/voice.log). O objetivo é capturar a "impressão
 * digital" do 503: o backend roda em `php artisan serve` (single-thread), e a
 * geração de áudio faz uma chamada SÍNCRONA de até 60s à ElevenLabs. Uma
 * requisição lenta que entra (`voice.api.request`) e nunca registra o
 * `voice.api.response` correspondente — ou registra com `duration_ms` perto de
 * 60000 — é o request que travou/derrubou o processo :8000 e gerou o 503 nas
 * chamadas seguintes (essas o app NÃO loga; ficam só no error log do Apache).
 *
 * Segurança: não loga corpo da mensagem, token de auth nem query string de URLs
 * assinadas. O telefone é mascarado.
 */
class LogVoiceApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $startedAt = microtime(true);

        Log::channel('voice')->info('voice.api.request', $this->requestContext($request, $requestId));

        $status = null;
        try {
            /** @var Response $response */
            $response = $next($request);
            $status = $response->getStatusCode();
            return $response;
        } catch (\Throwable $e) {
            // Loga o desfecho mesmo quando a controller/serviço explode antes de
            // produzir uma resposta, e repassa a exceção intacta.
            Log::channel('voice')->error('voice.api.response', [
                'request_id'  => $requestId,
                'status'      => 'exception',
                'duration_ms' => $this->elapsedMs($startedAt),
                'exception'   => class_basename($e),
                'error'       => mb_substr($e->getMessage(), 0, 300),
            ]);
            throw $e;
        } finally {
            if ($status !== null) {
                $durationMs = $this->elapsedMs($startedAt);
                // Request lento (>10s) é suspeito do bloqueio do single-thread.
                $level = ($status >= 500 || $durationMs >= 10000) ? 'warning' : 'info';
                Log::channel('voice')->log($level, 'voice.api.response', [
                    'request_id'  => $requestId,
                    'status'      => $status,
                    'duration_ms' => $durationMs,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(Request $request, string $requestId): array
    {
        $user = $request->user();

        return [
            'request_id'    => $requestId,
            'method'        => $request->method(),
            'path'          => $request->path(),
            'route'         => optional($request->route())->getName() ?? '(unnamed)',
            'tenant_id'     => $user?->tenant_id,
            'user_id'       => $user?->id,
            'ip'            => $request->ip(),
            'to'            => $this->maskPhone((string) $request->input('to', '')),
            'has_audio_url' => $request->filled('audio_url'),
            'audio_host'    => $this->urlHostPath((string) $request->input('audio_url', '')),
            'content_len'   => mb_strlen((string) $request->input('content', $request->input('script', ''))),
            'idem_key'      => $request->header('Idempotency-Key'),
            'user_agent'    => mb_substr((string) $request->userAgent(), 0, 160),
        ];
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Mascara o telefone, preservando só os 4 últimos dígitos para correlação.
     */
    private function maskPhone(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    /**
     * Host + path da audio_url, SEM query string (evita vazar URL assinada).
     */
    private function urlHostPath(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        return $parts['host'] . ($parts['path'] ?? '');
    }
}
