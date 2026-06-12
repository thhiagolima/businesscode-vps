<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundMessageJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\WhatsAppPhoneNumber;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expectedToken = $this->settings->getGlobal('whatsapp', 'verify_token', '');

        if ($mode === 'subscribe' && !empty($expectedToken) && hash_equals($expectedToken, $token ?? '')) {
            Log::channel('whatsapp')->info('webhook.verified');
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        Log::channel('whatsapp')->warning('webhook.verify_failed', ['mode' => $mode, 'ip' => $request->ip()]);
        return response('Forbidden', 403);
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->validateSignature($request)) {
            Log::channel('whatsapp')->warning('webhook.invalid_signature', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        Log::channel('whatsapp')->debug('webhook.received', [
            'entries_count'  => count($payload['entry'] ?? []),
            'object'         => $payload['object'] ?? null,
        ]);

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                if (! $phoneNumberId) continue;

                $phoneNumber = WhatsAppPhoneNumber::where('phone_number_id', $phoneNumberId)->first();
                if (! $phoneNumber) {
                    Log::channel('whatsapp')->debug('webhook.unknown_phone', ['phone_number_id' => $phoneNumberId]);
                    continue;
                }

                $tenantId = $phoneNumber->tenant_id;

                foreach ($value['messages'] ?? [] as $message) {
                    $this->processInboundMessage($message, $tenantId, $value['contacts'] ?? []);
                }

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->processStatusUpdate($status, $tenantId);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function processInboundMessage(array $message, int $tenantId, array $contacts): void
    {
        $waId = $message['from'] ?? null;
        if (! $waId) return;

        $contactName = collect($contacts)->firstWhere('wa_id', $waId)['profile']['name'] ?? null;

        $contact = Contact::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone', $waId)
            ->first();

        if (! $contact) {
            $contact = Contact::forceCreate([
                'tenant_id' => $tenantId,
                'phone'     => $waId,
                'name'      => $contactName,
                'status'    => 'active',
            ]);
        }

        $conversation = Conversation::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $waId],
            [
                'contact_id'      => $contact->id,
                'channel'         => 'whatsapp',
                'last_message_at' => now(),
            ]
        );

        $conversation->increment('unread_count');

        if ($conversation->status === 'closed') {
            $conversation->update(['status' => 'bot']);
        }

        $type    = $message['type'] ?? 'text';
        $content = null;
        $mediaUrl = null;

        switch ($type) {
            case 'text':
                $content = $message['text']['body'] ?? '';
                break;
            case 'image':
            case 'video':
            case 'audio':
            case 'document':
                $content = $message[$type]['caption'] ?? null;
                $mediaUrl = $message[$type]['id'] ?? null;
                break;
            case 'location':
                $lat = $message['location']['latitude'] ?? 0;
                $lng = $message['location']['longitude'] ?? 0;
                $content = "{$lat},{$lng}";
                break;
            case 'reaction':
                $content = $message['reaction']['emoji'] ?? '';
                break;
        }

        $conversationMessage = ConversationMessage::forceCreate([
            'conversation_id'     => $conversation->id,
            'tenant_id'           => $tenantId,
            'direction'           => 'inbound',
            'sender_type'         => 'contact',
            'type'                => $type,
            'content'             => $content,
            'media_url'           => $mediaUrl,
            'external_message_id' => $message['id'] ?? null,
            'status'              => 'delivered',
            'created_at'          => now(),
        ]);

        if ($conversation->status === 'bot') {
            // Check if contact is in active funnel
            $activeExecution = \App\Models\FunnelExecution::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contact->id)
                ->whereIn('status', ['running', 'waiting'])
                ->first();

            if ($activeExecution) {
                // Contact in funnel — update reply timestamp and recheck condition
                $meta = $activeExecution->metadata ?? [];
                $meta['last_reply_at'] = now()->toISOString();
                $activeExecution->update(['metadata' => $meta]);
                \App\Jobs\FunnelConditionCheckJob::dispatch($activeExecution->id);
            } else {
                // Try funnel trigger match
                $triggerService = app(\App\Services\Funnel\FunnelTriggerService::class);
                $matchedFunnel = $triggerService->matchFunnel($content ?? '', $tenantId);

                if ($matchedFunnel) {
                    // Anti-loop: don't re-trigger same funnel within 5 minutes
                    $recentExecution = \App\Models\FunnelExecution::withoutGlobalScopes()
                        ->where('contact_id', $contact->id)
                        ->where('funnel_id', $matchedFunnel->id)
                        ->where('created_at', '>=', now()->subMinutes(5))
                        ->exists();

                    if (! $recentExecution) {
                        \App\Jobs\StartFunnelExecutionJob::dispatch(
                            $matchedFunnel->id,
                            $contact->id,
                            $conversation->id,
                            $tenantId
                        );
                    } else {
                        ProcessInboundMessageJob::dispatch(
                            $conversation->id,
                            $conversationMessage->id,
                            $tenantId
                        );
                    }
                } else {
                    // Fallback to AI/bot
                    ProcessInboundMessageJob::dispatch(
                        $conversation->id,
                        $conversationMessage->id,
                        $tenantId
                    );
                }
            }
        }

        Log::channel('whatsapp')->info('inbound.processed', [
            'tenant'       => $tenantId,
            'from'         => $waId,
            'type'         => $type,
            'conversation' => $conversation->id,
        ]);
    }

    private function processStatusUpdate(array $status, int $tenantId): void
    {
        $wamid     = $status['id'] ?? null;
        $newStatus = $status['status'] ?? null;

        if (! $wamid || ! $newStatus) return;

        $msg = ConversationMessage::withoutGlobalScopes()
            ->where('external_message_id', $wamid)
            ->first();

        if (! $msg) return;

        $updates = ['status' => $newStatus];

        switch ($newStatus) {
            case 'sent':
                $updates['sent_at'] = now();
                break;
            case 'delivered':
                $updates['delivered_at'] = now();
                break;
            case 'read':
                $updates['read_at'] = now();
                break;
            case 'failed':
                $updates['error_message'] = $status['errors'][0]['title'] ?? 'Delivery failed';
                break;
        }

        $msg->update($updates);

        if ($msg->sender_type === 'campaign') {
            $campaignId = $msg->metadata['campaign_id'] ?? null;
            if ($campaignId) {
                \App\Models\CampaignDispatch::withoutGlobalScopes()
                    ->where('external_message_id', $wamid)
                    ->update(array_intersect_key($updates, array_flip(['status', 'delivered_at', 'error_message'])));
            }
        }

        Log::channel('whatsapp')->debug('status.updated', ['wamid' => $wamid, 'status' => $newStatus]);
    }

    private function validateSignature(Request $request): bool
    {
        $appSecret = $this->settings->getGlobal('whatsapp', 'app_secret', '');

        if (empty($appSecret)) {
            Log::channel('whatsapp')->warning('webhook.no_app_secret_configured');
            return false; // REJECT if no secret configured
        }

        $signature = $request->header('X-Hub-Signature-256', '');
        $expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }
}
