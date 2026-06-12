<?php

namespace App\Http\Controllers\API\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCouponRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Coupon;

class CouponController extends Controller
{
    public function index()
    {
        return ApiResponse::success(Coupon::orderBy('created_at', 'desc')->get());
    }

    public function store(StoreCouponRequest $request)
    {
        $data = $request->validated();
        $data['code'] = strtoupper($data['code']);
        $coupon = Coupon::create($data);
        return ApiResponse::success($coupon, 'Cupom criado.', [], 201);
    }

    public function update(StoreCouponRequest $request, int $id)
    {
        $coupon = Coupon::findOrFail($id);
        $data = $request->validated();
        $data['code'] = strtoupper($data['code']);
        $coupon->update($data);
        return ApiResponse::success($coupon, 'Cupom atualizado.');
    }

    public function destroy(int $id)
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->delete();
        return ApiResponse::success([], 'Cupom removido.');
    }
}
