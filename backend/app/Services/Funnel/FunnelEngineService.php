<?php

namespace App\Services\Funnel;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\FunnelEdge;
use App\Models\FunnelExecution;
use App\Models\FunnelNode;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

class FunnelEngineService
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private SettingsService $settings
    ) {}

    public function advance(FunnelExecution $execution): void
    {
        $maxSteps = 20;
        $steps = 0;

        while ($steps < $maxSteps) {
            $steps++;

            $currentNode = FunnelNode::where('funnel_id', $execution->funnel_id)
                ->where('node_id', $execution->current_node_id)
                ->first();

            if (! $currentNode) {
                $execution->update(['status' => 'completed', 'completed_at' => now()]);
                return;
            }

            $nextNodeId = $this->resolveNextNode($execution, $currentNode);

            if (! $nextNodeId) {
                $execution->update(['status' => 'completed', 'completed_at' => now()]);
                return;
            }

            $execution->update(['current_node_id' => $nextNodeId]);

            $nextNode = FunnelNode::where('funnel_id', $execution->funnel_id)
                ->where('node_id', $nextNodeId)
                ->first();

            if (! $nextNode) {
                $execution->update(['status' => 'completed', 'completed_at' => now()]);
                return;
            }

            $shouldContinue = $this->executeNode($execution, $nextNode);

            if (! $shouldContinue) {
                return;
            }
        }

        Log::channel('whatsapp')->warning('funnel.max_steps_reached', [
            'execution' => $execution->id,
            'funnel'    => $execution->funnel_id,
        ]);
    }

    private function resolveNextNode(FunnelExecution $execution, FunnelNode $currentNode): ?string
    {
        if ($currentNode->type === 'condition') {
            return null; // Condition nodes handle their own edge resolution in executeCondition
        }

        $edge = FunnelEdge::where('funnel_id', $execution->funnel_id)
            ->where('source_node_id', $currentNode->node_id)
            ->first();

        return $edge?->target_node_id;
    }

    private function executeNode(FunnelExecution $execution, FunnelNode $node): bool
    {
        return match ($node->type) {
            'message'   => $this->executeMessage($execution, $node),
            'wait'      => $this->executeWait($execution, $node),
            'condition' => $this->executeCondition($execution, $node),
            'tag'            => $this->executeTag($execution, $node),
            'transfer_human' => $this->executeTransferHuman($execution, $node),
            'ai_reply'       => $this->executeAiReply($execution, $node),
            'start'          => true,
            default     => true,
        };
    }

    private function executeMessage(FunnelExecution $execution, FunnelNode $node): bool
    {
        $config = $node->config ?? [];
        $contact = Contact::withoutGlobalScopes()->find($execution->contact_id);
        if (! $contact) return true;

        $conversation = Conversation::withoutGlobalScopes()->find($execution->conversation_id);

        if (($config['message_type'] ?? 'text') === 'template') {
            $result = $this->whatsapp->sendTemplate(
                $contact->phone,
                $config['template_name'] ?? '',
                $config['template_language'] ?? 'pt_BR',
                $config['template_components'] ?? [],
                $execution->tenant_id
            );
        } else {
            $text = str_replace(
                ['{nome}', '{telefone}', '{email}'],
                [$contact->name ?? '', $contact->phone ?? '', $contact->email ?? ''],
                $config['text'] ?? ''
            );
            $result = $this->whatsapp->sendText($contact->phone, $text, $execution->tenant_id);
        }

        if ($result['ok'] && $conversation) {
            $msg = ConversationMessage::forceCreate([
                'conversation_id'     => $conversation->id,
                'tenant_id'           => $execution->tenant_id,
                'direction'           => 'outbound',
                'sender_type'         => 'bot',
                'type'                => ($config['message_type'] ?? 'text') === 'template' ? 'template' : 'text',
                'content'             => $config['text'] ?? null,
                'template_name'       => $config['template_name'] ?? null,
                'external_message_id' => $result['message_id'],
                'status'              => 'sent',
                'created_at'          => now(),
                'sent_at'             => now(),
                'metadata'            => ['funnel_id' => $execution->funnel_id],
            ]);
            $execution->update(['last_message_id' => $msg->id]);
            $conversation->update(['last_message_at' => now()]);
        }

        Log::channel('whatsapp')->info('funnel.message_sent', [
            'execution' => $execution->id,
            'node'      => $node->node_id,
            'ok'        => $result['ok'],
        ]);

        return true;
    }

    private function executeWait(FunnelExecution $execution, FunnelNode $node): bool
    {
        $config = $node->config ?? [];
        $duration = (int) ($config['duration'] ?? 1);
        $unit = $config['unit'] ?? 'hours';

        $waitUntil = match ($unit) {
            'minutes' => now()->addMinutes($duration),
            'hours'   => now()->addHours($duration),
            'days'    => now()->addDays($duration),
            default   => now()->addHours($duration),
        };

        $execution->update([
            'status'     => 'waiting',
            'wait_until' => $waitUntil,
        ]);

        Log::channel('whatsapp')->debug('funnel.waiting', [
            'execution'  => $execution->id,
            'wait_until' => $waitUntil->toISOString(),
        ]);

        return false;
    }

    private function executeCondition(FunnelExecution $execution, FunnelNode $node): bool
    {
        $config = $node->config ?? [];
        $type = $config['condition_type'] ?? 'replied';

        $result = match ($type) {
            'replied' => $this->checkReplied($execution),
            'keyword' => $this->checkKeyword($execution, $config['keyword'] ?? ''),
            'has_tag' => $this->checkHasTag($execution, $config['tag'] ?? ''),
            default   => 'no',
        };

        $edges = FunnelEdge::where('funnel_id', $execution->funnel_id)
            ->where('source_node_id', $node->node_id)
            ->get();

        $yesLabels = ['Sim', 'sim', 'Match', 'match', 'Respondeu', 'respondeu', 'yes'];
        $noLabels  = ['Não', 'não', 'Sem match', 'sem match', 'Timeout', 'timeout', 'no'];

        $matchLabels = $result === 'yes' ? $yesLabels : $noLabels;

        $targetEdge = $edges->first(function ($edge) use ($matchLabels) {
            return in_array($edge->label, $matchLabels);
        }) ?? $edges->first();

        if ($targetEdge) {
            $execution->update(['current_node_id' => $targetEdge->target_node_id]);
        } else {
            $execution->update(['status' => 'completed', 'completed_at' => now()]);
            return false;
        }

        Log::channel('whatsapp')->debug('funnel.condition_evaluated', [
            'execution' => $execution->id,
            'type'      => $type,
            'result'    => $result,
            'next_node' => $targetEdge?->target_node_id,
        ]);

        return true;
    }

    private function checkReplied(FunnelExecution $execution): string
    {
        if (! $execution->last_message_id) return 'no';

        $hasReply = ConversationMessage::withoutGlobalScopes()
            ->where('conversation_id', $execution->conversation_id)
            ->where('direction', 'inbound')
            ->where('id', '>', $execution->last_message_id)
            ->exists();

        return $hasReply ? 'yes' : 'no';
    }

    private function checkKeyword(FunnelExecution $execution, string $pattern): string
    {
        if (empty($pattern) || ! $execution->last_message_id) return 'no';

        $reply = ConversationMessage::withoutGlobalScopes()
            ->where('conversation_id', $execution->conversation_id)
            ->where('direction', 'inbound')
            ->where('id', '>', $execution->last_message_id)
            ->latest('created_at')
            ->first();

        if (! $reply || ! $reply->content) return 'no';

        $safePattern = preg_quote($pattern, '/');
        return preg_match('/' . $safePattern . '/iu', $reply->content) ? 'yes' : 'no';
    }

    private function checkHasTag(FunnelExecution $execution, string $tag): string
    {
        if (empty($tag)) return 'no';

        $contact = Contact::withoutGlobalScopes()->find($execution->contact_id);
        if (! $contact) return 'no';

        $tags = $contact->meta['tags'] ?? [];
        return in_array($tag, $tags) ? 'yes' : 'no';
    }

    private function executeTag(FunnelExecution $execution, FunnelNode $node): bool
    {
        $config = $node->config ?? [];
        $action = $config['action'] ?? 'add';
        $tag = $config['tag'] ?? '';

        if (empty($tag)) return true;

        $contact = Contact::withoutGlobalScopes()->find($execution->contact_id);
        if (! $contact) return true;

        $meta = $contact->meta ?? [];
        $tags = $meta['tags'] ?? [];

        if ($action === 'add' && ! in_array($tag, $tags)) {
            $tags[] = $tag;
        } elseif ($action === 'remove') {
            $tags = array_values(array_filter($tags, fn($t) => $t !== $tag));
        }

        $meta['tags'] = $tags;
        $contact->update(['meta' => $meta]);

        Log::channel('whatsapp')->debug('funnel.tag_applied', [
            'execution' => $execution->id,
            'action'    => $action,
            'tag'       => $tag,
        ]);

        return true;
    }

    private function executeTransferHuman(FunnelExecution $execution, FunnelNode $node): bool
    {
        $conversation = Conversation::withoutGlobalScopes()->find($execution->conversation_id);
        if ($conversation) {
            $conversation->update(['status' => 'human']);
            Log::channel('whatsapp')->info('funnel.transfer_human', [
                'execution_id'    => $execution->id,
                'conversation_id' => $conversation->id,
            ]);
        }
        // Complete the funnel execution since human took over
        $execution->update(['status' => 'completed', 'completed_at' => now()]);
        return false; // Stop advancing
    }

    private function executeAiReply(FunnelExecution $execution, FunnelNode $node): bool
    {
        $conversation = Conversation::withoutGlobalScopes()->find($execution->conversation_id);
        if (! $conversation) return true;

        $chatAi = app(\App\Services\Ai\ChatAiService::class);
        $result = $chatAi->generateReply($conversation, $execution->tenant_id);

        if ($result['ok'] && $result['reply']) {
            $sendResult = $this->whatsapp->sendText($conversation->phone, $result['reply'], $execution->tenant_id);

            if ($sendResult['ok']) {
                $msg = ConversationMessage::forceCreate([
                    'conversation_id'     => $conversation->id,
                    'tenant_id'           => $execution->tenant_id,
                    'direction'           => 'outbound',
                    'sender_type'         => 'ai',
                    'type'                => 'text',
                    'content'             => $result['reply'],
                    'external_message_id' => $sendResult['message_id'],
                    'status'              => 'sent',
                    'created_at'          => now(),
                    'sent_at'             => now(),
                    'metadata'            => ['funnel_id' => $execution->funnel_id],
                ]);
                $execution->update(['last_message_id' => $msg->id]);
                $conversation->update(['last_message_at' => now()]);
            }
        }

        return true; // Continue to next node
    }
}
