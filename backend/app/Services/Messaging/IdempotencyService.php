<?php
namespace App\Services\Messaging;

use App\Exceptions\Messaging\IdempotencyKeyReuseException;
use App\Models\MessageDispatch;

class IdempotencyService
{
    public function hashPayload(array $payload): string
    {
        $sorted = $this->ksortRecursive($payload);
        return hash('sha256', json_encode($sorted));
    }

    public function lookup(int $tenantId, ?string $key, array $payload): ?MessageDispatch
    {
        if (! $key) return null;

        $ttlHours = (int) config('messaging.idempotency_ttl_hours', 24);
        $hash = $this->hashPayload($payload);

        $existing = MessageDispatch::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->where('created_at', '>=', now()->subHours($ttlHours))
            ->first();

        if (! $existing) return null;

        if ($existing->idempotency_payload_hash !== $hash) {
            throw new IdempotencyKeyReuseException("Idempotency key reused with different payload");
        }
        return $existing;
    }

    private function ksortRecursive(array $arr): array
    {
        ksort($arr);
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $this->ksortRecursive($v);
            }
        }
        return $arr;
    }
}
