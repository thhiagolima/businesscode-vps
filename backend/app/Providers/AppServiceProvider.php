<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public const SERVER_TO_SERVER_ABILITY = '__server-to-server';

    public function register(): void
    {
        $this->app->bind(
            \Laravel\Sanctum\Console\Commands\PruneExpired::class,
            \App\Console\Commands\PruneExpiredTokens::class,
        );
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureSanctumLongLivedTokens();
    }

    private function configureSanctumLongLivedTokens(): void
    {
        Sanctum::authenticateAccessTokensUsing(function ($accessToken, bool $isValid): bool {
            if ($isValid || ! $accessToken) {
                return $isValid;
            }
            $abilities = is_array($accessToken->abilities) ? $accessToken->abilities : [];
            if (! in_array(self::SERVER_TO_SERVER_ABILITY, $abilities, true)) {
                return false;
            }
            return ! $accessToken->expires_at || ! $accessToken->expires_at->isPast();
        });
    }

    private function configureRateLimiting(): void
    {
        // Geração de conteúdo IA — 10 req/min por usuário
        RateLimiter::for('ai', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.ai'))
                ->by($request->user()?->id ?? $request->ip());
        });

        // Geração de áudio TTS — 5 req/min por usuário
        RateLimiter::for('audio', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.audio'))
                ->by($request->user()?->id ?? $request->ip());
        });

        // Disparo de campanhas — 20 req/min por tenant
        RateLimiter::for('campaign-dispatch', function (Request $request) {
            return Limit::perMinute((int) config('rate_limits.campaign'))
                ->by($request->user()?->tenant_id ?? $request->ip());
        });

        // Webhook WhatsApp — 200 req/min por IP
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(200)->by($request->ip());
        });

        // Messaging per-channel (sms/voice/email) — plan-aware, tenant-scoped
        foreach (['sms', 'voice', 'email'] as $ch) {
            RateLimiter::for("messaging-{$ch}", function (Request $request) use ($ch) {
                $tenantId = $request->user()?->tenant_id;
                if (! $tenantId) {
                    return Limit::perMinute(5)->by($request->ip());
                }
                $plan = $request->user()->tenant->plan ?? null;
                $field = "rate_limit_{$ch}_per_min";
                $limit = $plan?->{$field} ?? (int) config("messaging.rate_limits.{$ch}");
                return Limit::perMinute($limit)
                    ->by("tenant:{$tenantId}:msg:{$ch}")
                    ->response(fn() => response()->json([
                        'error'   => 'RATE_LIMIT_EXCEEDED',
                        'channel' => $ch,
                    ], 429));
            });
        }
    }
}
