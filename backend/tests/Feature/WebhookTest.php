<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_invalid_signature()
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => '12345'],
        ], [
            'x-signature' => 'ts=123,v1=invalid',
            'x-request-id' => 'req-1',
        ]);

        $response->assertStatus(401);
    }

    public function test_webhook_returns_200_even_for_unknown_payment()
    {
        config(['services.mercadopago.webhook_secret' => 'test-secret']);

        $dataId = '99999';
        $ts = time();
        $manifest = "id:{$dataId};request-id:req-1;ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'test-secret');

        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => $dataId],
        ], [
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => 'req-1',
        ]);

        $response->assertOk();
    }
}
