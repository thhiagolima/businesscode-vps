<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Jobs\FireOutboundWebhookJob;
use App\Models\CampaignDispatch;
use App\Services\MercadoPagoService;
use App\Services\Messaging\MessagingService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Recebe webhook do Mercado Pago (pagamentos e assinaturas).
     */
    public function mercadopago(Request $request)
    {
        $xSignature = $request->header('x-signature', '');
        $xRequestId = $request->header('x-request-id', '');
        $dataId = (string) $request->input('data.id', '');
        $type   = (string) $request->input('type', '');

        // Validate payload shape before doing crypto / DB work. An empty
        // type or data.id is meaningless and used to slip past idempotency
        // checks downstream (P0-19).
        if ($dataId === '' || $type === '') {
            return response()->json(['status' => 'invalid payload'], 400);
        }
        // Whitelist every topic Mercado Pago actually emits for our integration. The
        // subscription flow sends `subscription_preapproval` (status changes) and
        // `subscription_authorized_payment` (recurring charge); the legacy `subscription`
        // / `preapproval` aliases are accepted too. These MUST match the routing in
        // MercadoPagoService::processWebhook or real notifications get dropped (P0-19 / C1).
        $supportedTypes = [
            'payment',
            'subscription',
            'preapproval',
            'subscription_preapproval',
            'subscription_authorized_payment',
        ];
        if (! in_array($type, $supportedTypes, true)) {
            return response()->json(['status' => 'unsupported type'], 400);
        }

        if (!MercadoPagoService::validateWebhookSignature($xSignature, $xRequestId, $dataId)) {
            Log::warning('[MercadoPago] webhook invalid signature', [
                'x_signature' => $xSignature,
                'data_id' => $dataId,
            ]);
            \App\Models\AuditLog::record('payment.webhook_rejected', null, null, [
                'type' => $type, 'data_id' => $dataId, 'reason' => 'invalid_signature',
            ]);
            return response()->json(['status' => 'invalid signature'], 401);
        }

        Log::info('[MercadoPago] webhook received', ['type' => $type, 'data_id' => $dataId]);
        \App\Models\AuditLog::record('payment.webhook_received', null, null, [
            'type' => $type, 'data_id' => $dataId,
        ]);

        try {
            $mpService = app(MercadoPagoService::class);
            $mpService->processWebhook($type, (string) $dataId, $xRequestId);
        } catch (\Exception $e) {
            Log::error('[MercadoPago] webhook processing error', [
                'type' => $type,
                'data_id' => $dataId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Recebe callbacks de status de entrega da Infobip (SMS, Email, Voz).
     * URL configurada no painel Infobip em "Webhook" → "Delivery Reports".
     */
    public function infobipDelivery(Request $request, ?string $token = null): JsonResponse
    {
        // Auth via path token (Infobip notifyUrl carries no header) or header.
        if (! $this->validateSecret($request, $token)) {
            Log::channel('infobip')->warning('webhook.unauthorized', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        Log::channel('infobip')->debug('webhook.received', ['results_count' => count($payload['results'] ?? [])]);

        // Infobip envia delivery reports em array "results" (SMS/Voz)
        // Para email o campo é "results" também
        $results = $payload['results'] ?? [];

        if (empty($results)) {
            return response()->json(['ok' => true, 'processed' => 0]);
        }

        $processed = 0;

        foreach ($results as $result) {
            $messageId = $result['messageId'] ?? null;
            if (! $messageId) {
                continue;
            }

            $dispatch = null;

            if (class_exists(\App\Models\MessageDispatch::class)) {
                $dispatch = \App\Models\MessageDispatch::withoutGlobalScopes()
                    ->where('external_message_id', $messageId)
                    ->first();
            }

            if (! $dispatch) {
                $dispatch = CampaignDispatch::withoutGlobalScopes()
                    ->where('external_message_id', $messageId)
                    ->first();
            }

            if (! $dispatch) {
                Log::channel('infobip')->debug('webhook.dispatch_not_found', ['message_id' => $messageId]);
                continue;
            }

            $newStatus = $this->resolveStatus($result);
            $this->applyStatus($dispatch, $newStatus, $result);
            $processed++;
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    /**
     * Mapeia os status groups da Infobip para os status internos.
     *
     * Infobip groupId:
     *  1 = PENDING
     *  3 = DELIVERED
     *  2 = UNDELIVERABLE
     *  4 = EXPIRED
     *  5 = REJECTED
     */
    private function resolveStatus(array $result): string
    {
        $groupId   = $result['status']['groupId']   ?? null;
        $groupName = $result['status']['groupName'] ?? '';

        return match ((int) $groupId) {
            3       => 'delivered',
            2, 4, 5 => 'failed',
            default => 'sent', // PENDING ou desconhecido → mantém como sent
        };
    }

    /**
     * Aplica o novo status ao dispatch (CampaignDispatch ou MessageDispatch).
     */
    private function applyStatus(\Illuminate\Database\Eloquent\Model $dispatch, string $status, array $result): void
    {
        $now = now();

        $previousStatus = $dispatch->status;

        $isVoice = $dispatch instanceof \App\Models\MessageDispatch && $dispatch->channel === 'voice';

        // For voice, the answer/end timing can arrive in a LATER delivery report
        // that carries the SAME status group (e.g. DELIVERED, then DELIVERED with
        // answerTime). A plain status-only dedup would drop that second report and
        // we'd never learn the call was actually picked up. Detect newly-arrived
        // answer metadata so it survives the dedup below.
        $bringsAnswer = $isVoice && empty($dispatch->answered_at) && ! empty($result['answerTime']);
        $bringsEnd    = $isVoice && empty($dispatch->ended_at) && ! empty($result['endTime']);

        // P0-20: dedupe replays. If a webhook delivers the same status we already have,
        // skip the update entirely. This prevents:
        //   - delivered_at / failed_at being overwritten on every replay,
        //   - FireOutboundWebhookJob being dispatched again for the same transition,
        //   - flood of outbound webhook events that downstream consumers would see as new.
        // Exception: a same-status voice report that brings new answer/end timing
        // is NOT a replay — it must still be applied so call.answered/completed fire.
        if ($status === $previousStatus && ! $bringsAnswer && ! $bringsEnd) {
            Log::channel('infobip')->debug('webhook.status_dedup_skip', [
                'dispatch_id' => $dispatch->id,
                'message_id'  => $dispatch->external_message_id,
                'status'      => $status,
            ]);
            return;
        }

        $statusChanged = $status !== $previousStatus;

        $updates = ['status' => $status];

        if ($status === 'delivered' && $statusChanged) {
            $updates['delivered_at'] = $now;
        } elseif ($status === 'failed' && $statusChanged) {
            $updates['failed_at']      = $now;
            $updates['error_message']  = $result['error']['description'] ?? $result['status']['description'] ?? 'Falha na entrega';
        }

        // Voice-specific fields (Infobip TTS delivery report carries call timing).
        // Apply when the dispatch is a voice MessageDispatch and the payload has
        // any of the voice keys. Schema accepts NULL on non-voice channels.
        if ($dispatch instanceof \App\Models\MessageDispatch && $dispatch->channel === 'voice') {
            // Infobip uses camelCase in webhook payloads.
            if (! empty($result['answerTime'])) {
                $updates['answered_at'] = $this->parseInfobipTime($result['answerTime']);
            }
            if (! empty($result['endTime'])) {
                $updates['ended_at'] = $this->parseInfobipTime($result['endTime']);
            }
            if (isset($result['callDurationInSeconds'])) {
                $updates['call_duration_seconds'] = (int) $result['callDurationInSeconds'];
            }
            // Some payloads put duration under "duration" instead.
            elseif (isset($result['duration'])) {
                $updates['call_duration_seconds'] = (int) $result['duration'];
            }
            $voiceStatusName = $result['status']['name'] ?? null;
            if ($voiceStatusName) {
                $updates['voice_status'] = mb_substr((string) $voiceStatusName, 0, 64);
            }
        }

        $dispatch->update($updates);

        Log::channel('infobip')->info('webhook.status_updated', [
            'dispatch_id' => $dispatch->id,
            'message_id'  => $dispatch->external_message_id,
            'status'      => $status,
        ]);

        // Fire outbound webhook events for MessageDispatch status transitions.
        // CampaignDispatch is a legacy model and is not covered by the new
        // outbound webhook integration.
        if ($dispatch instanceof \App\Models\MessageDispatch) {
            $payload = app(MessagingService::class)->buildEventPayload($dispatch);

            // message.* (and the failure-side call.failed) key off a real status
            // transition, so replays never re-fire them.
            if ($statusChanged) {
                if ($status === 'delivered') {
                    FireOutboundWebhookJob::dispatch($dispatch->tenant_id, 'message.delivered', $payload);
                } elseif ($status === 'failed') {
                    FireOutboundWebhookJob::dispatch($dispatch->tenant_id, 'message.failed', $payload);

                    // Voice failures (NO_ANSWER, BUSY, REJECTED, EXPIRED, etc.):
                    // we want consumers to learn which numbers did not pick up.
                    if ($isVoice) {
                        FireOutboundWebhookJob::dispatch($dispatch->tenant_id, 'call.failed', $payload);
                    }
                }
            }

            // Voice connect lifecycle keys off the ANSWER metadata transition, not
            // the message status — so a later DELIVERED report that finally carries
            // answerTime still surfaces call.answered/call.completed exactly once.
            if ($isVoice && $bringsAnswer && $dispatch->answered_at) {
                FireOutboundWebhookJob::dispatch($dispatch->tenant_id, 'call.answered', $payload);
            }
            if ($isVoice && $bringsEnd && $dispatch->answered_at && $dispatch->ended_at) {
                FireOutboundWebhookJob::dispatch($dispatch->tenant_id, 'call.completed', $payload);
            }
        }
    }

    /**
     * Recebe webhook de tracking de email (OPENED, CLICKED, BOUNCED, COMPLAINT,
     * UNSUBSCRIBE) da Infobip. URL configurada no painel Infobip em
     * "Email" → "Tracking" → "Webhook URL".
     *
     * Infobip envia o payload como { "results": [ { messageId, event, ... } ] }
     * onde event ∈ { SENT, DELIVERED, OPENED, CLICKED, BOUNCED, REJECTED,
     * COMPLAINT, UNSUBSCRIBE, DROPPED }.
     */
    public function infobipEmailEvents(Request $request, ?string $token = null): JsonResponse
    {
        if (! $this->validateSecret($request, $token)) {
            Log::channel('infobip')->warning('email_webhook.unauthorized', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $results = $request->input('results', []);
        if (! is_array($results) || empty($results)) {
            return response()->json(['ok' => true, 'processed' => 0]);
        }

        $processed = 0;

        foreach ($results as $event) {
            if (! is_array($event)) {
                continue;
            }
            $messageId = $event['messageId'] ?? null;
            $name      = mb_strtoupper((string) ($event['event'] ?? ''));
            if (! $messageId || $name === '') {
                continue;
            }

            $dispatch = \App\Models\MessageDispatch::withoutGlobalScopes()
                ->where('external_message_id', $messageId)
                ->where('channel', 'email')
                ->first();

            if (! $dispatch) {
                Log::channel('infobip')->debug('email_webhook.dispatch_not_found', [
                    'message_id' => $messageId,
                    'event'      => $name,
                ]);
                continue;
            }

            if ($this->applyEmailEvent($dispatch, $name, $event)) {
                $processed++;
            }
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    /**
     * Apply a single Infobip email tracking event to a dispatch and fire
     * the matching outbound webhook. Returns true when a state change was
     * applied (used for the processed counter).
     *
     * Dedup is enforced per-event: re-deliveries of the same OPENED/CLICKED
     * payload do not re-fire outbound webhooks. Multiple opens still update
     * nothing (opened_at is the *first* open); future enhancement could
     * count opens in meta if needed.
     */
    private function applyEmailEvent(\App\Models\MessageDispatch $dispatch, string $name, array $event): bool
    {
        $now    = now();
        $update = null;
        $fire   = null;

        switch ($name) {
            case 'OPENED':
                if ($dispatch->opened_at) {
                    return false; // dedup: keep first-open semantics
                }
                $update = ['opened_at' => $this->parseInfobipTime($event['openedAt'] ?? null) ?? $now];
                $fire   = 'email.opened';
                break;

            case 'CLICKED':
                if ($dispatch->first_clicked_at) {
                    return false; // dedup: track only the first click
                }
                $update = ['first_clicked_at' => $this->parseInfobipTime($event['clickedAt'] ?? null) ?? $now];
                $fire   = 'email.clicked';
                break;

            case 'BOUNCED':
            case 'DROPPED':
            case 'REJECTED':
                if ($dispatch->bounced_at) {
                    return false;
                }
                $update = [
                    'bounced_at'  => $this->parseInfobipTime($event['bouncedAt'] ?? null) ?? $now,
                    'bounce_type' => mb_substr((string) ($event['bounceType'] ?? $name), 0, 32),
                ];
                $fire = 'email.bounced';
                break;

            case 'COMPLAINT':
            case 'SPAMREPORT':
                if ($dispatch->complaint_at) {
                    return false;
                }
                $update = ['complaint_at' => $now];
                $fire   = 'email.complaint';
                break;

            case 'UNSUBSCRIBE':
            case 'UNSUBSCRIBED':
                if ($dispatch->unsubscribed_at) {
                    return false;
                }
                $update = ['unsubscribed_at' => $now];
                $fire   = 'email.unsubscribed';
                break;

            default:
                // SENT / DELIVERED: handled by the delivery report endpoint.
                return false;
        }

        $dispatch->update($update);

        Log::channel('infobip')->info('email_webhook.event_applied', [
            'dispatch_id' => $dispatch->id,
            'message_id'  => $dispatch->external_message_id,
            'event'       => $name,
        ]);

        if ($fire) {
            FireOutboundWebhookJob::dispatch(
                $dispatch->tenant_id,
                $fire,
                app(MessagingService::class)->buildEventPayload($dispatch->fresh())
            );
        }

        return true;
    }

    /**
     * Parse Infobip-style ISO8601 timestamp (may include milliseconds + timezone).
     * Returns Carbon instance or null if unparseable.
     */
    private function parseInfobipTime(?string $raw): ?\Carbon\Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($raw);
        } catch (\Throwable) {
            Log::channel('infobip')->warning('webhook.unparseable_time', ['raw' => $raw]);
            return null;
        }
    }

    /**
     * Validate the webhook secret.
     *
     * Secrets are only accepted via HTTP headers. Query-string secrets are
     * a known anti-pattern (CWE-598) because they are recorded in access logs,
     * browser history, referrer chains and proxies.
     */
    private function validateSecret(Request $request, ?string $token = null): bool
    {
        $secret = $this->settings->getGlobal('infobip', 'webhook_secret', '');

        if (empty($secret)) {
            Log::channel('infobip')->warning('webhook.no_secret_configured');
            return false;
        }

        // 1) Path token: Infobip's per-message notifyUrl cannot send custom auth
        // headers, so the secret rides in the callback URL path
        // (/webhooks/infobip/delivery/{token}). Timing-safe compare.
        if ($token !== null && $token !== '' && hash_equals($secret, $token)) {
            return true;
        }

        // 2) Header (ibm-signature-v2 or Authorization: Bearer) for callers that
        // can set headers / portal-level webhook config.
        $provided = $request->header('ibm-signature-v2')
            ?? $request->header('Authorization')
            ?? '';

        if ($provided === '') {
            return false;
        }

        $value = str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : $provided;
        return hash_equals($secret, $value);
    }
}
