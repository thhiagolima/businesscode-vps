<?php
namespace App\Services\Messaging;

use App\Jobs\FireOutboundWebhookJob;
use App\Models\MessageOptOut;

class OptOutService
{
    public function add(int $tenantId, string $channel, string $identifier, string $reason, ?int $sourceDispatchId = null): MessageOptOut
    {
        $hash = MessageOptOut::hashFor($identifier);

        $optOut = MessageOptOut::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'channel' => $channel, 'identifier_hash' => $hash],
            [
                'identifier'         => mb_strtolower(trim($identifier)),
                'reason'             => $reason,
                'source_dispatch_id' => $sourceDispatchId,
            ]
        );

        FireOutboundWebhookJob::dispatch($tenantId, 'optout.added', [
            'channel'            => $channel,
            'identifier_hash'    => $hash,
            'reason'             => $reason,
            'source_dispatch_id' => $sourceDispatchId,
        ]);

        return $optOut;
    }

    public function remove(int $tenantId, string $channel, string $identifier): void
    {
        $hash = MessageOptOut::hashFor($identifier);
        $deleted = MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('channel', [$channel, 'all'])
            ->where('identifier_hash', $hash)
            ->delete();

        if ($deleted > 0) {
            FireOutboundWebhookJob::dispatch($tenantId, 'optout.removed', [
                'channel'         => $channel,
                'identifier_hash' => $hash,
            ]);
        }
    }

    public function isOptedOut(int $tenantId, string $channel, string $identifier): bool
    {
        $hash = MessageOptOut::hashFor($identifier);
        return MessageOptOut::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('channel', [$channel, 'all'])
            ->where('identifier_hash', $hash)
            ->exists();
    }
}
