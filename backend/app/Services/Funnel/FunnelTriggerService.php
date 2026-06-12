<?php

namespace App\Services\Funnel;

use App\Models\Funnel;

class FunnelTriggerService
{
    public function matchFunnel(string $content, int $tenantId): ?Funnel
    {
        $funnels = Funnel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->get();

        foreach ($funnels as $funnel) {
            foreach ($funnel->triggers ?? [] as $trigger) {
                if (($trigger['type'] ?? '') === 'keyword' && !empty($trigger['pattern'])) {
                    if (preg_match('/' . $trigger['pattern'] . '/iu', $content)) {
                        return $funnel;
                    }
                }
            }
        }

        return $funnels->firstWhere('is_default', true);
    }
}
