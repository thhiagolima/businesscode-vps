<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function templates()
    {
        $tenantId = auth()->user()->tenant_id;
        $templates = \Illuminate\Support\Facades\Cache::remember(
            "whatsapp_templates_{$tenantId}",
            300, // 5 minutes
            fn () => $this->whatsapp->getTemplates($tenantId)
        );
        return ApiResponse::success($templates);
    }

    public function templatePreview(string $name)
    {
        $tenantId = auth()->user()->tenant_id;
        $templates = $this->whatsapp->getTemplates($tenantId);
        $template = collect($templates)->firstWhere('name', $name);

        if (! $template) {
            return ApiResponse::error('Template não encontrado', [], 404);
        }

        return ApiResponse::success($template);
    }

    public function sendText(Request $request)
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'text'            => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $conversation = Conversation::where('id', $data['conversation_id'])
            ->where('tenant_id', $tenantId)
            ->firstOrFail();

        $result = $this->whatsapp->sendText($conversation->phone, $data['text'], $tenantId);

        if (! $result['ok']) {
            return ApiResponse::error('Falha ao enviar mensagem: ' . ($result['error'] ?? 'Erro desconhecido'), [], 422);
        }

        $message = ConversationMessage::create([
            'conversation_id'     => $conversation->id,
            'tenant_id'           => $tenantId,
            'direction'           => 'outbound',
            'sender_type'         => 'human',
            'sender_id'           => auth()->id(),
            'type'                => 'text',
            'content'             => $data['text'],
            'external_message_id' => $result['message_id'],
            'status'              => 'sent',
            'created_at'          => now(),
            'sent_at'             => now(),
        ]);

        $conversation->update(['last_message_at' => now(), 'unread_count' => 0]);

        return ApiResponse::success($message, 'Mensagem enviada');
    }
}
