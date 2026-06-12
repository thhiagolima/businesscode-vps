<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Webhook idempotency: same request-id replayed twice must only be processed once.
 * The WebhookController stores request_id in `webhook_logs` and short-circuits replays.
 */
class MpWebhookMonthlyChargeIdempotentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Static webhook signature validation requires the secret; we'll always-pass via signature mock.
        config(['services.mercadopago.webhook_secret' => 'shared-secret']);
        config(['services.mercadopago.access_token'   => 'TEST-token']);
    }

    private function signature(string $dataId, string $requestId): array
    {
        $ts = time();
        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$ts};";
        $v1 = hash_hmac('sha256', $manifest, 'shared-secret');
        return [
            'x-signature' => "ts={$ts},v1={$v1}",
            'x-request-id' => $requestId,
        ];
    }

    public function test_replayed_webhook_does_not_double_process(): void
    {
        $plan = Plan::factory()->create();
        Tenant::factory()->create(['plan_id' => $plan->id]);

        // Mock MP REST for fetchPayment branch
        Http::fake([
            '*' => Http::response([
                'id'                 => 7777,
                'status'             => 'approved',
                'external_reference' => 'monthly:1:202606',
            ], 200),
        ]);

        $headers = $this->signature('7777', 'req-mp-1');
        $body = ['type' => 'payment', 'data' => ['id' => '7777']];

        $r1 = $this->withHeaders($headers)->postJson('/api/v1/webhooks/mercadopago', $body);
        $r1->assertStatus(200);

        // Second delivery with same request_id (same headers) → idempotent
        $r2 = $this->withHeaders($headers)->postJson('/api/v1/webhooks/mercadopago', $body);
        $r2->assertStatus(200);

        // webhook_logs table should have exactly one row for that request_id
        $logsCount = DB::table('webhook_logs')->where('request_id', 'req-mp-1')->count();
        $this->assertSame(1, $logsCount);
    }

    public function test_invalid_signature_returns_401(): void
    {
        $r = $this->withHeaders(['x-signature' => 'bogus', 'x-request-id' => 'bad'])
            ->postJson('/api/v1/webhooks/mercadopago', ['type' => 'payment', 'data' => ['id' => '1']]);

        $r->assertStatus(401);
    }

    public function test_two_distinct_request_ids_both_processed(): void
    {
        Http::fake(['*' => Http::response(['id' => 'x', 'status' => 'approved'], 200)]);

        $body = ['type' => 'payment', 'data' => ['id' => '5555']];

        $r1 = $this->withHeaders($this->signature('5555', 'req-A'))
            ->postJson('/api/v1/webhooks/mercadopago', $body);
        $r1->assertStatus(200);

        $r2 = $this->withHeaders($this->signature('5555', 'req-B'))
            ->postJson('/api/v1/webhooks/mercadopago', $body);
        $r2->assertStatus(200);

        $this->assertSame(1, DB::table('webhook_logs')->where('request_id', 'req-A')->count());
        $this->assertSame(1, DB::table('webhook_logs')->where('request_id', 'req-B')->count());
    }
}
