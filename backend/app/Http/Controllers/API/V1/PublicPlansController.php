<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignDispatch;
use App\Models\Plan;
use App\Models\ServicePrice;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicPlansController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = Plan::where('listed', true)->orderBy('price_monthly')->get()->map(function (Plan $plan) {
            return [
                'id'                       => $plan->id,
                'name'                     => $plan->name,
                'slug'                     => $plan->slug,
                'price_monthly'            => (float) $plan->price_monthly,
                'price_annual'             => $plan->price_annual !== null ? (float) $plan->price_annual : null,
                'monthly_equivalent_annual' => $plan->monthlyEquivalentFor('annual'),
                'included_balance_cents'   => (int) ($plan->included_balance_cents ?? 0),
                'max_contacts'             => (int) $plan->max_contacts,
                'max_campaigns'            => (int) $plan->max_campaigns,
                'features'                 => is_array($plan->features) ? $plan->features : json_decode($plan->features ?? '[]', true),
            ];
        });

        return response()->json([
            'data' => $plans,
            'meta' => [
                'annual_discount_percent' => (float) config('business.annual_discount_percent'),
            ],
        ]);
    }

    public function identity(): JsonResponse
    {
        return response()->json([
            'company_name'   => config('business.company_name'),
            'brand_name'     => config('business.brand_name'),
            'legal_name'     => config('business.company_legal_name'),
            'cnpj'           => config('business.company_cnpj'),
            'support_email'  => config('business.support_email'),
            'sales_whatsapp' => config('business.sales_whatsapp'),
            'sales_whatsapp_prompt' => config('business.sales_whatsapp_prompt'),
            'site_url'       => config('business.site_url'),
            'docs_url'       => config('business.docs_url'),
            'terms_version'  => config('business.terms_version'),
            'privacy_version' => config('business.privacy_version'),
        ]);
    }

    /**
     * Real marketing stats computed from the DB. Cached for 5 min.
     * Returns null values when there is no data — frontend decides how to render.
     */
    public function stats(): JsonResponse
    {
        $stats = Cache::remember('public.stats.v1', 300, function () {
            $totalDispatches = CampaignDispatch::withoutGlobalScopes()->count();
            $delivered = CampaignDispatch::withoutGlobalScopes()
                ->whereIn('status', ['delivered', 'read'])
                ->count();
            $totalTenants = Tenant::count();
            $activeCampaigns = Campaign::withoutGlobalScopes()
                ->whereIn('status', ['running', 'processing', 'scheduled'])
                ->count();

            $deliveryRate = $totalDispatches > 0
                ? round(($delivered / $totalDispatches) * 100, 1)
                : null;

            return [
                'total_dispatches'   => $totalDispatches,
                'delivered'          => $delivered,
                'delivery_rate'      => $deliveryRate,
                'total_tenants'      => $totalTenants,
                'active_campaigns'   => $activeCampaigns,
                'channels_supported' => 4,
                'has_data'           => $totalDispatches > 0,
            ];
        });

        return response()->json($stats);
    }

    /**
     * Tarifas públicas por canal, em R$ (sale_cents apenas).
     * NUNCA expõe cost_cents/margem. Cacheado 5 min.
     */
    public function pricing(): JsonResponse
    {
        // service => label exibido na landing. Só canais públicos por envio.
        $publicChannels = [
            'email'              => 'Email',
            'sms'                => 'SMS',
            'voice'              => 'Voz',
            'whatsapp_marketing' => 'WhatsApp',
            'ai_generation'      => 'IA Geração',
        ];

        $data = Cache::remember('public.pricing.v1', 300, function () use ($publicChannels) {
            $prices = ServicePrice::whereIn('service', array_keys($publicChannels))
                ->get()
                ->keyBy('service');

            $rows = [];
            foreach ($publicChannels as $service => $label) {
                if (! isset($prices[$service])) {
                    continue;
                }
                $rows[] = [
                    'service'    => $service,
                    'label'      => $label,
                    'sale_cents' => (int) $prices[$service]->sale_cents,
                ];
            }
            return $rows;
        });

        return response()->json(['data' => $data]);
    }
}
