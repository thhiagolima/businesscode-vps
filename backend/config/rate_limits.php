<?php

/**
 * Per-route RateLimiter buckets. Read inside RateLimiter::for() closures.
 *
 * IMPORTANT: keep these as `config('rate_limits.*')`, not `env()`. The closures
 * registered in AppServiceProvider run on each request; in production with
 * `config:cache` enabled, `env()` returns null inside closures because the
 * .env file is no longer read after boot. Centralizing here keeps the closures
 * config:cache-safe.
 */
return [
    'ai'       => (int) env('RATE_LIMIT_AI', 10),
    'audio'    => (int) env('RATE_LIMIT_AUDIO', 5),
    'campaign' => (int) env('RATE_LIMIT_CAMPAIGN_DISPATCH', 20),
];
