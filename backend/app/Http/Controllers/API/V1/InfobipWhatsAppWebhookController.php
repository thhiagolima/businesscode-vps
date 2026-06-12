<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundMessageJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\InfobipWhatsAppNumber;
use App\Models\FunnelExecution;
use App\Jobs\StartFunnelExecutionJob;
use App\Jobs\FunnelConditionCheckJob;
use App\Services\Funnel\FunnelTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InfobipWhatsAppWebhookController extends Controller
{
    private \App\Services\SettingsService $settings;

    public function __construct(\App\Services\SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Recebe mensagens WhatsApp inbound via Infobip.
     * URL para configurar no painel Infobip: POST /api/v1/webhooks/infobip/whatsapp
     */
    public function handle(Request $request): JsonResponse
    {
        $secret = $this->settings->getGlobal('infobip', 'webhook_secret', '');
        if (empty($secret)) {
            Log::channel('whatsapp')->warning('infobip.webhook.no_secret_configured', ['ip' => $request->ip()]);
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        // CWE-598: secret must be sent via header only. Never accept ?secret= in query string.
        $provided = $request->header('ibm-signature-v2') ?? $request->header('Authorization') ?? '';
        $value = str_starts_with($provided, 'Bearer ') ? substr($provided, 7) : $provided;
        if (!hash_equals($secret, $value)) {
            Log::channel('whatsapp')->warning('infobip.webhook.unauthorized', ['ip' => $request->ip()]);
            return response()->json(['ok' => false, 'message' => 'Unauthorized'], 401);
        }

        $payload = $request->all();
        Log::channel('whatsapp')->debug('infobip.webhook.received', ['message_count' => count($payload['results'] ?? [$payload])]);

        $results = $payload['results'] ?? [$payload];

        foreach ($results as $msg) {
            $this->processMessage($msg);
        }

        return response()->json(['ok' => true]);
    }

    private function processMessage(array $msg): void
    {
        $from = $msg['from'] ?? null;
        $to   = $msg['to']   ?? null;
        $messageId = $msg['messageId'] ?? $msg['message_id'] ?? null;

        if (!$from) return;

        // Resolver tenant pelo número destinatário (nosso sender)
        $number = InfobipWhatsAppNumber::where('sender', $to)
            ->orWhere('number', $to)
            ->first();

        if (!$number || !$number->tenant_id) {
            Log::channel('whatsapp')->debug('infobip.webhook.no_tenant', ['to' => $to]);
            return;
        }

        $tenantId = $number->tenant_id;

        // Extrair conteúdo da mensagem
        $type = 'text';
        $content = null;
        $mediaUrl = null;

        if (isset($msg['message']['text'])) {
            $content = $msg['message']['text'];
        } elseif (isset($msg['message']['caption'])) {
            $content = $msg['message']['caption'];
        }

        if (isset($msg['message']['url'])) {
            $mediaUrl = $msg['message']['url'];
            $type = $msg['message']['type'] ?? 'document';
        } elseif (isset($msg['message']['type'])) {
            $type = $msg['message']['type'];
        }

        // Buscar ou criar contato
        $contact = Contact::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $from],
            ['name' => $msg['from_name'] ?? $msg['contact']['name'] ?? null, 'status' => 'active']
        );

        // Buscar ou criar conversa
        $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenantId, 'phone' => $from],
            ['contact_id' => $contact->id, 'channel' => 'whatsapp', 'status' => 'bot']
        );

        $conversation->update([
            'last_message_at' => now(),
            'unread_count'    => $conversation->unread_count + 1,
        ]);

        // Criar mensagem
        $messageRecord = ConversationMessage::forceCreate([
            'conversation_id'     => $conversation->id,
            'tenant_id'           => $tenantId,
            'direction'           => 'inbound',
            'sender_type'         => 'contact',
            'type'                => $type,
            'content'             => $content,
            'media_url'           => $mediaUrl,
            'external_message_id' => $messageId,
            'status'              => 'delivered',
            'created_at'          => now(),
        ]);

        Log::channel('whatsapp')->info('infobip.webhook.message_saved', [
            'conversation_id' => $conversation->id,
            'from'            => $from,
            'type'            => $type,
        ]);

        // Trigger funil ou IA (mesma lógica do Meta webhook)
        $this->routeInbound($conversation, $contact, $messageRecord, $tenantId, $content);
    }

    /**
     * Roteia mensagem inbound para funil ou IA — mesma lógica do WhatsAppWebhookController.
     */
    private function routeInbound(Conversation $conversation, Contact $contact, ConversationMessage $message, int $tenantId, ?string $content): void
    {
        // Verificar se contato está em funil ativo
        $activeExecution = FunnelExecution::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contact->id)
            ->whereIn('status', ['running', 'waiting'])
            ->first();

        if ($activeExecution) {
            $meta = $activeExecution->metadata ?? [];
            $meta['last_reply_at'] = now()->toIso8601String();
            $activeExecution->update(['metadata' => $meta]);
            FunnelConditionCheckJob::dispatch($activeExecution->id);
            return;
        }

        // Tentar match de funil trigger
        $triggerService = app(FunnelTriggerService::class);
        $funnel = $triggerService->matchFunnel($content ?? '', $tenantId);

        if ($funnel) {
            $recentExecution = FunnelExecution::withoutGlobalScopes()
                ->where('contact_id', $contact->id)
                ->where('funnel_id', $funnel->id)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->exists();

            if (!$recentExecution) {
                StartFunnelExecutionJob::dispatch($funnel->id, $contact->id, $conversation->id, $tenantId);
                return;
            }
        }

        // Fallback: IA/chatbot
        if ($conversation->status === 'bot') {
            ProcessInboundMessageJob::dispatch($conversation->id, $message->id, $tenantId);
        }
    }
}
