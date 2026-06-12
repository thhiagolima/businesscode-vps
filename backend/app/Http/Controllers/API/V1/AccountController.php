<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Services\Billing\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function balance(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        $balance = (int) $tenant->balance_cents;
        $limit   = (int) $tenant->credit_limit_cents;
        $available = $balance + $limit;

        return response()->json([
            'data' => [
                'balance_cents'      => $balance,
                'balance_brl'        => $this->fmtBrl($balance),
                'credit_limit_cents' => $limit,
                'credit_limit_brl'   => $this->fmtBrl($limit),
                'available_cents'    => $available,
                'available_brl'      => $this->fmtBrl($available),
                'billing_status'     => $tenant->billing_status,
                'billing_cycle_day'  => $tenant->billing_cycle_day,
                'last_billing_at'    => $tenant->last_billing_at,
            ],
        ]);
    }

    public function pricing(Request $request, PricingService $pricing): JsonResponse
    {
        $tenant = $request->user()->tenant;

        // Single source of truth for prices shown to the tenant in Plans.vue,
        // ConfirmSendModal.vue, Step3Contacts.vue (P0-03). Cover every channel
        // the UI may render so the frontend never falls back to hard-coded prices.
        $services = ['sms', 'voice', 'email', 'whatsapp', 'ai_generation', 'audio_tts'];
        $out = [];

        foreach ($services as $svc) {
            try {
                $p = $pricing->priceFor($tenant, $svc);
            } catch (\InvalidArgumentException $e) {
                // service not configured globally — skip
                continue;
            }
            $out[] = [
                'service'    => $svc,
                'sale_cents' => (int) $p['sale_cents'],
                'sale_brl'   => $this->fmtBrl((int) $p['sale_cents']),
                'source'     => $p['source'],
            ];
        }

        return response()->json(['data' => $out]);
    }

    private function fmtBrl(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }
}
