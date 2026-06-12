<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOutboundWebhookRequest;
use App\Http\Requests\UpdateOutboundWebhookRequest;
use App\Models\OutboundWebhook;
use App\Models\WebhookDelivery;
use App\Services\Security\OutboundWebhookGuard;
use App\Services\Security\OutboundWebhookGuardException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhooksController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $webhooks = OutboundWebhook::where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get();

        $ids = $webhooks->pluck('id')->all();

        $last = WebhookDelivery::whereIn('outbound_webhook_id', $ids)
            ->orderByDesc('fired_at')
            ->get()
            ->groupBy('outbound_webhook_id')
            ->map(fn ($rows) => $rows->first());

        $data = $webhooks->map(function ($w) use ($last) {
            $lastDelivery = $last->get($w->id);
            return [
                'id'                  => $w->id,
                'url'                 => $w->url,
                'events'              => $w->events,
                'is_active'           => (bool) $w->is_active,
                'has_secret'          => ! empty($w->secret),
                'created_at'          => $w->created_at,
                'updated_at'          => $w->updated_at,
                'last_delivery_at'    => $lastDelivery?->fired_at,
                'last_delivery_status'=> $lastDelivery?->response_status,
            ];
        });

        return response()->json(['data' => $data]);
    }

    public function store(CreateOutboundWebhookRequest $request): JsonResponse
    {
        $data = $request->validated();

        $webhook = new OutboundWebhook();
        $webhook->tenant_id = $request->user()->tenant_id;
        $webhook->url       = $data['url'];
        $webhook->events    = $data['events'];
        $webhook->is_active = $data['is_active'] ?? true;
        $webhook->secret    = $data['secret'] ?? bin2hex(random_bytes(16)); // 32 hex chars
        $webhook->save();

        return response()->json([
            'data' => [
                'id'         => $webhook->id,
                'url'        => $webhook->url,
                'events'     => $webhook->events,
                'is_active'  => (bool) $webhook->is_active,
                'secret'     => $webhook->secret, // returned only on creation
                'created_at' => $webhook->created_at,
            ],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $webhook = OutboundWebhook::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $deliveries = WebhookDelivery::where('outbound_webhook_id', $webhook->id)
            ->orderByDesc('fired_at')
            ->limit(20)
            ->get();

        return response()->json([
            'data' => [
                'id'         => $webhook->id,
                'url'        => $webhook->url,
                'events'     => $webhook->events,
                'is_active'  => (bool) $webhook->is_active,
                'has_secret' => ! empty($webhook->secret),
                'created_at' => $webhook->created_at,
                'updated_at' => $webhook->updated_at,
                'deliveries' => $deliveries,
            ],
        ]);
    }

    public function update(UpdateOutboundWebhookRequest $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $webhook = OutboundWebhook::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $data = $request->validated();

        if (array_key_exists('url', $data) && $data['url'] !== null && $data['url'] !== '') {
            $webhook->url = $data['url'];
        }
        if (array_key_exists('events', $data) && $data['events'] !== null) {
            $webhook->events = $data['events'];
        }
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $webhook->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('secret', $data) && $data['secret'] !== null && $data['secret'] !== '') {
            $webhook->secret = $data['secret'];
        }

        $webhook->save();

        return response()->json([
            'data' => [
                'id'         => $webhook->id,
                'url'        => $webhook->url,
                'events'     => $webhook->events,
                'is_active'  => (bool) $webhook->is_active,
                'has_secret' => ! empty($webhook->secret),
                'updated_at' => $webhook->updated_at,
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $webhook = OutboundWebhook::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $webhook->delete(); // cascades to webhook_deliveries

        return response()->json(null, 204);
    }

    /**
     * Fire a webhook.test event synchronously and return the response details.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $webhook = OutboundWebhook::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $body = [
            'event'     => 'webhook.test',
            'timestamp' => now()->toIso8601String(),
            'data'      => [
                'message' => 'Este é um webhook de teste.',
                'sample'  => [
                    'webhook_id' => $webhook->id,
                    'tenant_id'  => $webhook->tenant_id,
                    'fired_by'   => $request->user()->id,
                ],
            ],
        ];

        $headers = ['Content-Type' => 'application/json'];
        if ($webhook->secret) {
            $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($body), $webhook->secret);
        }

        $start        = microtime(true);
        $status       = null;
        $responseBody = null;
        $errorMessage = null;

        try {
            // SSRF defense-in-depth: re-validate AND pin resolved IP to close
            // DNS rebinding TOCTOU window (P0R-05).
            // Redirects allowed (Slack/Discord/GitHub) but each hop re-validated.
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
                            $guard->assertSafeUrl((string) $uri);
                        },
                    ],
                ])
                ->post($webhook->url, $body);

            $status       = $response->status();
            $responseBody = mb_substr((string) $response->body(), 0, 2000);
        } catch (OutboundWebhookGuardException $e) {
            $errorMessage = 'SSRF guard: ' . mb_substr($e->getMessage(), 0, 480);
        } catch (\Throwable $e) {
            $errorMessage = mb_substr($e->getMessage(), 0, 500);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $delivery = WebhookDelivery::create([
            'outbound_webhook_id' => $webhook->id,
            'tenant_id'           => $webhook->tenant_id,
            'event'               => 'webhook.test',
            'request_body'        => $body,
            'response_status'     => $status,
            'response_body'       => $responseBody,
            'duration_ms'         => $durationMs,
            'attempt_number'      => 1,
            'error_message'       => $errorMessage,
            'fired_at'            => now(),
        ]);

        $ok = $status !== null && $status >= 200 && $status < 300;

        return response()->json([
            'data' => [
                'delivery_id'     => $delivery->id,
                'ok'              => $ok,
                'response_status' => $status,
                'duration_ms'     => $durationMs,
                'response_body'   => $responseBody,
                'error_message'   => $errorMessage,
            ],
        ]);
    }

    public function deliveries(Request $request, int $id): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        $webhook = OutboundWebhook::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        $deliveries = WebhookDelivery::where('outbound_webhook_id', $webhook->id)
            ->orderByDesc('fired_at')
            ->paginate(50);

        return response()->json($deliveries);
    }
}
