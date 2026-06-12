<?php
namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicRoutesWhitelistTest extends TestCase
{
    public function test_only_whitelisted_routes_are_public(): void
    {
        $whitelist = [
            'POST api/v1/auth/login',
            'POST api/v1/auth/register',
            'POST api/v1/auth/forgot-password',
            'POST api/v1/auth/reset-password',
            'GET api/v1/auth/verify-email/{id}/{hash}',
            'GET api/v1/auth/config',
            'POST api/v1/webhooks/infobip/delivery',
            'POST api/v1/webhooks/infobip/email-events',
            'GET api/v1/webhooks/whatsapp',
            'POST api/v1/webhooks/whatsapp',
            'POST api/v1/webhooks/infobip/whatsapp',
            'POST api/v1/webhooks/infobip/inbound',
            'POST api/v1/checkout/validate-coupon',
            'GET api/v1/checkout/config',
            'GET api/v1/plans',
            'GET api/v1/public/identity',
            'GET api/v1/public/stats',
            'GET api/v1/public/pricing',
            'POST api/v1/webhooks/mercadopago',
            'GET api/v1/messaging/unsubscribe/{token}',
        ];

        $publicRoutes = collect(Route::getRoutes())
            ->filter(fn($r) => str_starts_with($r->uri(), 'api/v1/'))
            ->filter(fn($r) => ! in_array('auth:sanctum', $r->gatherMiddleware()))
            ->flatMap(function ($r) {
                return collect($r->methods())
                    ->reject(fn($m) => $m === 'HEAD')
                    ->map(fn($m) => "{$m} {$r->uri()}");
            })
            ->unique()
            ->values()
            ->toArray();

        foreach ($publicRoutes as $route) {
            $this->assertContains($route, $whitelist, "Route {$route} is public but not in whitelist");
        }
    }
}
