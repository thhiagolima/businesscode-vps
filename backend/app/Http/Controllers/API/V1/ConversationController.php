<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    use Concerns\ChecksTenant;

    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:open,bot,human,closed'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $q = Conversation::query()->with('contact:id,name,phone,email');

        if (!empty($validated['status'])) {
            $q->where('status', $validated['status']);
        }
        if (!empty($validated['search'])) {
            $s = str_replace(['%', '_'], ['\\%', '\\_'], $validated['search']);
            $q->where(function ($w) use ($s) {
                $w->where('phone', 'like', "%{$s}%")
                  ->orWhereHas('contact', function ($cq) use ($s) {
                      $cq->where('name', 'like', "%{$s}%");
                  });
            });
        }

        $items = $q->orderByDesc('last_message_at')->paginate(20);
        return ApiResponse::paginated($items, 'OK');
    }

    public function show(int $id)
    {
        $conversation = Conversation::with('contact:id,name,phone,email', 'assignedUser:id,name')->findOrFail($id);
        $this->ensureTenantOwns($conversation);

        $messages = ConversationMessage::where('conversation_id', $id)
            ->orderByDesc('created_at')
            ->paginate(50);

        $conversation->update(['unread_count' => 0]);

        return ApiResponse::success([
            'conversation' => $conversation,
            'messages'     => $messages->items(),
            'pagination'   => [
                'total'        => $messages->total(),
                'current_page' => $messages->currentPage(),
                'last_page'    => $messages->lastPage(),
            ],
        ]);
    }

    public function sendMessage(int $id, Request $request)
    {
        $conversation = Conversation::findOrFail($id);
        $this->ensureTenantOwns($conversation);

        $data = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        $whatsapp = app(\App\Services\WhatsApp\WhatsAppService::class);
        $tenantId = auth()->user()->tenant_id;

        $result = $whatsapp->sendText($conversation->phone, $data['text'], $tenantId);

        if (! $result['ok']) {
            return ApiResponse::error('Falha ao enviar: ' . ($result['error'] ?? ''), [], 422);
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

        $conversation->update([
            'last_message_at' => now(),
            'unread_count'    => 0,
            'status'          => 'human',
        ]);

        return ApiResponse::success($message, 'Enviada');
    }

    public function updateStatus(int $id, Request $request)
    {
        $conversation = Conversation::findOrFail($id);
        $this->ensureTenantOwns($conversation);
        $data = $request->validate([
            'status' => ['required', 'in:bot,human,closed'],
        ]);

        $updates = ['status' => $data['status']];

        if ($data['status'] === 'human') {
            $updates['assigned_to'] = auth()->id();
        }
        if ($data['status'] === 'closed') {
            $updates['unread_count'] = 0;
        }

        $conversation->update($updates);
        return ApiResponse::success($conversation->fresh()->load('contact:id,name,phone,email'), 'Status atualizado');
    }

    public function unreadCount()
    {
        $count = Conversation::where('tenant_id', auth()->user()->tenant_id)
            ->where('unread_count', '>', 0)
            ->count();
        return response()->json(['count' => $count]);
    }
}
