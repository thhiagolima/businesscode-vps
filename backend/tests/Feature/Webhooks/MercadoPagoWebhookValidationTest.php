<?php

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MercadoPagoWebhookValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Provide a secret so signature verification is exercised (not silently rejected for missing config).
        config()->set('services.mercadopago.webhook_secret', 'unit-test-secret');
    }

    private function signedHeaders(string $dataId, string $requestId = 'req-1'): array
    {
        $ts = (string) time();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'unit-test-secret');
        return [
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ];
    }

    public function test_rejects_empty_type(): void
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => '',
            'data' => ['id' => 'abc'],
        ], $this->signedHeaders('abc'));

        $response->assertStatus(400);
    }

    public function test_rejects_unknown_type(): void
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'random_type_we_dont_know',
            'data' => ['id' => 'abc'],
        ], $this->signedHeaders('abc'));

        $response->assertStatus(400);
    }

    public function test_rejects_empty_data_id(): void
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => ''],
        ], $this->signedHeaders(''));

        $response->assertStatus(400);
    }

    public function test_rejects_missing_signature_headers(): void
    {
        $response = $this->postJson('/api/v1/webhooks/mercadopago', [
            'type' => 'payment',
            'data' => ['id' => 'abc'],
        ]); // no signature headers

        $response->assertStatus(401);
    }
}
