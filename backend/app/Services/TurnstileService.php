<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile server-side verification.
 *
 * Enabled by setting TURNSTILE_SECRET. When not enabled, isEnabled() returns
 * false and verify() is a no-op — callers skip the captcha check. This makes
 * local/dev environments work without extra setup while production can flip
 * on captcha without code changes.
 */
class TurnstileService
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function isEnabled(): bool
    {
        return !empty(config('services.turnstile.secret'));
    }

    public function verify(?string $token, ?string $remoteIp = null): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }

        if (empty($token)) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret'   => config('services.turnstile.secret'),
                'response' => $token,
                'remoteip' => $remoteIp,
            ]));

            if (!$response->successful()) {
                Log::warning('turnstile.verify_http_error', ['status' => $response->status()]);
                return false;
            }

            $body = $response->json();
            $ok = (bool) ($body['success'] ?? false);

            if (!$ok) {
                Log::warning('turnstile.verify_failed', [
                    'error_codes' => $body['error-codes'] ?? [],
                ]);
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::warning('turnstile.verify_exception', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
