<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateCouponRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;
use App\Models\Plan;

class CheckoutController extends Controller
{
    public function validateCoupon(ValidateCouponRequest $request)
    {
        $coupon = Coupon::where('code', strtoupper($request->code))->first();

        if (!$coupon || !$coupon->isValid()) {
            return ApiResponse::error('Cupom inválido ou expirado.', [], 422);
        }

        $discount = null;
        if ($request->plan_id) {
            $plan = Plan::find($request->plan_id);
            if ($plan) {
                $discount = $coupon->calculateDiscount((float) $plan->price_monthly);
            }
        }

        return ApiResponse::success([
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'discount_type' => $coupon->discount_type,
                'discount_value' => $coupon->discount_value,
            ],
            'calculated_discount' => $discount,
        ]);
    }

    public function config()
    {
        return ApiResponse::success([
            'mp_public_key' => config('services.mercadopago.public_key'),
            'credit_unit_price' => config('services.credits.unit_price'),
            'credit_min_purchase' => config('services.credits.min_purchase'),
        ]);
    }
}
