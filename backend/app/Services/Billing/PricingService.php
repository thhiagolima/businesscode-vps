<?php

namespace App\Services\Billing;

use App\Models\ServicePrice;
use App\Models\Tenant;
use App\Models\TenantServicePrice;
use Illuminate\Support\Facades\Cache;

class PricingService
{
    /**
     * Resolve the price for a service for a given tenant.
     *
     * Resolution order (highest first):
     *   1. TenantServicePrice (per-tenant override)
     *   2. Plan.sale_cents_overrides (per-plan override)
     *   3. ServicePrice (global default, cached 5min)
     *
     * Retorna AMBOS cents (retrocompat) e micros (precisão real). Snapshot em
     * MessageDispatch deve gravar micros pra preservar valor exato na cobrança.
     *
     * @return array{cost_cents: int, sale_cents: int, cost_micros: int, sale_micros: int, source: string}
     */
    public function priceFor(Tenant $tenant, string $service): array
    {
        $global = $this->globalPrice($service);
        if (! $global) {
            throw new \InvalidArgumentException("Unknown service: {$service}");
        }

        $costCents  = (int) $global->cost_cents;
        $saleCents  = (int) $global->sale_cents;
        $costMicros = $global->effectiveCostMicros();
        $saleMicros = $global->effectiveSaleMicros();

        $plan = $tenant->plan ?? null;
        $planOverridesHas = false;
        if ($plan && is_array($plan->sale_cents_overrides ?? null) && isset($plan->sale_cents_overrides[$service])) {
            $saleCents = (int) $plan->sale_cents_overrides[$service];
            $saleMicros = $saleCents * ServicePrice::MICROS_PER_CENT;
            $planOverridesHas = true;
        }

        $override = TenantServicePrice::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('service', $service)
            ->first();
        if ($override) {
            $saleCents = (int) $override->sale_cents;
            $saleMicros = $override->effectiveSaleMicros();
        }

        return [
            'cost_cents'  => $costCents,
            'sale_cents'  => $saleCents,
            'cost_micros' => $costMicros,
            'sale_micros' => $saleMicros,
            'source'      => $override ? 'tenant_override' : ($planOverridesHas ? 'plan_override' : 'global'),
        ];
    }

    private function globalPrice(string $service): ?ServicePrice
    {
        return Cache::remember(
            "service_price:{$service}",
            now()->addMinutes(5),
            fn () => ServicePrice::where('service', $service)->first()
        );
    }
}
