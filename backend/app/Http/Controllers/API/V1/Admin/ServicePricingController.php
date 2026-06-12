<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateServicePriceRequest;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\ServicePrice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServicePricingController extends Controller
{
    /**
     * List all 5 services with their global cost/sale/margin.
     */
    public function index()
    {
        $items = ServicePrice::orderBy('service')->get()->map(function (ServicePrice $sp) {
            $costMicros = $sp->effectiveCostMicros();
            $saleMicros = $sp->effectiveSaleMicros();
            return [
                'id'             => $sp->id,
                'service'        => $sp->service,
                'cost_cents'     => $sp->cost_cents,
                'sale_cents'     => $sp->sale_cents,
                'cost_micros'    => $costMicros,
                'sale_micros'    => $saleMicros,
                'margin_cents'   => $sp->marginCents(),
                'margin_micros'  => $sp->marginMicros(),
                'margin_percent' => $sp->marginPercent(),
                'updated_by'     => $sp->updated_by,
                'updated_at'     => $sp->updated_at,
            ];
        });

        return ApiResponse::success($items);
    }

    /**
     * Update cost_cents + sale_cents for a service (e.g. 'sms', 'voice', ...).
     * Audits before/after + invalidates pricing cache.
     */
    public function update(UpdateServicePriceRequest $request, string $service)
    {
        $data = $request->validated();
        $user = $request->user();

        return DB::transaction(function () use ($service, $data, $user, $request) {
            $sp = ServicePrice::where('service', $service)->lockForUpdate()->firstOrFail();

            $before = [
                'cost_cents'  => $sp->cost_cents,
                'sale_cents'  => $sp->sale_cents,
                'cost_micros' => $sp->cost_micros,
                'sale_micros' => $sp->sale_micros,
            ];

            // Micros are the source of truth for billing (PricingService prefers them).
            // If client sends micros, use them and mirror cents = round(micros/1000).
            // If client sends only cents, scale up to micros (cents × 1000) to keep them in sync.
            $costMicros = isset($data['cost_micros']) && $data['cost_micros'] !== null
                ? (int) $data['cost_micros']
                : (int) $data['cost_cents'] * ServicePrice::MICROS_PER_CENT;
            $saleMicros = isset($data['sale_micros']) && $data['sale_micros'] !== null
                ? (int) $data['sale_micros']
                : (int) $data['sale_cents'] * ServicePrice::MICROS_PER_CENT;

            $sp->update([
                'cost_cents'  => (int) round($costMicros / ServicePrice::MICROS_PER_CENT),
                'sale_cents'  => (int) round($saleMicros / ServicePrice::MICROS_PER_CENT),
                'cost_micros' => $costMicros,
                'sale_micros' => $saleMicros,
                'updated_by'  => $user?->id,
            ]);

            $after = [
                'cost_cents'  => $sp->cost_cents,
                'sale_cents'  => $sp->sale_cents,
                'cost_micros' => $sp->cost_micros,
                'sale_micros' => $sp->sale_micros,
            ];

            AuditLog::create([
                'user_id'     => $user?->id,
                'tenant_id'   => $user?->tenant_id,
                'action'      => 'service_price.updated',
                'resource'    => 'service_prices',
                'resource_id' => $sp->id,
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 500),
                'metadata'    => [
                    'service' => $service,
                    'before'  => $before,
                    'after'   => $after,
                    'reason'  => $data['reason'],
                ],
            ]);

            // Invalidate cached resolved price for this service.
            Cache::forget("service_price:{$service}");

            return ApiResponse::success([
                'id'             => $sp->id,
                'service'        => $sp->service,
                'cost_cents'     => $sp->cost_cents,
                'sale_cents'     => $sp->sale_cents,
                'cost_micros'    => $sp->cost_micros,
                'sale_micros'    => $sp->sale_micros,
                'margin_cents'   => $sp->marginCents(),
                'margin_micros'  => $sp->marginMicros(),
                'margin_percent' => $sp->marginPercent(),
            ], 'Preço atualizado.');
        });
    }
}
