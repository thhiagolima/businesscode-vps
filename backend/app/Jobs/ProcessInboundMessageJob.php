<?php

namespace App\Jobs;

use App\Models\AiPersona;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Ai\ChatAiService;
use App\Services\SettingsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 60;

    public function __construct(
        public int $conversationId,
        public int $messageId,
        public int $tenantId
    ) {}

    public function handle(
        ChatAiService $ai,
        WhatsAppService $whatsapp,
        SettingsService $settings
    ): void {
        $conversation = Conversation::withoutGlobalScopes()->findOrFail($this->conversationId);

        // Só processa se conversa está em modo bot
        if ($conversation->status !== 'bot') {
            Log::channel('whatsapp')->debug('inbound.skip_not_bot', [
                'conversation' => $this->conversationId,
                'status'       => $conversation->status,
            ]);
            return;
        }

        // Verificar se chatbot está habilitado
        $enabled = $settings->get($this->tenantId, 'chatbot', 'enabled', false);
        if (! $enabled) {
            Log::channel('whatsapp')->debug('inbound.skip_disabled', ['tenant' => $this->tenantId]);
            return;
        }

        // Verificar se tem persona aprovada
        $persona = AiPersona::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'approved')
            ->first();

        if (! $persona) {
            Log::channel('whatsapp')->debug('inbound.skip_no_persona', ['tenant' => $this->tenantId]);
            return;
        }

        // Gerar resposta da IA
        $result = $ai->generateReply($conversation, $this->tenantId);

        if (! $result['ok']) {
            Log::channel('ai')->warning('chat.reply_failed', [
                'conversation' => $this->conversationId,
                'error'        => $result['error'],
            ]);
            return;
        }

        $mode = $settings->get($this->tenantId, 'chatbot', 'mode', 'suggestion');

        if ($mode === 'autonomous') {
            // Enviar resposta diretamente via WhatsApp
            $sendResult = $whatsapp->sendText($conversation->phone, $result['reply'], $this->tenantId);

            if ($sendResult['ok']) {
                ConversationMessage::forceCreate([
                    'conversation_id'     => $conversation->id,
                    'tenant_id'           => $this->tenantId,
                    'direction'           => 'outbound',
                    'sender_type'         => 'ai',
                    'type'                => 'text',
                    'content'             => $result['reply'],
                    'external_message_id' => $sendResult['message_id'],
                    'status'              => 'sent',
                    'created_at'          => now(),
                    'sent_at'             => now(),
                ]);

                $conversation->update(['last_message_at' => now()]);

                Log::channel('whatsapp')->info('inbound.ai_replied', [
                    'conversation' => $this->conversationId,
                    'mode'         => 'autonomous',
                ]);
            } else {
                Log::channel('whatsapp')->warning('inbound.ai_send_failed', [
                    'conversation' => $this->conversationId,
                    'error'        => $sendResult['error'],
                ]);
            }
        } else {
            // Modo sugestão — salvar na metadata da conversa
            $metadata = $conversation->metadata ?? [];
            $metadata['ai_suggestion'] = $result['reply'];
            $conversation->update(['metadata' => $metadata]);

            Log::channel('whatsapp')->info('inbound.ai_suggestion_saved', [
                'conversation' => $this->conversationId,
                'mode'         => 'suggestion',
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::channel('whatsapp')->error('inbound.job_failed', [
            'conversation' => $this->conversationId,
            'error'        => $e->getMessage(),
        ]);
    }
}
