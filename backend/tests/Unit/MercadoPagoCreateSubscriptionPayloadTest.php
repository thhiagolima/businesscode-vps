<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoPagoCreateSubscriptionPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_subscription_does_not_force_status_authorized(): void
    {
        // The MP PreApprovalClient is final and strict-typed, so we cannot easily mock it.
        // Instead, validate statically that the service does NOT pass `'status' => 'authorized'`
        // as a literal in the preApproval payload — let Mercado Pago decide the real status
        // based on card authentication. Otherwise, a Subscription can be marked 'active'
        // locally while MP still has it as 'pending' (fraud / unconsented charge risk).
        $src = file_get_contents(__DIR__ . '/../../app/Services/MercadoPagoService.php');
        $this->assertIsString($src);
        $this->assertStringNotContainsString(
            "'status' => 'authorized'",
            $src,
            "MercadoPagoService must not force status='authorized' in preApproval payload (P0-24)."
        );
    }
}
